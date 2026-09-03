<?php

declare(strict_types=1);

namespace DoryoApi\Mapper;

use DoryoApi\Codebooks;
use DoryoApi\Config;
use DoryoApi\Extension\DoryoApiExtension;
use Eshop\DB\Address;
use StORM\Entity;

/**
 * Společný základ mapperů. Odpověď se vždycky skládá po polích — nikdy se nevrací
 * `toArray()` entity, aby se do API nemohlo protéct nic, co v něm nemá co dělat
 * (hesla, hashe, nákupní ceny, interní poznámky).
 */
abstract class Mapper
{
	/**
	 * @param array<\DoryoApi\Extension\DoryoApiExtension> $extensions
	 */
	public function __construct(
		protected Config $config,
		protected Codebooks $codebooks,
		protected array $extensions = [],
	) {
	}

	/**
	 * @return array{name: string|null, street: string|null, city: string|null, zip: string|null, country: string|null}|null
	 */
	protected function address(?Address $address, ?string $fallbackCountry = null): ?array
	{
		if ($address === null) {
			return null;
		}

		return [
			'name' => $address->companyName ?: $address->name,
			'street' => $address->street ?: null,
			'city' => $address->city ?: null,
			'zip' => $address->zipcode ?: null,
			'country' => $address->state ?: $fallbackCountry,
		];
	}

	/**
	 * Relace bez načtení celé entity — vrací jen cizí klíč.
	 */
	protected function relationId(Entity $entity, string $relation): ?string
	{
		$value = $this->value($entity, $relation);

		return \is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * Hodnota, která v téhle verzi eshopu existovat nemusí. Chybějící sloupec nebo relace
	 * je null, ne chyba — kontrakt s Doryo je jeden pro všechny verze (spec §8.5).
	 */
	protected function value(Entity $entity, string $property): mixed
	{
		try {
			return $entity->getValue($property);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @param array<string, mixed> $out
	 */
	protected function extend(string $method, object $entity, array &$out): void
	{
		foreach ($this->extensions as $extension) {
			if (!$extension instanceof DoryoApiExtension) {
				continue;
			}

			$standard = $out;
			$extension->{$method}($entity, $out);

			// rozšíření smí přidávat jen do `eshop`, standardní pole zůstanou, jak byla
			$eshop = $out['eshop'] ?? [];
			$out = $standard;
			$out['eshop'] = \is_array($eshop) ? $eshop : ($standard['eshop'] ?? []);
		}
	}
}
