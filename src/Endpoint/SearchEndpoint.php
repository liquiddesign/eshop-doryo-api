<?php

declare(strict_types=1);

namespace DoryoApi\Endpoint;

use DoryoApi\Http\ApiException;
use DoryoApi\Http\Query;
use DoryoApi\Http\Response;
use DoryoApi\Support\Sql;

/**
 * Jedno hledání napříč doménami.
 *
 * Člověk diktuje kód, číslo dokladu nebo jméno firmy — ne UUID. Bez tohohle by model musel
 * střílet do čtyř endpointů a hádat, do kterého to patří: „37214.01" je produkt,
 * „2026005126" objednávka, „Pro-Domu" zákazník. Vrací typ, id a natolik popisu, aby se dalo
 * rovnou pokračovat na detail.
 */
final class SearchEndpoint extends BaseEndpoint
{
	private const TYPES = ['products', 'customers', 'orders', 'invoices'];

	/**
	 * @return array<string, string>
	 */
	public function getRoutes(): array
	{
		return ['v1/search' => 'search'];
	}

	/**
	 * @param array<string, string> $params
	 */
	public function search(array $params, Query $query): Response
	{
		unset($params);

		$terms = $query->strings('q');

		if (!$terms) {
			throw ApiException::badRequest('Parametr q je povinný.');
		}

		$types = $query->strings('type') ?: self::TYPES;
		$unknown = \array_diff($types, self::TYPES);

		if ($unknown) {
			throw ApiException::badRequest('Neznámý typ ' . \implode(', ', $unknown) . '; povolené jsou ' . \implode(', ', self::TYPES) . '.');
		}

		$perType = \min($query->int('limit', 5) ?? 5, 25);
		$items = [];

		foreach ($types as $type) {
			foreach ($this->searchType($type, $terms, $perType) as $item) {
				$items[] = $item;
			}
		}

		return Response::list($items, null);
	}

	/**
	 * @param array<string> $terms
	 * @return array<array<string, mixed>>
	 */
	private function searchType(string $type, array $terms, int $limit): array
	{
		$suffix = $this->connection->getMutationSuffix();

		return match ($type) {
			'products' => $this->collect(
				'products',
				['this.code', 'this.ean', "this.name$suffix", 'this.mpn'],
				$terms,
				$limit,
				\Eshop\DB\Product::class,
				fn ($entity): array => [
					'label' => $entity->getFullCode() . ' — ' . $entity->name,
					'detail' => 'produkt',
				],
				static fn ($collection) => $collection->where('this.deletedTs IS NULL')->orderBy(['this.code' => 'ASC']),
			),
			'customers' => $this->collect(
				'customers',
				['this.fullname', 'this.company', 'this.email', 'this.ic'],
				$terms,
				$limit,
				\Eshop\DB\Customer::class,
				static fn ($entity): array => [
					'label' => $entity->company ?: $entity->fullname,
					'detail' => \implode(', ', \array_filter([$entity->ic ? "IČO $entity->ic" : null, $entity->email])),
				],
				static fn ($collection) => $collection->orderBy(['this.createdTs' => 'DESC']),
			),
			'orders' => $this->collect(
				'orders',
				['this.code', 'purchase.fullname', 'purchase.email'],
				$terms,
				$limit,
				\Eshop\DB\Order::class,
				static fn ($entity): array => [
					'label' => $entity->code,
					'detail' => 'objednávka z ' . \substr((string) $entity->createdTs, 0, 10),
				],
				static fn ($collection) => $collection
					->join(['purchase' => 'eshop_purchase'], 'purchase.uuid = this.fk_purchase', [], 'INNER')
					->orderBy(['this.createdTs' => 'DESC']),
			),
			'invoices' => $this->collect(
				'invoices',
				['this.code', 'this.variableSymbol', 'this.subject', 'this.ic'],
				$terms,
				$limit,
				\Eshop\DB\Invoice::class,
				static fn ($entity): array => [
					'label' => (string) $entity->code,
					'detail' => 'faktura z ' . (string) $entity->exposed,
				],
				static fn ($collection) => $collection->orderBy(['this.exposed' => 'DESC']),
			),
			default => [],
		};
	}

	/**
	 * @param array<string> $columns
	 * @param array<string> $terms
	 * @param class-string<\StORM\Entity> $entityClass
	 * @param callable(\StORM\Entity): array<string, string|null> $describe
	 * @param callable(\StORM\Collection<\StORM\Entity>): \StORM\Collection<\StORM\Entity> $prepare
	 * @return array<array<string, mixed>>
	 */
	private function collect(string $type, array $columns, array $terms, int $limit, string $entityClass, callable $describe, callable $prepare): array
	{
		$condition = Sql::likeAny($columns, $terms);

		if ($condition === null) {
			return [];
		}

		$collection = $prepare($this->repository($entityClass)->many())
			->where($condition[0], $condition[1])
			->setTake($limit);

		$items = [];

		foreach ($collection as $id => $entity) {
			$described = $describe($entity);

			$items[] = [
				'type' => $type,
				'id' => $id,
				'label' => $described['label'],
				'detail' => $described['detail'] ?: null,
			];
		}

		return $items;
	}
}
