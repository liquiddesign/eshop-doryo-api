<?php

declare(strict_types=1);

namespace DoryoApi\Endpoint;

use DoryoApi\Config;
use DoryoApi\Http\Query;
use DoryoApi\Http\Response;
use DoryoApi\Support\Dates;
use Nette\Utils\Strings;

/**
 * Health. Bez platného tokenu odpoví jen „běžím" — o shopu neřekne nic, aby se z veřejné
 * odpovědi nedala číst verze eshopu ani jeho konfigurace.
 */
final class MetaEndpoint extends BaseEndpoint
{
	/**
	 * @return array<string, string>
	 */
	public function getRoutes(): array
	{
		return [
			'v1/meta/health' => 'health',
			'v1/meta/codebooks' => 'codebooks',
			'v1/meta/capabilities' => 'capabilities',
			'v1/suppliers' => 'suppliers',
			'v1/categories' => 'categories',
		];
	}

	/**
	 * @param array<string, string> $params
	 */
	public function health(array $params, Query $query): Response
	{
		unset($query);

		$now = Dates::now($this->config->getTimezone());

		if (($params['authenticated'] ?? '1') === '0') {
			return new Response([
				'status' => 'ok',
				'service' => 'eshop-doryo-api',
				'now' => $now,
			]);
		}

		return new Response([
			'status' => 'ok',
			'service' => 'eshop-doryo-api',
			'version' => Config::VERSION,
			'shop' => [
				'name' => $this->config->getShopName(),
				'url' => $this->config->getShopUrl(),
				'currency' => $this->config->getCurrency(),
				'languages' => $this->config->getLanguages(),
			],
			'eshopVersion' => $this->config->getEshopVersion(),
			'now' => $now,
		]);
	}

	/**
	 * Číselníky v jednom volání — ceníky, skupiny, sklady, viditelnosti, stavy, dopravy,
	 * platby, sazby DPH, výrobci.
	 *
	 * Bez tohohle model tipuje jména („ceník PC_A"? „sklad Praha"?) a plýtvá voláními, než
	 * najde, co v shopu vůbec existuje. Je to malý slovník na začátek konverzace.
	 * @param array<string, string> $params
	 */
	public function codebooks(array $params, Query $query): Response
	{
		unset($params, $query);

		$suffix = $this->connection->getMutationSuffix();

		return new Response([
			'pricelists' => $this->rows(
				'eshop_pricelist',
				['id' => 'this.uuid', 'code' => 'this.code', 'name' => 'this.name', 'priority' => 'this.priority'],
				static fn ($collection) => $collection->where('this.isActive', true)->where('this.isPurchase', false)->orderBy(['this.priority' => 'ASC']),
			),
			'customerGroups' => $this->rows(
				'eshop_customergroup',
				['id' => 'this.uuid', 'name' => 'this.name', 'isDefault' => 'this.defaultAfterRegistration'],
			),
			'stores' => $this->rows(
				'eshop_store',
				['id' => 'this.uuid', 'code' => 'this.code', 'name' => "this.name$suffix"],
			),
			'visibilityLists' => $this->rows(
				'eshop_visibilitylist',
				['code' => 'this.code', 'name' => 'this.name', 'hidden' => 'this.hidden'],
			),
			'deliveryTypes' => $this->rows(
				'eshop_deliverytype',
				['code' => 'this.code', 'name' => "this.name$suffix"],
			),
			'paymentTypes' => $this->rows(
				'eshop_paymenttype',
				['code' => 'this.code', 'name' => "this.name$suffix"],
			),
			'vatRates' => $this->rows(
				'eshop_vatrate',
				['code' => 'this.uuid', 'name' => 'this.name', 'rate' => 'this.rate'],
			),
			'producers' => $this->rows(
				'eshop_producer',
				['id' => 'this.uuid', 'code' => 'this.code', 'name' => "this.name$suffix"],
				static fn ($collection) => $collection->orderBy(["this.name$suffix" => 'ASC']),
			),
			'orderStatuses' => $this->config->getOrderStates(),
			'counts' => [
				'products' => $this->count('eshop_product', 'this.deletedTs IS NULL'),
				'customers' => $this->count('eshop_customer'),
				'orders' => $this->count('eshop_order'),
				'invoices' => $this->count('eshop_invoice'),
			],
		]);
	}

