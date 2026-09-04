<?php

declare(strict_types=1);

namespace DoryoApi;

use Nette\Utils\Strings;

/**
 * Konfigurace čtecího API pro Doryo.
 *
 * Všechno, co se liší shop od shopu, je tady — kód endpointů je pak stejný pro každý eshop
 * postavený na liquiddesign/eshop. Hodnoty se nastavují v config/doryo-api.neon.
 */
final class Config
{
	/**
	 * Verze balíku, když se nedá zjistit z Composeru (balík nasazený mimo composer install).
	 * Skutečná verze se bere z tagu přes {@see version()}, aby s vydáním nedriftovala.
	 */
	public const VERSION = '1.0.1';

	/** Normalizované stavy objednávek, které API vrací v poli `status`. */
	public const ORDER_STATUSES = ['new', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];

	/**
	 * @param string $prefix Prefix cesty API; musí odpovídat routě v NEONu
	 * @param string|null $token Token; když je null, bere se z env DORYO_API_TOKEN
	 * @param array<string> $allowIps Whitelist IP/CIDR; prázdné = bez omezení
	 * @param array<string> $languages Mutace shopu
	 * @param array<string> $defaultPricelists UUID nebo kódy ceníků pro veřejnou cenu produktu
	 * @param string|null $defaultCustomerGroup UUID skupiny zákazníků, ze které se ceníky vezmou,
	 *     když $defaultPricelists je prázdné; null = skupina označená jako výchozí po registraci
	 * @param array<string, array<string>> $orderStates Mapa normalizovaný stav => stavy shopu
	 * @param bool $customerPricesEnabled Smí API vydat ceny konkrétního zákazníka (viz spec §11)
	 * @param string|null $userfilesDir Adresář s obrázky produktů — kvůli diagnostice médií
	 * @param array<string> $imageSizes Velikosti, ve kterých se obrázky generují
	 */
	public function __construct(
		private string $prefix = 'doryo-api',
		private ?string $token = null,
		private array $allowIps = [],
		private ?string $shopName = null,
		private ?string $shopUrl = null,
		private string $currency = 'CZK',
		private array $languages = ['cs'],
		private ?string $timezone = null,
		private array $defaultPricelists = [],
		private ?string $defaultCustomerGroup = null,
		private array $orderStates = [
			'new' => ['open'],
			'processing' => ['received'],
			'delivered' => ['finished'],
			'cancelled' => ['canceled'],
		],
		private bool $invoicePaymentTracked = true,
		private bool $customerPricesEnabled = false,
		private ?string $userfilesDir = null,
		private array $imageSizes = ['origin', 'detail', 'thumb'],
		private int $defaultLimit = 200,
		private int $maxLimit = 1000,
		private int $defaultWindowMonths = 6,
		private int $maxWindowMonths = 24,
	) {
	}

	public function getPrefix(): string
	{
		return Strings::trim($this->prefix, '/');
	}

	/**
	 * Token pro Bearer autentizaci. Prázdný token = API je celé vypnuté (vrací 401).
	 */
	public function getToken(): ?string
	{
		if ($this->token !== null && $this->token !== '') {
			return $this->token;
		}

		$fromEnv = \getenv('DORYO_API_TOKEN');

		return \is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : null;
	}

	/**
	 * @return array<string>
	 */
	public function getAllowIps(): array
	{
		return $this->allowIps;
	}

	public function getShopName(): ?string
	{
		return $this->shopName;
	}

	/**
	 * Veřejná adresa shopu pro odkazy v odpovědích. Env DORYO_API_SHOP_URL má přednost před
	 * konfigurací: config bývá v repu s produkční adresou, zatímco testovací server ji potřebuje
	 * přepsat bez zásahu do gitu — jinak model čte data z testu a odkazuje na produkci.
	 */
	public function getShopUrl(): ?string
	{
		$fromEnv = $_SERVER['DORYO_API_SHOP_URL'] ?? \getenv('DORYO_API_SHOP_URL');

		if (\is_string($fromEnv) && $fromEnv !== '') {
			return \rtrim($fromEnv, '/');
		}

		return $this->shopUrl;
	}

	public function getCurrency(): string
	{
		return $this->currency;
	}

	/**
	 * @return array<string>
	 */
	public function getLanguages(): array
	{
		return $this->languages;
	}

	public function getTimezone(): string
	{
		return $this->timezone ?? \date_default_timezone_get();
	}

	/**
	 * @return array<string>
	 */
	public function getDefaultPricelists(): array
	{
		return $this->defaultPricelists;
	}

	public function getDefaultCustomerGroup(): ?string
	{
		return $this->defaultCustomerGroup;
	}

	/**
	 * @return array<string, array<string>>
	 */
	public function getOrderStates(): array
	{
		return $this->orderStates;
	}

	public function isInvoicePaymentTracked(): bool
	{
		return $this->invoicePaymentTracked;
	}

	/**
	 * Smí API vydat ceny konkrétního zákazníka?
	 *
	 * Spec to ve výchozím stavu zakazuje (obchodní tajemství, §8.2) a nechává jako vědomou
	 * výjimku (§11). Bez toho ale nejde udělat cenová nabídka — shop, který to zapne, ví, co dělá.
	 */
	public function areCustomerPricesEnabled(): bool
	{
		return $this->customerPricesEnabled;
	}

	public function getUserfilesDir(): ?string
	{
		return $this->userfilesDir;
	}

	/**
	 * @return array<string>
	 */
	public function getImageSizes(): array
	{
		return $this->imageSizes;
	}

	public function getDefaultLimit(): int
	{
		return $this->defaultLimit;
	}

	public function getMaxLimit(): int
	{
		return $this->maxLimit;
	}

	public function getDefaultWindowMonths(): int
	{
		return $this->defaultWindowMonths;
	}

	public function getMaxWindowMonths(): int
	{
		return $this->maxWindowMonths;
	}

	/**
	 * Verze balíku liquiddesign/eshop, nad kterým API běží. Doryo si podle ní umí ohlídat,
	 * že mluví s tím, co čeká.
	 */
	/**
	 * Verze balíku (vrací ji /meta/health a kořen API) — z nainstalovaného tagu, ne z konstanty,
	 * kterou by bylo potřeba při každém vydání ručně přepsat.
	 */
	public static function version(): string
	{
		if (!\class_exists(\Composer\InstalledVersions::class)) {
			return self::VERSION;
		}

		try {
			return \ltrim(\Composer\InstalledVersions::getPrettyVersion('liquiddesign/eshop-doryo-api') ?? self::VERSION, 'v');
		} catch (\Throwable) {
			return self::VERSION;
		}
	}

	public function getEshopVersion(): ?string
	{
		if (!\class_exists(\Composer\InstalledVersions::class)) {
			return null;
		}

		try {
			return \Composer\InstalledVersions::getPrettyVersion('liquiddesign/eshop');
		} catch (\Throwable) {
			return null;
		}
	}
}
