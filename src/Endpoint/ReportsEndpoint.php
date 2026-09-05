<?php

declare(strict_types=1);

namespace DoryoApi\Endpoint;

use DoryoApi\Http\ApiException;
use DoryoApi\Http\Query;
use DoryoApi\Http\Response;
use DoryoApi\Support\Dates;
use DoryoApi\Support\Money;
use DoryoApi\Support\OrderTotals;
use DoryoApi\Support\Sql;
use Nette\Utils\Arrays;

/**
 * Souhrny, aby model nemusel stahovat objednávky po jedné. Všechno se počítá v SQL
 * a jen v povoleném časovém okně (viz Config::getMaxWindowMonths()).
 *
 * Zrušené objednávky se do tržeb nepočítají.
 */
final class ReportsEndpoint extends BaseEndpoint
{
	private const GROUP_BY = ['month', 'week', 'day', 'merchant', 'customer', 'category', 'producer'];

	/**
	 * @return array<string, string>
	 */
	public function getRoutes(): array
	{
		return [
			'v1/reports/sales' => 'sales',
			'v1/reports/top-products' => 'topProducts',
			'v1/reports/customers' => 'customers',
			'v1/reports/receivables' => 'receivables',
			'v1/reports/churn' => 'churn',
			'v1/reports/replenishment' => 'replenishment',
			'v1/reports/fulfillment' => 'fulfillment',
			'v1/reports/reviews' => 'reviews',
			'v1/reports/imports' => 'imports',
			'v1/reports/catalog-health' => 'catalogHealth',
			'v1/reports/unlinked' => 'unlinked',
			'v1/reports/abandoned-carts' => 'abandonedCarts',
		];
	}

	/**
	 * @param array<string, string> $params
	 */
	public function sales(array $params, Query $query): Response
	{
		unset($params);

		[$from, $to] = $query->window('from', 'to');
		$groupBy = $query->string('groupBy', 'month');

		if (!Arrays::contains(self::GROUP_BY, $groupBy)) {
			throw ApiException::badRequest('Parametr groupBy musí být jeden z: ' . \implode(', ', self::GROUP_BY) . '.');
		}

		// Volitelné zúžení na objednávky dané velikosti („po měsících, jen objednávky nad deset
		// položek"). Bez toho by se to muselo poskládat z detailu každé objednávky zvlášť.
		[$minItems, $maxItems] = $this->itemCountRange($query);
		// nákupy okna se tahají jen kvůli tomuhle filtru — bez něj by to byl dotaz navíc pro nic
		$purchases = $minItems === null && $maxItems === null
			? null
			: $this->purchasesByItemCount($this->purchasesInWindow($from, $to), $minItems, $maxItems);

		$items = match ($groupBy) {
			'category' => $this->salesByItems($from, $to, 'category', $purchases),
			'producer' => $this->salesByItems($from, $to, 'producer', $purchases),
			default => $this->salesByPeriod($groupBy, $from, $to, $purchases),
		};

		return Response::list($items, null);
	}

	/**
	 * @param array<string, string> $params
	 */
	public function topProducts(array $params, Query $query): Response
	{
		unset($params);

		[$from, $to] = $query->window('from', 'to');
		$limit = $query->limit();
		$suffix = $this->connection->getMutationSuffix();
		$currency = $this->config->getCurrency();

		$purchaseIds = $this->purchasesInWindow($from, $to);

		if (!$purchaseIds) {
			return Response::list([], null);
		}

		$rows = $this->connection->rows(['c' => 'eshop_cart'], [
			'productId' => 'ci.fk_product',
			'code' => 'MAX(ci.productCode)',
			'name' => "MAX(ci.productName$suffix)",
			'quantity' => 'SUM(ci.amount)',
			'revenue' => 'SUM(ci.priceVat * ci.amount)',
		])
			->join(['ci' => 'eshop_cartitem'], 'ci.fk_cart = c.uuid', [], 'INNER')
			->where('c.fk_purchase', $purchaseIds)
			->where('ci.fk_product IS NOT NULL')
			->setGroupBy(['ci.fk_product'])
			->orderBy(['revenue' => 'DESC'])
			->setTake($limit);

		$items = [];

		foreach ($rows as $row) {
			$items[] = [
				'productId' => $row->productId,
				'code' => $row->code,
				'name' => $row->name,
				'quantity' => (int) $row->quantity,
				'revenue' => Money::format($row->revenue, $currency),
			];
		}

		return Response::list($items, null);
	}

	/**
	 * Kdo roste a kdo padá. Obrat a počet objednávek za období proti srovnávacímu období;
	 * když srovnávací období klient neurčí, bere se stejně dlouhé bezprostředně předchozí.
	 * @param array<string, string> $params
	 */
	public function customers(array $params, Query $query): Response
	{
		unset($params);

		[$from, $to] = $query->window('from', 'to');
		[$compareFrom, $compareTo] = $this->comparisonWindow($query, $from, $to);

		$current = $this->loadCustomerTotals($from, $to);
		$previous = $this->loadCustomerTotals($compareFrom, $compareTo);
		$currency = $this->config->getCurrency();

		$names = $this->loadCustomerNames(\array_values(\array_unique(\array_merge(\array_keys($current), \array_keys($previous)))));
		$items = [];

		foreach ($names as $id => $name) {
			$now = $current[$id] ?? null;
			$before = $previous[$id] ?? null;
			$revenue = (float) ($now->revenue ?? 0);
			$revenueBefore = (float) ($before->revenue ?? 0);

			$items[] = [
				'customerId' => $id,
				'name' => $name,
				'orders' => (int) ($now->orders ?? 0),
				'revenue' => Money::format($revenue, $currency),
				'ordersBefore' => (int) ($before->orders ?? 0),
				'revenueBefore' => Money::format($revenueBefore, $currency),
				'change' => Money::format($revenue - $revenueBefore, $currency),
				'changePct' => $revenueBefore > 0 ? \round(($revenue - $revenueBefore) / $revenueBefore * 100, 1) : null,
				'lastOrderOn' => Dates::date($now->lastOrderOn ?? ($before->lastOrderOn ?? null)),
			];
		}

		$sort = $query->string('sort', 'revenue');

		\usort($items, match ($sort) {
			'growth' => static fn (array $a, array $b): int => (float) $b['change']['amount'] <=> (float) $a['change']['amount'],
			'drop' => static fn (array $a, array $b): int => (float) $a['change']['amount'] <=> (float) $b['change']['amount'],
			default => static fn (array $a, array $b): int => (float) $b['revenue']['amount'] <=> (float) $a['revenue']['amount'],
		});

		return Response::list(\array_slice($items, 0, $query->limit()), null);
	}

