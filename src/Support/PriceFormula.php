<?php

declare(strict_types=1);

namespace DoryoApi\Support;

/**
 * Cena produktu v ceníku tak, jak ji počítá sám eshop.
 *
 * Kopíruje Eshop\DB\ProductRepository::sqlHandlePrice(): u ceníků, které povolují slevovou
 * hladinu, se cena sníží o vyšší ze dvou slev — slevy na produktu (stropené
 * `maxDiscountProductPct`) a slevové hladiny zákazníka. Ceníky, které slevovou hladinu
 * nepovolují, jdou ven v původní ceně.
 *
 * Proč se to tu opisuje a nevolá se rovnou repozitář: getProducts() staví na přihlášeném
 * zákazníkovi ze session, kterou API nemá, a na jeden dotaz by lepilo desítky selectů.
 * Tady je potřeba jeden výraz, který jde vložit do SQL pro libovolného zákazníka.
 */
final class PriceFormula
{
	/**
	 * @param string $column Sloupec s cenou (price, priceVat, priceBefore, priceVatBefore)
	 * @param int $discountLevelPct Slevová hladina zákazníka (nebo jeho skupiny)
	 * @param int $maxDiscountPct Strop slevy na produktu
	 * @param float $surchargePct Pevná marže zákazníka; 0 = neuplatňuje se
	 * @param int $precision Přesnost zaokrouhlení podle měny
	 */
	public static function expression(
		string $column,
		int $discountLevelPct = 0,
		int $maxDiscountPct = 100,
		float $surchargePct = 0.0,
		int $precision = 4,
		string $priceAlias = 'p',
		string $productAlias = 'prod',
		string $pricelistAlias = 'pl',
	): string {
		$price = "$priceAlias.$column";

		// marže se uplatní jen v cenících, které ji povolují
		if ($surchargePct > 0) {
			$divisor = 1 - ($surchargePct / 100);
			$price = "IF($pricelistAlias.allowSurchargeLevel = 1, $price / $divisor, $price)";
		}

		// sleva na produktu vs. hladina zákazníka — bere se ta vyšší, produktová se stropí
		$discount = "IF(LEAST($productAlias.discountLevelPct, $maxDiscountPct) > $discountLevelPct,
			LEAST($productAlias.discountLevelPct, $maxDiscountPct), $discountLevelPct)";

		return "IF(
			$pricelistAlias.allowDiscountLevel = 1,
			ROUND($price * ((100 - $discount) / 100), $precision),
			$price
		)";
	}
}
