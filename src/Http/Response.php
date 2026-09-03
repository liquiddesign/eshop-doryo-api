<?php

declare(strict_types=1);

namespace DoryoApi\Http;

/**
 * Odpověď API: stavový kód, tělo a content type. Presenter z toho udělá HTTP odpověď.
 */
final class Response
{
	/**
	 * @param array<mixed> $body
	 */
	public function __construct(
		private array $body,
		private int $status = 200,
		private string $contentType = 'application/json',
	) {
	}

	/**
	 * @param array<mixed> $items
	 */
	public static function list(array $items, ?string $nextCursor): self
	{
		return new self([
			'items' => \array_values($items),
			'nextCursor' => $nextCursor,
			'hasMore' => $nextCursor !== null,
		]);
	}

	public static function problem(ApiException $exception): self
	{
		return new self($exception->toProblem(), $exception->getStatus(), 'application/problem+json');
	}

	/**
	 * @return array<mixed>
	 */
	public function getBody(): array
	{
		return $this->body;
	}

	public function getStatus(): int
	{
		return $this->status;
	}

	public function getContentType(): string
	{
		return $this->contentType;
	}

	public function getItemCount(): ?int
	{
		return isset($this->body['items']) && \is_array($this->body['items']) ? \count($this->body['items']) : null;
	}
}