	/**
	 * Pohledávky po zákaznících — kolik a jak dlouho dluží. Neuhrazené faktury se sčítají
	 * do pásem stáří, ať model nemusí stránkovat doklady a počítat je sám.
	 * @param array<string, string> $params
	 */
	public function receivables(array $params, Query $query): Response
	{
		unset($params);

		$currency = $this->config->getCurrency();
		$overdueOnly = $query->bool('overdueOnly', true);
		$today = (new \DateTimeImmutable('today', new \DateTimeZone($this->config->getTimezone())))->format('Y-m-d');
		$amount = $this->config->isInvoicePaymentTracked() ? '(i.totalPriceVat - IFNULL(i.paid, 0))' : 'i.totalPriceVat';

		// faktury bez navázaného zákazníka se seskupují podle IČO nebo odběratele — jinak by
		// se slily do jednoho řádku s náhodným jménem
		$rows = $this->connection->rows(['i' => 'eshop_invoice'], [
			'customerId' => 'i.fk_customer',
			'groupKey' => 'IFNULL(i.fk_customer, IFNULL(i.ic, i.subject))',
			'name' => 'IFNULL(IFNULL(c.company, c.fullname), i.subject)',
			'invoices' => 'COUNT(*)',
			'outstanding' => "SUM($amount)",
			'overdue' => "SUM(IF(i.dueDate < '$today', $amount, 0))",
			'bucket30' => "SUM(IF(i.dueDate >= DATE_SUB('$today', INTERVAL 30 DAY) AND i.dueDate < '$today', $amount, 0))",
			'bucket60' => "SUM(IF(i.dueDate >= DATE_SUB('$today', INTERVAL 60 DAY) AND i.dueDate < DATE_SUB('$today', INTERVAL 30 DAY), $amount, 0))",
			'bucket90' => "SUM(IF(i.dueDate >= DATE_SUB('$today', INTERVAL 90 DAY) AND i.dueDate < DATE_SUB('$today', INTERVAL 60 DAY), $amount, 0))",
			'bucketOlder' => "SUM(IF(i.dueDate < DATE_SUB('$today', INTERVAL 90 DAY), $amount, 0))",
			'oldestDueOn' => 'MIN(i.dueDate)',
		])
			->join(['c' => 'eshop_customer'], 'c.uuid = i.fk_customer')
			->where('i.canceled IS NULL')
			->where('i.paidDate IS NULL')
			->setGroupBy(['groupKey']);

		if ($overdueOnly) {
			$rows->where("i.dueDate < '$today'");
		}

		// Faktury, u kterých není ani zákazník, ani IČO, ani odběratel, by se seskupily do
		// JEDNOHO řádku bez jména (GROUP BY dá všechny NULL dohromady) — a protože je jich
		// hodně, sedl by si ten řádek na první místo a tvářil se jako největší dlužník.
		// Na mcprofi je to 16 tisíc faktur z Allegra, kde je kupující anonymní. Do žebříčku
		// zákazníků nepatří; sečtou se zvlášť, ať se ani neztratí, ani nepletou.
		$rows->where('IFNULL(i.fk_customer, IFNULL(i.ic, i.subject)) IS NOT NULL');
		$rows->orderBy(['outstanding' => 'DESC'])->setTake($query->limit());

		$items = [];

		foreach ($rows as $row) {
			$items[] = [
				'customerId' => $row->customerId,
				'name' => $row->name,
				'registrationNo' => $row->customerId === null ? $row->groupKey : null,
				'invoices' => (int) $row->invoices,
				'outstanding' => Money::format($row->outstanding, $currency),
				'overdue' => Money::format($row->overdue, $currency),
				'aging' => [
					'days0to30' => Money::format($row->bucket30, $currency),
					'days31to60' => Money::format($row->bucket60, $currency),
					'days61to90' => Money::format($row->bucket90, $currency),
					'over90' => Money::format($row->bucketOlder, $currency),
				],
				'oldestDueOn' => Dates::date($row->oldestDueOn),
			];
		}

		return Response::list($items, null)->withExtra($this->unassignedReceivables($overdueOnly, $today, $amount, $currency));
	}

