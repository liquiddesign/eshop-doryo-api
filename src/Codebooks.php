<?php

declare(strict_types=1);

namespace DoryoApi;

use StORM\DIConnection;

/**
 * Číselníky, které API potřebuje skoro u každé odpovědi (měny, sazby DPH, ceníky).
 * Načtou se jednou za request — jsou to malé tabulky a šetří to desítky dotazů.
 */
final class Codebooks
{
	/** @var array<string, string>|null */
	private ?array $currencies = null;

	/** @var array<string, float>|null */
	private ?array $vatRates = null;

	/** @var array<string, string>|null */
	private ?array $pricelistNames = null;

	/** @var array<string, int>|null */
	private ?array $currencyPrecisions = null;

	/** @var array<string>|null */
	private ?array $defaultPricelists = null;

	public function __construct(private DIConnection $connection, private Config $config)
	{
	}

	public function getCurrencyCode(?string $uuid): string
	{
		if ($uuid === null) {
			return $this->config->getCurrency();
		}

		$this->currencies ??= $this->connection->findRepository(\Eshop\DB\Currency::class)->many()->toArrayOf('code');

		return $this->currencies[$uuid] ?? $this->config->getCurrency();
	}

	/**
	 * Přesnost, na kterou měna zaokrouhluje výpočty. Eshop s ní počítá ceny, takže s ní
	 * musí počítat i API — jinak by se nabídka lišila od toho, co ukáže košík.
	 */
	public function getCurrencyPrecision(?string $uuid): int
	{
		if ($uuid === null) {
			return 4;
		}

		$this->currencyPrecisions ??= \array_map(
			static fn ($precision): int => (int) $precision,
			$this->connection->findRepository(\Eshop\DB\Currency::class)->many()->toArrayOf('calculationPrecision'),
		);

		return $this->currencyPrecisions[$uuid] ?? 4;
	}

	/**
	 * Sazba DPH v procentech podle klíče sazby na produktu (standard, reduced-high, …).
	 */
	public function getVatRate(?string $key): ?float
	{
		if ($key === null || $key === '') {
			return null;
		}

		$this->vatRates ??= \array_map(
			static fn ($rate): float => (float) $rate,
			$this->connection->findRepository(\Eshop\DB\VatRate::class)->many()->toArrayOf('rate'),
		);

		return $this->vatRates[$key] ?? null;
	}

	public function getPricelistName(?string $uuid): ?string
	{
		if ($uuid === null) {
			return null;
		}

		$this->pricelistNames ??= $this->connection->findRepository(\Eshop\DB\Pricelist::class)->many()->toArrayOf('name');

		return $this->pricelistNames[$uuid] ?? null;
	}

	/**
	 * Ceníky, ze kterých se bere veřejná cena produktu. Buď jsou vyjmenované v konfiguraci,
	 * nebo se vezmou z výchozí skupiny zákazníků — tedy přesně to, co v katalogu vidí
	 * nepřihlášený návštěvník. Ceníky konkrétního zákazníka do API nepatří.
	 *
	 * @return array<string>
	 */
	public function getDefaultPricelists(): array
	{
		if ($this->defaultPricelists !== null) {
			return $this->defaultPricelists;
		}

		$configured = $this->config->getDefaultPricelists();

		if ($configured) {
			$this->defaultPricelists = $this->connection->findRepository(\Eshop\DB\Pricelist::class)->many()
				->where('this.uuid IN :ids OR this.code IN :ids', ['ids' => $configured])
				->toArrayOf('uuid');

			return $this->defaultPricelists = \array_values($this->defaultPricelists);
		}

		$groupRepository = $this->connection->findRepository(\Eshop\DB\CustomerGroup::class);
		$group = $this->config->getDefaultCustomerGroup();

		$groupId = $group !== null
			? $groupRepository->many()->where('this.uuid', $group)->firstValue('uuid')
			: $groupRepository->many()->where('this.defaultAfterRegistration', true)->firstValue('uuid');

		if (!$groupId) {
			return $this->defaultPricelists = [];
		}

		$rows = $this->connection->rows(['nxn' => 'eshop_customergroup_nxn_eshop_pricelist'], ['fk_pricelist' => 'nxn.fk_pricelist'])
			->where('nxn.fk_customergroup', $groupId);

		$ids = [];

		foreach ($rows as $row) {
			$ids[] = $row->fk_pricelist;
		}

		return $this->defaultPricelists = $ids;
	}
}
