<?php

declare(strict_types=1);

namespace DoryoApi\Endpoint;

use DoryoApi\Http\Query;
use DoryoApi\Http\Response;
use DoryoApi\Support\Dates;
use DoryoApi\Support\Money;
use Eshop\DB\Invoice;
use Eshop\DB\Product;
use Nette\Utils\Arrays;

/**
 * Diagnostika — odpovědi na otázky „proč".
 *
 * Zbytek API vydává data; tohle vydává **důvody**. Model se z hodnot polí nedovtípí, proč
 * produkt není na webu nebo proč zaplacená faktura vypadá jako dlužná — tady dostane seznam
 * nálezů: co je špatně, jak moc to vadí a podle čeho se to pozná.
 *
 * Nálezy mají tvar `{code, severity, detail}`; `blocking` znamená „tohle je ta příčina“,
 * `warning` „tohle taky nesedí“ a `info` je kontext.
 */
final class DiagnosticsEndpoint extends BaseEndpoint
{
	private const SEVERITY_BLOCKING = 'blocking';
	private const SEVERITY_WARNING = 'warning';
	private const SEVERITY_INFO = 'info';

	/**
	 * @return array<string, string>
	 */
	public function getRoutes(): array
	{
		return [
			'v1/products/{id}/visibility' => 'productVisibility',
			'v1/products/{id}/media' => 'productMedia',
			'v1/invoices/{id}/payment' => 'invoicePayment',
		];
	}

	/**
	 * Proč produkt není (nebo je) vidět na webu.
	 * @param array<string, string> $params
	 */
	public function productVisibility(array $params, Query $query): Response
	{
		unset($query);

		/** @var \Eshop\DB\Product $product */
		$product = $this->one(Product::class, $params['id'], 'Produkt');
		$id = $product->getPK();
		$suffix = $this->connection->getMutationSuffix();

		$findings = [];

		if ($product->deletedTs !== null) {
			$findings[] = self::finding('deleted', self::SEVERITY_BLOCKING, 'Produkt je smazaný (deletedTs ' . $product->deletedTs . ').');
		}

		$draft = (bool) $this->connection->rows(['p' => 'eshop_product'], ['d' => "p.draft$suffix"])->where('p.uuid', $id)->firstValue('d');

		if ($draft) {
			$findings[] = self::finding('draft', self::SEVERITY_BLOCKING, 'Produkt je rozpracovaný (draft), na web nejde.');
		}

		$lists = $this->loadVisibility($id, $suffix);

		if (!$lists) {
			$findings[] = self::finding(
				'not-in-any-visibility-list',
				self::SEVERITY_BLOCKING,
				'Produkt není v žádném viditelnostním seznamu — katalog ho nemá kde vzít.',
			);
		} elseif (!\array_filter($lists, static fn (array $list): bool => !$list['hidden'])) {
			$findings[] = self::finding(
				'hidden-in-all-lists',
				self::SEVERITY_BLOCKING,
				'Produkt je skrytý ve všech viditelnostech: ' . \implode(', ', \array_column($lists, 'name')) . '.',
			);
		}

		$categories = $this->loadCategories($id, $suffix);

		if (!$categories) {
			$findings[] = self::finding(
				'no-category',
				self::SEVERITY_WARNING,
				'Produkt není v žádné kategorii — z menu se na něj nedá doklikat, přímý odkaz funguje.',
			);
		}

		$prices = $this->loadPublicPrices($id);

		if (!$prices) {
			$findings[] = self::finding(
				'no-price-in-public-pricelist',
				self::SEVERITY_BLOCKING,
				'Produkt nemá cenu ve veřejných cenících — nepřihlášený návštěvník ho neuvidí.',
			);
		}

		$page = $this->loadPage($id, $suffix);

		if ($page === null) {
			$findings[] = self::finding('no-page', self::SEVERITY_WARNING, 'Produkt nemá vygenerovanou stránku (tabulka stránek ho nezná).');
		}

		$stock = $this->loadStockTotal($id);

		if ($stock === 0) {
			$findings[] = self::finding('out-of-stock', self::SEVERITY_INFO, 'Produkt není skladem — podle nastavení shopu se v katalogu může tvářit jako nedostupný.');
		}

		$blocking = \array_filter($findings, static fn (array $finding): bool => $finding['severity'] === self::SEVERITY_BLOCKING);

		return new Response([
			'productId' => $id,
			'code' => $product->getFullCode(),
			'name' => $product->name,
			'visible' => !$blocking,
			'findings' => \array_values($findings),
			'checks' => [
				'deleted' => $product->deletedTs !== null,
				'draft' => $draft,
				'visibilityLists' => $lists,
				'categories' => $categories,
				'publicPrices' => $prices,
				'page' => $page,
				'stock' => $stock,
			],
		]);
	}

