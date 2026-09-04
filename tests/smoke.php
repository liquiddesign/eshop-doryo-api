<?php

declare(strict_types=1);

/**
 * Smoke testy čtecího API pro Doryo. Spouští se proti běžící instanci:
 *
 *   php tests/DoryoApi/smoke.php https://mcprofi.test.liquiddesign.work/doryo-api <token>
 *
 * Hlídá to, co spec vyžaduje jako nepřekročitelné: API je jen ke čtení, bez tokenu nevydá nic,
 * obálky a částky mají dohodnutý tvar, stropy platí a v odpovědích nejsou interní data.
 * Schválně to jede přes HTTP, ne přes DI kontejner — testuje se i routa, autentizace
 * a to, co reálně vyleze na drátě.
 */

$baseUrl = \rtrim($argv[1] ?? '', '/');
$token = $argv[2] ?? '';

if ($baseUrl === '' || $token === '') {
	\fwrite(\STDERR, "Použití: php tests/DoryoApi/smoke.php <base-url> <token>\n");

	exit(2);
}

$failures = 0;
$passed = 0;

function request(string $url, ?string $token = null, string $method = 'GET'): array
{
	$curl = \curl_init($url);
	\curl_setopt_array($curl, [
		\CURLOPT_RETURNTRANSFER => true,
		\CURLOPT_CUSTOMREQUEST => $method,
		\CURLOPT_HTTPHEADER => $token !== null ? ["Authorization: Bearer $token"] : [],
		\CURLOPT_TIMEOUT => 60,
	]);

	$body = (string) \curl_exec($curl);
	$status = (int) \curl_getinfo($curl, \CURLINFO_HTTP_CODE);
	$contentType = (string) \curl_getinfo($curl, \CURLINFO_CONTENT_TYPE);
	\curl_close($curl);

	return [$status, \json_decode($body, true), $body, $contentType];
}

function check(string $name, bool $condition, string $detail = ''): void
{
	global $failures, $passed;

	if ($condition) {
		$passed++;
		echo "  ok   $name\n";

		return;
	}

	$failures++;
	echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
}

/**
 * Rekurzivně hledá klíč nebo hodnotu, která v odpovědi nemá co dělat.
 */
function containsForbidden(mixed $data, array $forbidden): ?string
{
	if (\is_array($data)) {
		foreach ($data as $key => $value) {
			if (\is_string($key) && \in_array(\strtolower($key), $forbidden, true)) {
				return (string) $key;
			}

			$found = containsForbidden($value, $forbidden);

			if ($found !== null) {
				return $found;
			}
		}
	}

	return null;
}

function isMoney(mixed $value): bool
{
	return $value === null || (\is_array($value)
		&& isset($value['amount'], $value['currency'])
		&& \is_string($value['amount'])
		&& (bool) \preg_match('/^-?\d+\.\d{2}$/', $value['amount']));
}

echo "Doryo API smoke: $baseUrl\n\n";

echo "autentizace\n";
[$status] = request("$baseUrl/v1/customers");
check('bez tokenu je 401', $status === 401, "dostal jsem $status");

[$status, , , $contentType] = request("$baseUrl/v1/customers", 'spatny-token');
check('se špatným tokenem je 401', $status === 401, "dostal jsem $status");
check('chyba je problem+json', \str_contains($contentType, 'application/problem+json'), $contentType);

[$status] = request("$baseUrl/v1/customers", $token, 'POST');
check('POST je 405', $status === 405, "dostal jsem $status");

[$status] = request("$baseUrl/v1/customers", $token, 'PUT');
check('PUT je 405', $status === 405, "dostal jsem $status");

[$status] = request("$baseUrl/v1/neexistuje", $token);
check('neznámý endpoint je 404', $status === 404, "dostal jsem $status");

echo "\nhealth\n";
[$status, $index, , $contentType] = request($baseUrl);
check('kořen API odpovídá JSONem, ne stránkou shopu', $status === 200 && \str_contains($contentType, 'application/json'), $contentType);
check('kořen API ukazuje na dokumentaci', isset($index['documentation'], $index['health'], $index['capabilities']));

[$status, $health] = request("$baseUrl/v1/meta/health");
check('health jde i bez tokenu', $status === 200 && ($health['status'] ?? null) === 'ok');
check('health bez tokenu neprozradí shop', !isset($health['shop']) && !isset($health['eshopVersion']));

