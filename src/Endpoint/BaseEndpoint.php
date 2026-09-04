<?php

declare(strict_types=1);

namespace DoryoApi\Endpoint;

use DoryoApi\Codebooks;
use DoryoApi\Config;
use DoryoApi\Http\ApiException;
use DoryoApi\Http\Cursor;
use DoryoApi\Http\Query;
use DoryoApi\Http\Response;
use DoryoApi\Support\Sql;
use StORM\Collection;
use StORM\DIConnection;
use StORM\Entity;
use StORM\Repository;

/**
 * Základ endpointů: stránkování, dávkové dotažení relací a přístup k repozitářům.
 *
 * Relace se zásadně načítají dávkově za celou stránku (jeden dotaz na sto záznamů),
 * ne přes entitu záznam po záznamu — jinak by seznam dvou set objednávek udělal
 * tisíce dotazů.
 */
abstract class BaseEndpoint implements Endpoint
{
	public function __construct(
		protected DIConnection $connection,
		protected Config $config,
		protected Codebooks $codebooks,
	) {
	}

	/**
	 * @template T of \StORM\Entity
	 * @param class-string<T> $entityClass
	 * @return \StORM\Repository<T>
	 */
	protected function repository(string $entityClass): Repository
	{
		return $this->connection->findRepository($entityClass);
	}

	/**
	 * Stránka výsledků. Bere se o jeden záznam víc, než klient chtěl — z toho se pozná,
	 * jestli má smysl posílat další kurzor, a nemusí se počítat COUNT přes celou tabulku.
	 * @template T of \StORM\Entity
	 * @param \StORM\Collection<T> $collection
	 * @return array{rows: array<string, T>, nextCursor: string|null}
	 */
	protected function paginate(Collection $collection, Query $query): array
	{
		$limit = $query->limit();
		$offset = $query->offset();

		$rows = $collection->setTake($limit + 1)->setSkip($offset)->toArray();
		$hasMore = \count($rows) > $limit;

		return [
			'rows' => $hasMore ? \array_slice($rows, 0, $limit, true) : $rows,
			'nextCursor' => $hasMore ? Cursor::encode($offset + $limit) : null,
		];
	}

	/**
	 * @template T of \StORM\Entity
	 * @param \StORM\Collection<T> $collection
	 * @param array<string> $columns
	 */
	protected function applyFulltext(Collection $collection, Query $query, array $columns): void
	{
		$terms = $query->strings('q');

		if (!$terms) {
			return;
		}

		$condition = Sql::likeAny($columns, $terms);

		if ($condition === null) {
			return;
		}

		$collection->where($condition[0], $condition[1]);
	}

	/**
	 * Má ta tabulka vůbec nějaký použitelný řádek? Levné `LIMIT 1`, ne COUNT.
	 *
	 * Slouží k rozlišení „tenhle záznam nic nemá" od „tuhle doménu shop nevede" — což je
	 * z prázdného seznamu jinak nepoznat. Chybějící tabulka (starší verze eshopu) není
	 * chyba, jen `false`.
	 */
	protected function hasAnyRow(string $table, ?string $where = null): bool
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

	/**
	 * Prázdno u recenzí znamená u jednoho shopu „nikdo to nehodnotil" a u druhého „hodnocení
	 * se tu vůbec nevedou". Druhý případ se dá poznat jen jedním levným dotazem, tak ať se
	 * to řekne rovnou — ať to volající nevydává za nulové hodnocení.
	 */
	protected function withReviewsNote(Response $response): Response
	{
		$body = $response->getBody();

		if ($body['items'] !== [] || $this->hasAnyRow('eshop_review', 'score IS NOT NULL')) {
			return $response;
		}

		return $response->withExtra([
			'note' => 'Tenhle shop hodnocení nevede — v celé databázi není ani jedno vyplněné skóre '
				. '(řádek vzniká i jen odesláním žádosti o hodnocení). Prázdný výsledek proto neznamená, '
				. 'že produkt hodnocení nemá; viz /v1/meta/capabilities.',
		]);
	}

	/**
	 * @template T of \StORM\Entity
	 * @param class-string<T> $entityClass
	 * @return T
	 * @throws \DoryoApi\Http\ApiException
	 */
	protected function one(string $entityClass, string $id, string $label): Entity
	{
		$entity = $this->repository($entityClass)->one($id);

		if ($entity === null) {
			throw ApiException::notFound("$label $id neexistuje.");
		}

		return $entity;
	}

	/**
	 * Dávkové dotažení entit podle cizích klíčů ze stránky.
	 * @template T of \StORM\Entity
	 * @param class-string<T> $entityClass
	 * @param array<string|null> $ids
	 * @return array<string, T>
	 */
	protected function fetchByIds(string $entityClass, array $ids): array
	{
		$ids = \array_values(\array_unique(\array_filter($ids, static fn ($id): bool => \is_string($id) && $id !== '')));

		if (!$ids) {
			return [];
		}

		return $this->repository($entityClass)->many()->where('this.uuid', $ids)->toArray();
	}

	/**
	 * Cizí klíče relace přes celou stránku.
	 * @param array<\StORM\Entity> $entities
	 * @return array<string>
	 */
	protected function collectIds(array $entities, string $relation): array
	{
		$ids = [];

		foreach ($entities as $entity) {
			$value = self::idValue($entity, $relation);

			if ($value === null) {
				continue;
			}

			$ids[$value] = $value;
		}

		return \array_values($ids);
	}

	/**
	 * Cizí klíč relace, která v téhle verzi eshopu být nemusí — pak je null, ne chyba.
	 */
	protected static function idValue(Entity $entity, string $relation): ?string
	{
		try {
			$value = $entity->getValue($relation);
		} catch (\Throwable) {
			return null;
		}

		return \is_string($value) && $value !== '' ? $value : null;
	}
}
