<?php

declare(strict_types=1);

namespace DoryoApi\Support;

/**
 * Převod časů z databáze do tvaru, na kterém se Doryo a ERP konektory shodly:
 * datum YYYY-MM-DD, čas ISO 8601 včetně zóny.
 */
final class Dates
{
	public static function date(?string $value): ?string
	{
		if ($value === null || $value === '' || \str_starts_with($value, '0000')) {
			return null;
		}

		try {
			return (new \DateTimeImmutable($value))->format('Y-m-d');
		} catch (\Throwable) {
			return null;
		}
	}

	public static function dateTime(?string $value, string $timezone): ?string
	{
		if ($value === null || $value === '' || \str_starts_with($value, '0000')) {
			return null;
		}

		try {
			return (new \DateTimeImmutable($value, new \DateTimeZone($timezone)))->format('c');
		} catch (\Throwable) {
			return null;
		}
	}

	public static function now(string $timezone): string
	{
		return (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('c');
	}

	/**
	 * Kolik dní po splatnosti; záporné číslo = ještě neuplynula.
	 */
	public static function daysOverdue(?string $dueOn, string $timezone): ?int
	{
		$due = self::date($dueOn);

		if ($due === null) {
			return null;
		}

		$today = new \DateTimeImmutable('today', new \DateTimeZone($timezone));

		return (int) $today->diff(new \DateTimeImmutable($due))->format('%r%a') * -1;
	}
}