[$status, $health] = request("$baseUrl/v1/meta/health", $token);
check('health s tokenem má verzi a shop', $status === 200 && isset($health['version'], $health['shop']['currency']));
$languages = $health['shop']['languages'] ?? [];
check('health nese každý jazyk jen jednou', \is_array($languages) && $languages !== [] && \count($languages) === \count(\array_unique($languages)), \implode(',', $languages));

echo "\nobálka a stránkování\n";

foreach (['customers', 'orders', 'invoices', 'products'] as $domain) {
	[$status, $data] = request("$baseUrl/v1/$domain?limit=2", $token);
	check(
		"$domain vrací obálku",
		$status === 200 && \is_array($data['items'] ?? null) && \array_key_exists('nextCursor', $data) && \is_bool($data['hasMore'] ?? null),
		"status $status",
	);
	check("$domain vrací nejvýš limit položek", \count($data['items'] ?? []) <= 2);
}

// Bez zadaného rozsahu se bere posledních šest měsíců. Instance s postarší kopií dat
// (typicky testovací) v tom okně nemá ani jednu objednávku, takže by kontroly tvaru dat
// běžely naprázdno a hlásily chybu, která žádná není — vada je v datech, ne v API.
// Okno se proto odvodí z toho, co shop podle capabilities reálně vede.
$ordersWindow = '';
$reportWindow = '';
[, $probe] = request("$baseUrl/v1/orders?limit=1", $token);

if (($probe['items'] ?? []) === []) {
	[, $caps] = request("$baseUrl/v1/meta/capabilities", $token);
	$lastAt = $caps['features']['orders']['lastAt'] ?? null;

	if (\is_string($lastAt)) {
		$to = \substr($lastAt, 0, 10);
		$from = \date('Y-m-d', (int) \strtotime("$to -6 months"));
		$ordersWindow = "&createdFrom=$from&createdTo=$to";
		$reportWindow = "&from=$from&to=$to";
		echo "  (data jsou starší; kontroly objednávek jedou v okně {$from} - {$to})\n";
	}
}

[, $first] = request("$baseUrl/v1/orders?limit=1$ordersWindow", $token);
[, $second] = request("$baseUrl/v1/orders?limit=1$ordersWindow&cursor=" . \urlencode((string) ($first['nextCursor'] ?? '')), $token);
check(
	'kurzor posune na další stránku',
	isset($first['items'][0]['id'], $second['items'][0]['id']) && $first['items'][0]['id'] !== $second['items'][0]['id'],
);

echo "\nstropy a validace\n";
[$status] = request("$baseUrl/v1/customers?limit=1001", $token);
check('limit nad strop je 400', $status === 400, "dostal jsem $status");

[$status] = request("$baseUrl/v1/customers?limit=abc", $token);
check('nečíselný limit je 400', $status === 400, "dostal jsem $status");

[$status] = request("$baseUrl/v1/customers?cursor=rozbity", $token);
check('rozbitý kurzor je 400', $status === 400, "dostal jsem $status");

[$status] = request("$baseUrl/v1/orders?createdFrom=2020-01-01&createdTo=2026-01-01", $token);
check('okno delší než 24 měsíců je 400', $status === 400, "dostal jsem $status");

[$status] = request("$baseUrl/v1/orders?createdFrom=1.1.2026", $token);
check('špatný formát data je 400', $status === 400, "dostal jsem $status");

[$status] = request("$baseUrl/v1/orders?status=nesmysl", $token);
check('neznámý stav objednávky je 400', $status === 400, "dostal jsem $status");

[$status] = request("$baseUrl/v1/reports/sales?groupBy=nesmysl", $token);
check('neznámé groupBy je 400', $status === 400, "dostal jsem $status");

[$status] = request("$baseUrl/v1/stock", $token);
check('stock bez parametru je 400', $status === 400, "dostal jsem $status");

[$status, $data] = request("$baseUrl/v1/customers?since=2099-01-01", $token);
check('filtr since funguje', $status === 200 && ($data['items'] ?? []) === []);

[$status] = request("$baseUrl/v1/orders/neexistujici-id", $token);
check('detail neexistujícího záznamu je 404', $status === 404, "dostal jsem $status");

