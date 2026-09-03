<?php

declare(strict_types=1);

namespace DoryoApi\Support;

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
 */
final class ProductCode
{
	private const SUPPLIER_ALIAS = 'apiSupplier';

	/**
	 * Podmínka „tenhle produkt má tenhle kód".
	 * @param \StORM\Collection<\Eshop\DB\Product> $collection
	 * @param array<string> $codes
	 */
	public static function filter(Collection $collection, array $codes, Connection $connection): void
	{
		if (!$codes) {
			return;
		}

		self::joinSupplier($collection);

		$in = Sql::inList($connection, $codes);
		$display = self::displayExpression();

		$collection->where(
			"this.code IN ($in)
			OR CONCAT(this.code, '.', this.subCode) IN ($in)
			OR this.supplierCode IN ($in)
			OR $display IN ($in)",
		);
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
