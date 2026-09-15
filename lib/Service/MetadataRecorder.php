<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Service;

use OCA\FilesPublish\Target\PublishResult;
use OCA\FilesPublish\Target\PublishTarget;
use Psr\Log\LoggerInterface;

/**
 * The repository schema IS the publication record (old-service model): when a
 * file or folder is deposited, the target's meta_data schema tag (e.g.
 * "Zenodo", "data.dtu.dk") is assigned to it and its fields are filled with
 * the metadata the user entered plus what the repository answered (record id,
 * DOI, landing page). Opening "Publish…" on the same item later prefills the
 * form from those fields, and the values are searchable and travel with the
 * data like any other metadata. Nothing is written to oc_preferences.
 *
 * Requires the meta_data app; without it publishing still works, just without
 * a record on the file. Schema fields that are missing are created (the seeded
 * schemas are admin-owned; this runs server-side, outside the ownership gate).
 */
class MetadataRecorder {
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	private function tags(): ?object {
		if (!class_exists(\OCA\MetaData\Service\TagService::class)) {
			return null;
		}
		try {
			return \OCP\Server::get(\OCA\MetaData\Service\TagService::class);
		} catch (\Throwable $e) {
			$this->logger->warning('files_publish: meta_data unavailable: ' . $e->getMessage());
			return null;
		}
	}

	/** Tag id of the target's schema, created if it does not exist yet; null without meta_data. */
	private function tagId(object $tags, PublishTarget $t): ?int {
		$name = $t->getMetadataTag();
		if ($name === '') {
			return null;
		}
		$id = $tags->getTagIdByName($name);
		if ($id === null) {
			$created = $tags->newTag($name);
			$id = $created['id'] ?? null;
		}
		return $id === null ? null : (int)$id;
	}

	private function keyId(object $tags, int $tagId, string $keyName): ?int {
		$id = $tags->getKeyIdByName($tagId, $keyName);
		if ($id === null) {
			$created = $tags->newKey($tagId, $keyName);
			$id = $created['id'] ?? null;
		}
		return $id === null ? null : (int)$id;
	}

	/** @param mixed $v form value (string, or creators array) → stored string */
	private static function toStored(string $formKey, mixed $v): string {
		if (is_array($v)) {
			return $formKey === 'creators'
				? (string)json_encode(array_values($v), JSON_UNESCAPED_UNICODE)
				: implode(', ', array_map('strval', $v));
		}
		return trim((string)$v);
	}

	/**
	 * After a successful deposit: tag every published item with the target's
	 * schema and store the entered metadata + the repository's answer.
	 *
	 * @param int[] $fileids
	 */
	public function record(array $fileids, PublishTarget $t, array $metadata, PublishResult $r): void {
		$tags = $this->tags();
		if ($tags === null) {
			return;
		}
		try {
			$tagId = $this->tagId($tags, $t);
			if ($tagId === null) {
				return;
			}
			$map    = $t->getMetadataKeyMap();
			$values = [];
			foreach (($map['form'] ?? []) as $formKey => $schemaKey) {
				if (array_key_exists($formKey, $metadata) && $metadata[$formKey] !== '' && $metadata[$formKey] !== []) {
					$values[$schemaKey] = self::toStored($formKey, $metadata[$formKey]);
				}
			}
			$answer = ['record_id' => $r->recordId, 'doi' => $r->doi, 'url' => $r->landingUrl, 'date' => date('Y-m-d')];
			foreach (($map['result'] ?? []) as $resultKey => $schemaKey) {
				if (($answer[$resultKey] ?? '') !== '') {
					$values[$schemaKey] = (string)$answer[$resultKey];
				}
			}
			$keyIds = [];
			foreach ($values as $schemaKey => $_) {
				$kid = $this->keyId($tags, $tagId, $schemaKey);
				if ($kid !== null) {
					$keyIds[$schemaKey] = $kid;
				}
			}
			foreach ($fileids as $fileid) {
				$fileid = (int)$fileid;
				$tags->addFileTag($fileid, $tagId);
				foreach ($values as $schemaKey => $value) {
					if (isset($keyIds[$schemaKey])) {
						$tags->updateFileKey($fileid, $tagId, $keyIds[$schemaKey], $value);
					}
				}
			}
		} catch (\Throwable $e) {
			// The deposit itself succeeded; a missing record must not turn that into an error.
			$this->logger->error('files_publish: recording metadata on the published item(s) failed: ' . $e->getMessage());
		}
	}

	/**
	 * Form prefill for an item that already carries the target's schema:
	 * form key → value (creators as an array of {name, orcid, affiliation}).
	 * Empty when the item is untagged or meta_data is absent.
	 */
	public function prefill(int $fileid, PublishTarget $t): array {
		$tags = $this->tags();
		if ($tags === null || $fileid <= 0) {
			return [];
		}
		try {
			$tagId = $tags->getTagIdByName($t->getMetadataTag());
			if ($tagId === null) {
				return [];
			}
			$stored = [];
			$keys   = [];
			foreach ($tags->getKeys($tagId) as $k) {
				$keys[(int)$k['id']] = $k['name'];
			}
			foreach ($tags->getFileKeys($fileid, $tagId) as $row) {
				$name = $keys[(int)$row['keyid']] ?? null;
				if ($name !== null && (string)$row['value'] !== '') {
					$stored[$name] = (string)$row['value'];
				}
			}
			if ($stored === []) {
				return [];
			}
			$out = [];
			foreach (($t->getMetadataKeyMap()['form'] ?? []) as $formKey => $schemaKey) {
				if (!isset($stored[$schemaKey])) {
					continue;
				}
				if ($formKey === 'creators') {
					$decoded = json_decode($stored[$schemaKey], true);
					if (is_array($decoded)) {
						$out[$formKey] = $decoded;
					}
				} else {
					$out[$formKey] = $stored[$schemaKey];
				}
			}
			// The repository's answer, for the dialog to show "already deposited".
			foreach (($t->getMetadataKeyMap()['result'] ?? []) as $resultKey => $schemaKey) {
				if (isset($stored[$schemaKey])) {
					$out['_' . $resultKey] = $stored[$schemaKey];
				}
			}
			return $out;
		} catch (\Throwable $e) {
			$this->logger->warning('files_publish: prefill from metadata failed: ' . $e->getMessage());
			return [];
		}
	}
}
