<?php

declare(strict_types=1);

namespace DoryoApi\Endpoint;

use DoryoApi\Codebooks;
use DoryoApi\Config;
use DoryoApi\Http\Query;
use DoryoApi\Http\Response;
use DoryoApi\Mapper\CustomerMapper;
use DoryoApi\Support\Dates;
use DoryoApi\Support\Money;
use DoryoApi\Support\OrderTotals;
use Eshop\DB\Address;
use Eshop\DB\Customer;
use StORM\DIConnection;

/**
 * Zákazníci a jejich souhrny. Objednávky a faktury zákazníka obsluhují endpointy
 * objednávek a faktur — je to tentýž filtr, jen jinou cestou, takže se logika nekopíruje.
 */
final class CustomersEndpoint extends BaseEndpoint
{
	public function __construct(
		DIConnection $connection,
		Config $config,
		Codebooks $codebooks,
		private CustomerMapper $mapper,
		private OrdersEndpoint $orders,
		private InvoicesEndpoint $invoices,
	) {
		parent::__construct($connection, $config, $codebooks);
	}

	/**
	 * @return array<string, string>
	 */
	public function getRoutes(): array
	{
		return [
			'v1/customers' => 'list',
			'v1/customers/{id}' => 'detail',
			'v1/customers/{id}/orders' => 'orders',
			'v1/customers/{id}/invoices' => 'invoices',
			'v1/customers/{id}/summary' => 'summary',
			'v1/customers/{id}/products' => 'products',
		];
	}

	/**
	 * @param array<string, string> $params
	 */
	public function list(array $params, Query $query): Response
	{
		unset($params);

		$collection = $this->repository(Customer::class)->many();

		if ($registrationNo = $query->string('registrationNo')) {
			$collection->where('this.ic', $registrationNo);
		}

		if ($email = $query->string('email')) {
			$collection->where('this.email', $email);
		}

		if ($merchantId = $query->string('merchantId')) {
			$collection->where('this.fk_merchant', $merchantId);
		}

		if ($since = $query->dateTime('since')) {
			$collection->where('this.createdTs >= :apiSince', ['apiSince' => $since]);
		}

		$this->applyFulltext($collection, $query, ['this.fullname', 'this.company', 'this.email', 'this.ic', 'this.phone']);

		$page = $this->paginate($collection->orderBy(['this.createdTs' => 'DESC']), $query);
		$extras = $this->loadExtras($page['rows']);

		$items = [];

		foreach ($page['rows'] as $id => $customer) {
			$items[] = $this->mapper->map($customer, $extras[$id]);
		}

		return Response::list($items, $page['nextCursor']);
	}

	/**
	 * @param array<string, string> $params
	 */
	public function detail(array $params, Query $query): Response
	{
		unset($query);

		/** @var \Eshop\DB\Customer $customer */
		$customer = $this->one(Customer::class, $params['id'], 'Zákazník');
		$extras = $this->loadExtras([$customer->getPK() => $customer]);

		return new Response($this->mapper->map($customer, $extras[$customer->getPK()]));
	}

	/**
	 * @param array<string, string> $params
	 */
	public function orders(array $params, Query $query): Response
	{
		$this->one(Customer::class, $params['id'], 'Zákazník');

		return $this->orders->listFiltered($query, $params['id']);
	}

	/**
	 * @param array<string, string> $params
	 */
	public function invoices(array $params, Query $query): Response
	{
		$this->one(Customer::class, $params['id'], 'Zákazník');

		return $this->invoices->listFiltered($query, $params['id']);
	}