	/**
	 * Proč u produktu není obrázek.
	 * @param array<string, string> $params
	 */
	public function productMedia(array $params, Query $query): Response
	{
		unset($query);

		/** @var \Eshop\DB\Product $product */
		$product = $this->one(Product::class, $params['id'], 'Produkt');
		$id = $product->getPK();

		$findings = [];
		$files = [];

		if (!$product->imageFileName) {
			$findings[] = self::finding('no-image-file', self::SEVERITY_BLOCKING, 'Produkt nemá nastavený soubor s obrázkem (imageFileName je prázdné).');
		} else {
			$files = $this->checkFiles(Product::GALLERY_DIR, $product->imageFileName);
			$missing = \array_keys(\array_filter($files, static fn (bool $exists): bool => !$exists));

			if ($missing && \count($missing) === \count($files)) {
				$findings[] = self::finding(
					'image-file-missing',
					self::SEVERITY_BLOCKING,
					"Soubor $product->imageFileName ve složce obrázků neexistuje v žádné velikosti.",
				);
			} elseif ($missing) {
				$findings[] = self::finding(
					'image-size-missing',
					self::SEVERITY_WARNING,
					'Chybí varianta obrázku: ' . \implode(', ', $missing) . ' — nevygenerovaly se náhledy.',
				);
			}
		}

		if ($product->imageNeedFix) {
			$findings[] = self::finding('image-needs-fix', self::SEVERITY_WARNING, 'Obrázek je označený jako vadný (imageNeedFix).');
		}

		$gallery = (int) $this->connection->rows(['p' => 'eshop_photo'], ['cnt' => 'COUNT(*)'])->where('p.fk_product', $id)->firstValue('cnt');
		$supplierPhotos = (int) $this->connection->rows(['sp' => 'eshop_supplierproductphoto'], ['cnt' => 'COUNT(*)'])
			->join(['s' => 'eshop_supplierproduct'], 's.uuid = sp.fk_supplierProduct')
			->where('s.fk_product', $id)
			->firstValue('cnt');

		if (!$product->imageFileName && $supplierPhotos > 0 && !$product->importSupplierImages) {
			$findings[] = self::finding(
				'supplier-images-disabled',
				self::SEVERITY_BLOCKING,
				"Dodavatel má $supplierPhotos obrázků, ale produkt má import obrázků od dodavatele vypnutý.",
			);
		}

		return new Response([
			'productId' => $id,
			'code' => $product->getFullCode(),
			'name' => $product->name,
			'hasImage' => (bool) $product->imageFileName && Arrays::contains($files, true),
			'findings' => $findings,
			'checks' => [
				'imageFileName' => $product->imageFileName ?: null,
				'imageNeedFix' => (bool) $product->imageNeedFix,
				'files' => $files,
				'galleryPhotos' => $gallery,
				'supplierPhotos' => $supplierPhotos,
				'importSupplierImages' => (bool) $product->importSupplierImages,
				'supplierContentMode' => $product->supplierContentMode,
			],
		]);
	}