	/**
	 * Pohledávky, které se nedají přiřadit odběrateli — souhrn místo řádku v žebříčku.
	 * @return array<string, mixed>
	 */
	private function unassignedReceivables(bool $overdueOnly, string $today, string $amount, string $currency): array
	{
		$rows = $this->connection->rows(['i' => 'eshop_invoice'], [
			'invoices' => 'COUNT(*)',
			'outstanding' => "SUM($amount)",
			'overdue' => "SUM(IF(i.dueDate < '$today', $amount, 0))",
		])
			->where('i.canceled IS NULL')
			->where('i.paidDate IS NULL')
			->where('IFNULL(i.fk_customer, IFNULL(i.ic, i.subject)) IS NULL');

		if ($overdueOnly) {
			$rows->where("i.dueDate < '$today'");
		}

		$row = $rows->first();
		$invoices = $row !== null ? (int) $row->invoices : 0;

		if (!$invoices) {
			return [];
		}

		$outstanding = Money::format($row?->outstanding, $currency);

		return [
			'unassigned' => [
				'invoices' => $invoices,
				'outstanding' => $outstanding,
				'overdue' => Money::format($row?->overdue, $currency),
			],
			'note' => \sprintf(
				'Mimo seznam je %d faktur za %s bez identifikovatelného odběratele (není u nich zákazník, '
					. 'IČO ani jméno — typicky prodej přes marketplace). Nejsou to pohledávky za konkrétním '
					. 'zákazníkem, takže do žebříčku nepatří; kdo za nimi stojí, řekne až detail faktury.',
				$invoices,
				$outstanding !== null ? $outstanding['amount'] . ' ' . $currency : 'neznámou částku',
			),
		];
	}

	/**
	 * Kdo přestal odebírat: zákazníci, kteří mívali objednávky, ale poslední mají starší než
	 * `inactiveDays`. Vrací i to, co u nich shop dřív utržil — ať je vidět, o co jde.
	 * @param array<string, string> $params
	 */
	public function churn(array $params, Query $query): Response
	{
		unset($params);

		$inactiveDays = $query->int('inactiveDays', 90) ?? 90;
		$minOrders = $query->int('minOrders', 3) ?? 3;
		$currency = $this->config->getCurrency();
		$maxMonths = $this->config->getMaxWindowMonths();

		$rows = $this->connection->rows(['o' => 'eshop_order'], [
			'customerId' => 'p.fk_customer',
			'name' => 'IFNULL(IFNULL(c.company, c.fullname), p.fullname)',
			'orders' => 'COUNT(o.uuid)',
			'lastOrderOn' => 'MAX(o.createdTs)',
			'firstOrderOn' => 'MIN(o.createdTs)',
			'revenue' => 'SUM(' . OrderTotals::withVat('o', 'p') . ')',
		])
			->join(['p' => 'eshop_purchase'], 'p.uuid = o.fk_purchase', [], 'INNER')
			->join(['c' => 'eshop_customer'], 'c.uuid = p.fk_customer')
			->where('o.canceledTs IS NULL')
			->where('p.fk_customer IS NOT NULL')
			->where('o.createdTs >= DATE_SUB(NOW(), INTERVAL :apiMonths MONTH)', ['apiMonths' => $maxMonths])
			->setGroupBy(['p.fk_customer'], 'COUNT(o.uuid) >= :apiMin AND MAX(o.createdTs) < DATE_SUB(NOW(), INTERVAL :apiDays DAY)', [
				'apiMin' => $minOrders,
				'apiDays' => $inactiveDays,
			])
			->orderBy(['revenue' => 'DESC'])
			->setTake($query->limit());

		$items = [];

		foreach ($rows as $row) {
			$last = Dates::date($row->lastOrderOn);

			$items[] = [
				'customerId' => $row->customerId,
				'name' => $row->name,
				'orders' => (int) $row->orders,
				'revenue' => Money::format($row->revenue, $currency),
				'firstOrderOn' => Dates::date($row->firstOrderOn),
				'lastOrderOn' => $last,
				'daysSinceLastOrder' => $last !== null ? (int) (new \DateTimeImmutable($last))->diff(new \DateTimeImmutable('today'))->format('%a') : null,
			];
		}

		return Response::list($items, null);
	}

