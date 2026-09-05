<?php

declare(strict_types=1);

namespace DoryoApi\Endpoint;

use DoryoApi\Http\ApiException;
use DoryoApi\Http\Query;
use DoryoApi\Http\Response;
use DoryoApi\Support\Money;
use DoryoApi\Support\PriceFormula;
use DoryoApi\Support\ProductCode;
use DoryoApi\Support\Sql;
use Eshop\DB\Customer;

/**
 * Ceníky a ceny — podklad pro cenové nabídky.
 *
 * Ceny konkrétního zákazníka jsou vědomá výjimka z pravidla „ceny jiných zákazníků ven
 * nejdou" (spec §8.2 a §11): bez nich obchodník nabídku nesestaví. Zapíná je konfigurace
 * (`customerPrices: true`), takže shop, který je nechce, je nevystavuje.
 *
 * Počítá se stejným vzorcem jako v katalogu (viz DoryoApi\Support\PriceFormula), včetně
 * slevové hladiny, stropu slevy na produkt a pevné marže — aby nabídka seděla s košíkem.
 */
final class PricesEndpoint extends BaseEndpoint
{
	/**
	 * @return array<string, string>
	 */
	public function getRoutes(): array
	{
		return [
			'v1/pricelists' => 'pricelists',
			'v1/prices' => 'prices',
			'v1/customers/{id}/prices' => 'customerPrices',
		];
	}

	/**
	 * @param array<string, string> $params
	 */
	public function pricelists(array $params, Query $query): Response
	{
		unset($params);

		$collection = $this->repository(\Eshop\DB\Pricelist::class)->many()
			->where('this.isActive', true);

		if ($query->bool('purchase', false) === false) {
			// nákupní ceníky jsou nákupní ceny — ty ven nepatří
			$collection->where('this.isPurchase', false);
		}

		$this->applyFulltext($collection, $query, ['this.code', 'this.name']);

		$page = $this->paginate($collection->orderBy(['this.priority' => 'ASC', 'this.name' => 'ASC']), $query);
		$usage = $this->loadPricelistUsage(\array_keys($page['rows']));

		$items = [];

		foreach ($page['rows'] as $id => $pricelist) {
			$items[] = [
				'id' => $id,
				'code' => $pricelist->code ?: null,
				'name' => $pricelist->name,
				'currency' => $this->codebooks->getCurrencyCode(self::idValue($pricelist, 'currency')),
				'priority' => $pricelist->priority,
				'allowsDiscountLevel' => (bool) $pricelist->allowDiscountLevel,
				'onlyWithCoupon' => (bool) $pricelist->activeOnlyWithCoupon,
				'products' => $usage[$id]['products'] ?? 0,
				'customers' => $usage[$id]['customers'] ?? 0,
			];
		}

		return Response::list($items, $page['nextCursor']);
	}

	/**
	 * Ceny jednoho ceníku. Tohle je „ceníková cena", ne cena konkrétního zákazníka.
	 * @param array<string, string> $params
	 */
	public function prices(array $params, Query $query): Response
	{
		unset($params);

		$pricelist = $query->string('pricelist');

		if ($pricelist === null) {
			throw ApiException::badRequest('Parametr pricelist je povinný — kód nebo id ceníku (seznam dá /v1/pricelists).');
		}

		$resolved = $this->resolvePricelist($pricelist);

		return $this->listPrices([$resolved], $query, 0, 100, 0.0, null);
	}

	/**
	 * Ceny, které má konkrétní zákazník — jeho ceníky, jeho slevová hladina, jeho strop slevy.
	 * @param array<string, string> $params
	 */
	public function customerPrices(array $params, Query $query): Response
	{
		if (!$this->config->areCustomerPricesEnabled()) {
			throw ApiException::forbidden('Ceny konkrétních zákazníků API nevydává (vypnuto konfigurací).');
		}

		/** @var \Eshop\DB\Customer $customer */
		$customer = $this->one(Customer::class, $params['id'], 'Zákazník');

		$pricelists = $this->resolveCustomerPricelists($customer);

		if (!$pricelists) {
			return Response::list([], null);
		}

		[$discountLevel, $maxDiscount, $surcharge] = $this->resolveCustomerDiscount($customer);

		return $this->listPrices($pricelists, $query, $discountLevel, $maxDiscount, $surcharge, $customer->getPK());
	}

