<?php

declare(strict_types=1);

namespace DoryoApi\Endpoint;

use DoryoApi\Codebooks;
use DoryoApi\Config;
use DoryoApi\Http\ApiException;
use DoryoApi\Http\Query;
use DoryoApi\Http\Response;
use DoryoApi\Mapper\ProductMapper;
use DoryoApi\Support\Dates;
use DoryoApi\Support\ProductCode;
use Eshop\DB\Product;
use StORM\Collection;
use StORM\DIConnection;

/**
 * Produkty s cenou z veřejného ceníku a se skladem.
 *
 * Cena je ta, kterou v katalogu vidí nepřihlášený návštěvník (ceníky výchozí skupiny
 * zákazníků, nejnižší z nich). Ceny konkrétních zákazníků API nevydává.
 */
final class ProductsEndpoint extends BaseEndpoint
{
	public function __construct(
		DIConnection $connection,
		Config $config,
		Codebooks $codebooks,
		private ProductMapper $mapper,
	) {
		parent::__construct($connection, $config, $codebooks);
	}

	/**
	 * @return array<string, string>
	 */
	public function getRoutes(): array
	{
		return [
			'v1/products' => 'list',
			'v1/products/by-code/{code}' => 'detailByCode',
			'v1/products/{id}' => 'detail',
			'v1/products/{id}/reviews' => 'reviews',
		];
	}

	/**
	 * @param array<string, string> $params
	 */
	public function list(array $params, Query $query): Response
	{
		unset($params);

		$suffix = $this->connection->getMutationSuffix();
		$collection = $this->repository(Product::class)->many();

		if ($query->bool('active', true)) {
			$collection->where('this.deletedTs IS NULL')->where("this.draft$suffix", false);
		}

		if ($code = $query->string('code')) {
			ProductCode::filter($collection, [$code], $this->connection);
		}

		if ($ean = $query->string('ean')) {
			$collection->where('this.ean', $ean);
		}

		if ($category = $query->string('category')) {
			$this->filterByCategory($collection, $category, $suffix);
		}

		if ($since = $query->dateTime('since')) {
			$collection->where('this.createdTs >= :apiSince', ['apiSince' => $since]);
		}

		$this->filterByAttributes($collection, $query, $suffix);

		$this->applyFulltext($collection, $query, ["this.name$suffix", 'this.code', 'this.ean', 'this.mpn']);

		$page = $this->paginate($collection->orderBy(['this.createdTs' => 'DESC', 'this.uuid' => 'DESC']), $query);
		$extras = $this->loadExtras($page['rows']);

		$items = [];

		foreach ($page['rows'] as $id => $product) {
			$items[] = $this->mapper->map($product, $extras[$id]);
		}

		return Response::list($items, $page['nextCursor']);
	}

	/**
	 * @param array<string, string> $params
	 */
	public function detail(array $params, Query $query): Response
	{
		unset($query);

		/** @var \Eshop\DB\Product $product */
		$product = $this->one(Product::class, $params['id'], 'Produkt');
		$extras = $this->loadExtras([$product->getPK() => $product]);

		return new Response($this->mapper->map($product, $extras[$product->getPK()]));
	}

	/**
	 * Detail podle kódu, ne podle uuid — člověk diktuje kód, ne identifikátor.
	 *
	 * @param array<string, string> $params
	 */
	public function detailByCode(array $params, Query $query): Response
	{
		$collection = $this->repository(Product::class)->many()->where('this.deletedTs IS NULL');
		ProductCode::filter($collection, [$params['code']], $this->connection);
		$id = $collection->firstValue('this.uuid');

		// firstValue() vrací při prázdném výsledku false, ne null — proto negace, ne === null
		if (!$id) {
			throw ApiException::notFound('Produkt s kódem ' . $params['code'] . ' neexistuje.');
		}

		return $this->detail(['id' => (string) $id], $query);
	}

