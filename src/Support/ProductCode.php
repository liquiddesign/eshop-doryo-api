<?php

declare(strict_types=1);

namespace DoryoApi\Support;

use DoryoApi\Codebooks;
use StORM\Collection;
use StORM\Connection;

/**
 * Kód produktu tak, jak ho vidí člověk.
 *
 * Eshop u produktů převzatých od dodavatele **odřezává prefix** (Product::getFullCode):
 * v databázi je `db74079RL`, v katalogu i v odpovědi API je `74079RL`. Kdyby se filtry ptaly
 * jen na sloupec `code`, model by dostal kód, se kterým by se pak nedovolal zpátky — ptá se
 * tím, co mu API samo vrátilo.
 *
 * Proto se filtruje přes výraz, který tu logiku opakuje v SQL, a vedle toho i přes syrový kód,
 * kód s podkódem a kód dodavatele — člověk diktuje, co má na papíře.
 *
 * **Podkód se píše s vodicí nulou.** Shopy tahající zboží z K2 mají kód rozdělený (`37214` +
 * podkód `1`), ale člověk i doklad nesou `37214.01` — prosté `CONCAT(code, '.', subCode)`
 * dá `37214.1` a takový dotaz nenajde nic. Porovnává se proto obojí, doplněné na dvě místa
 * (`LPAD`) i syrové, a zadaný kód se roztáhne na obě podoby — vodicí nula může chybět na
 * kterékoli straně. Když shop vede celý kód ve vlastním sloupci `fullCode` (Levior), bere
 * se i ten.
 */
final class ProductCode
{
	private const SUPPLIER_ALIAS = 'apiSupplier';

	/**
	 * Podmínka „tenhle produkt má tenhle kód".
	 * @param \StORM\Collection<\Eshop\DB\Product> $collection
	 * @param array<string> $codes
	 */
	public static function filter(Collection $collection, array $codes, Connection $connection, ?Codebooks $codebooks = null): void
	{
		if (!$codes) {
			return;
		}

		self::joinSupplier($collection);

		$in = Sql::inList($connection, self::variants($codes));

		$expressions = self::codeExpressions($codebooks);
		$expressions[] = 'this.supplierCode';
		$expressions[] = self::displayExpression();

		$conditions = \array_map(static fn (string $expression): string => "$expression IN ($in)", $expressions);

		$collection->where('(' . \implode(' OR ', $conditions) . ')');
	}

	/**
	 * Výrazy, ve kterých se dá kód hledat — pro přesnou shodu i pro fulltext `q`.
	 *
	 * Bez dodavatelské části, takže se kvůli nim nemusí joinovat `eshop_supplier`.
	 * @return array<string>
	 */
	public static function codeExpressions(?Codebooks $codebooks = null, string $alias = 'this'): array
	{
		$sub = "NULLIF($alias.subCode, '')";

		$expressions = [
			"$alias.code",
			"CONCAT($alias.code, '.', $sub)",
			"CONCAT($alias.code, '.', LPAD($sub, 2, '0'))",
		];

		if ($codebooks !== null && $codebooks->hasColumn('eshop_product', 'fullCode')) {
			$expressions[] = "$alias.fullCode";
		}

		return $expressions;
	}

	/**
	 * Podoby zadaného kódu, které se ještě mají zkusit: podkód s vodicí nulou i bez ní.
	 * @param array<string> $codes
	 * @return array<string>
	 */
	public static function variants(array $codes): array
	{
		$out = [];

		foreach ($codes as $code) {
			$out[$code] = $code;

			if (!\preg_match('~^(.+)\.(\d+)$~', $code, $match)) {
				continue;
			}

			$bare = \ltrim($match[2], '0') ?: '0';
			$padded = \str_pad($bare, 2, '0', \STR_PAD_LEFT);

			$out["$match[1].$bare"] = "$match[1].$bare";
			$out["$match[1].$padded"] = "$match[1].$padded";
		}

		return \array_values($out);
	}

	/**
	 * @param \StORM\Collection<\Eshop\DB\Product> $collection
	 */
	public static function joinSupplier(Collection $collection): void
	{
		if (\array_key_exists(self::SUPPLIER_ALIAS, $collection->getAliases())) {
			return;
		}

		$collection->join([self::SUPPLIER_ALIAS => 'eshop_supplier'], self::SUPPLIER_ALIAS . '.uuid = this.fk_supplierSource');
	}

	/**
	 * Kód, jak ho ukáže katalog: s podkódem a bez dodavatelského prefixu, když se prefix skrývá.
	 */
	public static function displayExpression(string $alias = 'this', string $supplier = self::SUPPLIER_ALIAS): string
	{
		$full = "IF($alias.subCode != '', CONCAT($alias.code, '.', $alias.subCode), $alias.code)";

		return "IF(
			$supplier.productCodePrefix IS NOT NULL AND $supplier.productCodePrefix != '' AND $supplier.showCodeWithPrefix = 0,
			SUBSTRING($full, LENGTH($supplier.productCodePrefix) + 1),
			$full
		)";
	}
}
