<?php

declare(strict_types=1);

namespace DoryoApi\Mapper;

use DoryoApi\Support\Dates;
use DoryoApi\Support\Money;
use Eshop\DB\Order;
use Eshop\DB\Purchase;
use Nette\Utils\Arrays;

/**
 * Objednávka. `status` je normalizovaný slovník podle konfigurace, původní stav shopu
 * zůstává v `eshop.state` — shopy mají stavů různě a Doryo se na ně ptát nemá.
 */
final class OrderMapper extends Mapper
{
	public function status(Order $order): ?string
	{
		$shopState = self::shopState($order);

		foreach ($this->config->getOrderStates() as $status => $states) {
			if (Arrays::contains($states, $shopState)) {
				return $status;
			}
		}

		return null;
	}

	/**
	 * @param array{
	 *     purchase: \Eshop\DB\Purchase,
	 *     billAddress?: \Eshop\DB\Address|null,
	 *     deliveryAddress?: \Eshop\DB\Address|null,
	 *     total?: float|null,
	 *     totalWithoutVat?: float|null,
	 *     paidOn?: string|null,
	 *     paymentType?: string|null,
	 *     deliveryType?: string|null,
	 *     trackingUrl?: string|null,
	 *     invoiceIds?: array<string>,
	 *     items?: array<array<string, mixed>>|null
	 * } $extras
	 * @return array<string, mixed>
	 */
	public function map(Order $order, array $extras): array
	{
		$purchase = $extras['purchase'];
		$currency = $this->codebooks->getCurrencyCode($this->relationId($purchase, 'currency'));
		$paidOn = $extras['paidOn'] ?? null;

		$out = [
			'id' => $order->getPK(),
			'number' => $order->code,
			'status' => $this->status($order),
			'createdAt' => Dates::dateTime($order->createdTs, $this->config->getTimezone()),
			'customerId' => $this->relationId($purchase, 'customer'),
			'customer' => [
				'name' => $purchase->fullname ?: $purchase->accountFullname,
				'email' => $purchase->email ?: $purchase->accountEmail,
				'phone' => $purchase->phone ?: null,
				'registrationNo' => $purchase->ic ?: null,
			],
			'billingAddress' => $this->address($extras['billAddress'] ?? null),
			'deliveryAddress' => $this->address($extras['deliveryAddress'] ?? null),
			'totalWithoutVat' => Money::format($extras['totalWithoutVat'] ?? null, $currency),
			'total' => Money::format($extras['total'] ?? null, $currency),
			'currency' => $currency,
			'paymentType' => $extras['paymentType'] ?? null,
			'deliveryType' => $extras['deliveryType'] ?? null,
			'paid' => $paidOn !== null,
			'paidOn' => Dates::date($paidOn),
			'invoiceIds' => \array_values($extras['invoiceIds'] ?? []),
			'note' => $purchase->note ?: null,
			'items' => $extras['items'] ?? null,
			'eshop' => [
				'source' => self::source($purchase),
				'state' => self::shopState($order),
				'merchantId' => $this->relationId($purchase, 'merchant'),
				'trackingUrl' => $extras['trackingUrl'] ?? null,
				'receivedAt' => Dates::dateTime($order->receivedTs, $this->config->getTimezone()),
				'completedAt' => Dates::dateTime($order->completedTs, $this->config->getTimezone()),
				'canceledAt' => Dates::dateTime($order->canceledTs, $this->config->getTimezone()),
				'pickupPoint' => $purchase->pickupPointName ?: null,
			],
		];

		$this->extend('extendOrder', $order, $out);

		return $out;
	}

	/**
	 * Stav objednávky odvozený ze značek času. Záměrně nevoláme
	 * Eshop\DB\OrderRepository::getState(), protože ta sahá na přihlášeného zákazníka
	 * (session) — API žádnou nemá.
	 */
	public static function shopState(Order $order): string
	{
		if ($order->canceledTs) {
			return Order::STATE_CANCELED;
		}

		if ($order->receivedTs && $order->completedTs) {
			return Order::STATE_COMPLETED;
		}

		if ($order->receivedTs) {
			return Order::STATE_RECEIVED;
		}

		return Order::STATE_OPEN;
	}

	/**
	 * Odkud objednávka přišla. Eshop si zdroj neeviduje, takže se pozná jen to, co je vidět:
	 * externí identifikátor znamená, že ji založil někdo zvenčí (import, marketplace, API).
	 * Přesnější zařazení si shop doplní přes rozšiřovací bod.
	 */
	private static function source(Purchase $purchase): string
	{
		return $purchase->externalId || $purchase->externalCode ? 'api' : 'web';
	}
}
