<?php

declare(strict_types=1);

namespace DoryoApi\Http;

use DoryoApi\Config;

/**
 * Parametry dotazu i s validací. Cokoli, co neprojde, je 400 — API nikdy netipuje,
 * co klient asi myslel.
 */
final class Query
{
	/**
	 * @param array<string, mixed> $params
	 */
	public function __construct(private array $params, private Config $config)
	{
	}

	public function has(string $name): bool
	{
		return isset($this->params[$name]) && $this->params[$name] !== '';
	}

	public function string(string $name, ?string $default = null): ?string
	{
		if (!$this->has($name)) {
			return $default;
		}

		$value = $this->params[$name];

		if (!\is_string($value)) {
			throw ApiException::badRequest("Parametr $name musí být řetězec.");
		}

		return \trim($value);
	}

	/**
	 * Seznam hodnot oddělených čárkou — používá se u fulltextu `q` (čárka = OR).
	 *
	 * @return array<string>
	 */
	public function strings(string $name): array
	{
		$value = $this->string($name);

		if ($value === null) {
			return [];
		}

		$parts = \array_filter(\array_map('\trim', \explode(',', $value)), static fn (string $part): bool => $part !== '');

		return \array_values($parts);
	}

	public function int(string $name, ?int $default = null): ?int
	{
		if (!$this->has($name)) {
			return $default;
		}

		$value = (string) $this->params[$name];

		if (!\ctype_digit($value)) {
			throw ApiException::badRequest("Parametr $name musí být celé číslo.");
		}

		return (int) $value;
	}

	public function bool(string $name, ?bool $default = null): ?bool
	{
		if (!$this->has($name)) {
			return $default;
		}

		$value = \strtolower((string) $this->params[$name]);

		if (\in_array($value, ['1', 'true', 'yes'], true)) {
			return true;
		}

		if (\in_array($value, ['0', 'false', 'no'], true)) {
			return false;
		}

		throw ApiException::badRequest("Parametr $name musí být true nebo false.");
	}

	/**
	 * Datum ve tvaru YYYY-MM-DD.
	 */
	public function date(string $name): ?string
	{
		$value = $this->string($name);

		if ($value === null) {
			return null;
		}

		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

		if ($date === false || $date->format('Y-m-d') !== $value) {
			throw ApiException::badRequest("Parametr $name musí být datum ve tvaru YYYY-MM-DD.");
		}

		return $value;
	}

	/**
	 * Datum nebo čas — `since` smí přijít i s časem, ať se dá dotahovat inkrementálně.
	 */
	public function dateTime(string $name): ?string
	{
		$value = $this->string($name);

		if ($value === null) {
			return null;
		}

		try {
			$date = new \DateTimeImmutable($value);
		} catch (\Throwable) {
			throw ApiException::badRequest("Parametr $name musí být datum ve tvaru YYYY-MM-DD nebo ISO 8601.");
		}

		return $date->format('Y-m-d H:i:s');
	}

	public function limit(): int
	{
		$limit = $this->int('limit', $this->config->getDefaultLimit());

		if ($limit < 1 || $limit > $this->config->getMaxLimit()) {
			throw ApiException::badRequest(\sprintf('Parametr limit musí být mezi 1 a %d.', $this->config->getMaxLimit()));
		}

		return $limit;
	}

	public function offset(): int
	{
		return Cursor::decode($this->string('cursor'));
	}

	/**
	 * Časové okno pro seznamy a reporty. Když klient nic neřekne, bere se posledních
	 * `defaultWindowMonths` měsíců; delší okno než `maxWindowMonths` API odmítne.
	 *
	 * @return array{0: string, 1: string} od, do (YYYY-MM-DD)
	 */
	public function window(string $fromParam, string $toParam, bool $required = false): array
	{
		$from = $this->date($fromParam);
		$to = $this->date($toParam);

		if ($required && ($from === null || $to === null)) {
			throw ApiException::badRequest("Parametry $fromParam a $toParam jsou povinné.");
		}

		$today = new \DateTimeImmutable('today', new \DateTimeZone($this->config->getTimezone()));
		$to ??= $today->format('Y-m-d');
		$from ??= $today->modify('-' . $this->config->getDefaultWindowMonths() . ' months')->format('Y-m-d');

		if ($from > $to) {
			throw ApiException::badRequest("Parametr $fromParam musí být dřív než $toParam.");
		}

		$maxFrom = (new \DateTimeImmutable($to))->modify('-' . $this->config->getMaxWindowMonths() . ' months')->format('Y-m-d');

		if ($from < $maxFrom) {
			throw ApiException::badRequest(\sprintf('Rozsah %s–%s smí být nejvýše %d měsíců.', $fromParam, $toParam, $this->config->getMaxWindowMonths()));
		}

		return [$from, $to];
	}
}