	/**
	 * @param array<string, string> $params
	 */
	public function summary(array $params, Query $query): Response
	{
		unset($query);

		$this->one(Customer::class, $params['id'], 'Zákazník');

		$rollup = $this->loadRollup([$params['id']])[$params['id']] ?? null;
		$unpaid = $this->loadUnpaid([$params['id']])[$params['id']] ?? null;
		$currency = $this->config->getCurrency();

		return new Response([
			'orders' => $rollup !== null ? (int) $rollup->orders : 0,
			'revenue' => Money::format($rollup->turnover ?? 0, $currency),
			'lastOrderOn' => Dates::date($rollup->lastOrderOn ?? null),
			'unpaidInvoices' => $unpaid !== null ? (int) $unpaid->invoices : 0,
			'outstanding' => Money::format($unpaid->outstanding ?? 0, $currency),
		]);
	}

	/**
	 * Co zákazník bere — jeho položky za období, od nejvíc utržených.
	 *
	 * Na tohle se ptá obchodník před nabídkou („co mu naceníme?") i před telefonátem
	 * („co přestal brát?"), a z objednávek po jedné to model složit nemá jak.
	 * @param array<string, string> $params
	 */
	public function products(array $params, Query $query): Response
	{
		$this->one(Customer::class, $params['id'], 'Zákazník');

		[$from, $to] = $query->window('from', 'to');
		$suffix = $this->connection->getMutationSuffix();
		$currency = $this->config->getCurrency();

		$rows = $this->connection->rows(['o' => 'eshop_order'], [
			'productId' => 'ci.fk_product',
			'code' => 'MAX(ci.productCode)',
			'name' => "MAX(ci.productName$suffix)",
			'quantity' => 'SUM(ci.amount)',
			'revenue' => 'SUM(ci.priceVat * ci.amount)',
			'orders' => 'COUNT(DISTINCT o.uuid)',
			'lastOrderOn' => 'MAX(o.createdTs)',
		])
			->join(['p' => 'eshop_purchase'], 'p.uuid = o.fk_purchase', [], 'INNER')
			->join(['c' => 'eshop_cart'], 'c.fk_purchase = p.uuid', [], 'INNER')
			->join(['ci' => 'eshop_cartitem'], 'ci.fk_cart = c.uuid', [], 'INNER')
			->where('p.fk_customer', $params['id'])
			->where('o.canceledTs IS NULL')
			->where('o.createdTs >= :apiFrom AND o.createdTs <= :apiTo', ['apiFrom' => $from . ' 00:00:00', 'apiTo' => $to . ' 23:59:59'])
			->setGroupBy(['ci.fk_product'])
			->orderBy(['revenue' => 'DESC'])
			->setTake($query->limit());

		$items = [];

		foreach ($rows as $row) {
			$items[] = [
				'productId' => $row->productId,
				'code' => $row->code,
				'name' => $row->name,
				'quantity' => (int) $row->quantity,
				'orders' => (int) $row->orders,
				'revenue' => Money::format($row->revenue, $currency),
				'lastOrderOn' => Dates::date($row->lastOrderOn),
			];
		}

		return Response::list($items, null);
	}

	/**
	 * @param array<string, \Eshop\DB\Customer> $customers
	 * @return array<string, array<string, mixed>>
	 */
	private function loadExtras(array $customers): array
	{
		if (!$customers) {
			return [];
		}

		$ids = \array_keys($customers);
		$addresses = $this->fetchByIds(Address::class, $this->collectIds($customers, 'billAddress'));
		$pricelists = $this->loadPricelists($ids);
		$rollup = $this->loadRollup($ids);
		$groups = $this->loadGroups($customers);
		$merchants = $this->loadMerchantIds($ids);

		$extras = [];

		foreach ($customers as $id => $customer) {
			$row = $rollup[$id] ?? null;

			$extras[$id] = [
				'billAddress' => $addresses[(string) self::idValue($customer, 'billAddress')] ?? null,
				'groupName' => $groups[(string) self::idValue($customer, 'group')] ?? null,
				'merchantId' => $merchants[$id] ?? null,
				'pricelists' => $pricelists[$id] ?? [],
				'orderCount' => $row !== null ? (int) $row->orders : null,
				'lastOrderOn' => $row->lastOrderOn ?? null,
				'turnover' => $row !== null ? (float) $row->turnover : null,
			];
		}

		return $extras;
	}

