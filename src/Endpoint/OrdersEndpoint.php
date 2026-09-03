<?php

declare(strict_types=1);

namespace DoryoApi\Endpoint;

use DoryoApi\Codebooks;
use DoryoApi\Config;
use DoryoApi\Http\ApiException;
use DoryoApi\Http\Query;
use DoryoApi\Http\Response;
use DoryoApi\Mapper\ItemMapper;
use DoryoApi\Mapper\OrderMapper;
use DoryoApi\Support\Dates;
use DoryoApi\Support\OrderStates;
use DoryoApi\Support\OrderTotals;
use Eshop\DB\Address;
use Eshop\DB\CartItem;
use Eshop\DB\Order;
use Eshop\DB\Purchase;
use StORM\Collection;
use StORM\DIConnection;

/**
 * Objednávky. Seznam je bez položek (ty jsou v detailu) — jinak by jedno volání táhlo
 * desítky tisíc řádků košíků.
 */
final class OrdersEndpoint extends BaseEndpoint
{
	public function __construct(
		DIConnection $connection,
		Config $config,
		Codebooks $codebooks,
		private OrderMapper $mapper,
		private ItemMapper $itemMapper,
	) {
		parent::__construct($connection, $config, $codebooks);
	}

	/**
	 * @return array<string, string>
	 */
	public function getRoutes(): array
	{
		return [
			'v1/orders' => 'list',
			'v1/orders/by-number/{number}' => 'detailByNumber',
			'v1/orders/{id}' => 'detail',
			'v1/orders/{id}/history' => 'history',
			'v1/orders/{id}/shipments' => 'shipments',
		];
	}

	/**
	 * @param array<string, string> $params
	 */
	public function list(array $params, Query $query): Response
	{
		unset($params);

		return $this->listFiltered($query, null);
	}

	/**
	 * @param array<string, string> $params
	 */
	public function detail(array $params, Query $query): Response
	{
		unset($query);

		/** @var \Eshop\DB\Order $order */
		$order = $this->one(Order::class, $params['id'], 'Objednávka');
		$extras = $this->loadExtras([$order->getPK() => $order]);
		$extra = $extras[$order->getPK()];
		$extra['items'] = $this->loadItems($extra['purchase']);

		return new Response($this->mapper->map($order, $extra));
	}

	/**
	 * Detail podle čísla objednávky — to je to, co má člověk před sebou.
	 * @param array<string, string> $params
	 */
	public function detailByNumber(array $params, Query $query): Response
	{
		$id = $this->repository(Order::class)->many()->where('this.code', $params['number'])->firstValue('uuid');

		if (!$id) {
			throw ApiException::notFound('Objednávka číslo ' . $params['number'] . ' neexistuje.');
		}

		return $this->detail(['id' => (string) $id], $query);
	}

	/**
	 * Sdílí ho i /v1/customers/{id}/orders — filtr na zákazníka je jediný rozdíl.
	 */
	public function listFiltered(Query $query, ?string $customerId): Response
	{
		$collection = $this->baseCollection();

		[$from, $to] = $query->window('createdFrom', 'createdTo');
		$collection->where('this.createdTs >= :apiFrom AND this.createdTs <= :apiTo', [
			'apiFrom' => $from . ' 00:00:00',
			'apiTo' => $to . ' 23:59:59',
		]);

		if ($status = $query->string('status')) {
			$states = $this->config->getOrderStates()[$status] ?? null;

			if ($states === null) {
				throw ApiException::badRequest(\sprintf('Neznámý stav %s; povolené jsou %s.', $status, \implode(', ', \array_keys($this->config->getOrderStates()))));
			}

			$condition = OrderStates::conditions($states);

			$collection->where($condition ?? '1=0');
		}

		$customerId ??= $query->string('customerId');

		if ($customerId !== null) {
			$collection->where('purchase.fk_customer', $customerId);
		}

		if ($registrationNo = $query->string('registrationNo')) {
			$collection->where('purchase.ic', $registrationNo);
		}

		if ($merchantId = $query->string('merchantId')) {
			$collection->where('purchase.fk_merchant', $merchantId);
		}

		if ($since = $query->dateTime('since')) {
			$collection->where('this.createdTs >= :apiSince', ['apiSince' => $since]);
		}

		if ($shippingDate = $query->date('shippingDate')) {
			$collection->where('purchase.desiredShippingDate', $shippingDate);
		}

		$exported = $query->bool('exported');

		if ($exported !== null) {
			$collection->where('this.exportedTs IS ' . ($exported ? 'NOT NULL' : 'NULL'));
		}

		if ($query->bool('withoutInvoice', false)) {
			$collection->where('this.uuid NOT IN (SELECT nxn.fk_order FROM eshop_invoice_nxn_eshop_order nxn)');
		}

		$this->applyFulltext($collection, $query, ['this.code', 'purchase.fullname', 'purchase.email', 'purchase.ic']);

		$page = $this->paginate($collection->orderBy(['this.createdTs' => 'DESC']), $query);
		$extras = $this->loadExtras($page['rows']);

		$items = [];

		foreach ($page['rows'] as $id => $order) {
			$items[] = $this->mapper->map($order, $extras[$id]);
		}

		return Response::list($items, $page['nextCursor']);
	}

