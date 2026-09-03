<?php

declare(strict_types=1);

namespace DoryoApi\Support;

use Eshop\DB\Order;

/**
 * Stav objednávky se v eshopu nikde neukládá — odvozuje se ze značek času. Aby se dalo
 * podle stavu filtrovat v SQL (a ne až v PHP nad staženými řádky), je tady tatáž logika
 * jako v OrderMapper::shopState(), jen zapsaná jako podmínka.
 */
final class OrderStates
{
	public static function condition(string $shopState, string $alias = 'this'): ?string
	{
		return match ($shopState) {
			Order::STATE_CANCELED => "$alias.canceledTs IS NOT NULL",
			Order::STATE_COMPLETED => "($alias.canceledTs IS NULL AND $alias.receivedTs IS NOT NULL AND $alias.completedTs IS NOT NULL)",
			Order::STATE_RECEIVED => "($alias.canceledTs IS NULL AND $alias.receivedTs IS NOT NULL AND $alias.completedTs IS NULL)",
			Order::STATE_OPEN => "($alias.canceledTs IS NULL AND $alias.receivedTs IS NULL)",
			default => null,
		};
	}

	/**
	 * @param array<string> $shopStates
	 */
	public static function conditions(array $shopStates, string $alias = 'this'): ?string
	{
		$conditions = [];

		foreach ($shopStates as $state) {
			$condition = self::condition($state, $alias);

			if ($condition === null) {
				continue;
			}

			$conditions[] = $condition;
		}

		return $conditions ? '(' . \implode(' OR ', $conditions) . ')' : null;
	}
}
