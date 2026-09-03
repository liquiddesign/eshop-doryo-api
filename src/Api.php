<?php

declare(strict_types=1);

namespace DoryoApi;

use DoryoApi\Auth\Authenticator;
use DoryoApi\Http\ApiException;
use DoryoApi\Http\Query;
use DoryoApi\Http\Response;
use DoryoApi\OpenApi\Specification;
use Nette\Http\IRequest;
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

		try {
			$method = $request->getMethod();

			if ($method !== 'GET' && $method !== 'HEAD') {
				throw ApiException::methodNotAllowed("Metoda $method není povolena, API je jen ke čtení.");
			}

			$response = $this->route($request, $path, \is_array($params) ? $params : []);
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
			);
		}

		return $response;
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function route(IRequest $request, string $path, array $params): Response
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
			return new Response($this->specification->build($this->baseUrl($request)));
		}

		if ($path === '') {
			return $this->index($request);
		}

		[$handler, $routeParams] = $this->router->match($path);

		if ($path === 'v1/meta/health' && !$authenticated) {
			$routeParams['authenticated'] = '0';
		}

		return $handler($routeParams, new Query($params, $this->config));
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
			'version' => Config::VERSION,
			'documentation' => "$baseUrl/openapi.json",
			'health' => "$baseUrl/v1/meta/health",
			'capabilities' => "$baseUrl/v1/meta/capabilities",
			'hint' => 'Endpointy jsou pod /v1 a chtějí hlavičku Authorization: Bearer <token>. '
				. 'API je jen ke čtení, jiná metoda než GET vrací 405.',
		]);
	}

	private function baseUrl(IRequest $request): string
	{
		$url = $this->config->getShopUrl() ?? \rtrim($request->getUrl()->getBaseUrl(), '/');

		return \rtrim($url, '/') . '/' . $this->config->getPrefix();
	}
}
