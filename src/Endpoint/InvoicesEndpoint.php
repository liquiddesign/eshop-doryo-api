<?php

declare(strict_types=1);

namespace DoryoApi\Endpoint;

use DoryoApi\Codebooks;
use DoryoApi\Config;
use DoryoApi\Http\ApiException;
use DoryoApi\Http\Query;
use DoryoApi\Http\Response;
use DoryoApi\Mapper\InvoiceMapper;
use DoryoApi\Mapper\ItemMapper;
use Eshop\DB\Address;
use Eshop\DB\Invoice;
use Eshop\DB\InvoiceItem;
use StORM\DIConnection;

/**
 * Faktury, které vydal shop. Stav se počítá ze zbývající částky a splatnosti; když shop
 * úhrady nevede (config `invoicePaymentTracked: false`), jede se jen podle splatnosti
 * a `paid`/`outstanding` jsou null — API nikdy nehádá, že je zaplaceno.
 */
final class InvoicesEndpoint extends BaseEndpoint
{
	public function __construct(
		DIConnection $connection,
		Config $config,
		Codebooks $codebooks,
		private InvoiceMapper $mapper,
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
			'v1/invoices' => 'list',
			'v1/invoices/by-number/{number}' => 'detailByNumber',
			'v1/invoices/{id}' => 'detail',
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

		/** @var \Eshop\DB\Invoice $invoice */
		$invoice = $this->one(Invoice::class, $params['id'], 'Faktura');
		$extras = $this->loadExtras([$invoice->getPK() => $invoice]);
		$extra = $extras[$invoice->getPK()];
		$extra['items'] = $this->loadItems($invoice);

		return new Response($this->mapper->map($invoice, $extra));
	}

	/**
	 * Detail podle čísla faktury.
	 * @param array<string, string> $params
	 */
	public function detailByNumber(array $params, Query $query): Response
	{
		$id = $this->repository(Invoice::class)->many()->where('this.code', $params['number'])->firstValue('uuid');

		if (!$id) {
			throw ApiException::notFound('Faktura číslo ' . $params['number'] . ' neexistuje.');
		}

		return $this->detail(['id' => (string) $id], $query);
	}

	public function listFiltered(Query $query, ?string $customerId): Response
	{
		$collection = $this->repository(Invoice::class)->many();

		[$from, $to] = $query->window('issuedFrom', 'issuedTo');
		$collection->where('this.exposed >= :apiFrom AND this.exposed <= :apiTo', ['apiFrom' => $from, 'apiTo' => $to]);

		$today = (new \DateTimeImmutable('today', new \DateTimeZone($this->config->getTimezone())))->format('Y-m-d');

		if ($status = $query->string('status')) {
			$condition = $this->statusCondition($status, $today);

			if ($condition === null) {
				throw ApiException::badRequest("Neznámý stav $status; povolené jsou paid, sent, overdue, cancelled.");
			}

			$collection->where($condition);
		}

		if ($query->bool('unpaid', false)) {
			$collection->where('this.canceled IS NULL')->where('NOT ' . $this->paidCondition());
		}

		$customerId ??= $query->string('customerId');

		if ($customerId !== null) {
			$collection->where('this.fk_customer', $customerId);
		}

		if ($registrationNo = $query->string('registrationNo')) {
			$collection->where('this.ic', $registrationNo);
		}

		if ($query->bool('withoutOrder', false)) {
			$collection->where('this.uuid NOT IN (SELECT nxn.fk_invoice FROM eshop_invoice_nxn_eshop_order nxn)');
		}

		if ($since = $query->date('since')) {
			$collection->where('this.exposed >= :apiSince', ['apiSince' => $since]);
		}

		$this->applyFulltext($collection, $query, ['this.code', 'this.subject', 'this.ic', 'this.variableSymbol']);

		$page = $this->paginate($collection->orderBy(['this.exposed' => 'DESC', 'this.code' => 'DESC']), $query);
		$extras = $this->loadExtras($page['rows']);

		$items = [];

		foreach ($page['rows'] as $id => $invoice) {
			$items[] = $this->mapper->map($invoice, $extras[$id]);
		}

		return Response::list($items, $page['nextCursor']);
	}

	/**
	 * Faktura je zaplacená, když má datum úhrady, nebo když na ní nic nezbývá.
	 * Bez evidence úhrad ve shopu se druhá část neptá — sloupec `paid` tam nic neznamená.
	 */
	private function paidCondition(): string
	{
		return $this->config->isInvoicePaymentTracked()
			? '(this.paidDate IS NOT NULL OR (this.totalPriceVat - IFNULL(this.paid, 0)) <= 0)'
			: '(this.paidDate IS NOT NULL)';
	}

	private function statusCondition(string $status, string $today): ?string
	{
		$paid = $this->paidCondition();

		return match ($status) {
			'cancelled' => 'this.canceled IS NOT NULL',
			'paid' => "this.canceled IS NULL AND $paid",
			'overdue' => "this.canceled IS NULL AND NOT $paid AND this.dueDate IS NOT NULL AND this.dueDate < '$today'",
			'sent' => "this.canceled IS NULL AND NOT $paid AND (this.dueDate IS NULL OR this.dueDate >= '$today')",
			default => null,
		};
	}

	/**
	 * @param array<string, \Eshop\DB\Invoice> $invoices
	 * @return array<string, array<string, mixed>>
	 */
	private function loadExtras(array $invoices): array
	{
		if (!$invoices) {
			return [];
		}

		$addresses = $this->fetchByIds(Address::class, $this->collectIds($invoices, 'address'));
		$orders = $this->loadOrderIds(\array_keys($invoices));

		$extras = [];

		foreach ($invoices as $id => $invoice) {
			$extras[$id] = [
				'address' => $addresses[(string) self::idValue($invoice, 'address')] ?? null,
				'customerId' => self::idValue($invoice, 'customer'),
				'orderIds' => $orders[$id] ?? [],
				'items' => null,
			];
		}

		return $extras;
	}

	/**
	 * @param array<string> $invoiceIds
	 * @return array<string, array<string>>
	 */
	private function loadOrderIds(array $invoiceIds): array
	{
		$rows = $this->connection->rows(['nxn' => 'eshop_invoice_nxn_eshop_order'], ['invoice' => 'nxn.fk_invoice', 'order' => 'nxn.fk_order'])
			->where('nxn.fk_invoice', $invoiceIds);

		$map = [];

		foreach ($rows as $row) {
			$map[$row->invoice][] = $row->order;
		}

		return $map;
	}

	/**
	 * @return array<array<string, mixed>>
	 */
	private function loadItems(Invoice $invoice): array
	{
		$currency = $this->codebooks->getCurrencyCode(self::idValue($invoice, 'currency'));

		$mapped = [];

		foreach ($this->repository(InvoiceItem::class)->many()->where('this.fk_invoice', $invoice->getPK()) as $item) {
			$mapped[] = $this->itemMapper->map($item, $currency);
		}

		return $mapped;
	}

}
