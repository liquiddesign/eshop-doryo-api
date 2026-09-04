<?php

declare(strict_types=1);

namespace DoryoApi\Bridges;

use DoryoApi\Api;
use DoryoApi\ApiPresenter;
use DoryoApi\Auth\Authenticator;
use DoryoApi\Codebooks;
use DoryoApi\Config;
use DoryoApi\Endpoint\CustomersEndpoint;
use DoryoApi\Endpoint\DiagnosticsEndpoint;
use DoryoApi\Endpoint\InvoicesEndpoint;
use DoryoApi\Endpoint\MetaEndpoint;
use DoryoApi\Endpoint\OrdersEndpoint;
use DoryoApi\Endpoint\PricesEndpoint;
use DoryoApi\Endpoint\ProductsEndpoint;
use DoryoApi\Endpoint\ReportsEndpoint;
use DoryoApi\Endpoint\SearchEndpoint;
use DoryoApi\Endpoint\StockEndpoint;
use DoryoApi\Logger;
use DoryoApi\Mapper\CustomerMapper;
use DoryoApi\Mapper\InvoiceMapper;
use DoryoApi\Mapper\ItemMapper;
use DoryoApi\Mapper\OrderMapper;
use DoryoApi\Mapper\ProductMapper;
use DoryoApi\OpenApi\Specification;
use DoryoApi\Router;
use Nette\Application\Routers\RouteList;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Statement;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Nette\Utils\Strings;

/**
 * Registrace API do shopu.
 *
 * Shop přidá `doryoApi: DoryoApi\Bridges\DoryoApiDI` mezi rozšíření, nastaví token a je hotovo:
 * služby, routy i mapování presenteru si balík zařídí sám. Prefix cesty je proto konfigurace,
 * ne něco, co se musí opsat na dvou místech.
 */
