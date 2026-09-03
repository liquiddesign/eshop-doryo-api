<?php

declare(strict_types=1);

namespace DoryoApi\Extension;

use Eshop\DB\Customer;
use Eshop\DB\Order;
use Eshop\DB\Product;

/**
 * Rozšiřovací bod pro konkrétní shop. Implementaci stačí zaregistrovat do DI a API ji
 * zavolá na konci mapování — smí přidávat jen do klíče `eshop`, standardní pole se
 * nikdy nepřepisují, jinak by se rozpadl kontrakt s Doryo.
 */
interface DoryoApiExtension
{
	/**
	 * @param array<string, mixed> $out
	 */
	public function extendCustomer(Customer $customer, array &$out): void;

	/**
	 * @param array<string, mixed> $out
	 */
	public function extendOrder(Order $order, array &$out): void;

	/**
	 * @param array<string, mixed> $out
	 */
	public function extendProduct(Product $product, array &$out): void;
}
