<?php

declare(strict_types=1);

namespace DoryoApi;

use DoryoApi\Auth\Authenticator;
use DoryoApi\Http\ApiException;
use DoryoApi\Http\Query;
use DoryoApi\Http\Response;
use DoryoApi\OpenApi\Specification;
use Nette\Http\IRequest;
use Nette\Utils\Arrays;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Nette\Utils\Strings;

/**
 * Vstupní bod API: metoda, autentizace, směrování, logování. Presenter už jen obalí výsledek
 * do HTTP odpovědi — díky tomu se dá celé API zavolat i z testu bez HTTP vrstvy.
 */
final class Api
{
	public function __construct(
		private Config $config,
		private Authenticator $authenticator,
		private Router $router,
		private Logger $logger,
		private Specification $specification,
	) {
	}

	public function handle(IRequest $request, string $path): Response
	{
		$started = \microtime(true);
		$path = Strings::trim($path, '/');
		$params = $request->getQuery();
		$response = null;

		$method = Strings::upper($request->getMethod());
		$reads = $method === 'GET' || $method === 'HEAD';

		try {
			$response = $this->route($request, $path, \is_array($params) ? $params : [], $method);
		} catch (ApiException $e) {
			$response = Response::problem($e);
		} catch (\Throwable $e) {
			\Tracy\Debugger::log($e, \Tracy\Debugger::EXCEPTION);

			$response = Response::problem(new ApiException(500, 'Chyba serveru', 'Požadavek se nepodařilo zpracovat.'));
		} finally {
			$this->logger->log(
				$path,
				\is_array($params) ? $params : [],
				$response?->getStatus() ?? 500,
				$response?->getItemCount(),
				(\microtime(true) - $started) * 1000,
				$method,
				// velikost v bajtech, ne ve znacích — jde o to, kolik toho přišlo po drátě
				// phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative
				$reads ? null : \strlen($request->getRawBody() ?? ''),
			);
		}

		return $response;
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function route(IRequest $request, string $path, array $params, string $method): Response
	{
		// Health a rozcestník jdou schválně i bez tokenu — monitoring i člověk, který si
		// adresu otevře v prohlížeči, mají dostat odpověď API. Bez autentizace ale neřeknou
		// nic o shopu, jen že služba běží.
		if ($path === '' || $path === 'v1/meta/health' || $path === 'openapi.json') {
			try {
				$this->authenticator->authenticate($request);
				$authenticated = true;
			} catch (ApiException $e) {
				if ($path === 'openapi.json') {
					throw $e;
				}

				$authenticated = false;
			}
		} else {
			$this->authenticator->authenticate($request);
			$authenticated = true;
		}

		if ($path === 'openapi.json') {
			self::assertMethod($method, ['GET', 'HEAD']);

			return new Response($this->specification->build($this->baseUrl($request)));
		}

		if ($path === '') {
			self::assertMethod($method, ['GET', 'HEAD']);

			return $this->index($request);
		}

		// metoda se porovnává až s tím, co endpoint deklaruje — dřív by 405 přebilo i 404
		// a projektový zápisový endpoint by se nikdy nedostal ke slovu
		[$handler, $routeParams, $methods] = $this->router->match($path);
		self::assertMethod($method, $methods);

		if ($path === 'v1/meta/health' && !$authenticated) {
			$routeParams['authenticated'] = '0';
		}

		// tělo se čte až tady: nejdřív musí projít token, pak existovat cesta, která ho vůbec bere
		$query = new Query($params, $this->config, self::parseBody($request, $method), $method);
		$response = $handler($routeParams, $query);

		$this->assertKnownParams($path, $query);

		return $this->annotate($response, $query);
	}

	/**
	 * Překlep v názvu parametru končí chybou, ne tichým ignorováním.
	 *
	 * Kdo se splete (`?zakaznik=`, `?CreatedFrom=`), dostal by jinak nefiltrovaná data
	 * a odpovídal by z nich, jako by filtr platil. To je horší než 400, ze kterého se
	 * volající umí opravit — a je to i to, co slibuje Query: „API nikdy netipuje".
	 */
	private function assertKnownParams(string $path, Query $query): void
	{
		// health si volá i monitoring, kterému se do URL běžně přidávají cizí parametry
		if ($path === 'v1/meta/health') {
			return;
		}

		$unknown = $query->getUnknownParams();

		if (!$unknown) {
			return;
		}

		throw ApiException::badRequest(\sprintf(
			'Neznámý parametr %s. Tenhle endpoint zná: %s. Úplný popis je v /openapi.json.',
			\implode(', ', $unknown),
			\implode(', ', $query->getKnownParams()),
		));
	}

	/**
	 * Když se okno vzalo z výchozí hodnoty, řekne se to v odpovědi — a u prázdného seznamu
	 * i to, že za tím může být právě ono.
	 *
	 * Bez toho nejde rozlišit „zákazník nic neodebral" od „data jsou starší, než kam
	 * výchozí okno sahá". Obojí vypadá jako `items: []` a druhý případ svádí k tomu
	 * odpovědět, že záznamy neexistují.
	 */
	private function annotate(Response $response, Query $query): Response
	{
		$window = $query->getAppliedWindow();

		if ($window === null || !$window['defaulted']) {
			return $response;
		}

		$extra = [
			'window' => [
				'from' => $window['from'],
				'to' => $window['to'],
				'params' => $window['params'],
				'defaulted' => true,
			],
		];

		$body = $response->getBody();

		if (isset($body['items']) && \is_array($body['items']) && !$body['items']) {
			$extra['note'] = \sprintf(
				'Prázdné nemusí znamenat, že záznamy nejsou: bez %s a %s se bere posledních %d měsíců, '
					. 'tedy %s až %s. Starší záznamy vrátí až dotaz s vlastním rozsahem.',
				$window['params'][0],
				$window['params'][1],
				$this->config->getDefaultWindowMonths(),
				$window['from'],
				$window['to'],
			);
		}

		return $response->withExtra($extra);
	}

	/**
	 * Rozcestník na kořeni API.
	 *
	 * Kdo si adresu API otevře v prohlížeči nebo ji zkusí zavolat bez cesty, ať dostane
	 * odpověď API, ne stránkovou 404 shopu — a rovnou odkaz na to, co si má přečíst dřív,
	 * než začne volat: popis endpointů a přehled toho, co tenhle shop vede.
	 */
	private function index(IRequest $request): Response
	{
		$baseUrl = $this->baseUrl($request);

		return new Response([
			'service' => 'eshop-doryo-api',
			'version' => Config::version(),
			'documentation' => "$baseUrl/openapi.json",
			'health' => "$baseUrl/v1/meta/health",
			'capabilities' => "$baseUrl/v1/meta/capabilities",
			'hint' => 'Endpointy jsou pod /v1 a chtějí hlavičku Authorization: Bearer <token>. '
				. 'Čtecí část umí jen GET, jiná metoda vrací 405; co která cesta umí, je v /openapi.json.',
		]);
	}

	private function baseUrl(IRequest $request): string
	{
		$url = $this->config->getShopUrl() ?? \rtrim($request->getUrl()->getBaseUrl(), '/');

		return \rtrim($url, '/') . '/' . $this->config->getPrefix();
	}

	/**
	 * Tělo požadavku. U čtecích metod se nečte vůbec, u zápisových musí být platný JSON objekt.
	 * @return array<mixed>|null
	 * @throws \DoryoApi\Http\ApiException
	 */
	private static function parseBody(IRequest $request, string $method): ?array
	{
		if ($method === 'GET' || $method === 'HEAD') {
			return null;
		}

		$raw = $request->getRawBody();

		if ($raw === null || Strings::trim($raw) === '') {
			return [];
		}

		try {
			$decoded = Json::decode($raw, Json::FORCE_ARRAY);
		} catch (JsonException $e) {
			throw ApiException::badRequest('Tělo požadavku není platný JSON: ' . $e->getMessage());
		}

		if (!\is_array($decoded)) {
			throw ApiException::badRequest('Tělo požadavku musí být JSON objekt.');
		}

		return $decoded;
	}

	/**
	 * @param array<string> $allowed
	 * @throws \DoryoApi\Http\ApiException
	 */
	private static function assertMethod(string $method, array $allowed): void
	{
		if (Arrays::contains($allowed, $method)) {
			return;
		}

		throw ApiException::methodNotAllowed(\sprintf(
			'Metoda %s tady není povolena. Tahle cesta umí: %s.',
			$method,
			\implode(', ', $allowed),
		));
	}
}