	/**
	 * Skladová dostupnost. Používá ji i /v1/stock.
	 *
	 * Vrací i rozpad po skladech, protože samotný součet odpovídá na „máme to skladem?"
	 * zavádějícím způsobem: shop má vedle vlastního skladu i sklady dodavatelů, které jsou
	 * o řády větší. Obchodník se ptá na ten vlastní.
	 *
	 * @param array<string> $productIds
	 * @return array<string, array<string, mixed>>
	 */
	public function loadStock(array $productIds): array
	{
		if (!$productIds) {
			return [];
		}

		$suffix = $this->connection->getMutationSuffix();

		$rows = $this->connection->rows(['a' => 'eshop_amount'], [
			'id' => 'a.fk_product',
			'storeCode' => 's.code',
			'storeName' => "s.name$suffix",
			'available' => 'SUM(a.inStock)',
			'reserved' => 'SUM(a.reserved)',
			'onOrder' => 'SUM(a.ordered)',
		])
			->join(['s' => 'eshop_store'], 's.uuid = a.fk_store')
			->where('a.fk_product', $productIds)
			->setGroupBy(['a.fk_product', 'a.fk_store']);

		$map = [];

		foreach ($rows as $row) {
			$id = $row->id;

			$map[$id] ??= [
				'available' => 0,
				'reserved' => 0,
				'onOrder' => 0,
				'unit' => null,
				'updatedAt' => null,
				'byStore' => [],
			];

			$map[$id]['available'] += (int) $row->available;
			$map[$id]['reserved'] += (int) $row->reserved;
			$map[$id]['onOrder'] += (int) $row->onOrder;
			$map[$id]['byStore'][] = [
				'code' => $row->storeCode,
				'name' => $row->storeName,
				'available' => (int) $row->available,
				'reserved' => (int) $row->reserved,
				'onOrder' => (int) $row->onOrder,
			];
		}

		return $map;
	}

