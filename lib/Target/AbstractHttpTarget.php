<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Target;

use OCA\FilesPublish\Service\ConfigService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Shared plumbing for HTTP/OAuth repository targets. Uses the NC HTTP client
 * indirectly via curl helpers kept simple here; subclasses implement the
 * target-specific create/upload/DOI steps.
 */
abstract class AbstractHttpTarget implements PublishTarget {
	public function __construct(
		protected ConfigService   $configService,
		protected IL10N           $l,
		protected LoggerInterface $logger,
	) {
	}

	public function getIcon(): string {
		return 'img/' . $this->getId() . '.svg';
	}

	public function getAudienceModel(): string {
		return 'public-doi';
	}

	protected function cfg(string $key, string $default = ''): string {
		return $this->configService->get($this->getId(), $key, $default);
	}

	/**
	 * Minimal JSON request helper. Returns [status, decoded-body-or-raw].
	 * @return array{0:int,1:mixed}
	 */
	protected function http(string $method, string $url, array $opts = []): array {
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT        => $opts['timeout'] ?? 60,
			CURLOPT_HTTPHEADER     => $opts['headers'] ?? [],
		]);
		if (isset($opts['body'])) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
		}
		if (isset($opts['infile'])) {
			curl_setopt($ch, CURLOPT_UPLOAD, true);
			curl_setopt($ch, CURLOPT_INFILE, $opts['infile']);
			curl_setopt($ch, CURLOPT_INFILESIZE, $opts['infilesize'] ?? 0);
		}
		$raw    = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err    = curl_error($ch);
		curl_close($ch);
		if ($raw === false) {
			$this->logger->error('files_publish ' . $this->getId() . ' HTTP ' . $method . ' ' . $url . ' failed: ' . $err);
			return [0, null];
		}
		$decoded = json_decode((string)$raw, true);
		return [$status, $decoded ?? $raw];
	}
}
