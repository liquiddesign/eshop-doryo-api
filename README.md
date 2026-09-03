# eshop-doryo-api

Čtecí API e-shopu pro [Doryo](https://lqd.kolego.cz) — objednávky, zákazníci, produkty, sklad
a faktury v jednotné projekci, ve stejném tvaru, v jakém je vydávají ERP konektory.

Balík staví na `liquiddesign/eshop` a nepotřebuje od shopu nic než konfiguraci. Rozdíly mezi
verzemi 2.0–2.2 řeší uvnitř: repozitáře si bere přes `DIConnection::findRepository()`, chybějící
sloupec nebo relace je `null`, ne chyba, a `/v1/meta/capabilities` řekne, co daný shop reálně vede.

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

## Konfigurace

| klíč | výchozí | k čemu |
| --- | --- | --- |
| `prefix` | `doryo-api` | prefix cesty; routy se registrují podle něj |
| `token` | `null` | Bearer token; `null` = z env `DORYO_API_TOKEN` |
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

Vše na `/{prefix}/v1/…`, seznamy v obálce `{ items, nextCursor, hasMore }`, částky jako
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
