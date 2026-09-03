<?php

declare(strict_types=1);

namespace DoryoApi\Support;

/**
 * Drobné pomůcky pro skládání dotazů. Fulltext `q` jede přes LIKE nad sloupci s kolací
 * *_general_ci / *_unicode_ci, která je sama case- i diakritice-necitlivá — proto se
 * hodnota nijak nenormalizuje a nesahá se na COLLATE (to by se rozbilo na shopu
 * s jinou znakovou sadou).
 */
final class Sql
{
	/**
	 * Podmínka „některý ze sloupců obsahuje některý z termínů".
	 * @param array<string> $columns
	 * @param array<string> $terms
	 * @return array{0: string, 1: array<string, string>}|null výraz a hodnoty pro bind
	 */
	public static function likeAny(array $columns, array $terms): ?array
	{
		if (!$columns || !$terms) {
			return null;
		}

		$conditions = [];
		$values = [];

		foreach ($terms as $termIndex => $term) {
			$key = 'q' . $termIndex;
			$values[$key] = '%' . \str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

			foreach ($columns as $column) {
				$conditions[] = "$column LIKE :$key";
			}
		}

		return ['(' . \implode(' OR ', $conditions) . ')', $values];
	}

	/**
	 * Seznam hodnot pro IN(...) v surovém výrazu.
	 *
	 * StORM umí navázat pole jen ve tvaru `where('sloupec', $pole)`; jakmile je potřeba pole
	 * ve složitějším výrazu (třeba `kód NEBO kód.podkód`), musí se hodnoty ocitovat rovnou.
	 * Citování dělá PDO, ne konkatenace.
	 * @param array<string> $values
	 */
	public static function inList(\StORM\Connection $connection, array $values): string
	{
		if (!$values) {
			return "''";
		}

		return \implode(',', \array_map(static fn (string $value): string => $connection->quote($value), $values));
	}
}
