<?php

declare(strict_types=1);

namespace DoryoApi\Auth;

use DoryoApi\Config;
use DoryoApi\Http\ApiException;
use Nette\Http\IRequest;
use Nette\Utils\Strings;

/**
 * Bearer token a volitelný whitelist IP. Bez tokenu v konfiguraci je API celé vypnuté —
 * shop, který ho nenastaví, nevystavuje nic.
 */
final class Authenticator
{
	public function __construct(private Config $config)
	{
	}

	/**
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function authenticate(IRequest $request): void
	{
		$token = $this->config->getToken();

		if ($token === null) {
			throw ApiException::unauthorized('API není nakonfigurované (chybí token).');
		}

		$header = (string) $request->getHeader('authorization');

		if (!\str_starts_with($header, 'Bearer ')) {
			throw ApiException::unauthorized('Chybí hlavička Authorization: Bearer <token>.');
		}

		if (!\hash_equals($token, Strings::substring($header, 7))) {
			throw ApiException::unauthorized('Neplatný token.');
		}

		$allowed = $this->config->getAllowIps();

		if (!$allowed) {
			return;
		}

		$ip = (string) $request->getRemoteAddress();

		foreach ($allowed as $range) {
			if (self::matches($ip, $range)) {
				return;
			}
		}

		throw ApiException::forbidden('Adresa není na whitelistu.');
	}

	/**
	 * Shoda IP s adresou nebo CIDR rozsahem (IPv4 i IPv6).
	 */
	public static function matches(string $ip, string $range): bool
	{
		if (!\str_contains($range, '/')) {
			return $ip === $range;
		}

		[$subnet, $bits] = \explode('/', $range, 2);

		$ipBinary = \inet_pton($ip);
		$subnetBinary = \inet_pton($subnet);

		// binární porovnání, ne textové: Nette\Utils\Strings je UTF-8 aware a na bajtech
		// z inet_pton() by počítalo znaky, ne oktety
		// phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative
		if ($ipBinary === false || $subnetBinary === false || \strlen($ipBinary) !== \strlen($subnetBinary)) {
			return false;
		}

		$bits = (int) $bits;
		$bytes = \intdiv($bits, 8);
		$remainder = $bits % 8;

		// phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative
		if (\substr($ipBinary, 0, $bytes) !== \substr($subnetBinary, 0, $bytes)) {
			return false;
		}

		if ($remainder === 0) {
			return true;
		}

		$mask = \chr(0xFF << (8 - $remainder) & 0xFF);

		return (($ipBinary[$bytes] ?? "\0") & $mask) === (($subnetBinary[$bytes] ?? "\0") & $mask);
	}
}
