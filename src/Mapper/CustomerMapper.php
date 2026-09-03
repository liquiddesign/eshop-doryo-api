<?php

declare(strict_types=1);

namespace DoryoApi\Mapper;

use DoryoApi\Support\Dates;
use DoryoApi\Support\Money;
use Eshop\DB\Customer;

/**
 * Zákazník ve tvaru, na kterém se Doryo shodlo s ERP konektory — plus blok `eshop`
 * s tím, co ví jen shop (skupina, ceníky, obrat, sleva).
 */
final class CustomerMapper extends Mapper
{
	/**
	 * @param array{
	 *     billAddress?: \Eshop\DB\Address|null,
	 *     groupName?: string|null,
	 *     pricelists?: array<string>,
	 *     merchantId?: string|null,
	 *     orderCount?: int|null,
	 *     lastOrderOn?: string|null,
	 *     turnover?: float|null
	 * } $extras Data, která endpoint dotáhne dávkově za celou stránku
	 * @return array<string, mixed>
	 */
	public function map(Customer $customer, array $extras = []): array
	{
		$currency = $this->config->getCurrency();
		$active = $customer->buyAllowed && $customer->orderAllowed;

		$out = [
			'id' => $customer->getPK(),
			'state' => $active ? 'active' : 'inactive',
			'name' => $customer->fullname ?: $customer->company,
			'legalName' => $customer->company ?: null,
			'registrationNo' => $customer->ic ?: null,
			'vatNo' => $customer->dic ?: null,
			'address' => $this->address($extras['billAddress'] ?? null, $customer->countryCode),
			'contact' => [
				'name' => $customer->fullname ?: null,
				'email' => $customer->email ?: null,
				'phone' => $customer->phone ?: null,
			],
			'maturityDays' => null,
			'note' => null,
			'eshop' => [
				'login' => $customer->email ?: null,
				'group' => $extras['groupName'] ?? null,
				'pricelists' => \array_values($extras['pricelists'] ?? []),
				'merchantId' => $extras['merchantId'] ?? $this->relationId($customer, 'merchant'),
				'registeredOn' => Dates::date($customer->createdTs),
				'lastOrderOn' => Dates::date($extras['lastOrderOn'] ?? null),
				'orderCount' => $extras['orderCount'] ?? $customer->ordersCount,
				'turnover' => Money::format($extras['turnover'] ?? null, $currency),
				'discountLevel' => $customer->discountLevelPct,
				'b2b' => (bool) $customer->ic,
				'newsletter' => (bool) $this->value($customer, 'newsletter'),
			],
		];

		$this->extend('extendCustomer', $customer, $out);

		return $out;
	}
}