echo "\ntvar dat\n";
[, $orders] = request("$baseUrl/v1/orders?limit=5$ordersWindow", $token);
$order = $orders['items'][0] ?? [];
check('objednávka má peníze jako řetězec', isMoney($order['total'] ?? null) && isMoney($order['totalWithoutVat'] ?? null), \json_encode($order['total'] ?? null));
check('objednávka má normalizovaný stav', \in_array($order['status'] ?? null, ['new', 'processing', 'shipped', 'delivered', 'cancelled', 'returned', null], true));
check('seznam objednávek nenese položky', \array_key_exists('items', $order) && $order['items'] === null);

if (isset($order['id'])) {
	[$status, $detail] = request("$baseUrl/v1/orders/" . \rawurlencode($order['id']), $token);
	check('detail objednávky má položky', $status === 200 && \is_array($detail['items'] ?? null));
	check('položka má peníze jako řetězec', !isset($detail['items'][0]) || isMoney($detail['items'][0]['unitPrice']));
}

[, $products] = request("$baseUrl/v1/products?limit=5", $token);
$product = $products['items'][0] ?? [];
check('produkt má cenu jako řetězec', isMoney($product['price'] ?? null) && isMoney($product['priceWithVat'] ?? null));

[, $sales] = request("$baseUrl/v1/reports/sales?groupBy=month$reportWindow", $token);
check('report tržeb má klíč a částky', isset($sales['items'][0]['key']) && isMoney($sales['items'][0]['revenue']));

echo "\nzákazník a jeho doklady\n";
$customerId = null;

foreach ($orders['items'] ?? [] as $candidate) {
	if (($candidate['customerId'] ?? null) !== null) {
		$customerId = $candidate['customerId'];

		break;
	}
}

if ($customerId === null) {
	check('objednávka nese zákazníka', false, 'v prvních pěti objednávkách žádný není, kontroly zákazníka přeskočeny');
} else {
	[$status, $customer] = request("$baseUrl/v1/customers/" . \rawurlencode($customerId), $token);
	check('detail zákazníka', $status === 200 && ($customer['id'] ?? null) === $customerId);
	check('zákazník má blok eshop', isset($customer['eshop']['group']) || \array_key_exists('group', $customer['eshop'] ?? []));

	[$status, $summary] = request("$baseUrl/v1/customers/" . \rawurlencode($customerId) . '/summary', $token);
	check('souhrn zákazníka', $status === 200 && \is_int($summary['orders'] ?? null) && isMoney($summary['revenue'] ?? null) && isMoney($summary['outstanding'] ?? null));

	[$status, $customerOrders] = request("$baseUrl/v1/customers/" . \rawurlencode($customerId) . '/orders?limit=3', $token);
	check('objednávky zákazníka', $status === 200 && \is_array($customerOrders['items'] ?? null));
	check(
		'objednávky zákazníka jsou opravdu jeho',
		\array_reduce($customerOrders['items'] ?? [], static fn (bool $ok, array $o): bool => $ok && $o['customerId'] === $customerId, true),
	);

	[$status, $customerInvoices] = request("$baseUrl/v1/customers/" . \rawurlencode($customerId) . '/invoices?limit=3', $token);
	check('faktury zákazníka', $status === 200 && \is_array($customerInvoices['items'] ?? null));
}

echo "\nčíselníky, hledání a ceny\n";
[$status, $codebooks] = request("$baseUrl/v1/meta/codebooks", $token);
check('číselníky', $status === 200 && \is_array($codebooks['pricelists'] ?? null) && \is_array($codebooks['stores'] ?? null));
check('číselníky nesou počty', isset($codebooks['counts']['products'], $codebooks['counts']['orders']));

$productCode = $product['code'] ?? null;

if ($productCode !== null) {
	[$status, $found] = request("$baseUrl/v1/search?q=" . \rawurlencode($productCode), $token);
	check(
		'hledání najde produkt podle kódu',
		$status === 200 && \array_filter($found['items'] ?? [], static fn (array $hit): bool => $hit['type'] === 'products'),
	);

	[$status, $byCode] = request("$baseUrl/v1/products/by-code/" . \rawurlencode($productCode), $token);
	check('detail produktu podle kódu', $status === 200 && ($byCode['code'] ?? null) === $productCode);
}

[$status, $pricelists] = request("$baseUrl/v1/pricelists?limit=200", $token);
check('seznam ceníků', $status === 200 && \is_array($pricelists['items'] ?? null));

[$status] = request("$baseUrl/v1/prices", $token);
check('ceny bez ceníku jsou 400', $status === 400, "dostal jsem $status");

