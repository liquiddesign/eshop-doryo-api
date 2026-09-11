<?php

declare(strict_types=1);

namespace DoryoApi;

use StORM\Collection;
use StORM\DIConnection;

/**
 * Obchodník u zákazníka — jak ho ten který shop vede.
 *
 * Základní eshop má relaci: `eshop_customer.fk_merchant` u zákazníka a `eshop_purchase.fk_merchant`
 * u objednávky. Shopy, které zákazníky importují z ERP, ji ale často nevyplňují a obchodníka
 * nesou jako KÓD ve vlastním sloupci zákazníka — Levior má `dealerCode` z K2 ve tvaru `11_01-10`,
 * kde kód obchodníka je část před podtržítkem.
 *
 * Bez tohohle patra takový shop vrací `merchantId: null` u všech zákazníků i objednávek,
 * `?merchantId=` nevyfiltruje nic a `reports/sales?groupBy=merchant` vrátí jediný řádek
 * „bez obchodníka". To je horší než chyba: vypadá to jako pravda o datech.
 *
 * Sloupec se NEHÁDÁ — je to `merchantCodeColumn` v konfiguraci shopu. Kdo ho nenastaví, dostane
 * přesně dnešní chování a ani o jeden JOIN navíc. Kdo ho nastaví, dostane sjednocení obojího:
 * platí relace, a kde není, rozhodne kód. Míchat se to smí, protože web a import do téhož
 * shopu zapisují každý jinam.
 */
final class Merchants
{
	/** Tabulka obchodníků; ve starším eshopu nemusí být vůbec. */
	private const TABLE = 'eshop_merchant';

	private ?bool $codeReady = null;

	/** @var array<string, string|null>|null kód obchodníka => uuid */
	private ?array $byCode = null;

	public function __construct(private DIConnection $connection, private Config $config, private Codebooks $codebooks)
	{
	}

	/**
	 * Vede tenhle shop obchodníka i kódem v zákazníkovi? Ptá se na konfiguraci i na to,
	 * jestli ten sloupec v databázi vážně je — překlep v NEONu jinak shodí každý dotaz
	 * nad zákazníky, což je horší než nefunkční filtr.
	 */
	public function usesCode(): bool
	{
		if ($this->codeReady !== null) {
			return $this->codeReady;
		}

		$column = $this->config->getMerchantCodeColumn();

		return $this->codeReady = $column !== null
			&& $this->codebooks->hasColumn('eshop_customer', $column)
			&& $this->codebooks->hasColumn(self::TABLE, 'code');
	}

	/**
	 * Jak shop vazbu vede — do `/v1/meta/capabilities` a do diagnostiky.
	 * `relation` = jen relace, `relation+code` = relace a k tomu kód z ERP.
	 */
	public function mode(): string
	{
		return $this->usesCode() ? 'relation+code' : 'relation';
	}

	/**
	 * SQL výraz, který ze zákazníka (alias tabulky `eshop_customer`) udělá KÓD obchodníka.
	 * `null`, když shop kód nevede.
	 *
	 * Kód z ERP bývá složený (`11_01-10` = obchodník 11, pak sklad a trasa), takže se bere
	 * část před prvním oddělovačem. Prázdný oddělovač znamená „ber celou hodnotu".
	 */
	public function codeExpression(string $alias): ?string
	{
		if (!$this->usesCode()) {
			return null;
		}

		$column = "$alias." . $this->config->getMerchantCodeColumn();
		$separator = $this->config->getMerchantCodeSeparator();

		if ($separator === '') {
			return "NULLIF($column, '')";
		}

		// Oddělovač i jméno sloupce jdou do SQL natvrdo — vázat se nedají, protože výraz se
		// používá i v JOIN ON a v poddotazu. Bezpečné to je tím, že obojí projde přes Config,
		// který drží úzký whitelist znaků, a sloupec navíc musí existovat podle information_schema.
		return "NULLIF(SUBSTRING_INDEX($column, '$separator', 1), '')";
	}

	/**
	 * Obchodníci pro danou stránku zákazníků: `id zákazníka => id obchodníka`.
	 *
	 * Jeden dotaz na celou stránku, ne poddotaz na řádek. Přednost má relace; kde není,
	 * rozhodne kód. Chybějící tabulka ani sloupec není chyba, jen prázdná mapa — projekce
	 * pak vrací `merchantId: null` přesně jako dosud.
	 * @param array<string> $customerIds
	 * @return array<string, string>
	 */
	public function forCustomers(array $customerIds): array
	{
		if (!$customerIds) {
			return [];
		}

		try {
			$code = $this->codeExpression('c');

			$select = ['id' => 'c.uuid', 'merchant' => $code === null ? 'c.fk_merchant' : 'IFNULL(c.fk_merchant, m.uuid)'];
			$rows = $this->connection->rows(['c' => 'eshop_customer'], $select)->where('c.uuid', $customerIds);

			if ($code !== null) {
				$rows->join(['m' => self::TABLE], "m.code = $code", [], 'LEFT');
			}

			$map = [];

			foreach ($rows as $row) {
				if ($row->merchant === null) {
					continue;
				}

				$map[$row->id] = (string) $row->merchant;
			}

			return $map;
		} catch (\Throwable) {
			return [];
		}
	}