final class DoryoApiDI extends CompilerExtension
{
	private const PRESENTER_MODULE = 'DoryoApi';

	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'prefix' => Expect::string('doryo-api'),
			// null = token se vezme z proměnné prostředí DORYO_API_TOKEN; bez tokenu je API vypnuté
			'token' => Expect::string()->nullable(),
			'allowIps' => Expect::listOf('string'),
			'shopName' => Expect::string()->nullable(),
			'shopUrl' => Expect::string()->nullable(),
			'currency' => Expect::string('CZK'),
			'languages' => Expect::listOf('string')->default(['cs'])->mergeDefaults(false),
			'timezone' => Expect::string()->nullable(),
			'defaultPricelists' => Expect::listOf('string'),
			'defaultCustomerGroup' => Expect::string()->nullable(),
			// mergeDefaults(false): Nette Schema jinak seznamy z configu k výchozím PŘIDÁVÁ, nenahrazuje je —
			// `languages: [cs]` by vyšlo jako [cs, cs] a shop s vlastními stavy by měl vedle nich i ty výchozí
			'orderStates' => Expect::arrayOf(Expect::listOf('string'))->default([
				'new' => ['open'],
				'processing' => ['received'],
				'delivered' => ['finished'],
				'cancelled' => ['canceled'],
			])->mergeDefaults(false),
			'invoicePaymentTracked' => Expect::bool(true),
			// ceny konkrétního zákazníka jsou vědomá výjimka — ve výchozím stavu vypnuté
			'customerPrices' => Expect::bool(false),
			'userfilesDir' => Expect::string()->nullable(),
			'imageSizes' => Expect::listOf('string')->default(['origin', 'detail', 'thumb'])->mergeDefaults(false),
			'logDir' => Expect::string()->nullable(),
			// vlastní pole shopu: seznam služeb implementujících DoryoApi\Extension\DoryoApiExtension
			'extensions' => Expect::listOf(Expect::anyOf(Expect::string(), Expect::type(Statement::class))),
		]);
	}

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		/** @var \stdClass $config */
		$config = $this->getConfig();

		$builder->addDefinition($this->prefix('config'))
			->setFactory(Config::class, [
				'prefix' => $config->prefix,
				'token' => $config->token,
				'allowIps' => $config->allowIps,
				'shopName' => $config->shopName,
				'shopUrl' => $config->shopUrl,
				'currency' => $config->currency,
				'languages' => $config->languages,
				'timezone' => $config->timezone,
				'defaultPricelists' => $config->defaultPricelists,
				'defaultCustomerGroup' => $config->defaultCustomerGroup,
				'orderStates' => $config->orderStates,
				'invoicePaymentTracked' => $config->invoicePaymentTracked,
				'customerPricesEnabled' => $config->customerPrices,
				'userfilesDir' => $config->userfilesDir ?? $builder->parameters['wwwDir'] . '/userfiles',
				'imageSizes' => $config->imageSizes,
			]);

		$builder->addDefinition($this->prefix('codebooks'))->setFactory(Codebooks::class);
		$builder->addDefinition($this->prefix('authenticator'))->setFactory(Authenticator::class);
		$builder->addDefinition($this->prefix('specification'))->setFactory(Specification::class);
		$builder->addDefinition($this->prefix('logger'))
			->setFactory(Logger::class, [$config->logDir ?? $builder->parameters['appDir'] . '/../log']);

		foreach ([
			'customerMapper' => CustomerMapper::class,
			'orderMapper' => OrderMapper::class,
			'invoiceMapper' => InvoiceMapper::class,
			'productMapper' => ProductMapper::class,
			'itemMapper' => ItemMapper::class,
		] as $name => $class) {
			$builder->addDefinition($this->prefix($name))->setFactory($class, ['extensions' => $config->extensions]);
		}

		$endpoints = [];

		foreach ([
			'metaEndpoint' => MetaEndpoint::class,
			'searchEndpoint' => SearchEndpoint::class,
			'diagnosticsEndpoint' => DiagnosticsEndpoint::class,
			'pricesEndpoint' => PricesEndpoint::class,
			'customersEndpoint' => CustomersEndpoint::class,
			'ordersEndpoint' => OrdersEndpoint::class,
			'invoicesEndpoint' => InvoicesEndpoint::class,
			'productsEndpoint' => ProductsEndpoint::class,
			'stockEndpoint' => StockEndpoint::class,
			'reportsEndpoint' => ReportsEndpoint::class,
		] as $name => $class) {
			$builder->addDefinition($this->prefix($name))->setFactory($class);
			$endpoints[] = '@' . $this->prefix($name);
		}

		$builder->addDefinition($this->prefix('router'))->setFactory(Router::class, [$endpoints]);
		$builder->addDefinition($this->prefix('api'))->setFactory(Api::class);
		$builder->addDefinition($this->prefix('presenter'))->setFactory(ApiPresenter::class)->setAutowired(false);

		// vlastní RouteList, který se před kompilací předřadí routám shopu
		$prefix = Strings::trim($config->prefix, '/');
		$builder->addDefinition($this->prefix('routes'))
			->setFactory(RouteList::class)
			->addSetup('addRoute', [$prefix, self::PRESENTER_MODULE . ':Api:index'])
			->addSetup('addRoute', ["$prefix/openapi.json", self::PRESENTER_MODULE . ':Api:openapi'])
			->addSetup('addRoute', ["$prefix/v1/<path .+>", self::PRESENTER_MODULE . ':Api:default'])
			->setAutowired(false);
	}

	public function beforeCompile(): void
	{
		$builder = $this->getContainerBuilder();

		// presenter žije v balíku, ne v App\ — mapování se přidává, ne přepisuje
		if ($builder->hasDefinition('application.presenterFactory')) {
			/** @var \Nette\DI\Definitions\ServiceDefinition $presenterFactory */
			$presenterFactory = $builder->getDefinition('application.presenterFactory');
			$presenterFactory->addSetup('setMapping', [[self::PRESENTER_MODULE => 'DoryoApi\*Presenter']]);
		}

		$routerName = $builder->hasDefinition('routing.router') ? 'routing.router' : $builder->getByType(RouteList::class);

		if ($routerName === null) {
			return;
		}

		// prepend, ne add: stránkový router shopu by jinak dostal cestu dřív
		/** @var \Nette\DI\Definitions\ServiceDefinition $router */
		$router = $builder->getDefinition($routerName);
		$router->addSetup('prepend', ['@' . $this->prefix('routes')]);
	}
}
