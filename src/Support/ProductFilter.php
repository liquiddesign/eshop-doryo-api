<?php

declare(strict_types=1);

namespace DoryoApi\Support;

use DoryoApi\Codebooks;
use StORM\DIConnection;

/**
 * Zúžení na produkty jedné kategorie (i s podkategoriemi) nebo jednoho výrobce — jedna podmínka
 * pro seznam produktů i pro reporty nad položkami objednávek. Kategorie se hledá podle id, kódu
 * nebo názvu a bere se celý podstrom (`path` prefixem), výrobce podle id nebo názvu.
 */
final class ProductFilter
{
	/**
	 * @return array{sql: string, values: array<string, string>, nazev: string}|null null = taková kategorie není
	 */
	public static function category(DIConnection $connection, string $category, string $suffix, string $productExpr): ?array
	{
		$rows = $connection->rows(['fc' => 'eshop_category'], ['path' => 'fc.path', 'name' => "fc.name$suffix"])
			->where("fc.uuid = :apiCat OR fc.code = :apiCat OR fc.name$suffix = :apiCat", ['apiCat' => $category]);

		$conditions = [];
		$values = [];
		$names = [];
		$index = 0;

		foreach ($rows as $row) {
			$key = 'apiPath' . $index++;
			$conditions[] = "fcat.path LIKE :$key";
			$values[$key] = $row->path . '%';
			$names[] = (string) $row->name;
		}

		if (!$conditions) {
			return null;
		}

		return [
			'sql' => "$productExpr IN (SELECT fnxn.fk_product FROM eshop_product_nxn_eshop_category fnxn"
				. ' JOIN eshop_category fcat ON fcat.uuid = fnxn.fk_category WHERE ' . \implode(' OR ', $conditions) . ')',
			'values' => $values,
			'nazev' => \implode(', ', \array_unique($names)),
		];
	}

	/**
	 * Jeden produkt podle id nebo kódu (i s podkódem, s vodicí nulou i bez — {@see ProductCode}).
	 * Holý kód bez podkódu zahrne všechny jeho podkódy, to je u „vývoj prodeje produktu" žádoucí.
	 * @return array{sql: string, values: array<string, string>, nazev: string}|null null = takový produkt není
	 */
	public static function product(DIConnection $connection, ?Codebooks $codebooks, string $product, string $suffix, string $productExpr): ?array
	{
		$in = Sql::inList($connection, ProductCode::variants([$product]));
		$byCode = \implode(' OR ', \array_map(static fn (string $e): string => "$e IN ($in)", ProductCode::codeExpressions($codebooks, 'fpp')));
		$condition = "(fpp.uuid = :apiProduct OR $byCode)";

		$rows = $connection->rows(['fpp' => 'eshop_product'], ['id' => 'fpp.uuid', 'code' => 'fpp.code', 'name' => "fpp.name$suffix"])
			->where($condition, ['apiProduct' => $product])
			->setTake(3);
		$found = [];

		foreach ($rows as $row) {
			$found[] = (string) $row->code . ' ' . (string) $row->name;
		}

		if (!$found) {
			return null;
		}

		return [
			'sql' => "$productExpr IN (SELECT fpp.uuid FROM eshop_product fpp WHERE $condition)",
			'values' => ['apiProduct' => $product],
			'nazev' => \implode('; ', $found),
		];
	}

	/**
	 * @return array{sql: string, values: array<string, string>, nazev: string}|null null = takový výrobce není
	 */
	public static function producer(DIConnection $connection, string $producer, string $suffix, string $productExpr): ?array
	{
		$row = $connection->rows(['fpr' => 'eshop_producer'], ['id' => 'fpr.uuid', 'name' => "fpr.name$suffix"])
			->where("fpr.uuid = :apiProd OR fpr.name$suffix = :apiProd", ['apiProd' => $producer])
			->first();

		if ($row === null) {
			return null;
		}

		return [
			'sql' => "$productExpr IN (SELECT fpp.uuid FROM eshop_product fpp WHERE fpp.fk_producer = :apiProducer)",
			'values' => ['apiProducer' => (string) $row->id],
			'nazev' => (string) $row->name,
		];
	}
}
