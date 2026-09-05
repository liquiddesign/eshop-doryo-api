# eshop-doryo-api

Čtecí API e-shopu pro [Doryo](https://lqd.kolego.cz) — objednávky, zákazníci, produkty, sklad
a faktury v jednotné projekci, ve stejném tvaru, v jakém je vydávají ERP konektory.

Balík staví na `liquiddesign/eshop` a nepotřebuje od shopu nic než konfiguraci. Rozdíly mezi
verzemi 2.0–2.2 řeší uvnitř: repozitáře si bere přes `DIConnection::findRepository()`, chybějící
tabulka nebo relace je `null`, ne chyba, a `/v1/meta/capabilities` řekne, co daný shop reálně vede.

Se sloupci, které mezi verzemi přibyly, se počítá zvlášť — chybějící sloupec v podmínce dotaz
neshodí, jen se podmínka vynechá (`Codebooks::hasColumn()`). Týká se to `eshop_product.deletedTs`
a `eshop_price.hidden`, které jsou až od eshopu 2.1; shop na 2.0 produkty měkce nemaže a ceny
neskrývá, takže tam ty podmínky nedávají smysl. Běží to i na StORM 1.1 — balík z něj používá
jen API, které je v 1.1 i 2.0 shodné.

**Jen ke čtení.** Žádný endpoint nemění data; jiná metoda než GET/HEAD vrací `405`.

## Instalace

```bash
composer require liquiddesign/eshop-doryo-api
```

```neon
extensions:
    doryoApi: DoryoApi\Bridges\DoryoApiDI

doryoApi:
    shopName: 'Můj shop'
    shopUrl: 'https://muj-shop.cz'
    # null = token se vezme z proměnné prostředí DORYO_API_TOKEN; bez tokenu je API vypnuté
    token: null
```

Služby, routy i mapování presenteru si balík zaregistruje sám. Zbývá jediné, co musí udělat
projekt: **pustit požadavek přes svou bránu na front**. Projekty postavené na `Base\Application`
mají v `config/environments.neon` seznam `frontAccess.exclude` — přidej do něj `DoryoApi:Api`,
jinak brána odmítne požadavek dřív, než se dostane na token.

Dvě věci, na které se naráží na klasickém serveru:

- **Adresa shopu.** `shopUrl` bývá v repu s produkční adresou; testovací server ji přepíše env
  proměnnou `DORYO_API_SHOP_URL` (má přednost před configem), třeba `SetEnv` ve vhostu. Bez toho
  rozcestník i odkazy na produkty ukazují na produkci, i když data jdou z testu.
- **Apache s PHP přes FastCGI/CGI** hlavičku `Authorization` do PHP nepředává a API pak vrací
  `401 Chybí hlavička Authorization`, i když je token správně. Do vhostu dej `CGIPassAuth On`
  (Apache ≥ 2.4.13), nebo do `.htaccess`:

  ```apache
  RewriteCond %{HTTP:Authorization} .
  RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
  ```

  Balík si hlavičku z `HTTP_AUTHORIZATION` i `REDIRECT_HTTP_AUTHORIZATION` přečte sám.

## Konfigurace

| klíč | výchozí | k čemu |
| --- | --- | --- |
| `prefix` | `doryo-api` | prefix cesty; routy se registrují podle něj |
| `token` | `null` | Bearer token; `null` = z env `DORYO_API_TOKEN` |
| `shopUrl` | `null` | veřejná adresa shopu v odkazech; env `DORYO_API_SHOP_URL` ji přepíše |
| `allowIps` | `[]` | whitelist IP/CIDR; prázdné = stačí platný token |
| `currency` | `CZK` | měna, ve které se vydávají částky bez vazby na ceník |
| `defaultPricelists` | `[]` | ceníky pro veřejnou cenu; prázdné = ceníky výchozí skupiny zákazníků |
| `defaultCustomerGroup` | `null` | skupina, ze které se ceníky vezmou; `null` = výchozí po registraci |
| `orderStates` | viz níž | mapa normalizovaný stav → stavy shopu |
| `invoicePaymentTracked` | `true` | eviduje shop úhrady faktur? kde je vede ERP, dej `false` |
| `customerPrices` | `false` | vydávat ceny konkrétního zákazníka (vědomá výjimka, viz níž) |
| `extensions` | `[]` | služby implementující `DoryoApi\Extension\DoryoApiExtension` |

Výchozí mapa stavů je `new: [open]`, `processing: [received]`, `delivered: [finished]`,
`cancelled: [canceled]`. Stavy `shipped` a `returned` eshop nerozlišuje; shop, který si je vede
po svém, si mapu přepíše.

## Co API vydává

Kořen `/{prefix}` vrací rozcestník (odkaz na `openapi.json`, health a capabilities) — kdo si
adresu otevře v prohlížeči, dostane odpověď API, ne stránkovou 404 shopu.

Zbytek je na `/{prefix}/v1/…`, seznamy v obálce `{ items, nextCursor, hasMore }`, částky jako
**řetězec** s měnou (`{"amount": "12500.00", "currency": "CZK"}`), chyby v `application/problem+json`
česky. `/{prefix}/openapi.json` popisuje endpointy pro introspekci.

- **zákazníci** — seznam, detail, jejich objednávky, faktury, souhrn, odebírané položky
- **objednávky** — seznam a detail s položkami, historie změn, zásilky a balíky
- **faktury** — seznam a detail, diagnostika úhrady
- **produkty a sklad** — katalog s parametry, sklad s rozpadem po skladech, diagnostika
  viditelnosti a obrázků
- **ceny** — ceníky, ceny z ceníku a (volitelně) ceny konkrétního zákazníka
- **reporty** — tržby, top produkty, růst a pokles zákazníků, pohledávky, churn, pokrytí zásob,
  expedice, hodnocení, importy, zdraví katalogu, doklady bez protějšku
- **orientace** — `meta/capabilities`, `meta/codebooks`, `categories`, `suppliers`, `search`

## Aby odpověď nešla přečíst špatně

Odpověď čte model, ne člověk, takže dvě věci nejdou nechat na domýšlení:

**Prázdný seznam řekne, jestli za tím není jen výchozí okno.** Seznamy a reporty bez zadaného
rozsahu berou posledních `defaultWindowMonths` měsíců. Když se okno vzalo z výchozí hodnoty,
odpověď to přizná v `window` — a je-li výsledek prázdný, přidá i `note`:

```json
{
  "items": [],
  "nextCursor": null,
  "hasMore": false,
  "window": { "from": "2026-03-04", "to": "2026-09-04", "params": ["createdFrom", "createdTo"], "defaulted": true },
  "note": "Prázdné nemusí znamenat, že záznamy nejsou: bez createdFrom a createdTo se bere posledních 6 měsíců…"
}
```

Bez toho nejde rozeznat „zákazník nic neodebral" od „data jsou starší, než kam výchozí okno
sahá" — obojí je `items: []`. Když si rozsah zadáš sám, `window` ani `note` v odpovědi nejsou;
víš, na co ses ptal, a nemá smysl tím ujídat kontext.

**Překlep v názvu parametru je `400`, ne ticho.** `?zakaznik=…` nebo `?CreatedFrom=…` by se
jinak zahodily a vrátila by se nefiltrovaná data, která vypadají jako odfiltrovaná. Chyba
vyjmenuje, co daný endpoint zná:

```
Neznámý parametr zakaznik. Tenhle endpoint zná: createdFrom, createdTo, cursor, customerId,
limit, q, status… Úplný popis je v /openapi.json.
```

## Nejdřív se zeptej, co shop vede

`GET /v1/meta/capabilities` řekne u každé domény, jestli ji shop používá, kolik má záznamů a kdy
do ní naposled něco přibylo. Bez toho nejde poznat rozdíl mezi „dnes nic" a „tohle se tu
nepoužívá" — a modelu se pak snadno stane, že si domyslí odpověď.

Kontrola se neptá jen na existenci řádků, ale na **použitelné** řádky. Ověřeno v praxi: shop měl
65 tisíc řádků recenzí, ve kterých nebylo ani jedno vyplněné hodnocení — byly to odeslané žádosti
o hodnocení, ne recenze.

## Co API nikdy nevydá

Odpověď skládá mapper pole po poli, nikdy `toArray()` entity. Ven nejdou hesla ani hashe, tokeny,
nákupní ceny a marže, interní poznámky adminů ani platební údaje. Osobní údaje zákazníků **ano** —
Doryo je pseudonymizuje na své straně a cenzurovat je tady by API znehodnotilo.

Jediná vědomá výjimka je `customerPrices`. Ceny konkrétního zákazníka jsou obchodní tajemství
a ve výchozím stavu jsou vypnuté (endpoint vrací `403`); bez nich ale nejde sestavit cenová
nabídka, tak ať je to rozhodnutí shopu, ne balíku.

## Rozšíření o vlastní pole

```php
final class MojeRozsireni implements DoryoApi\Extension\DoryoApiExtension
{
    public function extendOrder(Eshop\DB\Order $order, array &$out): void
    {
        $out['eshop']['channel'] = 'web';
    }
    // extendCustomer(), extendProduct()
}
```

```neon
doryoApi:
    extensions: [@mojeRozsireni]
```

Rozšíření smí přidávat **jen do klíče `eshop`** — standardní pole mapper po zavolání vrátí zpátky,
aby se nedal rozbít kontrakt s Doryo.

## Testy

```bash
php tests/smoke.php https://muj-shop.cz/doryo-api <token>
```

Devadesát kontrol přes HTTP: autentizace, `405` na zápisové metody, tvar obálek, částky jako
řetězce, stránkování kurzorem, stropy a validace parametrů, ceny, diagnostika, reporty, whitelist
polí a platnost OpenAPI. Jede to schválně po drátě, ne přes DI kontejner — testuje se i routa
a autentizace.
