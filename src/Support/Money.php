<?php

declare(strict_types=1);

namespace DoryoApi\Support;

/**
 * Peníze jdou ven vždycky jako řetězec na dvě desetinná místa. Float v JSONu by
 * na druhé straně skončil v plovoucí aritmetice a částky by se rozjely.
 */
final class Money
{
	/**
	 * @return array{amount: string, currency: string}|null
	 */
	public static function format(float|int|string|null $amount, string $currency): ?array
	{
		if ($amount === null || $amount === '') {
			return null;
		}

		return [
			'amount' => \number_format(\round((float) $amount, 2), 2, '.', ''),
			'currency' => $currency,
		];
	}

	/**
	 * @return array{amount: string, currency: string}
	 */
	public static function zero(string $currency): array
	{
		return ['amount' => '0.00', 'currency' => $currency];
	}
}
