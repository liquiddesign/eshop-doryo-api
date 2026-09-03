<?php

declare(strict_types=1);

namespace DoryoApi\Support;

/**
 * Celková cena objednávky spočítaná v SQL.
 *
 * Kopíruje definici z Eshop\DB\Order::getTotalPrice() — položky košíku + doprava + platba
 * mínus pevná sleva z kupónu a procentní sleva na nákup. Když má objednávka spočítanou
 * cenu v `totalPriceComputed`, použije se ta (to je hodnota, které věří i admin shopu).
 *
 * Proč v SQL: seznam 200 objednávek by přes entity znamenal tisíce dotazů.
 */
final class OrderTotals
{
	public static function withVat(string $order = 'this', string $purchase = 'purchase'): string
	{
		return self::expression($order, $purchase, true);
	}

	public static function withoutVat(string $order = 'this', string $purchase = 'purchase'): string
	{
		return self::expression($order, $purchase, false);
	}

	private static function expression(string $order, string $purchase, bool $vat): string
	{
		$priceColumn = $vat ? 'priceVat' : 'price';
		$computedColumn = $vat ? 'totalPriceVatComputed' : 'totalPriceComputed';
		$couponColumn = $vat ? 'discountValueVat' : 'discountValue';

		$items = "(SELECT SUM(ci.$priceColumn * ci.amount) FROM eshop_cartitem ci
			JOIN eshop_cart c ON c.uuid = ci.fk_cart WHERE c.fk_purchase = $purchase.uuid)";
		$delivery = "(SELECT SUM(d.$priceColumn) FROM eshop_delivery d WHERE d.fk_order = $order.uuid)";
		$payment = "(SELECT SUM(pm.$priceColumn) FROM eshop_payment pm WHERE pm.fk_order = $order.uuid)";
		$coupon = "(SELECT dc.$couponColumn FROM eshop_discountcoupon dc WHERE dc.uuid = $purchase.fk_coupon)";

		$computed = "IFNULL($items, 0) + IFNULL($delivery, 0) + IFNULL($payment, 0)
			- IFNULL($coupon, 0) - ROUND(IFNULL($items, 0) * IFNULL($purchase.discountPct, 0) / 100, 2)";

		return "IFNULL($order.$computedColumn, $computed)";
	}
}
