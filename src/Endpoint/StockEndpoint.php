<?php

declare(strict_types=1);

namespace DoryoApi\Endpoint;

use DoryoApi\Codebooks;
use DoryoApi\Config;
use DoryoApi\Http\ApiException;
use DoryoApi\Http\Query;
use DoryoApi\Http\Response;
use DoryoApi\Support\ProductCode;
use Eshop\DB\Product;
use StORM\DIConnection;

/**
 * Levná odpověď na „máme to skladem" — jen kód, název a sklad, žádné ceny ani kategorie.
 */
final class StockEndpoint extends BaseEndpoint
{
	public function __construct(
		DIConnection $connection,
		Config $config,
		Codebooks $codebooks,
		private ProductsEndpoint $products,
	) {
		parent::__construct($connection, $config, $codebooks);
	}

	/**
	 * @return array<string, string>
	 */
	public function getRoutes(): array
	{
		return ['v1/stock' => 'list'];
	}

	/**
	 * @param array<string, string> $params
	 */
	public function list(array $params, Query $query): Response
	{
		unset($params);

		$suffix = $this->connection->getMutationSuffix();
		$collection = $this->repository(Product::class)->many()->where('this.deletedTs IS NULL');

		$code = $query->string('code');
		$ean = $query->string('ean');
		$id = $query->string('id');
		$terms = $query->strings('q');

		if ($code === null && $ean === null && $id === null && !$terms) {
			throw ApiException::badRequest('Zadej aspoň jeden z parametrů code, ean, id nebo q.');
		}

		if ($code !== null) {
			ProductCode::filter($collection, [$code], $this->connection);
		}

		if ($ean !== null) {
			$collection->where('this.ean', $ean);
		}

		if ($id !== null) {
			$collection->where('this.uuid', $id);
		}

		$this->applyFulltext($collection, $query, ["this.name$suffix", 'this.code', 'this.ean', 'this.mpn']);

		$page = $this->paginate($collection->orderBy(['this.code' => 'ASC']), $query);
		$stock = $this->products->loadStock(\array_keys($page['rows']));

		$items = [];

		foreach ($page['rows'] as $productId => $product) {
			$items[] = [
				'productId' => $productId,
				'code' => $product->getFullCode(),
				'ean' => $product->ean ?: null,
				'name' => $product->name,
				'unit' => $product->unit ?: null,
				'stock' => $stock[$productId] ?? [
					'available' => 0,
					'reserved' => 0,
					'onOrder' => 0,
					'unit' => null,
					'updatedAt' => null,
					'byStore' => [],
				],
			];
		}

		$response = Response::list($items, $page['nextCursor']);

		if ($items) {
			return $response;
		}

		// „Nemáme skladem" a „takový kód neznáme" vypadají obojí jako prázdný seznam, ale vedou
		// k jiné odpovědi. Produkt, který v katalogu je, se vrátí i s nulovou zásobou — když se
		// tedy ptali na konkrétní kód a nevrátilo se nic, ten kód prostě neexistuje. Řekněme to.
		$hledane = \array_filter(['code' => $code, 'ean' => $ean, 'id' => $id], static fn (?string $v): bool => $v !== null);

		if (!$hledane) {
			return $response;
		}

		$vypis = [];

		foreach ($hledane as $klic => $hodnota) {
			$vypis[] = "$klic=$hodnota";
		}

		return $response->withExtra(['note' => \sprintf(
			'Katalog nezná %s — tohle NENÍ „nemáme skladem". Produkt, který v katalogu je, se vrátí '
				. 'i s nulovou zásobou. Zkus /v1/search?q=… nebo jiný tvar kódu.',
			\implode(', ', $vypis),
		)]);
	}
}