	/**
	 * Proč faktura vypadá tak, jak vypadá — jaká pole rozhodla o jejím stavu.
	 * @param array<string, string> $params
	 */
	public function invoicePayment(array $params, Query $query): Response
	{
		unset($query);

		/** @var \Eshop\DB\Invoice $invoice */
		$invoice = $this->one(Invoice::class, $params['id'], 'Faktura');
		$currency = $this->codebooks->getCurrencyCode(self::idValue($invoice, 'currency'));
		$tracked = $this->config->isInvoicePaymentTracked();
		$total = (float) $invoice->totalPriceVat;
		$paidAmount = $invoice->paid !== null ? (float) $invoice->paid : null;

		$findings = [];

		if ($invoice->canceled !== null) {
			$findings[] = self::finding('cancelled', self::SEVERITY_INFO, 'Faktura je stornovaná (' . $invoice->canceled . ').');
		}

		if ($invoice->paidDate !== null && ($paidAmount === null || $paidAmount <= 0.0)) {
			$findings[] = self::finding(
				'paid-date-without-amount',
				self::SEVERITY_INFO,
				'Faktura má datum úhrady, ale nulovou uhrazenou částku. Tenhle shop částky úhrad nevede — '
					. 'rozhoduje datum, API proto hlásí zaplaceno. Kdo počítá dluh z částky, uvidí dlužníka mylně.',
			);
		}

		if ($invoice->paidDate === null && $paidAmount !== null && $paidAmount > 0.0) {
			$findings[] = self::finding(
				'amount-without-paid-date',
				self::SEVERITY_WARNING,
				'Je uhrazená částka, ale chybí datum úhrady — částečná platba, nebo se nedopsalo datum.',
			);
		}

		$payments = $this->loadOrderPayments($invoice->getPK(), $currency);

		if ($invoice->paidDate === null && \array_filter($payments, static fn (array $payment): bool => $payment['paidOn'] !== null)) {
			$findings[] = self::finding(
				'order-paid-invoice-not',
				self::SEVERITY_WARNING,
				'Objednávka k faktuře má zaplacenou platbu, faktura ale datum úhrady nemá — nespárovalo se.',
			);
		}

		if (!$tracked) {
			$findings[] = self::finding(
				'payment-not-tracked',
				self::SEVERITY_INFO,
				'Shop úhrady faktur nevede (konfigurace), stav se odvozuje jen ze splatnosti.',
			);
		}

		return new Response([
			'invoiceId' => $invoice->getPK(),
			'number' => $invoice->code,
			'status' => $this->invoiceStatus($invoice, $paidAmount, $total, $tracked),
			'findings' => $findings,
			'checks' => [
				'issuedOn' => Dates::date($invoice->exposed),
				'dueOn' => Dates::date($invoice->dueDate),
				'paidOn' => Dates::date($invoice->paidDate),
				'canceledOn' => Dates::date($invoice->canceled),
				'total' => Money::format($total, $currency),
				'paidAmount' => $paidAmount !== null ? Money::format($paidAmount, $currency) : null,
				'paymentTracked' => $tracked,
				'variableSymbol' => $invoice->variableSymbol ?: null,
				'orderPayments' => $payments,
				'remindersSent' => $this->loadReminders($invoice->getPK()),
			],
		]);
	}

