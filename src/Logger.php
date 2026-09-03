<?php

declare(strict_types=1);

namespace DoryoApi;

/**
 * Log volání API do vlastního souboru. Není to audit (ten má Doryo na své straně),
 * je to na ladění a na hlídání limitů — proto cesta, parametry, počet položek a čas.
 * Token se do logu nikdy nepíše.
 */
final class Logger
{
	public function __construct(private string $logDir, private string $fileName = 'doryo-api.log')
	{
	}

	/**
	 * @param array<string, mixed> $params
	 */
	public function log(string $path, array $params, int $status, ?int $items, float $milliseconds): void
	{
		unset($params['token']);

		$line = \json_encode([
			'ts' => (new \DateTimeImmutable())->format('c'),
			'path' => $path,
			'params' => $params,
			'status' => $status,
			'items' => $items,
			'ms' => \round($milliseconds, 1),
		], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

		if ($line === false) {
			return;
		}

		try {
			if (!\is_dir($this->logDir)) {
				return;
			}

			\file_put_contents($this->logDir . '/' . $this->fileName, $line . \PHP_EOL, \FILE_APPEND | \LOCK_EX);
		} catch (\Throwable) {
			// log nesmí shodit odpověď
		}
	}
}