// Schválně ten NEJMENŠÍ ceník, který podle seznamu produkty má. Velké ceníky pokrývají
// skoro celý katalog, takže projdou i tehdy, když se stránkuje přes produkty místo přes
// ceník; malý ceník takovou chybu odhalí — vrátil by prázdnou první stránku.
$pricelist = null;

foreach ($pricelists['items'] ?? [] as $item) {
	if (($item['products'] ?? 0) < 1) {
		continue;
	}

	if ($pricelist === null || $item['products'] < $pricelist['products']) {
		$pricelist = $item;
	}
}

if ($pricelist !== null) {
	$key = $pricelist['code'] ?? $pricelist['id'];
	[$status, $prices] = request("$baseUrl/v1/prices?pricelist=" . \rawurlencode($key) . '&limit=3', $token);
	check('ceny z ceníku', $status === 200 && \is_array($prices['items'] ?? null));
	check(
		'ceník s produkty vrací ceny hned na první stránce',
		isset($prices['items'][0]),
		"ceník {$pricelist['name']} hlásí {$pricelist['products']} produktů, ale /v1/prices vrátil prázdno",
	);
	check('cena je řetězec s měnou', !isset($prices['items'][0]) || isMoney($prices['items'][0]['price']));
}

[$status] = request("$baseUrl/v1/prices?pricelist=neexistujici-cenik", $token);
check('neznámý ceník je 404', $status === 404, "dostal jsem $status");

if ($customerId !== null) {
	[$status, $customerPrices] = request("$baseUrl/v1/customers/" . \rawurlencode($customerId) . '/prices?limit=3', $token);
	check('ceny zákazníka', \in_array($status, [200, 403], true), "dostal jsem $status");

	if ($status === 200 && isset($customerPrices['items'][0])) {
		$first = $customerPrices['items'][0];
		check('cena zákazníka nese ceníkovou i jeho cenu', isMoney($first['listPrice']) && isMoney($first['price']) && isset($first['pricelist']['name']));
		check('cena zákazníka není vyšší než ceníková', (float) $first['price']['amount'] <= (float) $first['listPrice']['amount']);
	}
}

echo "\ndiagnostika\n";

if (isset($product['id'])) {
	[$status, $visibility] = request("$baseUrl/v1/products/" . \rawurlencode($product['id']) . '/visibility', $token);
	check('diagnostika viditelnosti', $status === 200 && \is_bool($visibility['visible'] ?? null) && \is_array($visibility['findings'] ?? null));
	check('diagnostika nese kontroly', isset($visibility['checks']['visibilityLists']) && \array_key_exists('publicPrices', $visibility['checks']));

	[$status, $media] = request("$baseUrl/v1/products/" . \rawurlencode($product['id']) . '/media', $token);
	check('diagnostika obrázků', $status === 200 && \array_key_exists('hasImage', $media) && \is_array($media['findings'] ?? null));
}

[, $invoices] = request("$baseUrl/v1/invoices?limit=1", $token);
$invoiceId = $invoices['items'][0]['id'] ?? null;

if ($invoiceId !== null) {
	[$status, $payment] = request("$baseUrl/v1/invoices/" . \rawurlencode($invoiceId) . '/payment', $token);
	check('diagnostika úhrady', $status === 200 && isset($payment['status']) && \is_array($payment['findings'] ?? null));
	check('diagnostika úhrady nese pole, ze kterých se stav bere', isset($payment['checks']['total']) && \array_key_exists('paidOn', $payment['checks']));
}

echo "\nreporty přes zákazníky a zásoby\n";

foreach (['customers', 'receivables', 'churn', 'replenishment'] as $report) {
	[$status, $data] = request("$baseUrl/v1/reports/$report?limit=5", $token);
	check("report $report", $status === 200 && \is_array($data['items'] ?? null), "status $status");
}

[$status, $growth] = request("$baseUrl/v1/reports/customers?limit=5&sort=growth", $token);
check(
	'report zákazníků nese srovnání',
	$status === 200 && (!isset($growth['items'][0]) || (isMoney($growth['items'][0]['revenue']) && isMoney($growth['items'][0]['change']))),
);

[$status, $stock] = request("$baseUrl/v1/stock?q=" . \rawurlencode((string) $productCode), $token);
check(
	'sklad má rozpad po skladech',
	$status === 200 && (!isset($stock['items'][0]) || \is_array($stock['items'][0]['stock']['byStore'] ?? null)),
);