	/**
	 * Ceníky zákazníka. Když nemá vlastní, platí pro něj ceníky jeho skupiny — tak to má
	 * i katalog. Kupónové ceníky se vynechávají, nabídka se nedělá „za kupón".
	 * @return array<string>
	 */
	private function resolveCustomerPricelists(Customer $customer): array
	{
		$rows = $this->connection->rows(['nxn' => 'eshop_customer_nxn_eshop_pricelist'], ['id' => 'nxn.fk_pricelist'])
			->join(['pl' => 'eshop_pricelist'], 'pl.uuid = nxn.fk_pricelist', [], 'INNER')
			->where('nxn.fk_customer', $customer->getPK())
			->where('pl.isActive', true)
			->where('pl.isPurchase', false)
			->where('pl.activeOnlyWithCoupon', false);

		$ids = [];

		foreach ($rows as $row) {
			$ids[$row->id] = $row->id;
		}

		if ($ids) {
			return \array_values($ids);
		}

		$group = self::idValue($customer, 'group');

		if ($group === null) {
			return [];
		}

		$rows = $this->connection->rows(['nxn' => 'eshop_customergroup_nxn_eshop_pricelist'], ['id' => 'nxn.fk_pricelist'])
			->join(['pl' => 'eshop_pricelist'], 'pl.uuid = nxn.fk_pricelist', [], 'INNER')
			->where('nxn.fk_customergroup', $group)
			->where('pl.isActive', true)
			->where('pl.isPurchase', false)
			->where('pl.activeOnlyWithCoupon', false);

		foreach ($rows as $row) {
			$ids[$row->id] = $row->id;
		}

		return \array_values($ids);
	}

	/**
	 * Slevová hladina zákazníka, strop slevy na produkt a pevná marže. Věrnostní program
	 * hladinu přebíjí, když je vyšší — stejně jako v katalogu.
	 * @return array{0: int, 1: int, 2: float}
	 */
	private function resolveCustomerDiscount(Customer $customer): array
	{
		$discount = (int) $customer->discountLevelPct;
		$loyaltyLevel = self::idValue($customer, 'loyaltyProgramDiscountLevel');

		if ($loyaltyLevel !== null) {
			$level = $this->connection->rows(['l' => 'eshop_loyaltyprogramdiscountlevel'], ['pct' => 'l.discountLevel'])
				->where('l.uuid', $loyaltyLevel)
				->firstValue('pct');

			if ($level !== null) {
				$discount = \max($discount, (int) $level);
			}
		}

		return [
			$discount,
			(int) ($customer->maxDiscountProductPct ?? 100),
			(float) ($customer->surchargeLevelPct ?? 0),
		];
	}

	private function resolvePricelist(string $codeOrId): string
	{
		$id = $this->repository(\Eshop\DB\Pricelist::class)->many()
			->where('this.uuid = :value OR this.code = :value', ['value' => $codeOrId])
			->firstValue('uuid');

		if (!$id) {
			throw ApiException::notFound("Ceník $codeOrId neexistuje.");
		}

		return (string) $id;
	}

	/**
	 * Společné jádro obou cenových endpointů: ceny vyjmenovaných ceníků pro stránku produktů,
	 * z nich vítězná (nejnižší priorita, pak nejnižší cena) — tak, jak vybírá katalog.
	 * @param array<string> $pricelists
	 */
	private function listPrices(array $pricelists, Query $query, int $discountLevel, int $maxDiscount, float $surcharge, ?string $customerId): Response
	{
		$suffix = $this->connection->getMutationSuffix();
		$collection = $this->repository(\Eshop\DB\Product::class)->many()->where($this->productNotDeleted());

		// Stránkuje se přes produkty, ale ceny se berou z vyjmenovaných ceníků — bez tohohle
		// omezení by se listovalo celým katalogem a ceník se 139 položkami by na první
		// stránce vrátil prázdno, protože by se do ní žádná z nich netrefila. Vypadalo by to
		// jako prázdný ceník; ve skutečnosti byly ty ceny až na jedenácté stránce.
		$collection->where(
			'this.uuid IN (SELECT dp.fk_product FROM eshop_price dp WHERE dp.fk_pricelist IN ('
				. Sql::inList($this->connection, $pricelists) . ') AND ' . $this->priceNotHidden('dp') . ')',
		);

		$codes = $query->strings('codes');

		if ($code = $query->string('code')) {
			$codes[] = $code;
		}

		ProductCode::filter($collection, $codes, $this->connection);

		if ($ean = $query->string('ean')) {
			$collection->where('this.ean', $ean);
		}

		$this->applyFulltext($collection, $query, ["this.name$suffix", 'this.code', 'this.ean']);

		$page = $this->paginate($collection->orderBy(['this.code' => 'ASC']), $query);

		if (!$page['rows']) {
			return Response::list([], $page['nextCursor']);
		}

		$prices = $this->loadPrices(\array_keys($page['rows']), $pricelists, $discountLevel, $maxDiscount, $surcharge);
		$items = [];

		foreach ($page['rows'] as $id => $product) {
			$price = $prices[$id] ?? null;

			if ($price === null) {
				continue;
			}

			$currency = $this->codebooks->getCurrencyCode($price->currency);
			$vatRate = $this->codebooks->getVatRate($product->vatRate);

			$items[] = [
				'productId' => $id,
				'code' => $product->getFullCode(),
				'ean' => $product->ean ?: null,
				'name' => $product->name,
				'unit' => $product->unit ?: null,
				'vatRate' => $vatRate,
				'customerId' => $customerId,
				'pricelist' => [
					'id' => $price->pricelist,
					'code' => $price->pricelistCode ?: null,
					'name' => $price->pricelistName,
				],
				'listPrice' => Money::format($price->listPrice, $currency),
				'price' => Money::format($price->price, $currency),
				'priceWithVat' => Money::format($price->priceVat, $currency),
				'priceBefore' => Money::format($price->priceBefore, $currency),
				'discountPct' => $price->listPrice > 0 ? \round((1 - $price->price / $price->listPrice) * 100, 2) : 0.0,
			];
		}

		return Response::list($items, $page['nextCursor']);
	}

