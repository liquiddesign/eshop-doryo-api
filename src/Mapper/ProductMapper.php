<?php

declare(strict_types=1);

namespace DoryoApi\Mapper;

use DoryoApi\Support\Money;
use Eshop\DB\Product;

/**
 * Produkt s cenou z veřejného ceníku a se skladem. Ceníky konkrétních zákazníků do API
 * nepatří — obchodní tajemství a per shop jiná pravidla (viz spec §6).
 */
final class ProductMapper extends Mapper
{
	/**
	 * @param array{
	 *     price?: float|null,
	 *     priceVat?: float|null,
	 *     pricelist?: string|null,
	 *     currency?: string|null,
	 *     stock?: array<string, mixed>|null,
	 *     categories?: array<string>,
	 *     hidden?: bool|null,
	 *     supplierCodes?: array<string>,
	 *     producer?: string|null,
	 *     url?: string|null,
	 *     attributes?: array<array<string, mixed>>
	 * } $extras
	 * @return array<string, mixed>
	 */
	public function map(Product $product, array $extras = []): array
	{
		$currency = $extras['currency'] ?? $this->config->getCurrency();
		$vatRate = $this->codebooks->getVatRate($product->vatRate);
		$price = $extras['price'] ?? null;
		$priceVat = $extras['priceVat'] ?? ($price !== null && $vatRate !== null ? \round($price * (1 + $vatRate / 100), 2) : null);

		$out = [
			'id' => $product->getPK(),
			'code' => $product->getFullCode(),
			'ean' => $product->ean ?: null,
			'name' => $product->name,
			'producer' => $extras['producer'] ?? null,
			'categories' => \array_values($extras['categories'] ?? []),
			// ?? null, ne přímé čtení: eshop 2.0 sloupec deletedTs nemá a StORM na neznámou
			// vlastnost vyhodí výjimku (__isset ji ale ustojí). Bez měkkého mazání je produkt
			// z principu aktivní.
			'active' => ($product->deletedTs ?? null) === null,
			'unit' => $product->unit ?: null,
			'vatRate' => $vatRate,
			'price' => $price !== null ? Money::format($price, $currency) + ['pricelist' => $extras['pricelist'] ?? null] : null,
			'priceWithVat' => Money::format($priceVat, $currency),
			'stock' => $extras['stock'] ?? null,
			'attributes' => \array_values($extras['attributes'] ?? []),
			'eshop' => [
				'url' => $extras['url'] ?? null,
				'supplierCodes' => \array_values($extras['supplierCodes'] ?? []),
				'hidden' => $extras['hidden'] ?? null,
				'mpn' => $product->mpn ?: null,
				'deliveryDays' => $product->deliveryDays,
			],
		];

		$this->extend('extendProduct', $product, $out);

		return $out;
	}
}
