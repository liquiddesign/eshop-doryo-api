<?php

declare(strict_types=1);

namespace DoryoApi\OpenApi;

/**
 * Endpoint, který si svůj kus popisu do `openapi.json` napíše sám.
 *
 * Popis API je záměrně ručně psaná dokumentace pro model, ne generovaný katalog — projektový
 * endpoint proto dodá hotové `paths` ve stejném tvaru, v jakém je má {@see Specification}.
 *
 * Pozor na `$ref`: parser v Doryo resolvuje odkazy jen u parametrů, u schémat těla ne. Schémata
 * zápisu se proto píšou inline, jinak model uvidí místo pole `{"$ref": "..."}`.
 */
interface SpecificationPart
{
	/**
	 * Cesty ve tvaru OpenAPI 3.0 (`/v1/...` => operace). Slučuje se s vestavěnými cestami.
	 * @return array<string, mixed>
	 */
	public function getOpenApiPaths(): array;

	/**
	 * Doplňky do `components` (schemas, parameters…). Klíče, které už balík má, se nepřepisují.
	 * @return array<string, mixed>
	 */
	public function getOpenApiComponents(): array;
}