	/**
	 * Co se s objednávkou dělo — kdo ji kdy změnil a na co.
	 *
	 * Eshop si historii vede sám (přes sto tisíc záznamů), jen ji nikdo nevystavuje. Na otázku
	 * „proč je ta objednávka pozastavená" nebo „kdo změnil dopravu" je to jediný zdroj pravdy;
	 * bez toho musí člověk do adminu.
	 * @param array<string, string> $params
	 */
	public function history(array $params, Query $query): Response
	{
		$this->one(Order::class, $params['id'], 'Objednávka');

		$collection = $this->connection->rows(['l' => 'eshop_orderlogitem'], [
			'id' => 'l.uuid',
			'operation' => 'l.operation',
			'message' => 'l.message',
			'createdTs' => 'l.createdTs',
			'administrator' => 'l.administratorFullName',
		])
			->where('l.fk_order', $params['id'])
			->orderBy(['l.createdTs' => 'ASC'])
			->setTake($query->limit());

		$items = [];

		foreach ($collection as $row) {
			$items[] = [
				'id' => $row->id,
				'at' => Dates::dateTime($row->createdTs, $this->config->getTimezone()),
				'operation' => $row->operation,
				'message' => $row->message,
				'by' => $row->administrator ?: null,
			];
		}

		return Response::list($items, null);
	}

	/**
	 * Balíky a doprava objednávky — kde zásilka je a co v ní bylo.
	 * @param array<string, string> $params
	 */
	public function shipments(array $params, Query $query): Response
	{
		unset($query);

		$this->one(Order::class, $params['id'], 'Objednávka');
		$suffix = $this->connection->getMutationSuffix();

		$deliveries = $this->connection->rows(['d' => 'eshop_delivery'], [
			'id' => 'd.uuid',
			'type' => "d.typeName$suffix",
			'trackingLink' => 'dt.trackingLink',
			'code' => "COALESCE(NULLIF(d.zasilkovnaCode, ''), NULLIF(d.dpdCode, ''), NULLIF(d.pplCode, ''), NULLIF(d.externalId, ''))",
			'shippedTs' => 'd.shippedTs',
			'shippingDate' => 'd.shippingDate',
			'price' => 'd.price',
		])
			->join(['dt' => 'eshop_deliverytype'], 'dt.uuid = d.fk_type')
			->where('d.fk_order', $params['id']);

		$packages = $this->loadPackages($params['id'], $suffix);
		$items = [];

		foreach ($deliveries as $row) {
			$items[] = [
				'deliveryId' => $row->id,
				'type' => $row->type,
				'trackingCode' => $row->code ?: null,
				'trackingUrl' => self::trackingUrl($row),
				'shippedAt' => Dates::dateTime($row->shippedTs, $this->config->getTimezone()),
				'shippingDate' => Dates::date($row->shippingDate),
				'packages' => $packages[$row->id] ?? [],
			];
		}

		return Response::list($items, null);
	}