	private function invoiceStatus(Invoice $invoice, ?float $paid, float $total, bool $tracked): string
	{
		if ($invoice->canceled !== null) {
			return 'cancelled';
		}

		if ($invoice->paidDate !== null || ($tracked && $paid !== null && $paid >= $total)) {
			return 'paid';
		}

		$due = Dates::date($invoice->dueDate);
		$today = (new \DateTimeImmutable('today', new \DateTimeZone($this->config->getTimezone())))->format('Y-m-d');

		return $due !== null && $due < $today ? 'overdue' : 'sent';
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function finding(string $code, string $severity, string $detail): array
	{
		return ['code' => $code, 'severity' => $severity, 'detail' => $detail];
	}

	/**
	 * @return array<array<string, mixed>>
	 */
	private function loadVisibility(string $productId, string $suffix): array
	{
		unset($suffix);

		$rows = $this->connection->rows(['v' => 'eshop_visibilitylistitem'], [
			'code' => 'l.code',
			'name' => 'l.name',
			'hidden' => 'v.hidden',
			'unavailable' => 'v.unavailable',
			'hiddenInMenu' => 'v.hiddenInMenu',
			'listHidden' => 'l.hidden',
		])
			->join(['l' => 'eshop_visibilitylist'], 'l.uuid = v.fk_visibilityList', [], 'INNER')
			->where('v.fk_product', $productId);

		$lists = [];

		foreach ($rows as $row) {
			$lists[] = [
				'code' => $row->code,
				'name' => $row->name,
				'hidden' => (bool) $row->hidden || (bool) $row->listHidden,
				'unavailable' => (bool) $row->unavailable,
				'hiddenInMenu' => (bool) $row->hiddenInMenu,
			];
		}

		return $lists;
	}

	/**
	 * @return array<string>
	 */
	private function loadCategories(string $productId, string $suffix): array
	{
		$rows = $this->connection->rows(['nxn' => 'eshop_product_nxn_eshop_category'], [
			'name' => "IFNULL(c.fullName$suffix, c.name$suffix)",
		])
			->join(['c' => 'eshop_category'], 'c.uuid = nxn.fk_category', [], 'INNER')
			->where('nxn.fk_product', $productId);

		$names = [];

		foreach ($rows as $row) {
			if ($row->name === null) {
				continue;
			}

			$names[] = $row->name;
		}

		return $names;
	}

	/**
	 * @return array<array<string, mixed>>
	 */
	private function loadPublicPrices(string $productId): array
	{
		$pricelists = $this->codebooks->getDefaultPricelists();

		if (!$pricelists) {
			return [];
		}

		$rows = $this->connection->rows(['p' => 'eshop_price'], [
			'pricelist' => 'pl.name',
			'price' => 'p.price',
			'currency' => 'pl.fk_currency',
			'hidden' => 'p.hidden',
		])
			->join(['pl' => 'eshop_pricelist'], 'pl.uuid = p.fk_pricelist', [], 'INNER')
			->where('p.fk_product', $productId)
			->where('p.fk_pricelist', $pricelists)
			->where('p.hidden', false);

		$prices = [];

		foreach ($rows as $row) {
			$prices[] = [
				'pricelist' => $row->pricelist,
				'price' => Money::format($row->price, $this->codebooks->getCurrencyCode($row->currency)),
			];
		}

		return $prices;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function loadPage(string $productId, string $suffix): ?array
	{
		try {
			$row = $this->connection->rows(['p' => 'web_page'], ['url' => "p.url$suffix", 'offline' => 'p.isOffline'])
				->where('p.type', 'product_detail')
				->where('p.params', "product=$productId&")
				->fetch();

			if ($row === null) {
				return null;
			}

			return ['url' => $row->url, 'offline' => (bool) $row->offline];
		} catch (\Throwable) {
			return null;
		}
	}

	private function loadStockTotal(string $productId): int
	{
		return (int) $this->connection->rows(['a' => 'eshop_amount'], ['total' => 'SUM(a.inStock)'])
			->where('a.fk_product', $productId)
			->firstValue('total');
	}

	/**
	 * Existence souborů obrázku v jednotlivých velikostech.
	 * @return array<string, bool>
	 */
	private function checkFiles(string $dir, string $fileName): array
	{
		$root = $this->config->getUserfilesDir();

		if ($root === null) {
			return [];
		}

		$files = [];

		foreach ($this->config->getImageSizes() as $size) {
			$files[$size] = \is_file("$root/$dir/$size/$fileName");
		}

		return $files;
	}

	/**
	 * @return array<array<string, mixed>>
	 */
	private function loadOrderPayments(string $invoiceId, string $currency): array
	{
		$rows = $this->connection->rows(['nxn' => 'eshop_invoice_nxn_eshop_order'], [
			'orderCode' => 'o.code',
			'typeName' => 'pm.typeCode',
			'price' => 'pm.price',
			'paidOn' => 'pm.paidTs',
		])
			->join(['o' => 'eshop_order'], 'o.uuid = nxn.fk_order', [], 'INNER')
			->join(['pm' => 'eshop_payment'], 'pm.fk_order = o.uuid')
			->where('nxn.fk_invoice', $invoiceId);

		$payments = [];

		foreach ($rows as $row) {
			$payments[] = [
				'orderNumber' => $row->orderCode,
				'type' => $row->typeName,
				'amount' => Money::format($row->price, $currency),
				'paidOn' => Dates::date($row->paidOn),
			];
		}

		return $payments;
	}

	/**
	 * @return array<string>
	 */
	private function loadReminders(string $invoiceId): array
	{
		try {
			$row = $this->connection->rows(['i' => 'eshop_invoice'], [
				'first' => 'i.reminder1SentAt',
				'second' => 'i.reminder2SentAt',
				'third' => 'i.reminder3SentAt',
			])->where('i.uuid', $invoiceId)->fetch();

			if ($row === null) {
				return [];
			}

			return \array_values(\array_filter([
				$row->first ? 'první ' . Dates::date($row->first) : null,
				$row->second ? 'druhá ' . Dates::date($row->second) : null,
				$row->third ? 'třetí ' . Dates::date($row->third) : null,
			]));
		} catch (\Throwable) {
			// shop upomínky nemusí vůbec evidovat
			return [];
		}
	}
}