echo "\ncapabilities a provozní domény\n";
[$status, $capabilities] = request("$baseUrl/v1/meta/capabilities", $token);
check('capabilities', $status === 200 && \is_array($capabilities['features'] ?? null));
check(
	'capabilities říká u každé domény, jestli ji shop používá',
	\array_reduce(
		$capabilities['features'] ?? [],
		static fn (bool $ok, array $feature): bool => $ok && \is_bool($feature['available'] ?? null),
		true,
	),
);
check('capabilities zná objednávky', ($capabilities['features']['orders']['available'] ?? null) === true);

[$status, $suppliers] = request("$baseUrl/v1/suppliers", $token);
check('dodavatelé', $status === 200 && \is_array($suppliers['items'] ?? null));

if (isset($order['id'])) {
	[$status, $history] = request("$baseUrl/v1/orders/" . \rawurlencode($order['id']) . '/history?limit=5', $token);
	check('historie objednávky', $status === 200 && \is_array($history['items'] ?? null));

	[$status, $shipments] = request("$baseUrl/v1/orders/" . \rawurlencode($order['id']) . '/shipments', $token);
	check('zásilky objednávky', $status === 200 && \is_array($shipments['items'] ?? null));
}

if (isset($product['id'])) {
	[$status, $productReviews] = request("$baseUrl/v1/products/" . \rawurlencode($product['id']) . '/reviews?limit=3', $token);
	check('hodnocení produktu', $status === 200 && \is_array($productReviews['items'] ?? null));
	check('produkt nese parametry', \array_key_exists('attributes', $product));
}

[$status] = request("$baseUrl/v1/products?attribute=nesmysl&limit=1", $token);
check('parametr bez dvojtečky je 400', $status === 400, "dostal jsem $status");

// catalog-health nestránkuje (vrací pevný seznam kontrol) a od 1.1 je neznámý parametr 400,
// takže `limit` smí jen tam, kde se stránkuje
foreach (['fulfillment' => '?limit=5', 'reviews' => '?limit=5', 'imports' => '?limit=5', 'catalog-health' => ''] as $report => $params) {
	[$status, $data] = request("$baseUrl/v1/reports/$report$params", $token);
	check("report $report", $status === 200 && \is_array($data['items'] ?? null), "status $status");
}

[$status, $health] = request("$baseUrl/v1/reports/catalog-health?samples=2", $token);
check(
	'zdraví katalogu nese ukázky',
	$status === 200 && (!isset($health['items'][0]) || (\is_int($health['items'][0]['products']) && \is_array($health['items'][0]['examples']))),
);

[$status, $unlinked] = request("$baseUrl/v1/reports/unlinked?limit=3", $token);
check('doklady bez protějšku', $status === 200 && \is_array($unlinked['ordersWithoutInvoice'] ?? null) && \is_array($unlinked['invoicesWithoutOrder'] ?? null));

[$status, $carts] = request("$baseUrl/v1/reports/abandoned-carts", $token);
check('opuštěné košíky', $status === 200 && \is_int($carts['carts'] ?? null));
check('opuštěné košíky bez withItems neplatí za hodnotu', \array_key_exists('value', $carts) && $carts['value'] === null && isset($carts['hint']));

[$status, $notExported] = request("$baseUrl/v1/orders?exported=false&limit=3", $token);
check('filtr na nezaexportované objednávky', $status === 200 && \is_array($notExported['items'] ?? null));

[$status, $withoutOrder] = request("$baseUrl/v1/invoices?withoutOrder=true&limit=3", $token);
check('filtr na faktury bez objednávky', $status === 200 && \is_array($withoutOrder['items'] ?? null));

echo "\nco v odpovědi nesmí být\n";
$forbidden = ['password', 'hash', 'purchaseprice', 'internalnote', 'token', 'secret', 'ccemails', 'accountpassword'];

foreach (['customers?limit=20', 'orders?limit=20', 'invoices?limit=20', 'products?limit=20'] as $path) {
	[, $data] = request("$baseUrl/v1/$path", $token);
	$found = containsForbidden($data, $forbidden);
	check("$path neobsahuje interní pole", $found === null, "našel jsem $found");
}

echo "\nsrozumitelnost pro volajícího\n";

// Překlep v názvu parametru nesmí projít tiše — jinak volající dostane nefiltrovaná data
// a bude je považovat za odfiltrovaná.
[$status, $problem] = request("$baseUrl/v1/orders?limit=1&zakaznik=nesmysl", $token);
check('neznámý parametr je 400', $status === 400, "dostal jsem $status");
check(
	'chyba u neznámého parametru vyjmenuje, co endpoint zná',
	\str_contains((string) ($problem['detail'] ?? ''), 'zná:'),
	(string) ($problem['detail'] ?? ''),
);