	/**
	 * @return \StORM\Collection<\Eshop\DB\Order>
	 */
	private function baseCollection(): Collection
	{
		return $this->repository(Order::class)->many()
			->join(['purchase' => 'eshop_purchase'], 'purchase.uuid = this.fk_purchase', [], 'INNER');
	}

	/**
	 * Dávkově dotáhne všechno, co mapper k objednávkám potřebuje: nákupy, adresy, ceny,
	 * platby, dopravy a faktury. Pět dotazů na stránku bez ohledu na to, kolik má položek.
	 * @param array<string, \Eshop\DB\Order> $orders
	 * @return array<string, array<string, mixed>>
	 */
	private function loadExtras(array $orders): array
	{
		if (!$orders) {
			return [];
		}

		$orderIds = \array_keys($orders);
		$suffix = $this->connection->getMutationSuffix();

		/** @var array<string, \Eshop\DB\Purchase> $purchases */
		$purchases = $this->fetchByIds(Purchase::class, $this->collectIds($orders, 'purchase'));

		$addresses = $this->fetchByIds(Address::class, \array_merge(
			$this->collectIds($purchases, 'billAddress'),
			$this->collectIds($purchases, 'deliveryAddress'),
		));

		$totals = $this->loadTotals($orderIds);
		$payments = $this->loadPayments($orderIds, $suffix);
		$deliveries = $this->loadDeliveries($orderIds, $suffix);
		$invoices = $this->loadInvoiceIds($orderIds);

		$extras = [];

		foreach ($orders as $id => $order) {
			$purchase = $purchases[(string) self::idValue($order, 'purchase')] ?? null;

			if ($purchase === null) {
				continue;
			}

			$payment = $payments[$id] ?? null;
			$delivery = $deliveries[$id] ?? null;

			$extras[$id] = [
				'purchase' => $purchase,
				'billAddress' => $addresses[(string) self::idValue($purchase, 'billAddress')] ?? null,
				'deliveryAddress' => $addresses[(string) self::idValue($purchase, 'deliveryAddress')] ?? null,
				'total' => isset($totals[$id]) ? (float) $totals[$id]->total : null,
				'totalWithoutVat' => isset($totals[$id]) ? (float) $totals[$id]->totalWithoutVat : null,
				'paidOn' => $payment?->paidTs,
				'paymentType' => $payment?->typeName ?: null,
				'deliveryType' => $delivery?->typeName ?: null,
				'trackingUrl' => $delivery !== null ? self::trackingUrl($delivery) : null,
				'invoiceIds' => $invoices[$id] ?? [],
				'items' => null,
			];
		}

		return $extras;
	}

	/**
	 * @param array<string> $orderIds
	 * @return array<string, object>
	 */
	private function loadTotals(array $orderIds): array
	{
		$rows = $this->connection->rows(['o' => 'eshop_order'], [
			'id' => 'o.uuid',
			'total' => OrderTotals::withVat('o', 'p'),
			'totalWithoutVat' => OrderTotals::withoutVat('o', 'p'),
		])
			->join(['p' => 'eshop_purchase'], 'p.uuid = o.fk_purchase', [], 'INNER')
			->where('o.uuid', $orderIds);

		return self::index($rows);
	}

	/**
	 * @param array<string> $orderIds
	 * @return array<string, object>
	 */
	private function loadPayments(array $orderIds, string $suffix): array
	{
		$rows = $this->connection->rows(['pm' => 'eshop_payment'], [
			'id' => 'pm.fk_order',
			'typeName' => "MAX(pm.typeName$suffix)",
			'paidTs' => 'MAX(pm.paidTs)',
		])
			->where('pm.fk_order', $orderIds)
			->setGroupBy(['pm.fk_order']);

		return self::index($rows);
	}

