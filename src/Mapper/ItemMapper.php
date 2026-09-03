<?php

declare(strict_types=1);

namespace DoryoApi\Mapper;

use DoryoApi\Support\Money;
use Eshop\DB\CartItem;
use Eshop\DB\InvoiceItem;

/**
 * Položky objednávky a faktury mají v API stejný tvar, i když v shopu jsou to dvě entity.
 * `unitPrice` a `total` jsou bez DPH (jako na dokladu), ceny s DPH jsou vedle nich navíc.
 */
final class ItemMapper extends Mapper
{
	/**
	 * @return array<string, mixed>
	 */
	public function map(CartItem|InvoiceItem $item, string $currency): array
	{
		$amount = (int) $item->amount;
		$price = (float) $item->price;
		$priceVat = $item->priceVat !== null ? (float) $item->priceVat : null;
		$name = $item instanceof CartItem ? $item->productName : $item->name;

		return [
			'id' => $item->getPK(),
			'productId' => $this->relationId($item, 'product'),
			'code' => $item->getFullCode(),
			'name' => $name,
			'quantity' => $amount,
			'unit' => null,
			'unitPrice' => Money::format($price, $currency),
			'unitPriceWithVat' => Money::format($priceVat, $currency),
			'total' => Money::format($price * $amount, $currency),
			'totalWithVat' => $priceVat !== null ? Money::format($priceVat * $amount, $currency) : null,
			'vatRate' => $item->vatPct !== null ? (float) $item->vatPct : null,
		];
	}
}
