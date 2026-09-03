<?php

declare(strict_types=1);

namespace DoryoApi;

use DoryoApi\Http\JsonApiResponse;
use Nette\Application\UI\Presenter;

/**
 * Jediný vstup do čtecího API pro Doryo. Presenter nedělá nic než překlad HTTP na volání
 * Api::handle() — veškerá logika (autentizace, směrování, mapování) je v DoryoApi,
 * aby se dala vytáhnout do balíku liquiddesign/eshop-doryo-api beze změny.
 *
 * Cesty registruje config/doryo-api.neon.
 */
final class ApiPresenter extends Presenter
{
	/** @inject */
	public Api $api;

	public function actionDefault(string $path = ''): void
	{
		$this->respond('v1/' . $path);
	}

	public function actionOpenapi(): void
	{
		$this->respond('openapi.json');
	}

	public function actionIndex(): void
	{
		$this->respond('');
	}

	private function respond(string $path): void
	{
		$this->sendResponse(new JsonApiResponse($this->api->handle($this->getHttpRequest(), $path)));
	}
}