	/**
	 * @param \StORM\Collection<\Eshop\DB\Product> $collection
	 */
	private function filterByCategory(Collection $collection, string $category, string $suffix): void
	{
		$paths = $this->connection->rows(['c' => 'eshop_category'], ['path' => 'c.path'])
			->where("c.uuid = :apiCat OR c.code = :apiCat OR c.name$suffix = :apiCat", ['apiCat' => $category]);

		$conditions = [];
		$values = [];
		$index = 0;

		foreach ($paths as $row) {
			$key = 'apiPath' . $index++;
			$conditions[] = "cat.path LIKE :$key";
			$values[$key] = $row->path . '%';
		}

		if (!$conditions) {
			$collection->where('1=0');

			return;
		}

		$collection->where(
			'this.uuid IN (SELECT nxn.fk_product FROM eshop_product_nxn_eshop_category nxn
				JOIN eshop_category cat ON cat.uuid = nxn.fk_category WHERE ' . \implode(' OR ', $conditions) . ')',
			$values,
		);
	}

	/**
	 * @param array<string, \Eshop\DB\Product> $products
	 * @return array<string, array<string, mixed>>
	 */
	private function loadExtras(array $products): array
	{
		if (!$products) {
			return [];
		}

		$ids = \array_keys($products);
		$suffix = $this->connection->getMutationSuffix();

		$prices = $this->loadPrices($ids);
		$stock = $this->loadStock($ids);
		$categories = $this->loadCategories($ids, $suffix);
		$producers = $this->loadProducers($products, $suffix);
		$hidden = $this->loadHidden($ids);
		$supplierCodes = $this->loadSupplierCodes($ids);
		$urls = $this->loadUrls($ids, $suffix);
		$attributes = $this->loadAttributes($ids, $suffix);

		$extras = [];

		foreach ($products as $id => $product) {
			$price = $prices[$id] ?? null;

			$extras[$id] = [
				'price' => $price !== null ? (float) $price->price : null,
				'priceVat' => $price !== null && $price->priceVat !== null ? (float) $price->priceVat : null,
				'pricelist' => $price !== null ? $this->codebooks->getPricelistName($price->pricelist) : null,
				'currency' => $price !== null ? $this->codebooks->getCurrencyCode($price->currency) : $this->config->getCurrency(),
				'stock' => $stock[$id] ?? null,
				'categories' => $categories[$id] ?? [],
				'producer' => $producers[(string) self::idValue($product, 'producer')] ?? null,
				'hidden' => $hidden[$id] ?? null,
				'supplierCodes' => $supplierCodes[$id] ?? [],
				'url' => $urls[$id] ?? null,
				'attributes' => $attributes[$id] ?? [],
			];
		}

		return $extras;
	}

	/**
	 * Nejnižší cena z veřejných ceníků. Kdyby jich shop měl víc, vyhrává ta nižší —
	 * stejně jako v katalogu.
	 *
	 * @param array<string> $ids
	 * @return array<string, object>
	 */
	private function loadPrices(array $ids): array
	{
		$pricelists = $this->codebooks->getDefaultPricelists();

		if (!$pricelists) {
			return [];
		}

		$rows = $this->connection->rows(['p' => 'eshop_price'], [
			'id' => 'p.fk_product',
			'price' => 'p.price',
			'priceVat' => 'p.priceVat',
			'pricelist' => 'p.fk_pricelist',
			'currency' => 'pl.fk_currency',
		])
			->join(['pl' => 'eshop_pricelist'], 'pl.uuid = p.fk_pricelist', [], 'INNER')
			->where('p.fk_product', $ids)
			->where('p.fk_pricelist', $pricelists)
			->where('p.hidden', false)
			->orderBy(['p.price' => 'ASC']);

		$map = [];

		foreach ($rows as $row) {
			$map[$row->id] ??= $row;
		}

		return $map;
	}

	/**
	 * @param array<string> $ids
	 * @return array<string, array<string>>
	 */
	private function loadCategories(array $ids, string $suffix): array
	{
		$rows = $this->connection->rows(['nxn' => 'eshop_product_nxn_eshop_category'], [
			'id' => 'nxn.fk_product',
			'name' => "IFNULL(c.fullName$suffix, c.name$suffix)",
		])
			->join(['c' => 'eshop_category'], 'c.uuid = nxn.fk_category', [], 'INNER')
			->where('nxn.fk_product', $ids);

		$map = [];

		foreach ($rows as $row) {
			if ($row->name === null) {
				continue;
			}

			$map[$row->id][] = $row->name;
		}

		return $map;
	}

	/**
	 * @param array<string, \Eshop\DB\Product> $products
	 * @return array<string, string>
	 */
	private function loadProducers(array $products, string $suffix): array
	{
		$ids = $this->collectIds($products, 'producer');

		if (!$ids) {
			return [];
		}

		$rows = $this->connection->rows(['p' => 'eshop_producer'], ['id' => 'p.uuid', 'name' => "p.name$suffix"])
			->where('p.uuid', $ids);

		$map = [];

		foreach ($rows as $row) {
			$map[$row->id] = $row->name;
		}

		return $map;
	}

	/**
	 * Produkt je skrytý, když je skrytý ve všech viditelnostech, které shop používá.
	 *
	 * @param array<string> $ids
	 * @return array<string, bool>
	 */
	private function loadHidden(array $ids): array
	{
		try {
			$rows = $this->connection->rows(['v' => 'eshop_visibilitylistitem'], [
				'id' => 'v.fk_product',
				'hidden' => 'MIN(v.hidden)',
			])
				->where('v.fk_product', $ids)
				->setGroupBy(['v.fk_product']);

			$map = [];

			foreach ($rows as $row) {
				$map[$row->id] = (bool) $row->hidden;
			}

			return $map;
		} catch (\Throwable) {
			// starší verze eshopu viditelnost přes seznamy nemá
			return [];
		}
	}

	/**
	 * @param array<string> $ids
	 * @return array<string, array<string>>
	 */
	private function loadSupplierCodes(array $ids): array
	{
		$rows = $this->connection->rows(['sp' => 'eshop_supplierproduct'], ['id' => 'sp.fk_product', 'code' => 'sp.code'])
			->where('sp.fk_product', $ids)
			->where('sp.code IS NOT NULL');

		$map = [];

		foreach ($rows as $row) {
			$map[$row->id][$row->code] = $row->code;
		}

		return \array_map('\array_values', $map);
	}

	/**
	 * Veřejná adresa produktu. Bere se ze stránek (balík liquiddesign/web); když je shop
	 * nemá, zůstane null — API kvůli tomu nespadne.
	 *
	 * @param array<string> $ids
	 * @return array<string, string>
	 */
	private function loadUrls(array $ids, string $suffix): array
	{
		try {
			$params = [];

			foreach ($ids as $id) {
				$params[] = "product=$id&";
			}

			$rows = $this->connection->rows(['p' => 'web_page'], ['params' => 'p.params', 'url' => "p.url$suffix"])
				->where('p.type', 'product_detail')
				->where('p.params', $params);

			$base = $this->config->getShopUrl();
			$map = [];

			foreach ($rows as $row) {
				$id = \substr((string) $row->params, \strlen('product='), -1);
				$map[$id] = ($base !== null ? \rtrim($base, '/') : '') . '/' . \ltrim((string) $row->url, '/');
			}

			return $map;
		} catch (\Throwable) {
			return [];
		}
	}

	/**
	 * Hodnocení produktu od zákazníků.
	 *
	 * @param array<string, string> $params
	 */
	public function reviews(array $params, Query $query): Response
	{
		/** @var \Eshop\DB\Product $product */
		$product = $this->one(Product::class, $params['id'], 'Produkt');

		$collection = $this->connection->rows(['r' => 'eshop_review'], [
			'id' => 'r.uuid',
			'score' => 'r.score',
			'text' => 'r.text',
			'author' => 'r.customerFullName',
			'customerId' => 'r.fk_customer',
			'createdTs' => 'r.createdTs',
			'reviewedTs' => 'r.reviewedTs',
			'recommends' => 'r.recommends',
		])
			->where('r.fk_product', $product->getPK())
			->where('r.score IS NOT NULL')
			->orderBy(['r.createdTs' => 'DESC'])
			->setTake($query->limit());

		$items = [];

		foreach ($collection as $row) {
			$items[] = [
				'id' => $row->id,
				'score' => $row->score !== null ? (float) $row->score : null,
				'text' => $row->text ?: null,
				'author' => $row->author ?: null,
				'customerId' => $row->customerId,
				'recommends' => $row->recommends === null ? null : (bool) $row->recommends,
				'createdAt' => Dates::dateTime($row->createdTs, $this->config->getTimezone()),
			];
		}

		return Response::list($items, null);
	}

	/**
	 * Filtr podle parametrů produktu: `attribute=objem:300ml` (víc dvojic přes čárku, platí AND).
	 * Hledá se podle kódu i podle názvu, protože člověk diktuje název.
	 *
	 * @param \StORM\Collection<\Eshop\DB\Product> $collection
	 */
	private function filterByAttributes(Collection $collection, Query $query, string $suffix): void
	{
		foreach ($query->strings('attribute') as $index => $pair) {
			if (!\str_contains($pair, ':')) {
				throw ApiException::badRequest("Parametr attribute musí být ve tvaru název:hodnota, dostal jsem \"$pair\".");
			}

			[$attribute, $value] = \explode(':', $pair, 2);
			$attributeKey = 'apiAttr' . $index;
			$valueKey = 'apiVal' . $index;

			$collection->where(
				"this.uuid IN (
					SELECT aa.fk_product FROM eshop_attributeassign aa
					JOIN eshop_attributevalue av ON av.uuid = aa.fk_value
					JOIN eshop_attribute a ON a.uuid = av.fk_attribute
					WHERE (a.code = :$attributeKey OR a.name$suffix = :$attributeKey)
						AND (av.code = :$valueKey OR av.label$suffix = :$valueKey)
				)",
				[$attributeKey => \trim($attribute), $valueKey => \trim($value)],
			);
		}
	}

	/**
	 * Parametry produktů — jeden dotaz za celou stránku.
	 *
	 * @param array<string> $ids
	 * @return array<string, array<array<string, mixed>>>
	 */
	private function loadAttributes(array $ids, string $suffix): array
	{
		$rows = $this->connection->rows(['aa' => 'eshop_attributeassign'], [
			'id' => 'aa.fk_product',
			'attribute' => "a.name$suffix",
			'attributeCode' => 'a.code',
			'value' => "av.label$suffix",
			'valueCode' => 'av.code',
		])
			->join(['av' => 'eshop_attributevalue'], 'av.uuid = aa.fk_value', [], 'INNER')
			->join(['a' => 'eshop_attribute'], 'a.uuid = av.fk_attribute', [], 'INNER')
			->where('aa.fk_product', $ids)
			->orderBy(['a.priority' => 'ASC', 'av.priority' => 'ASC']);

		$map = [];

		foreach ($rows as $row) {
			$key = $row->attributeCode ?: $row->attribute;

			if ($key === null) {
				continue;
			}

			$map[$row->id][$key]['name'] ??= $row->attribute;
			$map[$row->id][$key]['code'] ??= $row->attributeCode;
			$map[$row->id][$key]['values'][] = $row->value;
		}

		return \array_map('\array_values', $map);
	}
}