	/**
	 * @param array<string> $productIds
	 * @param array<string> $pricelists
	 * @return array<string, object>
	 */
	private function loadPrices(array $productIds, array $pricelists, int $discountLevel, int $maxDiscount, float $surcharge): array
	{
		$precision = $this->codebooks->getCurrencyPrecision(null);

		$price = PriceFormula::expression('price', $discountLevel, $maxDiscount, $surcharge, $precision);
		$priceVat = PriceFormula::expression('priceVat', $discountLevel, $maxDiscount, $surcharge, $precision);

		$rows = $this->connection->rows(['p' => 'eshop_price'], [
			'id' => 'p.fk_product',
			'listPrice' => 'p.price',
			'price' => $price,
			'priceVat' => $priceVat,
			'priceBefore' => 'p.priceBefore',
			'pricelist' => 'p.fk_pricelist',
			'pricelistCode' => 'pl.code',
			'pricelistName' => 'pl.name',
			'currency' => 'pl.fk_currency',
			'priority' => 'pl.priority',
		])
			->join(['pl' => 'eshop_pricelist'], 'pl.uuid = p.fk_pricelist', [], 'INNER')
			->join(['prod' => 'eshop_product'], 'prod.uuid = p.fk_product', [], 'INNER')
			->where('p.fk_product', $productIds)
			->where('p.fk_pricelist', $pricelists)
			->where($this->priceNotHidden('p'))
			->orderBy(['pl.priority' => 'ASC', 'price' => 'ASC']);

		$map = [];

		foreach ($rows as $row) {
			$map[$row->id] ??= $row;
		}

		return $map;
	}

	/**
	 * Kolik produktů a zákazníků ceník má — ať model pozná, který ceník je ten „hlavní".
	 * @param array<string> $ids
	 * @return array<string, array<string, int>>
	 */
	private function loadPricelistUsage(array $ids): array
	{
		if (!$ids) {
			return [];
		}

		$map = [];

		// Počítá se to samé, co pak vrátí /v1/prices — tedy bez skrytých cen a bez smazaných
		// produktů. Jinak by ceník sliboval víc položek, než se z něj dá vytáhnout.
		$prices = $this->connection->rows(['p' => 'eshop_price'], ['id' => 'p.fk_pricelist', 'cnt' => 'COUNT(*)'])
			->join(['prod' => 'eshop_product'], 'prod.uuid = p.fk_product', [], 'INNER')
			->where('p.fk_pricelist', $ids)
			->where($this->priceNotHidden('p'))
			->where($this->productNotDeleted('prod'))
			->setGroupBy(['p.fk_pricelist']);

		foreach ($prices as $row) {
			$map[$row->id]['products'] = (int) $row->cnt;
		}

		$customers = $this->connection->rows(['nxn' => 'eshop_customer_nxn_eshop_pricelist'], ['id' => 'nxn.fk_pricelist', 'cnt' => 'COUNT(*)'])
			->where('nxn.fk_pricelist', $ids)
			->setGroupBy(['nxn.fk_pricelist']);

		foreach ($customers as $row) {
			$map[$row->id]['customers'] = (int) $row->cnt;
		}

		return $map;
	}
}
