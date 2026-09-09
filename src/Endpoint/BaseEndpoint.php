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
	 * Podmínka „produkt není smazaný".
	 *
	 * `deletedTs` přibyl v eshopu 2.1; starší shopy produkty měkce nemažou, takže tam podmínka
	 * nedává smysl a hlavně by shodila dotaz. Vrací se `1=1`, ne null, aby volající místa
	 * zůstala jednoduchá — podmínka se dá vždycky vložit tam, kde stála dřív.
	 */
	protected function productNotDeleted(string $alias = 'this'): string
	{
		return $this->codebooks->hasColumn('eshop_product', 'deletedTs') ? "$alias.deletedTs IS NULL" : '1=1';
	}

	/**
	 * Podmínka „cena není skrytá". `hidden` je na cenách taky až od eshopu 2.1.
	 */
	protected function priceNotHidden(string $alias = 'p'): string
	{
		return $this->codebooks->hasColumn('eshop_price', 'hidden') ? "$alias.hidden = 0" : '1=1';
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
	 * Nákupy, které mají počet položek v zadaném rozsahu. `null` = o velikost nikdo nestojí.
	 *
	 * Schválně na dvakrát (nejdřív nákupy okna, pak jejich položky): spojit to do jednoho
	 * dotazu znamená, že si databáze jako výchozí tabulku vezme milionový `eshop_cartitem`
	 * a dotaz běží minuty místo vteřiny — stejný důvod, proč tak chodí reporty nad položkami.
	 * @param array<string> $purchaseIds nákupy, mezi kterými se hledá
	 * @return array<string>|null
	 */
	protected function purchasesByItemCount(array $purchaseIds, ?int $min, ?int $max): ?array
	{
		if ($min === null && $max === null) {
			return null;
		}

		if (!$purchaseIds) {
			return [];
		}

		$having = [];
		$binds = [];

		if ($min !== null) {
			$having[] = 'COUNT(ci.uuid) >= :apiMinItems';
			$binds['apiMinItems'] = $min;
		}

		if ($max !== null) {
			$having[] = 'COUNT(ci.uuid) <= :apiMaxItems';
			$binds['apiMaxItems'] = $max;
		}

		$rows = $this->connection->rows(['cart' => 'eshop_cart'], ['purchase' => 'cart.fk_purchase'])
			->join(['ci' => 'eshop_cartitem'], 'ci.fk_cart = cart.uuid', [], 'INNER')
			->where('cart.fk_purchase', $purchaseIds)
			->setGroupBy(['cart.fk_purchase'], \implode(' AND ', $having), $binds);

		$ids = [];

		foreach ($rows as $row) {
			$ids[] = (string) $row->purchase;
		}

		return $ids;
	}

	/**
	 * Kolik položek mají nákupy stránky. Jeden seskupený dotaz, ne dotaz na objednávku.
	 * @param array<string> $purchaseIds
	 * @return array<string, int>
	 */
	protected function itemCounts(array $purchaseIds): array
	{
		if (!$purchaseIds) {
			return [];
		}

		$rows = $this->connection->rows(['cart' => 'eshop_cart'], [
			'purchase' => 'cart.fk_purchase',
			'cnt' => 'COUNT(ci.uuid)',
		])
			->join(['ci' => 'eshop_cartitem'], 'ci.fk_cart = cart.uuid', [], 'INNER')
			->where('cart.fk_purchase', $purchaseIds)
			->setGroupBy(['cart.fk_purchase']);

		$map = [];

		foreach ($rows as $row) {
			$map[(string) $row->purchase] = (int) $row->cnt;
		}

		return $map;
	}

	/**
	 * Meze počtu položek z dotazu. Validuje se tady, ať je hláška všude stejná.
	 * @return array{0: int|null, 1: int|null}
	 */
	protected function itemCountRange(Query $query): array
	{
		$min = $query->int('minItems');
		$max = $query->int('maxItems');

		if ($min !== null && $max !== null && $min > $max) {
			throw ApiException::badRequest('Parametr minItems nesmí být větší než maxItems.');
		}

		return [$min, $max];
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
