<?php

declare(strict_types=1);

namespace DoryoApi\Endpoint;

/**
 * Kus API, který obsluhuje jednu doménu. Router si od něj vyzvedne vzory cest;
 * obsluha má vždycky tvar `method(array $params, Query $query): Response`.
 */
interface Endpoint
{
	/**
	 * Vzor cesty (segmenty, proměnná část v {}) => jméno metody na tomhle endpointu.
	 *
	 * @return array<string, string>
	 */
	public function getRoutes(): array;
}
