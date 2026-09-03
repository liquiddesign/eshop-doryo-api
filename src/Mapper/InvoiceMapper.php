<?php

declare(strict_types=1);

namespace DoryoApi\Mapper;

use DoryoApi\Support\Dates;
use DoryoApi\Support\Money;
use Eshop\DB\Invoice;

/**
 * Faktura vydaná shopem. Když shop úhrady nevede (fakturu vystaví, zaplacení hlídá ERP),
 * vrací se `paid` i `outstanding` jako null a `eshop.paymentTracked: false` — stav se pak
 * odvozuje jen ze splatnosti. Nikdy se nehádá.
 */
final class InvoiceMapper extends Mapper
{
	/**
	 * @param array{
	 *     address?: \Eshop\DB\Address|null,
	 *     customerId?: string|null,
	 *     orderIds?: array<string>,
	 *     items?: array<array<string, mixed>>|null
	 * } $extras
	 * @return array<string, mixed>
	 */
	public function map(Invoice $invoice, array $extras = []): array
	{
		$currency = $this->codebooks->getCurrencyCode($this->relationId($invoice, 'currency'));
		$tracked = $this->config->isInvoicePaymentTracked();
		$total = (float) $invoice->totalPriceVat;
		// Datum úhrady je silnější než částka: shopy, které částečné úhrady nevedou, mají
		// `paid` prázdné i u zaplacené faktury a jinak by pořád dlužila celou částku.
		$paid = $tracked ? ($invoice->paidDate !== null ? $total : (float) $invoice->paid) : null;
		$outstanding = $paid !== null ? \round($total - $paid, 2) : null;
		$address = $extras['address'] ?? null;

		return [
			'id' => $invoice->getPK(),
			'number' => $invoice->code,
			'type' => 'issued',
			'status' => $this->status($invoice, $outstanding),
			'customerId' => $extras['customerId'] ?? $this->relationId($invoice, 'customer'),
			'orderIds' => \array_values($extras['orderIds'] ?? []),
			'issuedOn' => Dates::date($invoice->exposed),
			'dueOn' => Dates::date($invoice->dueDate),
			'taxableOn' => Dates::date($invoice->taxDate),
			'paidOn' => Dates::date($invoice->paidDate),
			'daysOverdue' => $invoice->paidDate === null ? Dates::daysOverdue($invoice->dueDate, $this->config->getTimezone()) : 0,
			'variableSymbol' => $invoice->variableSymbol ?: null,
			'total' => Money::format($total, $currency),
			'totalWithoutVat' => Money::format($invoice->totalPrice, $currency),
			'paid' => $paid !== null ? Money::format($paid, $currency) : null,
			'outstanding' => $outstanding !== null ? Money::format($outstanding, $currency) : null,
			'billedTo' => [
				'name' => $invoice->subject ?: ($address?->companyName ?: $address?->name),
				'registrationNo' => $invoice->ic ?: null,
				'vatNo' => $invoice->dic ?: null,
				'email' => $invoice->email ?: null,
				'city' => $address?->city ?: null,
			],
			'items' => $extras['items'] ?? null,
			'eshop' => [
				'paymentTracked' => $tracked,
				'pdfUrl' => null,
				'canceledOn' => Dates::date($invoice->canceled),
			],
		];
	}

	private function status(Invoice $invoice, ?float $outstanding): string
	{
		if ($invoice->canceled) {
			return 'cancelled';
		}

		if ($invoice->paidDate !== null || ($outstanding !== null && $outstanding <= 0.0)) {
			return 'paid';
		}

		$due = Dates::date($invoice->dueDate);
		$today = (new \DateTimeImmutable('today', new \DateTimeZone($this->config->getTimezone())))->format('Y-m-d');

		return $due !== null && $due < $today ? 'overdue' : 'sent';
	}
}
