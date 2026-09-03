<?php

declare(strict_types=1);

namespace DoryoApi\OpenApi;

use DoryoApi\Config;

/**
 * Popis API pro introspekci z Doryo. Je psaný ručně, ne generovaný z kódu — model si ho čte
 * jako dokumentaci, takže popisy jsou česky a mluví o tom, k čemu je který endpoint dobrý,
 * ne o tom, jak je udělaný.
 */
final class Specification
{
	public function __construct(private Config $config)
	{
	}

	/**
	 * @return array<string, mixed>
	 */
	public function build(string $baseUrl): array
	{
		return [
			'openapi' => '3.0.3',
			'info' => [
				'title' => 'Čtecí API e-shopu ' . ($this->config->getShopName() ?? ''),
				'version' => Config::VERSION,
				'description' => 'Jen ke čtení. Objednávky, zákazníci, produkty, sklad a faktury e-shopu '
					. 've stejném tvaru, v jakém je vydávají ERP konektory. Všechny seznamy jsou stránkované '
					. 'kurzorem, částky jsou řetězce s měnou, časy jsou v ISO 8601.',
			],
			'servers' => [['url' => $baseUrl]],
			'security' => [['bearerAuth' => []]],
			'paths' => $this->paths(),
			'components' => [
				'securitySchemes' => [
					'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
				],
				'parameters' => $this->parameters(),
				'schemas' => $this->schemas(),
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function paths(): array
	{
		return [
			'/v1/meta/health' => $this->operation(
				'Kontrola dostupnosti',
				'Vrátí stav služby, verzi API a základní údaje o shopu.',
				[],
				'HealthResponse',
			),
			'/v1/customers' => $this->operation(
				'Seznam zákazníků',
				'Zákazníci e-shopu i s obratem a počtem objednávek. Hledá se podle jména, firmy, e-mailu a IČO.',
				[
					$this->ref('Q'),
					$this->param('registrationNo', 'IČO zákazníka (přesná shoda).'),
					$this->param('email', 'E-mail zákazníka (přesná shoda).'),
					$this->param('since', 'Jen zákazníci registrovaní od tohoto data (YYYY-MM-DD).'),
					$this->param('merchantId', 'Jen zákazníci přiřazení tomuto obchodníkovi.'),
					$this->ref('Limit'),
					$this->ref('Cursor'),
				],
				'CustomerList',
			),
			'/v1/customers/{id}' => $this->operation(
				'Detail zákazníka',
				'Jeden zákazník podle id.',
				[$this->pathParam('id', 'Id zákazníka.')],
				'Customer',
			),
			'/v1/customers/{id}/orders' => $this->operation(
				'Objednávky zákazníka',
				'Objednávky jednoho zákazníka; parametry jsou stejné jako u /v1/orders.',
				[$this->pathParam('id', 'Id zákazníka.'), $this->ref('Limit'), $this->ref('Cursor')],
				'OrderList',
			),
			'/v1/customers/{id}/invoices' => $this->operation(
				'Faktury zákazníka',
				'Faktury jednoho zákazníka; parametry jsou stejné jako u /v1/invoices.',
				[$this->pathParam('id', 'Id zákazníka.'), $this->ref('Limit'), $this->ref('Cursor')],
				'InvoiceList',
			),
			'/v1/customers/{id}/summary' => $this->operation(
				'Souhrn za zákazníka',
				'Počet objednávek, obrat, datum poslední objednávky, počet neuhrazených faktur a dlužná částka.',
				[$this->pathParam('id', 'Id zákazníka.')],
				'CustomerSummary',
			),
			'/v1/orders' => $this->operation(
				'Seznam objednávek',
				'Objednávky e-shopu, nejnovější první. Bez uvedení rozsahu se vrací posledních šest měsíců. '
					. 'Položky objednávky jsou až v detailu.',
				[
					$this->param('createdFrom', 'Objednávky vytvořené od data (YYYY-MM-DD).'),
					$this->param('createdTo', 'Objednávky vytvořené do data (YYYY-MM-DD).'),
					$this->param('status', 'Stav objednávky: new, processing, shipped, delivered, cancelled, returned.'),
					$this->param('customerId', 'Jen objednávky tohoto zákazníka.'),
					$this->param('registrationNo', 'Jen objednávky s tímto IČO.'),
					$this->param('merchantId', 'Jen objednávky tohoto obchodníka.'),
					$this->param('since', 'Jen objednávky vytvořené od tohoto okamžiku (YYYY-MM-DD nebo ISO 8601).'),
					$this->param('shippingDate', 'Jen objednávky s tímhle požadovaným datem expedice.'),
					$this->param('exported', 'true/false — jestli je objednávka zaexportovaná do ERP.', 'boolean'),
					$this->param('withoutInvoice', 'true = jen objednávky bez faktury.', 'boolean'),
					$this->ref('Q'),
					$this->ref('Limit'),
					$this->ref('Cursor'),
				],
				'OrderList',
			),
			'/v1/orders/{id}' => $this->operation(
				'Detail objednávky',
				'Jedna objednávka včetně položek.',
				[$this->pathParam('id', 'Id objednávky.')],
				'Order',
			),
			'/v1/invoices' => $this->operation(
				'Seznam faktur',
				'Faktury vydané e-shopem. Bez uvedení rozsahu se vrací posledních šest měsíců.',
				[
					$this->param('issuedFrom', 'Faktury vystavené od data (YYYY-MM-DD).'),
					$this->param('issuedTo', 'Faktury vystavené do data (YYYY-MM-DD).'),
					$this->param('status', 'Stav faktury: paid, sent, overdue, cancelled.'),
					$this->param('unpaid', 'true = jen neuhrazené faktury.', 'boolean'),
					$this->param('withoutOrder', 'true = jen faktury bez navázané objednávky.', 'boolean'),
					$this->param('customerId', 'Jen faktury tohoto zákazníka.'),
					$this->param('registrationNo', 'Jen faktury s tímto IČO.'),
					$this->ref('Q'),
					$this->ref('Limit'),
					$this->ref('Cursor'),
				],
				'InvoiceList',
			),
			'/v1/invoices/{id}' => $this->operation(
				'Detail faktury',
				'Jedna faktura včetně položek.',
				[$this->pathParam('id', 'Id faktury.')],
				'Invoice',
			),
			'/v1/products' => $this->operation(
				'Seznam produktů',
				'Produkty s cenou z veřejného ceníku a se skladovou dostupností.',
				[
					$this->ref('Q'),
					$this->param('code', 'Kód produktu (přesná shoda).'),
					$this->param('ean', 'EAN produktu (přesná shoda).'),
					$this->param('category', 'Kategorie — id, kód nebo název; zahrne i podkategorie.'),
					$this->param('since', 'Jen produkty založené od tohoto data.'),
					$this->param('active', 'false = vrátí i smazané a rozpracované produkty (výchozí true).', 'boolean'),
					$this->param('attribute', 'Filtr podle parametru ve tvaru název:hodnota, víc dvojic přes čárku (platí AND).'),
					$this->ref('Limit'),
					$this->ref('Cursor'),
				],
				'ProductList',
			),
			'/v1/products/{id}' => $this->operation(
				'Detail produktu',
				'Jeden produkt podle id.',
				[$this->pathParam('id', 'Id produktu.')],
				'Product',
			),
			'/v1/stock' => $this->operation(
				'Skladová dostupnost',
				'Levná odpověď na otázku „máme to skladem". Vyžaduje aspoň jeden z parametrů code, ean, id nebo q.',
				[
					$this->param('code', 'Kód produktu.'),
					$this->param('ean', 'EAN produktu.'),
					$this->param('id', 'Id produktu.'),
					$this->ref('Q'),
					$this->ref('Limit'),
					$this->ref('Cursor'),
				],
				'StockList',
			),
			'/v1/reports/sales' => $this->operation(
				'Report tržeb',
				'Počty objednávek a tržby seskupené po měsících, týdnech, dnech, obchodnících nebo kategoriích. '
					. 'Zrušené objednávky se nepočítají.',
				[
					$this->param('from', 'Začátek období (YYYY-MM-DD).'),
					$this->param('to', 'Konec období (YYYY-MM-DD).'),
					$this->param('groupBy', 'Seskupení: month (výchozí), week, day, merchant, customer, category, producer.'),
				],
				'ReportList',
			),
			'/v1/meta/codebooks' => $this->operation(
				'Číselníky shopu',
				'Ceníky, skupiny zákazníků, sklady, viditelnosti, dopravy, platby, sazby DPH a výrobci '
					. 'v jednom volání. Odsud se berou kódy do ostatních endpointů — netipuj je.',
				[],
				'Codebooks',
			),
			'/v1/categories' => $this->operation(
				'Strom kategorií',
				'Kategorie i s cestou a úrovní zanoření; `path` říká zanoření i pořadí.',
				[$this->ref('Q'), $this->param('level', 'Jen kategorie téhle úrovně (1 = kořenové).', 'integer'), $this->ref('Limit'), $this->ref('Cursor')],
				'CategoryList',
			),
			'/v1/search' => $this->operation(
				'Hledání napříč',
				'Najde produkt, zákazníka, objednávku nebo fakturu podle kódu, čísla nebo jména. '
					. 'Používej to, když máš od člověka „37214.01" nebo „Pro-Domu" a potřebuješ id.',
				[
					$this->param('q', 'Co hledat — kód, číslo dokladu, jméno firmy, e-mail.'),
					$this->param('type', 'Omezení na typy: products, customers, orders, invoices (čárkou).'),
					$this->param('limit', 'Kolik výsledků na typ (výchozí 5, max 25).', 'integer'),
				],
				'SearchList',
			),
			'/v1/pricelists' => $this->operation(
				'Seznam ceníků',
				'Aktivní prodejní ceníky i s tím, kolik mají produktů a zákazníků.',
				[$this->ref('Q'), $this->ref('Limit'), $this->ref('Cursor')],
				'PricelistList',
			),
			'/v1/prices' => $this->operation(
				'Ceny z ceníku',
				'Ceníkové ceny vybraných položek. Na cenovou nabídku „z ceníku X mínus Y %" si vezmi tyhle ceny '
					. 'a slevu spočítej sám — API obchodní logiku nedělá.',
				[
					$this->param('pricelist', 'Kód nebo id ceníku (povinné).'),
					$this->param('codes', 'Kódy produktů oddělené čárkou.'),
					$this->param('code', 'Jeden kód produktu.'),
					$this->param('ean', 'EAN produktu.'),
					$this->ref('Q'),
					$this->ref('Limit'),
					$this->ref('Cursor'),
				],
				'PriceList',
			),
			'/v1/customers/{id}/prices' => $this->operation(
				'Ceny konkrétního zákazníka',
				'Ceny, které zákazník reálně má — jeho ceníky, jeho slevová hladina a strop slevy. '
					. 'Tohle je podklad pro cenovou nabídku. Shop to může mít vypnuté (pak 403).',
				[
					$this->pathParam('id', 'Id zákazníka.'),
					$this->param('codes', 'Kódy produktů oddělené čárkou.'),
					$this->ref('Q'),
					$this->ref('Limit'),
					$this->ref('Cursor'),
				],
				'PriceList',
			),
			'/v1/customers/{id}/products' => $this->operation(
				'Co zákazník odebírá',
				'Položky, které zákazník bral za období — množství, tržba, poslední nákup.',
				[$this->pathParam('id', 'Id zákazníka.'), $this->param('from', 'Od (YYYY-MM-DD).'), $this->param('to', 'Do (YYYY-MM-DD).'), $this->ref('Limit')],
				'CustomerProductList',
			),
			'/v1/products/{id}/visibility' => $this->operation(
				'Proč produkt není na webu',
				'Diagnostika viditelnosti: smazaný, rozpracovaný, mimo viditelnostní seznamy, skrytý, bez kategorie, '
					. 'bez ceny ve veřejném ceníku, bez stránky. Vrací nálezy s vysvětlením.',
				[$this->pathParam('id', 'Id produktu.')],
				'Diagnostics',
			),
			'/v1/products/{id}/media' => $this->operation(
				'Proč u produktu není obrázek',
				'Diagnostika obrázků: nastavený soubor, existence jednotlivých velikostí, galerie, '
					. 'obrázky od dodavatele a jestli je jejich import povolený.',
				[$this->pathParam('id', 'Id produktu.')],
				'Diagnostics',
			),
			'/v1/invoices/{id}/payment' => $this->operation(
				'Proč je faktura v tomhle stavu',
				'Diagnostika úhrady: datum úhrady, uhrazená částka, storno, platby navázaných objednávek a upomínky — '
					. 'a která z těch hodnot rozhodla o stavu.',
				[$this->pathParam('id', 'Id faktury.')],
				'Diagnostics',
			),
			'/v1/reports/customers' => $this->operation(
				'Zákazníci: růst a pokles',
				'Obrat a počet objednávek za období proti srovnávacímu období. Bez `compareFrom`/`compareTo` '
					. 'se srovnává se stejně dlouhým předchozím obdobím. Řazení `sort`: revenue, growth, drop.',
				[
					$this->param('from', 'Od (YYYY-MM-DD).'),
					$this->param('to', 'Do (YYYY-MM-DD).'),
					$this->param('compareFrom', 'Začátek srovnávacího období.'),
					$this->param('compareTo', 'Konec srovnávacího období.'),
					$this->param('sort', 'revenue (výchozí), growth, drop.'),
					$this->ref('Limit'),
				],
				'CustomerReportList',
			),
			'/v1/reports/receivables' => $this->operation(
				'Pohledávky po zákaznících',
				'Neuhrazené faktury sečtené po zákaznících, včetně pásem stáří (0–30, 31–60, 61–90, 90+ dní).',
				[$this->param('overdueOnly', 'Jen po splatnosti (výchozí true).', 'boolean'), $this->ref('Limit')],
				'ReceivableList',
			),
			'/v1/reports/churn' => $this->operation(
				'Kdo přestal odebírat',
				'Zákazníci, kteří mívali objednávky, ale poslední mají starší než `inactiveDays`.',
				[
					$this->param('inactiveDays', 'Kolik dní bez objednávky (výchozí 90).', 'integer'),
					$this->param('minOrders', 'Minimální počet dřívějších objednávek (výchozí 3).', 'integer'),
					$this->ref('Limit'),
				],
				'ChurnList',
			),
			'/v1/reports/replenishment' => $this->operation(
				'Co dochází',
				'Prodejnost proti skladu: kolik se prodalo, kolik jde denně, co zbývá a na kolik dní to vystačí. '
					. 'Bez `store` se sčítají i dodavatelské sklady — na „musíme objednat?" se ptej na vlastní sklad.',
				[
					$this->param('from', 'Od (YYYY-MM-DD).'),
					$this->param('to', 'Do (YYYY-MM-DD).'),
					$this->param('store', 'Kód skladu (seznam dá /v1/meta/codebooks).'),
					$this->param('maxCoverageDays', 'Vrátit jen položky s pokrytím do tolika dnů (výchozí 30).', 'integer'),
					$this->ref('Limit'),
				],
				'ReplenishmentList',
			),
			'/v1/meta/capabilities' => $this->operation(
				'Co tenhle shop používá',
				'U každé domény říká, jestli ji shop vede, kolik v ní je záznamů a kdy do ní naposled něco přibylo. '
					. '`available: false` znamená, že to shop neeviduje — na takovou věc se neptej a rovnou to řekni. '
					. 'Volej to na začátku, ušetří to slepé dotazy.',
				[],
				'Capabilities',
			),
			'/v1/suppliers' => $this->operation(
				'Dodavatelé',
				'Dodavatelé i s tím, kdy od nich naposled přišel import a kolik mají produktů. '
					. '`codePrefix` říká, jestli se kód produktu v katalogu ukazuje bez prefixu.',
				[],
				'SupplierList',
			),
			'/v1/orders/{id}/history' => $this->operation(
				'Historie objednávky',
				'Kdo objednávku kdy změnil a na co — z logu, který si shop vede sám. Na otázky „proč je pozastavená" '
					. 'nebo „kdo změnil dopravu".',
				[$this->pathParam('id', 'Id objednávky.'), $this->ref('Limit')],
				'OrderHistoryList',
			),
			'/v1/orders/{id}/shipments' => $this->operation(
				'Zásilky objednávky',
				'Dopravy, balíky a sledovací kódy — kde zásilka je a co v ní bylo.',
				[$this->pathParam('id', 'Id objednávky.')],
				'ShipmentList',
			),
			'/v1/products/{id}/reviews' => $this->operation(
				'Hodnocení produktu',
				'Recenze od zákazníků, nejnovější první.',
				[$this->pathParam('id', 'Id produktu.'), $this->ref('Limit')],
				'ReviewList',
			),
			'/v1/reports/fulfillment' => $this->operation(
				'Co čeká na expedici',
				'Přijaté a nedokončené objednávky se stářím ve dnech; `olderThanDays` odfiltruje čerstvé.',
				[$this->param('olderThanDays', 'Jen objednávky starší než tolik dní.', 'integer'), $this->ref('Limit')],
				'FulfillmentList',
			),
			'/v1/reports/reviews' => $this->operation(
				'Produkty podle hodnocení',
				'Průměrné hodnocení a počet recenzí, od nejhoršího. `minCount` filtruje produkty s pár recenzemi, '
					. '`maxScore` nechá jen ty pod hranicí.',
				[
					$this->param('minCount', 'Minimální počet recenzí (výchozí 3).', 'integer'),
					$this->param('maxScore', 'Jen produkty s průměrem do téhle hodnoty.'),
					$this->ref('Limit'),
				],
				'ReviewReportList',
			),
			'/v1/reports/imports' => $this->operation(
				'Importy od dodavatelů',
				'Jak dopadly poslední běhy importů — počty, chyby, časy. Na „proč nemá produkt novou cenu".',
				[
					$this->param('supplier', 'Kód, id nebo název dodavatele.'),
					$this->param('status', 'Jen běhy v tomhle stavu.'),
					$this->ref('Limit'),
				],
				'ImportList',
			),
			'/v1/reports/catalog-health' => $this->operation(
				'Zdraví katalogu',
				'Kolik produktů je rozbitých a čím: bez viditelnosti, skryté všude, bez kategorie, bez obrázku, '
					. 'bez ceny ve veřejném ceníku, bez EANu. U každé kategorie problému pár ukázek.',
				[$this->param('samples', 'Kolik ukázek u každého nálezu (výchozí 5, max 20).', 'integer')],
				'CatalogHealthList',
			),
			'/v1/reports/unlinked' => $this->operation(
				'Doklady bez protějšku',
				'Objednávky bez faktury, faktury bez objednávky a objednávky nezaexportované do ERP.',
				[$this->param('from', 'Od (YYYY-MM-DD).'), $this->param('to', 'Do (YYYY-MM-DD).'), $this->ref('Limit')],
				'Unlinked',
			),
			'/v1/reports/abandoned-carts' => $this->operation(
				'Opuštěné košíky',
				'Kolik košíků nedošlo k objednávce. Hodnotu a nejčastější položky vrátí až withItems=true — '
					. 'je to dotaz přes všechny položky košíků a trvá desítky sekund. Košíky zakládají i roboti, '
					. 'takže value je horní odhad, ne ušlá tržba.',
				[
					$this->param('from', 'Od (YYYY-MM-DD).'),
					$this->param('to', 'Do (YYYY-MM-DD).'),
					$this->param('withItems', 'true = dopočítat hodnotu a nejčastější položky (pomalé).', 'boolean'),
					$this->ref('Limit'),
				],
				'AbandonedCarts',
			),
			'/v1/reports/top-products' => $this->operation(
				'Nejprodávanější produkty',
				'Produkty seřazené podle tržby za období.',
				[
					$this->param('from', 'Začátek období (YYYY-MM-DD).'),
					$this->param('to', 'Konec období (YYYY-MM-DD).'),
					$this->ref('Limit'),
				],
				'TopProductList',
			),
		];
	}

	/**
	 * @param array<array<string, mixed>> $parameters
	 * @return array<string, mixed>
	 */
	private function operation(string $summary, string $description, array $parameters, string $schema): array
	{
		return [
			'get' => [
				'summary' => $summary,
				'description' => $description,
				'parameters' => $parameters,
				'responses' => [
					'200' => [
						'description' => 'OK',
						'content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/$schema"]]],
					],
					'400' => $this->problemResponse('Chybný parametr'),
					'401' => $this->problemResponse('Chybějící nebo neplatný token'),
					'404' => $this->problemResponse('Záznam neexistuje'),
				],
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function problemResponse(string $description): array
	{
		return [
			'description' => $description,
			'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function param(string $name, string $description, string $type = 'string'): array
	{
		return [
			'name' => $name,
			'in' => 'query',
			'required' => false,
			'description' => $description,
			'schema' => ['type' => $type],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function pathParam(string $name, string $description): array
	{
		return [
			'name' => $name,
			'in' => 'path',
			'required' => true,
			'description' => $description,
			'schema' => ['type' => 'string'],
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function ref(string $name): array
	{
		return ['$ref' => "#/components/parameters/$name"];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parameters(): array
	{
		return [
			'Limit' => $this->param('limit', \sprintf('Počet záznamů (výchozí %d, maximum %d).', $this->config->getDefaultLimit(), $this->config->getMaxLimit()), 'integer'),
			'Cursor' => $this->param('cursor', 'Kurzor další stránky — hodnota nextCursor z předchozí odpovědi.'),
			'Q' => $this->param('q', 'Fulltext; bez ohledu na diakritiku a velikost písmen, čárka odděluje varianty (malina,transkomp).'),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function schemas(): array
	{
		$money = [
			'type' => 'object',
			'nullable' => true,
			'description' => 'Částka s měnou; amount je řetězec na dvě desetinná místa.',
			'properties' => [
				'amount' => ['type' => 'string', 'example' => '12500.00'],
				'currency' => ['type' => 'string', 'example' => 'CZK'],
			],
		];

		$address = [
			'type' => 'object',
			'nullable' => true,
			'properties' => [
				'name' => ['type' => 'string', 'nullable' => true],
				'street' => ['type' => 'string', 'nullable' => true],
				'city' => ['type' => 'string', 'nullable' => true],
				'zip' => ['type' => 'string', 'nullable' => true],
				'country' => ['type' => 'string', 'nullable' => true],
			],
		];

		return [
			'Money' => $money,
			'Address' => $address,
			'Problem' => [
				'type' => 'object',
				'properties' => [
					'type' => ['type' => 'string'],
					'title' => ['type' => 'string'],
					'status' => ['type' => 'integer'],
					'detail' => ['type' => 'string'],
				],
			],
			'HealthResponse' => [
				'type' => 'object',
				'properties' => [
					'status' => ['type' => 'string'],
					'service' => ['type' => 'string'],
					'version' => ['type' => 'string'],
					'eshopVersion' => ['type' => 'string', 'nullable' => true],
					'now' => ['type' => 'string'],
				],
			],
			'Customer' => [
				'type' => 'object',
				'description' => 'Zákazník e-shopu. Blok eshop nese to, co ERP nezná — skupinu, ceníky, obrat.',
				'properties' => [
					'id' => ['type' => 'string'],
					'state' => ['type' => 'string', 'enum' => ['active', 'inactive']],
					'name' => ['type' => 'string', 'nullable' => true],
					'legalName' => ['type' => 'string', 'nullable' => true],
					'registrationNo' => ['type' => 'string', 'nullable' => true, 'description' => 'IČO'],
					'vatNo' => ['type' => 'string', 'nullable' => true, 'description' => 'DIČ'],
					'address' => ['$ref' => '#/components/schemas/Address'],
					'contact' => ['type' => 'object'],
					'eshop' => ['type' => 'object'],
				],
			],
			'CustomerSummary' => [
				'type' => 'object',
				'properties' => [
					'orders' => ['type' => 'integer'],
					'revenue' => ['$ref' => '#/components/schemas/Money'],
					'lastOrderOn' => ['type' => 'string', 'nullable' => true],
					'unpaidInvoices' => ['type' => 'integer'],
					'outstanding' => ['$ref' => '#/components/schemas/Money'],
				],
			],
			'OrderItem' => [
				'type' => 'object',
				'properties' => [
					'id' => ['type' => 'string'],
					'productId' => ['type' => 'string', 'nullable' => true],
					'code' => ['type' => 'string', 'nullable' => true],
					'name' => ['type' => 'string', 'nullable' => true],
					'quantity' => ['type' => 'integer'],
					'unitPrice' => ['$ref' => '#/components/schemas/Money'],
					'total' => ['$ref' => '#/components/schemas/Money'],
					'vatRate' => ['type' => 'number', 'nullable' => true],
				],
			],
			'Order' => [
				'type' => 'object',
				'description' => 'Objednávka. status je normalizovaný slovník, eshop.state je původní stav shopu.',
				'properties' => [
					'id' => ['type' => 'string'],
					'number' => ['type' => 'string'],
					'status' => ['type' => 'string', 'nullable' => true, 'enum' => Config::ORDER_STATUSES],
					'createdAt' => ['type' => 'string'],
					'customerId' => ['type' => 'string', 'nullable' => true],
					'customer' => ['type' => 'object'],
					'billingAddress' => ['$ref' => '#/components/schemas/Address'],
					'deliveryAddress' => ['$ref' => '#/components/schemas/Address'],
					'total' => ['$ref' => '#/components/schemas/Money'],
					'totalWithoutVat' => ['$ref' => '#/components/schemas/Money'],
					'paid' => ['type' => 'boolean'],
					'invoiceIds' => ['type' => 'array', 'items' => ['type' => 'string']],
					'items' => [
						'type' => 'array',
						'nullable' => true,
						'description' => 'Jen v detailu objednávky; v seznamu je null.',
						'items' => ['$ref' => '#/components/schemas/OrderItem'],
					],
					'eshop' => ['type' => 'object'],
				],
			],
			'Invoice' => [
				'type' => 'object',
				'description' => 'Faktura vydaná e-shopem. Když shop úhrady nevede, paid i outstanding jsou null '
					. 'a eshop.paymentTracked je false.',
				'properties' => [
					'id' => ['type' => 'string'],
					'number' => ['type' => 'string', 'nullable' => true],
					'type' => ['type' => 'string'],
					'status' => ['type' => 'string', 'enum' => ['paid', 'sent', 'overdue', 'cancelled']],
					'customerId' => ['type' => 'string', 'nullable' => true],
					'orderIds' => ['type' => 'array', 'items' => ['type' => 'string']],
					'issuedOn' => ['type' => 'string', 'nullable' => true],
					'dueOn' => ['type' => 'string', 'nullable' => true],
					'paidOn' => ['type' => 'string', 'nullable' => true],
					'daysOverdue' => ['type' => 'integer', 'nullable' => true],
					'total' => ['$ref' => '#/components/schemas/Money'],
					'outstanding' => ['$ref' => '#/components/schemas/Money'],
					'items' => ['type' => 'array', 'nullable' => true, 'items' => ['$ref' => '#/components/schemas/OrderItem']],
				],
			],
			'Stock' => [
				'type' => 'object',
				'nullable' => true,
				'properties' => [
					'available' => ['type' => 'integer'],
					'reserved' => ['type' => 'integer'],
					'onOrder' => ['type' => 'integer'],
				],
			],
			'Product' => [
				'type' => 'object',
				'properties' => [
					'id' => ['type' => 'string'],
					'code' => ['type' => 'string', 'nullable' => true],
					'ean' => ['type' => 'string', 'nullable' => true],
					'name' => ['type' => 'string', 'nullable' => true],
					'producer' => ['type' => 'string', 'nullable' => true],
					'categories' => ['type' => 'array', 'items' => ['type' => 'string']],
					'active' => ['type' => 'boolean'],
					'vatRate' => ['type' => 'number', 'nullable' => true],
					'price' => ['$ref' => '#/components/schemas/Money'],
					'priceWithVat' => ['$ref' => '#/components/schemas/Money'],
					'stock' => ['$ref' => '#/components/schemas/Stock'],
					'eshop' => ['type' => 'object'],
				],
			],
			'Report' => [
				'type' => 'object',
				'properties' => [
					'key' => ['type' => 'string'],
					'orders' => ['type' => 'integer'],
					'revenue' => ['$ref' => '#/components/schemas/Money'],
					'revenueWithoutVat' => ['$ref' => '#/components/schemas/Money'],
				],
			],
			'TopProduct' => [
				'type' => 'object',
				'properties' => [
					'productId' => ['type' => 'string', 'nullable' => true],
					'code' => ['type' => 'string', 'nullable' => true],
					'name' => ['type' => 'string', 'nullable' => true],
					'quantity' => ['type' => 'integer'],
					'revenue' => ['$ref' => '#/components/schemas/Money'],
				],
			],
			'Codebooks' => ['type' => 'object', 'description' => 'Slovníky shopu; klíče odpovídají doménám.'],
			'Diagnostics' => [
				'type' => 'object',
				'description' => 'Nálezy a kontroly. `findings[].severity` blocking = tohle je příčina, warning = taky nesedí, info = kontext.',
				'properties' => [
					'findings' => [
						'type' => 'array',
						'items' => [
							'type' => 'object',
							'properties' => [
								'code' => ['type' => 'string'],
								'severity' => ['type' => 'string', 'enum' => ['blocking', 'warning', 'info']],
								'detail' => ['type' => 'string'],
							],
						],
					],
					'checks' => ['type' => 'object'],
				],
			],
			'SearchHit' => [
				'type' => 'object',
				'properties' => [
					'type' => ['type' => 'string', 'enum' => ['products', 'customers', 'orders', 'invoices']],
					'id' => ['type' => 'string'],
					'label' => ['type' => 'string'],
					'detail' => ['type' => 'string', 'nullable' => true],
				],
			],
			'Pricelist' => [
				'type' => 'object',
				'properties' => [
					'id' => ['type' => 'string'],
					'code' => ['type' => 'string', 'nullable' => true],
					'name' => ['type' => 'string'],
					'currency' => ['type' => 'string'],
					'products' => ['type' => 'integer'],
					'customers' => ['type' => 'integer'],
				],
			],
			'Price' => [
				'type' => 'object',
				'properties' => [
					'productId' => ['type' => 'string'],
					'code' => ['type' => 'string', 'nullable' => true],
					'name' => ['type' => 'string', 'nullable' => true],
					'listPrice' => ['$ref' => '#/components/schemas/Money'],
					'price' => ['$ref' => '#/components/schemas/Money'],
					'priceWithVat' => ['$ref' => '#/components/schemas/Money'],
					'discountPct' => ['type' => 'number'],
					'pricelist' => ['type' => 'object'],
				],
			],
			'Category' => [
				'type' => 'object',
				'properties' => [
					'id' => ['type' => 'string'],
					'code' => ['type' => 'string', 'nullable' => true],
					'name' => ['type' => 'string', 'nullable' => true],
					'path' => ['type' => 'string'],
					'level' => ['type' => 'integer'],
				],
			],
			'CustomerReport' => [
				'type' => 'object',
				'properties' => [
					'customerId' => ['type' => 'string'],
					'name' => ['type' => 'string', 'nullable' => true],
					'orders' => ['type' => 'integer'],
					'revenue' => ['$ref' => '#/components/schemas/Money'],
					'revenueBefore' => ['$ref' => '#/components/schemas/Money'],
					'change' => ['$ref' => '#/components/schemas/Money'],
					'changePct' => ['type' => 'number', 'nullable' => true],
				],
			],
			'Receivable' => [
				'type' => 'object',
				'properties' => [
					'customerId' => ['type' => 'string', 'nullable' => true],
					'name' => ['type' => 'string', 'nullable' => true],
					'invoices' => ['type' => 'integer'],
					'outstanding' => ['$ref' => '#/components/schemas/Money'],
					'overdue' => ['$ref' => '#/components/schemas/Money'],
					'aging' => ['type' => 'object'],
					'oldestDueOn' => ['type' => 'string', 'nullable' => true],
				],
			],
			'Churn' => [
				'type' => 'object',
				'properties' => [
					'customerId' => ['type' => 'string'],
					'name' => ['type' => 'string', 'nullable' => true],
					'orders' => ['type' => 'integer'],
					'revenue' => ['$ref' => '#/components/schemas/Money'],
					'lastOrderOn' => ['type' => 'string', 'nullable' => true],
					'daysSinceLastOrder' => ['type' => 'integer', 'nullable' => true],
				],
			],
			'Replenishment' => [
				'type' => 'object',
				'properties' => [
					'productId' => ['type' => 'string'],
					'code' => ['type' => 'string', 'nullable' => true],
					'name' => ['type' => 'string', 'nullable' => true],
					'sold' => ['type' => 'integer'],
					'perDay' => ['type' => 'number'],
					'available' => ['type' => 'integer'],
					'coverageDays' => ['type' => 'integer', 'nullable' => true],
					'suggestedOrder' => ['type' => 'integer'],
				],
			],
			'CustomerProduct' => [
				'type' => 'object',
				'properties' => [
					'productId' => ['type' => 'string', 'nullable' => true],
					'code' => ['type' => 'string', 'nullable' => true],
					'name' => ['type' => 'string', 'nullable' => true],
					'quantity' => ['type' => 'integer'],
					'orders' => ['type' => 'integer'],
					'revenue' => ['$ref' => '#/components/schemas/Money'],
					'lastOrderOn' => ['type' => 'string', 'nullable' => true],
				],
			],
			'Capabilities' => [
				'type' => 'object',
				'description' => 'Klíč `features` má u každé domény available/records/lastAt/detail.',
				'properties' => ['shop' => ['type' => 'string', 'nullable' => true], 'features' => ['type' => 'object']],
			],
			'Supplier' => [
				'type' => 'object',
				'properties' => [
					'id' => ['type' => 'string'],
					'code' => ['type' => 'string', 'nullable' => true],
					'name' => ['type' => 'string'],
					'importActive' => ['type' => 'boolean'],
					'lastImportAt' => ['type' => 'string', 'nullable' => true],
					'products' => ['type' => 'integer'],
				],
			],
			'OrderHistoryItem' => [
				'type' => 'object',
				'properties' => [
					'at' => ['type' => 'string', 'nullable' => true],
					'operation' => ['type' => 'string', 'nullable' => true],
					'message' => ['type' => 'string', 'nullable' => true],
					'by' => ['type' => 'string', 'nullable' => true],
				],
			],
			'Shipment' => [
				'type' => 'object',
				'properties' => [
					'type' => ['type' => 'string', 'nullable' => true],
					'trackingCode' => ['type' => 'string', 'nullable' => true],
					'trackingUrl' => ['type' => 'string', 'nullable' => true],
					'shippedAt' => ['type' => 'string', 'nullable' => true],
					'packages' => ['type' => 'array', 'items' => ['type' => 'object']],
				],
			],
			'Review' => [
				'type' => 'object',
				'properties' => [
					'score' => ['type' => 'number', 'nullable' => true],
					'text' => ['type' => 'string', 'nullable' => true],
					'author' => ['type' => 'string', 'nullable' => true],
					'recommends' => ['type' => 'boolean', 'nullable' => true],
					'createdAt' => ['type' => 'string', 'nullable' => true],
				],
			],
			'ReviewReport' => [
				'type' => 'object',
				'properties' => [
					'productId' => ['type' => 'string'],
					'code' => ['type' => 'string', 'nullable' => true],
					'name' => ['type' => 'string', 'nullable' => true],
					'reviews' => ['type' => 'integer'],
					'score' => ['type' => 'number'],
				],
			],
			'Fulfillment' => [
				'type' => 'object',
				'properties' => [
					'orderId' => ['type' => 'string'],
					'number' => ['type' => 'string'],
					'ageDays' => ['type' => 'integer'],
					'total' => ['$ref' => '#/components/schemas/Money'],
					'packages' => ['type' => 'integer'],
					'exported' => ['type' => 'boolean'],
				],
			],
			'Import' => [
				'type' => 'object',
				'properties' => [
					'supplier' => ['type' => 'string', 'nullable' => true],
					'status' => ['type' => 'string', 'nullable' => true],
					'startedAt' => ['type' => 'string', 'nullable' => true],
					'finishedAt' => ['type' => 'string', 'nullable' => true],
					'inserted' => ['type' => 'integer'],
					'updated' => ['type' => 'integer'],
					'error' => ['type' => 'string', 'nullable' => true],
				],
			],
			'CatalogHealth' => [
				'type' => 'object',
				'properties' => [
					'check' => ['type' => 'string'],
					'products' => ['type' => 'integer'],
					'detail' => ['type' => 'string'],
					'examples' => ['type' => 'array', 'items' => ['type' => 'object']],
				],
			],
			'Unlinked' => [
				'type' => 'object',
				'properties' => [
					'ordersWithoutInvoice' => ['type' => 'array', 'items' => ['type' => 'object']],
					'invoicesWithoutOrder' => ['type' => 'array', 'items' => ['type' => 'object']],
					'ordersNotExported' => ['type' => 'array', 'items' => ['type' => 'object']],
				],
			],
			'AbandonedCarts' => [
				'type' => 'object',
				'properties' => [
					'carts' => ['type' => 'integer'],
					'items' => ['type' => 'integer'],
					'value' => ['$ref' => '#/components/schemas/Money'],
					'topProducts' => ['type' => 'array', 'items' => ['type' => 'object']],
				],
			],
			'SupplierList' => $this->listSchema('Supplier'),
			'OrderHistoryList' => $this->listSchema('OrderHistoryItem'),
			'ShipmentList' => $this->listSchema('Shipment'),
			'ReviewList' => $this->listSchema('Review'),
			'ReviewReportList' => $this->listSchema('ReviewReport'),
			'FulfillmentList' => $this->listSchema('Fulfillment'),
			'ImportList' => $this->listSchema('Import'),
			'CatalogHealthList' => $this->listSchema('CatalogHealth'),
			'SearchList' => $this->listSchema('SearchHit'),
			'PricelistList' => $this->listSchema('Pricelist'),
			'PriceList' => $this->listSchema('Price'),
			'CategoryList' => $this->listSchema('Category'),
			'CustomerReportList' => $this->listSchema('CustomerReport'),
			'ReceivableList' => $this->listSchema('Receivable'),
			'ChurnList' => $this->listSchema('Churn'),
			'ReplenishmentList' => $this->listSchema('Replenishment'),
			'CustomerProductList' => $this->listSchema('CustomerProduct'),
			'CustomerList' => $this->listSchema('Customer'),
			'OrderList' => $this->listSchema('Order'),
			'InvoiceList' => $this->listSchema('Invoice'),
			'ProductList' => $this->listSchema('Product'),
			'StockList' => $this->listSchema('Stock'),
			'ReportList' => $this->listSchema('Report'),
			'TopProductList' => $this->listSchema('TopProduct'),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function listSchema(string $item): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'items' => ['type' => 'array', 'items' => ['$ref' => "#/components/schemas/$item"]],
				'nextCursor' => ['type' => 'string', 'nullable' => true, 'description' => 'Kurzor další stránky, nebo null.'],
				'hasMore' => ['type' => 'boolean'],
			],
		];
	}
}