	/**
	 * Počet objednávek, poslední objednávka a obrat — spočítané v SQL za celou stránku.
	 * Zrušené objednávky se nepočítají.
	 * @param array<string> $ids
	 * @return array<string, object>
	 */
	private function loadRollup(array $ids): array
	{
		$rows = $this->connection->rows(['o' => 'eshop_order'], [
			'id' => 'p.fk_customer',
			'orders' => 'COUNT(o.uuid)',
			'lastOrderOn' => 'MAX(o.createdTs)',
			'turnover' => 'SUM(' . OrderTotals::withVat('o', 'p') . ')',
		])
			->join(['p' => 'eshop_purchase'], 'p.uuid = o.fk_purchase', [], 'INNER')
			->where('p.fk_customer', $ids)
			->where('o.canceledTs IS NULL')
			->setGroupBy(['p.fk_customer']);

		$map = [];

		foreach ($rows as $row) {
			$map[$row->id] = $row;
		}

		return $map;
	}

	/**
	 * @param array<string> $ids
	 * @return array<string, object>
	 */
	private function loadUnpaid(array $ids): array
	{
		$tracked = $this->config->isInvoicePaymentTracked();
		$outstanding = $tracked ? '(i.totalPriceVat - IFNULL(i.paid, 0))' : 'i.totalPriceVat';

		$rows = $this->connection->rows(['i' => 'eshop_invoice'], [
			'id' => 'i.fk_customer',
			'invoices' => 'COUNT(i.uuid)',
			'outstanding' => "SUM($outstanding)",
		])
			->where('i.fk_customer', $ids)
			->where('i.canceled IS NULL')
			->where('i.paidDate IS NULL')
			->setGroupBy(['i.fk_customer']);

		$map = [];

		foreach ($rows as $row) {
			$map[$row->id] = $row;
		}

		return $map;
	}

	/**
	 * Obchodník přiřazený zákazníkovi. Ve verzích eshopu, kde tahle vazba na entitě není,
	 * se čte přímo sloupec — a když není ani ten, zůstane merchantId null.
	 * @param array<string> $ids
	 * @return array<string, string>
	 */
	private function loadMerchantIds(array $ids): array
	{
		try {
			$rows = $this->connection->rows(['c' => 'eshop_customer'], ['id' => 'c.uuid', 'merchant' => 'c.fk_merchant'])
				->where('c.uuid', $ids)
				->where('c.fk_merchant IS NOT NULL');

			$map = [];

			foreach ($rows as $row) {
				$map[$row->id] = $row->merchant;
			}

			return $map;
		} catch (\Throwable) {
			return [];
		}
	}

	/**
	 * @param array<string> $ids
	 * @return array<string, array<string>>
	 */
	private function loadPricelists(array $ids): array
	{
		$rows = $this->connection->rows(['nxn' => 'eshop_customer_nxn_eshop_pricelist'], [
			'id' => 'nxn.fk_customer',
			'name' => 'IFNULL(pl.code, pl.name)',
		])
			->join(['pl' => 'eshop_pricelist'], 'pl.uuid = nxn.fk_pricelist', [], 'INNER')
			->where('nxn.fk_customer', $ids);

		$map = [];

		foreach ($rows as $row) {
			$map[$row->id][] = $row->name;
		}

		return $map;
	}

	/**
	 * @param array<string, \Eshop\DB\Customer> $customers
	 * @return array<string, string>
	 */
	private function loadGroups(array $customers): array
	{
		$ids = $this->collectIds($customers, 'group');

		if (!$ids) {
			return [];
		}

		$rows = $this->connection->rows(['g' => 'eshop_customergroup'], ['id' => 'g.uuid', 'name' => 'g.name'])
			->where('g.uuid', $ids);

		$map = [];

		foreach ($rows as $row) {
			$map[$row->id] = $row->name;
		}

		return $map;
	}
}
