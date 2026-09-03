<?php

declare(strict_types=1);

namespace DoryoApi\Http;

/**
 * Chyba, kterou API umí vrátit klientovi jako problem+json. Cokoli jiného je 500.
 */
final class ApiException extends \RuntimeException
{
	public function __construct(private int $status, private string $title, string $detail = '')
	{
		parent::__construct($detail);
	}

	public static function badRequest(string $detail): self
	{
		return new self(400, 'Chybný požadavek', $detail);
	}

	public static function unauthorized(string $detail): self
	{
		return new self(401, 'Neautorizováno', $detail);
	}

	public static function forbidden(string $detail): self
	{
		return new self(403, 'Přístup odepřen', $detail);
	}

	public static function notFound(string $detail): self
	{
		return new self(404, 'Nenalezeno', $detail);
	}

	public static function methodNotAllowed(string $detail): self
	{
		return new self(405, 'Metoda není povolena', $detail);
	}

	public function getStatus(): int
	{
		return $this->status;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toProblem(): array
	{
		return [
			'type' => 'about:blank',
			'title' => $this->title,
			'status' => $this->status,
			'detail' => $this->getMessage(),
		];
	}
}
