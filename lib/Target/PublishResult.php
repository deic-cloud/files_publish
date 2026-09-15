<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Target;

/** Outcome of a publish, surfaced to the user and recorded on the item. */
class PublishResult {
	public function __construct(
		public bool    $success,
		public string  $recordId = '',
		public string  $doi = '',
		public string  $landingUrl = '',
		public string  $message = '',
		/** The deposit's upload URL (Zenodo "bucket"), kept so later files can be added to the same deposit. */
		public string  $bucket = '',
		/** True when the item's data was uploaded into the deposit (false for a link deposit). */
		public bool    $uploaded = false,
	) {
	}

	public static function ok(string $recordId, string $landingUrl, string $doi = '', string $bucket = '', bool $uploaded = true): self {
		return new self(true, $recordId, $doi, $landingUrl, '', $bucket, $uploaded);
	}

	public static function fail(string $message): self {
		return new self(false, '', '', '', $message);
	}
}