	/**
	 * Strom kategorií. `path` je materializovaná cesta, takže z ní jde poznat zanoření
	 * i pořadí bez dalšího dotazu.
	 * @param array<string, string> $params
	 */
	public function categories(array $params, Query $query): Response
	{
		unset($params);

		$suffix = $this->connection->getMutationSuffix();
		$collection = $this->repository(\Eshop\DB\Category::class)->many();

		if ($level = $query->int('level')) {
			$collection->where('LENGTH(this.path) = :apiLen', ['apiLen' => $level * 4]);
		}

		if ($query->bool('hidden', false) === false) {
			$collection->where('this.hidden', false);
		}

		$this->applyFulltext($collection, $query, ["this.name$suffix", 'this.code']);

		$page = $this->paginate($collection->orderBy(['this.path' => 'ASC']), $query);
		$items = [];

		foreach ($page['rows'] as $id => $category) {
			$items[] = [
				'id' => $id,
				'code' => $category->code ?: null,
				'name' => $category->name,
				'fullName' => self::stringValue($category, 'fullName'),
				'path' => $category->path,
				'level' => (int) (Strings::length((string) $category->path) / 4),
				'hidden' => (bool) $category->hidden,
			];
		}

		return Response::list($items, $page['nextCursor']);
	}

	/**
	 * Co tenhle shop reálně používá.
	 *
	 * Ne každý shop vede balíky, recenze, importy od dodavatelů nebo úhrady faktur — a model
	 * to z prázdného seznamu nepozná: neví, jestli „nic tam není" znamená „dnes nic" nebo
	 * „tohle se tu nepoužívá vůbec". Tady dostane u každé domény, jestli je naplněná, kolik
	 * v ní je záznamů a kdy do ní naposled něco přibylo — a podle toho se buď ptá, nebo rovnou
	 * řekne, že to shop neeviduje.
	 *
	 * Počty jsou odhad ze statistik tabulek (přesný COUNT přes miliony řádků by tenhle
	 * přehled zdržel o vteřiny); od toho je to přehled, ne report.
	 * @param array<string, string> $params
	 */
	public function capabilities(array $params, Query $query): Response
	{
		unset($params, $query);

		$rows = $this->tableRows();

		$features = [
			'orders' => $this->feature($rows, 'eshop_order', 'createdTs', 'Objednávky a jejich položky.'),
			'invoices' => $this->feature($rows, 'eshop_invoice', 'exposed', 'Faktury vydané shopem.'),
			'products' => $this->feature($rows, 'eshop_product', 'createdTs', 'Katalog produktů.'),
			'customers' => $this->feature($rows, 'eshop_customer', 'createdTs', 'Zákaznické účty.'),
			'stock' => $this->feature($rows, 'eshop_amount', null, 'Skladové zásoby po skladech.'),
			'orderHistory' => $this->feature($rows, 'eshop_orderlogitem', 'createdTs', 'Historie změn objednávek — kdo co kdy změnil.'),
			'shipments' => $this->feature($rows, 'eshop_package', null, 'Balíky a expedice; bez nich zná API jen dopravu objednávky.'),
			// pozor: řádek recenze vzniká i jen odesláním žádosti o hodnocení, proto se ptáme
			// na vyplněné skóre — jinak by shop hlásil desítky tisíc recenzí, které nikdo nenapsal
			'reviews' => $this->feature($rows, 'eshop_review', 'createdTs', 'Hodnocení produktů zákazníky.', 'score IS NOT NULL'),
			'attributes' => $this->feature($rows, 'eshop_attributeassign', null, 'Parametry produktů (rozměr, objem, barva…).'),
			'supplierImports' => $this->feature($rows, 'eshop_importresult', 'finishedTs', 'Běhy importů od dodavatelů.'),
			'abandonedCarts' => $this->feature($rows, 'eshop_cart', null, 'Košíky, které nedošly k objednávce.'),
			'loyalty' => $this->feature($rows, 'eshop_loyaltyprogram', null, 'Věrnostní program a jeho slevové hladiny.'),
		];

		$features['customerPrices'] = [
			'available' => $this->config->areCustomerPricesEnabled(),
			'detail' => $this->config->areCustomerPricesEnabled()
				? 'Ceny konkrétního zákazníka API vydává (/v1/customers/{id}/prices).'
				: 'Ceny konkrétního zákazníka jsou vypnuté konfigurací, endpoint vrací 403.',
		];

		$features['invoicePayments'] = [
			'available' => $this->config->isInvoicePaymentTracked(),
			'detail' => $this->config->isInvoicePaymentTracked()
				? 'Shop eviduje úhrady faktur; stav se počítá z data úhrady a částky.'
				: 'Shop úhrady nevede — paid a outstanding jsou null, stav jen ze splatnosti.',
		];

		return new Response([
			'shop' => $this->config->getShopName(),
			'features' => $features,
			'note' => 'available: false znamená, že tohle shop neeviduje — neptej se na to a rovnou to řekni.',
		]);
	}