	/**
	 * Co se prodává a dochází. Spojuje prodejnost za období se stavem skladu a počítá
	 * **pokrytí ve dnech** — kolik dní vydrží zásoba při dosavadním tempu prodeje.
	 *
	 * Bez parametru `store` se sčítají všechny sklady včetně dodavatelských; na otázku
	 * „musíme objednat?" se ptej na vlastní sklad (`store=<kód>`, seznam dá /v1/meta/codebooks).
	 * @param array<string, string> $params
	 */
	public function replenishment(array $params, Query $query): Response
	{
		unset($params);

		[$from, $to] = $query->window('from', 'to');
		$coverageLimit = $query->int('maxCoverageDays', 30) ?? 30;
		$store = $query->string('store');
		$currency = $this->config->getCurrency();

		$days = \max(1, (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%a'));
		$sales = [];

		foreach ($this->loadItemSales($from, $to) as $row) {
			$sales[$row->productId]['quantity'] = ($sales[$row->productId]['quantity'] ?? 0) + (int) $row->quantity;
			$sales[$row->productId]['revenue'] = ($sales[$row->productId]['revenue'] ?? 0.0) + (float) $row->revenue;
		}

		if (!$sales) {
			return Response::list([], null);
		}

		$productIds = \array_keys($sales);
		$stock = $this->loadStock($productIds, $store);
		$products = $this->repository(\Eshop\DB\Product::class)->many()->where('this.uuid', $productIds)->toArray();

		$items = [];

		foreach ($sales as $productId => $sold) {
			$product = $products[$productId] ?? null;

			if ($product === null) {
				continue;
			}

			$available = $stock[$productId] ?? 0;
			$perDay = $sold['quantity'] / $days;
			$coverage = $perDay > 0 ? (int) \floor($available / $perDay) : null;

			if ($coverage !== null && $coverage > $coverageLimit) {
				continue;
			}

			$items[] = [
				'productId' => $productId,
				'code' => $product->getFullCode(),
				'name' => $product->name,
				'sold' => $sold['quantity'],
				'revenue' => Money::format($sold['revenue'], $currency),
				'perDay' => \round($perDay, 2),
				'available' => $available,
				'coverageDays' => $coverage,
				'suggestedOrder' => $perDay > 0 ? (int) \max(0, \ceil($perDay * $coverageLimit) - $available) : 0,
			];
		}

		\usort($items, static fn (array $a, array $b): int => ($a['coverageDays'] ?? \PHP_INT_MAX) <=> ($b['coverageDays'] ?? \PHP_INT_MAX));

		return Response::list(\array_slice($items, 0, $query->limit()), null);
	}

	/**
	 * Co čeká na expedici a jak dlouho. Přijaté a nedokončené objednávky se stářím —
	 * na otázku „co nám leží" a „co je nejstarší".
	 * @param array<string, string> $params
	 */
	public function fulfillment(array $params, Query $query): Response
	{
		unset($params);

		$currency = $this->config->getCurrency();
		$olderThan = $query->int('olderThanDays', 0) ?? 0;

		$rows = $this->connection->rows(['o' => 'eshop_order'], [
			'id' => 'o.uuid',
			'number' => 'o.code',
			'customer' => 'IFNULL(IFNULL(c.company, c.fullname), p.fullname)',
			'createdTs' => 'o.createdTs',
			'receivedTs' => 'o.receivedTs',
			'desiredShippingDate' => 'p.desiredShippingDate',
			'ageDays' => 'DATEDIFF(NOW(), o.createdTs)',
			'total' => OrderTotals::withVat('o', 'p'),
			'packages' => '(SELECT COUNT(*) FROM eshop_package pkg WHERE pkg.fk_order = o.uuid)',
			'exportedTs' => 'o.exportedTs',
		])
			->join(['p' => 'eshop_purchase'], 'p.uuid = o.fk_purchase', [], 'INNER')
			->join(['c' => 'eshop_customer'], 'c.uuid = p.fk_customer')
			->where('o.canceledTs IS NULL')
			->where('o.completedTs IS NULL')
			->where('DATEDIFF(NOW(), o.createdTs) >= :apiAge', ['apiAge' => $olderThan])
			->orderBy(['o.createdTs' => 'ASC'])
			->setTake($query->limit());

		$items = [];

		foreach ($rows as $row) {
			$items[] = [
				'orderId' => $row->id,
				'number' => $row->number,
				'customer' => $row->customer,
				'createdAt' => Dates::dateTime($row->createdTs, $this->config->getTimezone()),
				'receivedAt' => Dates::dateTime($row->receivedTs, $this->config->getTimezone()),
				'desiredShippingDate' => Dates::date($row->desiredShippingDate),
				'ageDays' => (int) $row->ageDays,
				'total' => Money::format($row->total, $currency),
				'packages' => (int) $row->packages,
				'exported' => $row->exportedTs !== null,
			];
		}

		return Response::list($items, null);
	}

	/**
	 * Produkty podle hodnocení — hledá se hlavně to špatné, proto výchozí řazení od nejhoršího.
	 * @param array<string, string> $params
	 */
	public function reviews(array $params, Query $query): Response
	{
		unset($params);

		$suffix = $this->connection->getMutationSuffix();
		$minCount = $query->int('minCount', 3) ?? 3;
		$maxScore = $query->string('maxScore');

		$rows = $this->connection->rows(['r' => 'eshop_review'], [
			'productId' => 'r.fk_product',
			'name' => "MAX(p.name$suffix)",
			'code' => 'MAX(p.code)',
			'reviews' => 'COUNT(*)',
			'score' => 'AVG(r.score)',
			'lastAt' => 'MAX(r.createdTs)',
		])
			->join(['p' => 'eshop_product'], 'p.uuid = r.fk_product', [], 'INNER')
			->where('r.score IS NOT NULL')
			->setGroupBy(['r.fk_product'], 'COUNT(*) >= :apiMin', ['apiMin' => $minCount])
			->orderBy(['score' => 'ASC'])
			->setTake($query->limit());

		if ($maxScore !== null) {
			$rows->having('AVG(r.score) <= :apiScore', ['apiScore' => (float) $maxScore]);
		}

		$items = [];

		foreach ($rows as $row) {
			$items[] = [
				'productId' => $row->productId,
				'code' => $row->code,
				'name' => $row->name,
				'reviews' => (int) $row->reviews,
				'score' => \round((float) $row->score, 2),
				'lastReviewAt' => Dates::dateTime($row->lastAt, $this->config->getTimezone()),
			];
		}

		return $this->withReviewsNote(Response::list($items, null));
	}

	/**
	 * Jak dopadly importy od dodavatelů. Na „proč nemá produkt novou cenu" je tohle první
	 * místo, kam se dívat.
	 * @param array<string, string> $params
	 */
	public function imports(array $params, Query $query): Response
	{
		unset($params);

		$rows = $this->connection->rows(['i' => 'eshop_importresult'], [
			'id' => 'i.uuid',
			'supplier' => 's.name',
			'supplierCode' => 's.code',
			'status' => 'i.status',
			'type' => 'i.type',
			'startedTs' => 'i.startedTs',
			'finishedTs' => 'i.finishedTs',
			'inserted' => 'i.insertedCount',
			'updated' => 'i.updatedCount',
			'skipped' => 'i.skippedCount',
			'imageErrors' => 'i.imageErrorCount',
			'error' => 'i.errorMessage',
		])
			->join(['s' => 'eshop_supplier'], 's.uuid = i.fk_supplier')
			->orderBy(['i.startedTs' => 'DESC'])
			->setTake($query->limit());

		if ($supplier = $query->string('supplier')) {
			$rows->where('s.code = :apiSupplier OR s.uuid = :apiSupplier OR s.name = :apiSupplier', ['apiSupplier' => $supplier]);
		}

		if ($status = $query->string('status')) {
			$rows->where('i.status', $status);
		}

		$items = [];

		foreach ($rows as $row) {
			$items[] = [
				'id' => $row->id,
				'supplier' => $row->supplier,
				'supplierCode' => $row->supplierCode,
				'status' => $row->status,
				'type' => $row->type,
				'startedAt' => Dates::dateTime($row->startedTs, $this->config->getTimezone()),
				'finishedAt' => Dates::dateTime($row->finishedTs, $this->config->getTimezone()),
				'inserted' => (int) $row->inserted,
				'updated' => (int) $row->updated,
				'skipped' => (int) $row->skipped,
				'imageErrors' => (int) $row->imageErrors,
				'error' => $row->error ?: null,
			];
		}

		return Response::list($items, null);
	}

	/**
	 * Zdraví katalogu — kolik produktů je rozbitých a čím.
	 *
	 * Je to diagnostika viditelnosti zvednutá na celý katalog: místo proklikávání produkt
	 * po produktu jeden přehled „co opravit", ke každé kategorii problému pár ukázek.
	 * @param array<string, string> $params
	 */
	public function catalogHealth(array $params, Query $query): Response
	{
		unset($params);

		$suffix = $this->connection->getMutationSuffix();
		$samples = \min($query->int('samples', 5) ?? 5, 20);
		$pricelists = $this->codebooks->getDefaultPricelists();
		// pole se do surového výrazu navázat nedá, hodnoty se citují rovnou (viz Sql::inList)
		$pricelistCondition = $pricelists
			? 'this.uuid NOT IN (SELECT pr.fk_product FROM eshop_price pr WHERE ' . $this->priceNotHidden('pr') . ' AND pr.fk_pricelist IN ('
				. Sql::inList($this->connection, $pricelists) . '))'
			: null;

		$checks = [
			'no-visibility-list' => [
				'detail' => 'Není v žádném viditelnostním seznamu — katalog ho nemá kde vzít.',
				'where' => 'this.uuid NOT IN (SELECT v.fk_product FROM eshop_visibilitylistitem v)',
			],
			'hidden-everywhere' => [
				'detail' => 'Je skrytý ve všech viditelnostech, ve kterých je.',
				'where' => 'this.uuid IN (SELECT v.fk_product FROM eshop_visibilitylistitem v
					GROUP BY v.fk_product HAVING MIN(v.hidden) = 1)',
			],
			'no-category' => [
				'detail' => 'Není v žádné kategorii — z menu se na něj nedá doklikat.',
				'where' => 'this.uuid NOT IN (SELECT nxn.fk_product FROM eshop_product_nxn_eshop_category nxn)',
			],
			'no-image' => [
				'detail' => 'Nemá nastavený obrázek.',
				'where' => "this.imageFileName IS NULL OR this.imageFileName = ''",
			],
			'no-price' => [
				'detail' => 'Nemá cenu ve veřejném ceníku — nepřihlášený návštěvník ho neuvidí.',
				'where' => $pricelistCondition,
			],
			'no-ean' => [
				'detail' => 'Nemá EAN — vadí to feedům a párování s dodavateli.',
				'where' => "this.ean IS NULL OR this.ean = ''",
			],
		];

		$items = [];

		foreach ($checks as $code => $check) {
			if ($check['where'] === null) {
				continue;
			}

			// dvakrát nová kolekce schválně: StORM nedovolí měnit tu, ze které se už četlo
			$build = fn (): \StORM\Collection => $this->repository(\Eshop\DB\Product::class)->many()
				->where($this->productNotDeleted())
				->where($check['where']);

			$count = $build()->count();
			$examples = [];

			foreach ($build()->orderBy(['this.code' => 'ASC'])->setTake($samples) as $product) {
				$examples[] = ['id' => $product->getPK(), 'code' => $product->getFullCode(), 'name' => $product->name];
			}

			$items[] = [
				'check' => $code,
				'products' => $count,
				'detail' => $check['detail'],
				'examples' => $examples,
			];
		}

		unset($suffix);

		\usort($items, static fn (array $a, array $b): int => $b['products'] <=> $a['products']);

		return Response::list($items, null);
	}

	/**
	 * Doklady, které nemají protějšek: objednávky bez faktury, faktury bez objednávky
	 * a objednávky nezaexportované do ERP.
	 * @param array<string, string> $params
	 */
	public function unlinked(array $params, Query $query): Response
	{
		unset($params);

		[$from, $to] = $query->window('from', 'to');
		$currency = $this->config->getCurrency();
		$limit = $query->limit();

		$ordersWithoutInvoice = $this->connection->rows(['o' => 'eshop_order'], [
			'id' => 'o.uuid',
			'number' => 'o.code',
			'createdTs' => 'o.createdTs',
			'total' => OrderTotals::withVat('o', 'p'),
		])
			->join(['p' => 'eshop_purchase'], 'p.uuid = o.fk_purchase', [], 'INNER')
			->where('o.createdTs >= :apiFrom AND o.createdTs <= :apiTo', ['apiFrom' => $from . ' 00:00:00', 'apiTo' => $to . ' 23:59:59'])
			->where('o.canceledTs IS NULL')
			->where('o.uuid NOT IN (SELECT nxn.fk_order FROM eshop_invoice_nxn_eshop_order nxn)')
			->orderBy(['o.createdTs' => 'DESC'])
			->setTake($limit);

		$invoicesWithoutOrder = $this->connection->rows(['i' => 'eshop_invoice'], [
			'id' => 'i.uuid',
			'number' => 'i.code',
			'exposed' => 'i.exposed',
			'total' => 'i.totalPriceVat',
		])
			->where('i.exposed >= :apiFrom AND i.exposed <= :apiTo', ['apiFrom' => $from, 'apiTo' => $to])
			->where('i.canceled IS NULL')
			->where('i.uuid NOT IN (SELECT nxn.fk_invoice FROM eshop_invoice_nxn_eshop_order nxn)')
			->orderBy(['i.exposed' => 'DESC'])
			->setTake($limit);

		$notExported = $this->connection->rows(['o' => 'eshop_order'], [
			'id' => 'o.uuid',
			'number' => 'o.code',
			'createdTs' => 'o.createdTs',
			'total' => OrderTotals::withVat('o', 'p'),
		])
			->join(['p' => 'eshop_purchase'], 'p.uuid = o.fk_purchase', [], 'INNER')
			->where('o.createdTs >= :apiFrom AND o.createdTs <= :apiTo', ['apiFrom' => $from . ' 00:00:00', 'apiTo' => $to . ' 23:59:59'])
			->where('o.canceledTs IS NULL')
			->where('o.exportedTs IS NULL')
			->orderBy(['o.createdTs' => 'DESC'])
			->setTake($limit);

		return new Response([
			'from' => $from,
			'to' => $to,
			'ordersWithoutInvoice' => $this->documents($ordersWithoutInvoice, 'createdTs', $currency),
			'invoicesWithoutOrder' => $this->documents($invoicesWithoutOrder, 'exposed', $currency),
			'ordersNotExported' => $this->documents($notExported, 'createdTs', $currency),
		]);
	}

	/**
	 * Košíky, které nedošly k objednávce.
	 *
	 * Ve výchozím stavu jen počet — ten se spočítá nad tabulkou košíků. Hodnotu a nejčastější
	 * položky si musí klient vyžádat (`withItems=true`), protože to znamená projít všechny
	 * položky košíků: eshop nemá index na `eshop_cart.createdTs`, takže je to na velkém shopu
	 * dotaz na desítky sekund.
	 *
	 * A pozor na výklad čísla: košíky zakládají i nepřihlášení návštěvníci a roboti, takže
	 * `value` je horní odhad „co se nedotáhlo", ne ušlá tržba. Proto je v odpovědi i `note`.
	 * @param array<string, string> $params
	 */
	public function abandonedCarts(array $params, Query $query): Response
	{
		unset($params);

		[$from, $to] = $query->window('from', 'to');
		$currency = $this->config->getCurrency();
		$withItems = $query->bool('withItems', false);

		$carts = (int) $this->connection->rows(['c' => 'eshop_cart'], ['carts' => 'COUNT(*)'])
			->where('c.fk_purchase IS NULL')
			->where('c.createdTs >= :apiFrom AND c.createdTs <= :apiTo', ['apiFrom' => $from . ' 00:00:00', 'apiTo' => $to . ' 23:59:59'])
			->firstValue('carts');

		$response = [
			'from' => $from,
			'to' => $to,
			'carts' => $carts,
			'note' => 'Košíky zakládají i nepřihlášení návštěvníci a roboti — ber to jako horní odhad, ne jako ušlou tržbu.',
		];

		if (!$withItems) {
			$response['items'] = null;
			$response['value'] = null;
			$response['topProducts'] = null;
			$response['hint'] = 'Hodnotu a nejčastější položky vrátí withItems=true; je to dotaz přes všechny položky košíků a trvá déle.';

			return new Response($response);
		}

		$totals = $this->connection->rows(['c' => 'eshop_cart'], [
			'items' => 'COUNT(ci.uuid)',
			'value' => 'SUM(ci.priceVat * ci.amount)',
		])
			->join(['ci' => 'eshop_cartitem'], 'ci.fk_cart = c.uuid', [], 'INNER')
			->where('c.fk_purchase IS NULL')
			->where('c.createdTs >= :apiFrom AND c.createdTs <= :apiTo', ['apiFrom' => $from . ' 00:00:00', 'apiTo' => $to . ' 23:59:59'])
			->fetch();

		$topRows = $this->connection->rows(['c' => 'eshop_cart'], [
			'productId' => 'ci.fk_product',
			'code' => 'MAX(ci.productCode)',
			'name' => 'MAX(ci.productName' . $this->connection->getMutationSuffix() . ')',
			'quantity' => 'SUM(ci.amount)',
			'carts' => 'COUNT(DISTINCT c.uuid)',
		])
			->join(['ci' => 'eshop_cartitem'], 'ci.fk_cart = c.uuid', [], 'INNER')
			->where('c.fk_purchase IS NULL')
			->where('c.createdTs >= :apiFrom AND c.createdTs <= :apiTo', ['apiFrom' => $from . ' 00:00:00', 'apiTo' => $to . ' 23:59:59'])
			->where('ci.fk_product IS NOT NULL')
			->setGroupBy(['ci.fk_product'])
			->orderBy(['carts' => 'DESC'])
			->setTake($query->limit());

		$top = [];

		foreach ($topRows as $topRow) {
			$top[] = [
				'productId' => $topRow->productId,
				'code' => $topRow->code,
				'name' => $topRow->name,
				'quantity' => (int) $topRow->quantity,
				'carts' => (int) $topRow->carts,
			];
		}

		$response['items'] = (int) ($totals->items ?? 0);
		$response['value'] = Money::format($totals->value ?? 0, $currency);
		$response['topProducts'] = $top;

		return new Response($response);
	}

	/**
	 * Nákupy objednávek v okně. Reporty nad položkami košíku se počítají ve dvou krocích:
	 * nejdřív objednávky (pár tisíc řádků), pak položky jejich košíků. Jedním spojeným
	 * dotazem to nejde — eshop nemá index na `eshop_order.createdTs`, takže si databáze
	 * vybere jako výchozí tabulku tříapůlmilionový `eshop_cartitem` a report běží minuty.
	 * @return array<string>
	 */
	private function purchasesInWindow(string $from, string $to): array
	{
		$rows = $this->connection->rows(['o' => 'eshop_order'], ['purchase' => 'o.fk_purchase'])
			->where('o.createdTs >= :apiFrom AND o.createdTs <= :apiTo', ['apiFrom' => $from . ' 00:00:00', 'apiTo' => $to . ' 23:59:59'])
			->where('o.canceledTs IS NULL')
			->where('o.fk_purchase IS NOT NULL');

		$ids = [];

		foreach ($rows as $row) {
			$ids[$row->purchase] = $row->purchase;
		}

		return \array_values($ids);
	}

	/**
	 * @param array<string>|null $purchases
	 * @return array<array<string, mixed>>
	 */
	private function salesByPeriod(string $groupBy, string $from, string $to, ?array $purchases = null): array
	{
		if ($purchases === []) {
			return [];
		}

		$key = match ($groupBy) {
			'month' => "DATE_FORMAT(o.createdTs, '%Y-%m')",
			'week' => "DATE_FORMAT(o.createdTs, '%x-W%v')",
			'day' => 'DATE(o.createdTs)',
			'customer' => "IFNULL(IFNULL(cu.company, cu.fullname), 'bez zákazníka')",
			default => "IFNULL(m.fullname, 'bez obchodníka')",
		};

		$currency = $this->config->getCurrency();

		$rows = $this->connection->rows(['o' => 'eshop_order'], [
			'reportKey' => $key,
			'orders' => 'COUNT(o.uuid)',
			'revenue' => 'SUM(' . OrderTotals::withVat('o', 'p') . ')',
			'revenueWithoutVat' => 'SUM(' . OrderTotals::withoutVat('o', 'p') . ')',
		])
			->join(['p' => 'eshop_purchase'], 'p.uuid = o.fk_purchase', [], 'INNER')
			->where('o.createdTs >= :apiFrom AND o.createdTs <= :apiTo', ['apiFrom' => $from . ' 00:00:00', 'apiTo' => $to . ' 23:59:59'])
			->where('o.canceledTs IS NULL')
			->setGroupBy(['reportKey'])
			->orderBy(['reportKey' => 'ASC']);

		if ($groupBy === 'merchant') {
			$rows->join(['m' => 'eshop_merchant'], 'm.uuid = p.fk_merchant');
		}

		if ($groupBy === 'customer') {
			$rows->join(['cu' => 'eshop_customer'], 'cu.uuid = p.fk_customer');
		}

		if ($purchases !== null) {
			$rows->where('o.fk_purchase', $purchases);
		}

		$items = [];

		foreach ($rows as $row) {
			$items[] = [
				'key' => (string) $row->reportKey,
				'orders' => (int) $row->orders,
				'revenue' => Money::format($row->revenue, $currency),
				'revenueWithoutVat' => Money::format($row->revenueWithoutVat, $currency),
			];
		}

		return $items;
	}

	/**
	 * Tržby po kategoriích nebo výrobcích. Počítají se z položek košíku, ne z celkových cen
	 * objednávek — dopravu a platbu do kategorie zařadit nejde. Produkt se počítá do jedné
	 * kategorie (té s nejnižším UUID), aby se stejná položka nesečetla vícekrát; `orders` je
	 * počet různých objednávek, ve kterých se kategorie objevila.
	 * @param array<string>|null $purchases
	 * @return array<array<string, mixed>>
	 */
	private function salesByItems(string $from, string $to, string $dimension, ?array $purchases = null): array
	{
		$currency = $this->config->getCurrency();
		$rows = $this->loadItemSales($from, $to, $purchases);

		if (!$rows) {
			return [];
		}

		$productIds = [];

		foreach ($rows as $row) {
			$productIds[$row->productId] = $row->productId;
		}

		$labels = $dimension === 'category'
			? $this->loadPrimaryCategories(\array_values($productIds))
			: $this->loadProducers(\array_values($productIds));

		$fallback = $dimension === 'category' ? 'bez kategorie' : 'bez výrobce';
		$totals = [];

		foreach ($rows as $row) {
			$key = $labels[$row->productId] ?? $fallback;

			$totals[$key]['orders'][$row->purchase] = true;
			$totals[$key]['revenue'] = ($totals[$key]['revenue'] ?? 0.0) + (float) $row->revenue;
			$totals[$key]['revenueWithoutVat'] = ($totals[$key]['revenueWithoutVat'] ?? 0.0) + (float) $row->revenueWithoutVat;
		}

		\uasort($totals, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

		$items = [];

		foreach ($totals as $key => $total) {
			$items[] = [
				'key' => (string) $key,
				'orders' => \count($total['orders']),
				'revenue' => Money::format($total['revenue'], $currency),
				'revenueWithoutVat' => Money::format($total['revenueWithoutVat'], $currency),
			];
		}

		return $items;
	}

	/**
	 * Prodeje po položkách za okno — společný základ reportů, které jdou pod úroveň objednávky.
	 * @param array<string>|null $purchases
	 * @return array<object>
	 */
	private function loadItemSales(string $from, string $to, ?array $purchases = null): array
	{
		$purchaseIds = $purchases ?? $this->purchasesInWindow($from, $to);

		if (!$purchaseIds) {
			return [];
		}

		$rows = $this->connection->rows(['c' => 'eshop_cart'], [
			'purchase' => 'c.fk_purchase',
			'productId' => 'ci.fk_product',
			'quantity' => 'SUM(ci.amount)',
			'revenue' => 'SUM(ci.priceVat * ci.amount)',
			'revenueWithoutVat' => 'SUM(ci.price * ci.amount)',
		])
			->join(['ci' => 'eshop_cartitem'], 'ci.fk_cart = c.uuid', [], 'INNER')
			->where('c.fk_purchase', $purchaseIds)
			->where('ci.fk_product IS NOT NULL')
			->setGroupBy(['c.fk_purchase', 'ci.fk_product']);

		$items = [];

		foreach ($rows as $row) {
			$items[] = $row;
		}

		return $items;
	}

	/**
	 * Hlavní kategorie produktů — jedna na produkt, ať se položka nezapočítá vícekrát.
	 * @param array<string> $productIds
	 * @return array<string, string> id produktu => název kategorie
	 */
	private function loadPrimaryCategories(array $productIds): array
	{
		if (!$productIds) {
			return [];
		}

		$suffix = $this->connection->getMutationSuffix();

		$rows = $this->connection->rows(['nxn' => 'eshop_product_nxn_eshop_category'], [
			'product' => 'nxn.fk_product',
			'category' => 'MIN(nxn.fk_category)',
		])
			->where('nxn.fk_product', $productIds)
			->setGroupBy(['nxn.fk_product']);

		$byProduct = [];
		$categoryIds = [];

		foreach ($rows as $row) {
			$byProduct[$row->product] = $row->category;
			$categoryIds[$row->category] = $row->category;
		}

		if (!$categoryIds) {
			return [];
		}

		$names = $this->connection->rows(['c' => 'eshop_category'], [
			'id' => 'c.uuid',
			'name' => "IFNULL(c.fullName$suffix, c.name$suffix)",
		])
			->where('c.uuid', \array_values($categoryIds));

		$byCategory = [];

		foreach ($names as $row) {
			$byCategory[$row->id] = (string) $row->name;
		}

		$map = [];

		foreach ($byProduct as $product => $category) {
			if (!isset($byCategory[$category])) {
				continue;
			}

			$map[$product] = $byCategory[$category];
		}

		return $map;
	}

	/**
	 * @param array<string> $productIds
	 * @return array<string, string> id produktu => výrobce
	 */
	private function loadProducers(array $productIds): array
	{
		if (!$productIds) {
			return [];
		}

		$suffix = $this->connection->getMutationSuffix();

		$rows = $this->connection->rows(['p' => 'eshop_product'], [
			'product' => 'p.uuid',
			'name' => "pr.name$suffix",
		])
			->join(['pr' => 'eshop_producer'], 'pr.uuid = p.fk_producer', [], 'INNER')
			->where('p.uuid', $productIds);

		$map = [];

		foreach ($rows as $row) {
			if ($row->name === null) {
				continue;
			}

			$map[$row->product] = (string) $row->name;
		}

		return $map;
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private function comparisonWindow(Query $query, string $from, string $to): array
	{
		$compareFrom = $query->date('compareFrom');
		$compareTo = $query->date('compareTo');

		if ($compareFrom !== null && $compareTo !== null) {
			return [$compareFrom, $compareTo];
		}

		$start = new \DateTimeImmutable($from);
		$end = new \DateTimeImmutable($to);
		$length = (int) $start->diff($end)->format('%a') + 1;

		return [
			$start->modify("-$length days")->format('Y-m-d'),
			$start->modify('-1 day')->format('Y-m-d'),
		];
	}

	/**
	 * @return array<string, object>
	 */
	private function loadCustomerTotals(string $from, string $to): array
	{
		$rows = $this->connection->rows(['o' => 'eshop_order'], [
			'customerId' => 'p.fk_customer',
			'orders' => 'COUNT(o.uuid)',
			'revenue' => 'SUM(' . OrderTotals::withVat('o', 'p') . ')',
			'lastOrderOn' => 'MAX(o.createdTs)',
		])
			->join(['p' => 'eshop_purchase'], 'p.uuid = o.fk_purchase', [], 'INNER')
			->where('o.createdTs >= :apiFrom AND o.createdTs <= :apiTo', ['apiFrom' => $from . ' 00:00:00', 'apiTo' => $to . ' 23:59:59'])
			->where('o.canceledTs IS NULL')
			->where('p.fk_customer IS NOT NULL')
			->setGroupBy(['p.fk_customer']);

		$map = [];

		foreach ($rows as $row) {
			$map[$row->customerId] = $row;
		}

		return $map;
	}

	/**
	 * @param array<string> $ids
	 * @return array<string, string>
	 */
	private function loadCustomerNames(array $ids): array
	{
		if (!$ids) {
			return [];
		}

		$rows = $this->connection->rows(['c' => 'eshop_customer'], [
			'id' => 'c.uuid',
			'name' => 'IFNULL(c.company, c.fullname)',
		])->where('c.uuid', $ids);

		$map = [];

		foreach ($rows as $row) {
			$map[$row->id] = (string) $row->name;
		}

		return $map;
	}

	/**
	 * Skladová dostupnost, volitelně jen v jednom skladu.
	 * @param array<string> $productIds
	 * @return array<string, int>
	 */
	private function loadStock(array $productIds, ?string $store): array
	{
		$rows = $this->connection->rows(['a' => 'eshop_amount'], [
			'id' => 'a.fk_product',
			'available' => 'SUM(a.inStock)',
		])
			->where('a.fk_product', $productIds)
			->setGroupBy(['a.fk_product']);

		if ($store !== null) {
			$rows->join(['s' => 'eshop_store'], 's.uuid = a.fk_store', [], 'INNER')
				->where('s.code = :apiStore OR s.uuid = :apiStore', ['apiStore' => $store]);
		}

		$map = [];

		foreach ($rows as $row) {
			$map[$row->id] = (int) $row->available;
		}

		return $map;
	}

	/**
	 * @param iterable<object> $rows
	 * @return array<array<string, mixed>>
	 */
	private function documents(iterable $rows, string $dateColumn, string $currency): array
	{
		$items = [];

		foreach ($rows as $row) {
			$items[] = [
				'id' => $row->id,
				'number' => $row->number,
				'date' => Dates::date($row->{$dateColumn}),
				'total' => Money::format($row->total, $currency),
			];
		}

		return $items;
	}
}
