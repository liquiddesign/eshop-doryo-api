<?php

declare(strict_types=1);

namespace DoryoApi\Http;

use Nette\Http\IRequest;
use Nette\Http\IResponse;

/**
 * Odeslání odpovědi API. Vlastní třída kvůli tomu, že chyby jdou ven jako
 * application/problem+json — Nette\Application\Responses\JsonResponse umí jen json.
 */
final class JsonApiResponse implements \Nette\Application\Response
{
	public function __construct(private Response $response)
	{
	}

	public function send(IRequest $httpRequest, IResponse $httpResponse): void
	{
		$httpResponse->setCode($this->response->getStatus());
		$httpResponse->setContentType($this->response->getContentType(), 'utf-8');
		$httpResponse->setHeader('Cache-Control', 'no-store');

		if ($httpRequest->getMethod() === 'HEAD') {
			return;
		}

		echo \json_encode($this->response->getBody(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
	}
}
