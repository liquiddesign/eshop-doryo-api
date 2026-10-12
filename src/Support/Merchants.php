<?php

declare(strict_types=1);

namespace DoryoApi\Support;

use DoryoApi\Codebooks;
use StORM\DIConnection;

/**
 * Komu patří záznam, když se ptá obchodník (`?merchantId=`).
 *
 * Vazba zákazník–obchodník je v eshopu dvojí: sloupec `eshop_customer.fk_merchant` a vazební
 * tabulka `eshop_merchant_nxn_eshop_customer`. Shop může používat jen jednu z nich — na Levioru
 * je sloupec prázdný u všech zákazníků a vazba jde čistě přes M:N, Forbaby (eshop 2.2) sloupec
 * vůbec nemá. Filtry podle obchodníka se proto ptají na to, co shop skutečně vede.
 *
 * Rozsah dál upravuje `merchantScope` v configu: podřízení obchodníci (`eshop_merchant_nxn_subordinate`),
 * skupiny zákazníků obchodníka a jestli objednávky obchodníka jsou ty, které zadal, nebo objednávky
 * jeho zákazníků. Forbaby tak v API vidí totéž, co obchodník v adminu (`MerchantAdminContext`).
 *
 * Všechny podmínky berou obchodníka z pojmenovaného parametru; StORM zvládne tentýž parametr
 * v dotazu vícekrát.
 */
final class Merchants
{
	public const NXN_TABLE = 'eshop_merchant_nxn_eshop_customer';

	public const CUSTOMER_TABLE = 'eshop_customer';

	public const SUBORDINATE_TABLE = 'eshop_merchant_nxn_subordinate';

	public const GROUP_TABLE = 'eshop_merchant_nxn_eshop_customergroup';

	/**
	 * Množina obchodníků, za které :param mluví — on sám, podle configu i jeho přímí podřízení.
	 * Výraz je na pravou stranu `IN`.
	 */
	public static function merchantSet(Codebooks $codebooks, string $param = 'apiMerchant'): string
	{
		if ($codebooks->getConfig()->merchantIncludesSubordinates() && $codebooks->hasColumn(self::SUBORDINATE_TABLE, 'fk_subordinate')) {
			return "(SELECT :$param UNION SELECT msub.fk_subordinate FROM " . self::SUBORDINATE_TABLE . " msub WHERE msub.fk_merchant = :$param)";
		}

		return "(:$param)";
	}

	/**
	 * Podmínka „zákazník (výraz s jeho uuid) patří obchodníkovi :apiMerchant". Hodnotu naváže
	 * volající — proto pojmenovaný parametr, ne literál.
	 */
	public static function customerCondition(Codebooks $codebooks, string $customerExpr, string $param = 'apiMerchant'): string
	{
		$set = self::merchantSet($codebooks, $param);
		$parts = [];

		if ($codebooks->hasColumn(self::CUSTOMER_TABLE, 'fk_merchant')) {
			$parts[] = "$customerExpr IN (SELECT mc.uuid FROM eshop_customer mc WHERE mc.fk_merchant IN $set)";
		}

		if ($codebooks->hasColumn(self::NXN_TABLE, 'fk_merchant')) {
			$parts[] = "$customerExpr IN (SELECT mnxn.fk_customer FROM " . self::NXN_TABLE . " mnxn WHERE mnxn.fk_merchant IN $set)";
		}

		if ($codebooks->getConfig()->merchantIncludesCustomerGroups()
			&& $codebooks->hasColumn(self::GROUP_TABLE, 'fk_customergroup')
			&& $codebooks->hasColumn(self::CUSTOMER_TABLE, 'fk_group')
		) {
			$parts[] = "$customerExpr IN (SELECT mgc.uuid FROM eshop_customer mgc INNER JOIN " . self::GROUP_TABLE
				. " mg ON mg.fk_customergroup = mgc.fk_group WHERE mg.fk_merchant IN $set)";
		}

		// Shop bez vazby nepřiřadí obchodníkovi nikoho. Parametr v podmínce zůstat musí — volající
		// ho váže vždycky a PDO by na nepoužitý pojmenovaný parametr spadlo.
		return $parts ? '(' . \implode(' OR ', $parts) . ')' : "(1 = 0 AND :$param IS NOT NULL)";
	}

	/**
	 * Podmínka „objednávka (alias jejího nákupu) patří obchodníkovi". Bez `merchantScope.orders: customers`
	 * jsou to objednávky, které zadal; s ním objednávky jeho zákazníků a k tomu ty, které zadal.
	 */
	public static function orderCondition(Codebooks $codebooks, string $purchaseAlias, string $param = 'apiMerchant'): string
	{
		$placed = "$purchaseAlias.fk_merchant IN " . self::merchantSet($codebooks, $param);

		if (!$codebooks->getConfig()->merchantOrdersByCustomers()) {
			return $placed;
		}

		return '(' . self::customerCondition($codebooks, "$purchaseAlias.fk_customer", $param) . " OR $placed)";
	}

	/**
	 * Patří zákazník obchodníkovi? Jeden dotaz nad eshop_customer; obchodník i zákazník jsou navázané parametry.
	 */
	public static function ownsCustomer(DIConnection $connection, Codebooks $codebooks, string $customerId, string $merchantId): bool
	{
		$row = $connection->rows(['oc' => 'eshop_customer'], ['id' => 'oc.uuid'])
			->where('oc.uuid = :apiOwnedCustomer', ['apiOwnedCustomer' => $customerId])
			->where(self::customerCondition($codebooks, 'oc.uuid'), ['apiMerchant' => $merchantId])
			->first();

		return $row !== null;
	}

	public static function ownsOrder(DIConnection $connection, Codebooks $codebooks, string $orderId, string $merchantId): bool
	{
		$row = $connection->rows(['oo' => 'eshop_order'], ['id' => 'oo.uuid'])
			->join(['op' => 'eshop_purchase'], 'op.uuid = oo.fk_purchase', [], 'INNER')
			->where('oo.uuid = :apiOwnedOrder', ['apiOwnedOrder' => $orderId])
			->where(self::orderCondition($codebooks, 'op'), ['apiMerchant' => $merchantId])
			->first();

		return $row !== null;
	}

	/**
	 * Faktura patří obchodníkovi, když patří jeho zákazníkovi — kdo ji vystavil, eshop nevede.
	 */
	public static function ownsInvoice(DIConnection $connection, Codebooks $codebooks, string $invoiceId, string $merchantId): bool
	{
		$row = $connection->rows(['oi' => 'eshop_invoice'], ['id' => 'oi.uuid'])
			->where('oi.uuid = :apiOwnedInvoice', ['apiOwnedInvoice' => $invoiceId])
			->where(self::customerCondition($codebooks, 'oi.fk_customer'), ['apiMerchant' => $merchantId])
			->first();

		return $row !== null;
	}
}