	/**
	 * @param array<string> $orderIds
	 * @return array<string, object>
	 */
	private function loadDeliveries(array $orderIds, string $suffix): array
	{
		$rows = $this->connection->rows(['d' => 'eshop_delivery'], [
			'id' => 'd.fk_order',
			'typeName' => "MAX(d.typeName$suffix)",
			'trackingLink' => 'MAX(dt.trackingLink)',
			'code' => 'MAX(COALESCE(NULLIF(d.zasilkovnaCode, \'\'), NULLIF(d.dpdCode, \'\'), NULLIF(d.pplCode, \'\'), NULLIF(d.externalId, \'\')))',
		])
			->join(['dt' => 'eshop_deliverytype'], 'dt.uuid = d.fk_type')
			->where('d.fk_order', $orderIds)
			->setGroupBy(['d.fk_order']);

		return self::index($rows);
	}

	/**
	 * @param array<string> $orderIds
	 * @return array<string, array<string>>
	 */
	private function loadInvoiceIds(array $orderIds): array
	{
		$rows = $this->connection->rows(['nxn' => 'eshop_invoice_nxn_eshop_order'], ['order' => 'nxn.fk_order', 'invoice' => 'nxn.fk_invoice'])
			->where('nxn.fk_order', $orderIds);

		$map = [];

		foreach ($rows as $row) {
			$map[$row->order][] = $row->invoice;
		}

		return $map;
	}

	/**
	 * @return array<array<string, mixed>>
	 */
	private function loadItems(Purchase $purchase): array
	{
		$currency = $this->codebooks->getCurrencyCode(self::idValue($purchase, 'currency'));

		$items = $this->repository(CartItem::class)->many()
			->join(['cart' => 'eshop_cart'], 'cart.uuid = this.fk_cart', [], 'INNER')
			->where('cart.fk_purchase', $purchase->getPK())
			->orderBy(['this.createdTs' => 'ASC']);

		$mapped = [];

		foreach ($items as $item) {
			$mapped[] = $this->itemMapper->map($item, $currency);
		}

		return $mapped;
	}

	/**
	 * Balíky po dopravách, i s tím, kolik položek se z nich reálně vyexpedovalo.
	 * @return array<string, array<array<string, mixed>>>
	 */
	private function loadPackages(string $orderId, string $suffix): array
	{
		$rows = $this->connection->rows(['pkg' => 'eshop_package'], [
			'id' => 'pkg.uuid',
			'number' => 'pkg.id',
			'delivery' => 'pkg.fk_delivery',
			'weight' => 'pkg.weight',
			'items' => 'COUNT(pi.uuid)',
			'dispatched' => 'SUM(IFNULL(pi.dispatchedAmount, 0))',
			'store' => "MAX(s.name$suffix)",
			'expeditionNumber' => 'MAX(pi.expeditionNumber)',
		])
			->join(['pi' => 'eshop_packageitem'], 'pi.fk_package = pkg.uuid')
			->join(['s' => 'eshop_store'], 's.uuid = pi.fk_store')
			->where('pkg.fk_order', $orderId)
			->setGroupBy(['pkg.uuid']);

		$map = [];

		foreach ($rows as $row) {
			$map[$row->delivery][] = [
				'id' => $row->id,
				'number' => $row->number,
				'items' => (int) $row->items,
				'dispatched' => (int) $row->dispatched,
				'weight' => $row->weight !== null ? (float) $row->weight : null,
				'store' => $row->store,
				'expeditionNumber' => $row->expeditionNumber,
			];
		}

		return $map;
	}

	private static function trackingUrl(object $delivery): ?string
	{
		$link = $delivery->trackingLink ?? null;
		$code = $delivery->code ?? null;

		if (!$link || !$code) {
			return null;
		}

		return \str_contains($link, '%s') ? \str_replace('%s', \rawurlencode($code), $link) : $link . \rawurlencode($code);
	}

	/**
	 * @param iterable<object> $rows
	 * @return array<string, object>
	 */
	private static function index(iterable $rows): array
	{
		$map = [];

		foreach ($rows as $row) {
			$map[$row->id] = $row;
		}

		return $map;
	}
}