[$status] = request("$baseUrl/v1/orders?limit=1&Limit=2", $token);
check('překlep ve velikosti písmen je 400', $status === 400, "dostal jsem $status");

[$status] = request("$baseUrl/v1/orders?limit=1", $token);
check('správné parametry projdou', $status === 200, "dostal jsem $status");

// Výchozí okno se musí přiznat. Bez toho nejde rozeznat „nic tam není" od
// „je to starší, než kam výchozí okno sahá".
[$status, $orders] = request("$baseUrl/v1/orders?limit=1", $token);
check('výchozí okno je vidět v odpovědi', isset($orders['window']['from'], $orders['window']['to']));
check('výchozí okno je označené jako výchozí', ($orders['window']['defaulted'] ?? null) === true);
check(
	'okno pojmenuje parametry, kterými se přebije',
	($orders['window']['params'] ?? []) === ['createdFrom', 'createdTo'],
	\implode(',', $orders['window']['params'] ?? []),
);

if (($orders['items'] ?? []) === []) {
	check('prázdný výsledek ve výchozím okně vysvětlí, proč je prázdný', isset($orders['note']));
}

// Zadané okno se nekomentuje — volající ho zná, poznámka by jen ujídala kontext.
[$status, $windowed] = request("$baseUrl/v1/orders?limit=1&createdFrom=2020-01-01&createdTo=2020-12-31", $token);
check('zadané okno se v odpovědi neopakuje', $status === 200 && !isset($windowed['window'], $windowed['note']));

// Doména, kterou shop nevede, se nesmí tvářit jako „zatím nic". Platí jen tam, kde
// capabilities hlásí reviews: false — jinde je prázdno legitimní odpověď.
[$status, $capabilities] = request("$baseUrl/v1/meta/capabilities", $token);

if (($capabilities['features']['reviews']['available'] ?? null) === false && isset($product['id'])) {
	[$status, $reviews] = request("$baseUrl/v1/products/" . \rawurlencode($product['id']) . '/reviews', $token);
	check('recenze u shopu, který je nevede, to řeknou', $status === 200 && isset($reviews['note']));

	[$status, $reviewReport] = request("$baseUrl/v1/reports/reviews", $token);
	check('report recenzí u shopu, který je nevede, to řekne', $status === 200 && isset($reviewReport['note']));
}

echo "\nopenapi\n";
[$status] = request("$baseUrl/openapi.json");
check('openapi bez tokenu je 401', $status === 401, "dostal jsem $status");

[$status, $spec] = request("$baseUrl/openapi.json", $token);
check('openapi je validní JSON', $status === 200 && \is_array($spec));
check('openapi popisuje endpointy', isset($spec['paths']['/v1/orders']['get']['summary']));
check('openapi má bezpečnostní schéma', isset($spec['components']['securitySchemes']['bearerAuth']));
$expectedPaths = [
	'/v1/customers', '/v1/orders', '/v1/invoices', '/v1/products', '/v1/stock', '/v1/reports/sales',
	'/v1/search', '/v1/pricelists', '/v1/prices', '/v1/customers/{id}/prices', '/v1/meta/codebooks',
	'/v1/products/{id}/visibility', '/v1/products/{id}/media', '/v1/invoices/{id}/payment',
	'/v1/reports/customers', '/v1/reports/receivables', '/v1/reports/churn', '/v1/reports/replenishment',
	'/v1/meta/capabilities', '/v1/suppliers', '/v1/orders/{id}/history', '/v1/orders/{id}/shipments',
	'/v1/products/{id}/reviews', '/v1/reports/fulfillment', '/v1/reports/reviews', '/v1/reports/imports',
	'/v1/reports/catalog-health', '/v1/reports/unlinked', '/v1/reports/abandoned-carts',
];
$missingPaths = \array_diff($expectedPaths, \array_keys($spec['paths'] ?? []));
check('openapi popisuje všechny domény', $missingPaths === [], 'chybí ' . \implode(', ', $missingPaths));

echo "\n";
echo $failures === 0
	? "Všech $passed kontrol prošlo.\n"
	: "$failures z " . ($passed + $failures) . " kontrol selhalo.\n";

exit($failures === 0 ? 0 : 1);