	/**
	 * Podmínka „tenhle zákazník patří tomuhle obchodníkovi" nad kolekcí zákazníků.
	 *
	 * `$alias` je alias tabulky `eshop_customer` v té kolekci (u seznamu zákazníků `this`,
	 * u objednávek přijoinovaná tabulka). Neznámý obchodník nevrátí nic — nikdy všechno.
	 * @template T of \StORM\Entity
	 * @param \StORM\Collection<T> $collection
	 */
	public function whereCustomerBelongsTo(Collection $collection, string $alias, string $merchantId): void
	{
		$code = $this->usesCode() ? $this->codeFor($merchantId) : null;

		if ($code === null) {
			$collection->where("$alias.fk_merchant", $merchantId);

			return;
		}

		$collection->where(
			"($alias.fk_merchant = :apiMerchantId OR " . $this->codeExpression($alias) . ' = :apiMerchantCode)',
			['apiMerchantId' => $merchantId, 'apiMerchantCode' => $code],
		);
	}

	/**
	 * Kód obchodníka podle jeho id, nebo `null` (neexistuje, nebo kód nemá). Mapa se načte
	 * jednou za request — obchodníků jsou desítky, ne tisíce.
	 */
	public function codeFor(string $merchantId): ?string
	{
		if ($this->byCode === null) {
			$this->byCode = [];

			try {
				foreach ($this->connection->rows(['m' => self::TABLE], ['id' => 'm.uuid', 'code' => 'm.code']) as $row) {
					$this->byCode[(string) $row->id] = $row->code !== null && $row->code !== '' ? (string) $row->code : null;
				}
			} catch (\Throwable) {
				$this->byCode = [];
			}
		}

		return $this->byCode[$merchantId] ?? null;
	}

	/**
	 * Obchodníci shopu i s tím, kolik na nich visí zákazníků. Bez počtu je seznam k ničemu:
	 * správce podle něj páruje člověka na obchodníka a potřebuje poznat, kdo je živý záznam
	 * a kdo zbytek po importu.
	 * @return array<array<string, mixed>>
	 */
	public function all(?string $email = null, ?string $fulltext = null): array
	{
		try {
			$code = $this->codeExpression('c');
			$linked = $code === null
				? '(SELECT COUNT(*) FROM eshop_customer c WHERE c.fk_merchant = m.uuid)'
				: "(SELECT COUNT(*) FROM eshop_customer c WHERE c.fk_merchant = m.uuid OR $code = m.code)";

			$rows = $this->connection->rows(['m' => self::TABLE], [
				'id' => 'm.uuid',
				'code' => 'm.code',
				'name' => 'm.fullname',
				'email' => 'm.email',
				'phone' => 'm.phone',
				'customers' => $linked,
			])->orderBy(['m.fullname' => 'ASC']);

			if ($email !== null && $email !== '') {
				$rows->where('m.email', $email);
			}

			if ($fulltext !== null && $fulltext !== '') {
				$rows->where('m.fullname LIKE :apiMerchantQ OR m.email LIKE :apiMerchantQ OR m.code LIKE :apiMerchantQ', ['apiMerchantQ' => "%$fulltext%"]);
			}

			$items = [];

			foreach ($rows->setTake(500) as $row) {
				$items[] = [
					'id' => (string) $row->id,
					'code' => $row->code !== null && $row->code !== '' ? (string) $row->code : null,
					'name' => (string) $row->name,
					'email' => $row->email !== null && $row->email !== '' ? (string) $row->email : null,
					'phone' => $row->phone !== null && $row->phone !== '' ? (string) $row->phone : null,
					'customers' => (int) $row->customers,
				];
			}

			return $items;
		} catch (\Throwable) {
			return [];
		}
	}

	/**
	 * Je aspoň jeden zákazník na nějakého obchodníka navázaný? Tohle rozhoduje, jestli
	 * `/v1/meta/capabilities` řekne „obchodníky vedeme" — samotná tabulka plná obchodníků
	 * nestačí, když u zákazníků není ani jeden přiřazený (přesně tenhle stav mají shopy,
	 * kde vazbu drží jen ERP).
	 */
	public function hasAnyLink(): bool
	{
		try {
			$code = $this->codeExpression('c');
			$where = $code === null
				? 'c.fk_merchant IS NOT NULL'
				: "(c.fk_merchant IS NOT NULL OR $code IN (SELECT m.code FROM " . self::TABLE . ' m WHERE m.code IS NOT NULL))';

			return $this->connection->rows(['c' => 'eshop_customer'], ['one' => '1'])
				->where($where)
				->setTake(1)
				->firstValue('one') !== false;
		} catch (\Throwable) {
			return false;
		}
	}
}