	/**
	 * Dodavatelé i s tím, kdy od nich naposled přišel import.
	 * @param array<string, string> $params
	 */
	public function suppliers(array $params, Query $query): Response
	{
		unset($params, $query);

		$rows = $this->connection->rows(['s' => 'eshop_supplier'], [
			'id' => 's.uuid',
			'code' => 's.code',
			'name' => 's.name',
			'importActive' => 's.isImportActive',
			'lastImportTs' => 's.lastImportTs',
			'lastUpdateTs' => 's.lastUpdateTs',
			'codePrefix' => 's.productCodePrefix',
			'showCodeWithPrefix' => 's.showCodeWithPrefix',
			'products' => '(SELECT COUNT(*) FROM eshop_supplierproduct sp WHERE sp.fk_supplier = s.uuid)',
		])->orderBy(['s.importPriority' => 'ASC']);

		$items = [];

		foreach ($rows as $row) {
			$items[] = [
				'id' => $row->id,
				'code' => $row->code,
				'name' => $row->name,
				'importActive' => (bool) $row->importActive,
				'lastImportAt' => Dates::dateTime($row->lastImportTs, $this->config->getTimezone()),
				'lastUpdateAt' => Dates::dateTime($row->lastUpdateTs, $this->config->getTimezone()),
				'products' => (int) $row->products,
				// kód produktu se v katalogu ukazuje bez prefixu, když se prefix skrývá
				'codePrefix' => $row->codePrefix ?: null,
				'codeShownWithPrefix' => (bool) $row->showCodeWithPrefix,
			];
		}

		return Response::list($items, null);
	}

	/**
	 * @param array<string, string> $select
	 * @param callable(\StORM\Collection<\StORM\Entity>): \StORM\Collection<\StORM\Entity>|null $prepare
	 * @return array<array<string, mixed>>
	 */
	private function rows(string $table, array $select, ?callable $prepare = null): array
	{
		$collection = $this->connection->rows(['this' => $table], $select);

		if ($prepare !== null) {
			$collection = $prepare($collection);
		}

		$items = [];

		foreach ($collection->setTake(500) as $row) {
			$items[] = (array) $row;
		}

		return $items;
	}

	private function count(string $table, ?string $where = null): int
	{
		$collection = $this->connection->rows(['this' => $table], ['cnt' => 'COUNT(*)']);

		if ($where !== null) {
			$collection->where($where);
		}

		return (int) $collection->firstValue('cnt');
	}

	/**
	 * Odhad počtu řádků tabulek ze statistik databáze — bez skenování dat.
	 * @return array<string, int>
	 */
	private function tableRows(): array
	{
		$rows = $this->connection->rows(['t' => 'information_schema.TABLES'], [
			'name' => 't.TABLE_NAME',
			// `rows` je v MariaDB rezervované slovo, alias musí být jiný
			'tableRows' => 't.TABLE_ROWS',
		])->where('t.TABLE_SCHEMA = DATABASE()');

		$map = [];

		foreach ($rows as $row) {
			$map[$row->name] = (int) $row->tableRows;
		}

		return $map;
	}

	/**
	 * @param array<string, int> $tableRows
	 * @return array<string, mixed>
	 */
	private function feature(array $tableRows, string $table, ?string $timestampColumn, string $detail, ?string $usableWhere = null): array
	{
		if (!\array_key_exists($table, $tableRows)) {
			return [
				'available' => false,
				'records' => 0,
				'lastAt' => null,
				'detail' => "$detail Tabulka v téhle verzi eshopu není.",
			];
		}

		$approximate = $tableRows[$table];
		$hasRows = $this->hasAnyRow($table);
		$used = $hasRows && ($usableWhere === null || $this->hasAnyRow($table, $usableWhere));

		if (!$hasRows) {
			$note = "$detail Shop to nepoužívá — tabulka je prázdná.";
		} elseif (!$used) {
			$note = "$detail Řádky sice existují, ale žádný není vyplněný k použití — ber to, jako by to shop nevedl.";
		} else {
			$note = $detail;
		}

		return [
			'available' => $used,
			'records' => $used ? $approximate : 0,
			'recordsApproximate' => true,
			'lastAt' => $used && $timestampColumn !== null ? $this->lastTimestamp($table, $timestampColumn) : null,
			'detail' => $note,
		];
	}

	private function hasAnyRow(string $table, ?string $where = null): bool
	{
		try {
			$collection = $this->connection->rows(['t' => $table], ['one' => '1'])->setTake(1);

			if ($where !== null) {
				$collection->where("t.$where");
			}

			return $collection->firstValue('one') !== false;
		} catch (\Throwable) {
			return false;
		}
	}

	private function lastTimestamp(string $table, string $column): ?string
	{
		try {
			$value = $this->connection->rows(['t' => $table], ['last' => "MAX(t.$column)"])->firstValue('last');

			return \is_string($value) ? Dates::dateTime($value, $this->config->getTimezone()) : null;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Hodnota sloupce, který v téhle verzi eshopu být nemusí.
	 */
	private static function stringValue(\StORM\Entity $entity, string $property): ?string
	{
		try {
			$value = $entity->getValue($property);
		} catch (\Throwable) {
			return null;
		}

		return \is_string($value) && $value !== '' ? $value : null;
	}
}
