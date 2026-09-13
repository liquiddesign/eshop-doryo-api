<?php

declare(strict_types=1);

namespace DoryoApi\Support;

use DoryoApi\Codebooks;

/**
 * Vazba zákazník–obchodník je v eshopu dvojí: sloupec `eshop_customer.fk_merchant` a vazební
 * tabulka `eshop_merchant_nxn_eshop_customer`. Shop může používat jen jednu z nich — na Levioru
 * je sloupec prázdný u všech zákazníků a vazba jde čistě přes M:N. Filtry podle obchodníka se
 * proto ptají na obojí; shop bez vazební tabulky jede po sloupci.
 */
final class Merchants
{
	public const NXN_TABLE = 'eshop_merchant_nxn_eshop_customer';

	/**
	 * Podmínka „zákazník (výraz s jeho uuid) patří obchodníkovi :apiMerchant". Hodnotu naváže
	 * volající — proto pojmenovaný parametr, ne literál.
	 */
	public static function customerCondition(Codebooks $codebooks, string $customerExpr, string $param = 'apiMerchant'): string
	{
		$column = "$customerExpr IN (SELECT mc.uuid FROM eshop_customer mc WHERE mc.fk_merchant = :$param)";

		if (!$codebooks->hasColumn(self::NXN_TABLE, 'fk_merchant')) {
			return $column;
		}

		return "($column OR $customerExpr IN (SELECT mnxn.fk_customer FROM " . self::NXN_TABLE . " mnxn WHERE mnxn.fk_merchant = :$param))";
	}
}
