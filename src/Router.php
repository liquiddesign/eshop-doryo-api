<?php

declare(strict_types=1);

namespace DoryoApi;

use DoryoApi\Endpoint\MethodAware;
use DoryoApi\Http\ApiException;
use Nette\Utils\Strings;

/**
 * Statická tabulka cest. Žádné regulární výrazy nad celou cestou — porovnávají se segmenty,
 * takže vzor `customers/{id}/orders` je čitelný a nemůže se překrýt s ničím jiným.
 */
final class Router
{
	/** Co smí endpoint, který o metodách nic neřekl. */
	private const READ_ONLY = ['GET', 'HEAD'];

	/** @var array<array{segments: array<string>, handler: callable, methods: array<string>}> */
	private array $routes = [];

	/**
	 * @param array<\DoryoApi\Endpoint\Endpoint> $endpoints
	 */
	public function __construct(array $endpoints)
	{
		foreach ($endpoints as $endpoint) {
			$methods = $endpoint instanceof MethodAware ? $endpoint->getMethods() : [];

			foreach ($endpoint->getRoutes() as $pattern => $method) {
				$this->routes[] = [
					'segments' => \explode('/', Strings::trim($pattern, '/')),
					'handler' => [$endpoint, $method],
					'methods' => $methods[$pattern] ?? self::READ_ONLY,
				];
			}
		}
	}

	/**
	 * @return array{0: callable, 1: array<string, string>, 2: array<string>} obsluha, parametry cesty, povolené metody
	 * @throws \DoryoApi\Http\ApiException
	 */
	public function match(string $path): array
	{
		$segments = \explode('/', Strings::trim($path, '/'));

		foreach ($this->routes as $route) {
			$params = self::matchSegments($route['segments'], $segments);

			if ($params !== null) {
				return [$route['handler'], $params, $route['methods']];
			}
		}

		throw ApiException::notFound("Endpoint /$path neexistuje.");
	}

	/**
	 * @return array<string>
	 */
	public function getPatterns(): array
	{
		return \array_map(static fn (array $route): string => \implode('/', $route['segments']), $this->routes);
	}

	/**
	 * @param array<string> $pattern
	 * @param array<string> $segments
	 * @return array<string, string>|null
	 */
	private static function matchSegments(array $pattern, array $segments): ?array
	{
		if (\count($pattern) !== \count($segments)) {
			return null;
		}

		$params = [];

		foreach ($pattern as $index => $part) {
			$value = $segments[$index];

			if (\str_starts_with($part, '{')) {
				if ($value === '') {
					return null;
				}

				$params[Strings::trim($part, '{}')] = \rawurldecode($value);

				continue;
			}

			if ($part !== $value) {
				return null;
			}
		}

		return $params;
	}
}
