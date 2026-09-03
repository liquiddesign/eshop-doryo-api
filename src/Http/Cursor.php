<?php

declare(strict_types=1);

namespace DoryoApi\Http;

/**
 * Neprůhledný kurzor pro stránkování. Uvnitř je jen offset — klient s ním nic počítat nemá,
 * jen ho vrátí zpátky v parametru `cursor`.
 */
final class Cursor
{
	private const PREFIX = 'o:';

	public static function encode(int $offset): string
	{
		return \rtrim(\strtr(\base64_encode(self::PREFIX . $offset), '+/', '-_'), '=');
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	public static function decode(?string $cursor): int
	{
		if ($cursor === null || $cursor === '') {
			return 0;
		}

		$decoded = \base64_decode(\strtr($cursor, '-_', '+/'), true);

		if ($decoded === false || !\str_starts_with($decoded, self::PREFIX)) {
			throw ApiException::badRequest('Parametr cursor není platný.');
		}

		$offset = \substr($decoded, \strlen(self::PREFIX));

		if (!\ctype_digit($offset)) {
			throw ApiException::badRequest('Parametr cursor není platný.');
		}

		return (int) $offset;
	}
}
