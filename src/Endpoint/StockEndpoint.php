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

		return Response::list($items, $page['nextCursor']);
	}
}
