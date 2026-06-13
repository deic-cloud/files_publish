<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Target;

/** Outcome of a publish, surfaced to the user and stored on the file. */
class PublishResult {
	public function __construct(
		public bool    $success,
		public string  $recordId = '',
		public string  $doi = '',
		public string  $landingUrl = '',
		public string  $message = '',
	) {
	}

	public static function ok(string $recordId, string $landingUrl, string $doi = ''): self {
		return new self(true, $recordId, $doi, $landingUrl);
	}

	public static function fail(string $message): self {
		return new self(false, '', '', '', $message);
	}
}
