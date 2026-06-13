<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Target;

/**
 * Zenodo / Invenio (zenodo.org, sandbox.zenodo.org, or a self-hosted clone
 * such as sciencerepository.dk). OAuth2; deposition record + bucket upload.
 * Left as a DRAFT deposit for the user to review and submit on Zenodo —
 * publishing there mints the DOI and is irreversible.
 *
 * Admin config keys: baseUrl, clientAppID, clientSecret.
 */
class ZenodoTarget extends AbstractHttpTarget {
	public function getId(): string {
		return 'zenodo';
	}

	public function getLabel(): string {
		return $this->l->t('Zenodo');
	}

	public function isConfigured(): bool {
		return $this->cfg('clientAppID') !== '' && $this->baseUrl() !== '';
	}

	private function baseUrl(): string {
		return rtrim($this->cfg('baseUrl', 'https://zenodo.org'), '/');
	}

	public function getMetadataSchema(): array {
		return [
			['key' => 'title',       'label' => $this->l->t('Title'),       'type' => 'text',     'required' => true],
			['key' => 'description', 'label' => $this->l->t('Description'), 'type' => 'textarea', 'required' => true],
			['key' => 'creators',    'label' => $this->l->t('Authors'),     'type' => 'authors',  'required' => true,
				'hint' => $this->l->t('Prefilled from your profile and ORCID iD; edit as needed.')],
			['key' => 'keywords',    'label' => $this->l->t('Keywords'),    'type' => 'text',     'required' => false,
				'hint' => $this->l->t('Comma-separated.')],
			['key' => 'upload_type', 'label' => $this->l->t('Type'),        'type' => 'select',   'required' => true,
				'default' => 'dataset',
				'options' => [
					'dataset'      => $this->l->t('Dataset'),
					'publication'  => $this->l->t('Publication'),
					'image'        => $this->l->t('Image'),
					'video'        => $this->l->t('Video/Audio'),
					'software'     => $this->l->t('Software'),
					'other'        => $this->l->t('Other'),
				]],
		];
	}

	public function getAuthorizeUrl(string $state): string {
		if (!$this->isConfigured()) {
			return '';
		}
		return $this->baseUrl() . '/oauth/authorize?' . http_build_query([
			'client_id'     => $this->cfg('clientAppID'),
			'response_type' => 'code',
			'scope'         => 'deposit:write',
			'state'         => $state,
			'redirect_uri'  => $this->configService->get('zenodo', 'redirectUri'),
		]);
	}

	/** Maps the dialog metadata to a Zenodo deposition metadata block. */
	private function zenodoMetadata(array $m): array {
		$creators = [];
		foreach (($m['creators'] ?? []) as $c) {
			$creator = ['name' => $c['name'] ?? ''];
			if (!empty($c['affiliation'])) {
				$creator['affiliation'] = $c['affiliation'];
			}
			if (!empty($c['orcid'])) {
				$creator['orcid'] = $c['orcid'];
			}
			$creators[] = $creator;
		}
		$meta = [
			'title'       => $m['title'] ?? '',
			'description' => nl2br($m['description'] ?? ''),
			'upload_type' => $m['upload_type'] ?? 'dataset',
			'creators'    => $creators ?: [['name' => $m['title'] ?? 'Unknown']],
		];
		if (!empty($m['keywords'])) {
			$meta['keywords'] = array_values(array_filter(array_map('trim', explode(',', $m['keywords']))));
		}
		return $meta;
	}

	public function publish(array $files, array $metadata, array $auth): PublishResult {
		$token = $auth['access_token'] ?? '';
		if ($token === '') {
			return PublishResult::fail($this->l->t('Not authorized with Zenodo.'));
		}
		$api = $this->baseUrl() . '/api/deposit/depositions';

		// 1. Create the draft deposition
		[$status, $body] = $this->http('POST', $api . '?access_token=' . urlencode($token), [
			'headers' => ['Content-Type: application/json'],
			'body'    => json_encode(['metadata' => $this->zenodoMetadata($metadata)]),
		]);
		if ($status < 200 || $status >= 300 || empty($body['id'])) {
			return PublishResult::fail($this->l->t('Zenodo rejected the deposit: ') . $this->errorText($body));
		}
		$depositId = (string)$body['id'];
		$bucket    = $body['links']['bucket'] ?? '';
		$landing   = $body['links']['html'] ?? ($this->baseUrl() . '/deposit/' . $depositId);
		$doi       = $body['metadata']['prereserve_doi']['doi'] ?? '';

		// 2. Upload each file into the deposition bucket
		foreach ($files as $name => $path) {
			if ($bucket !== '') {
				$fh = fopen($path, 'rb');
				[$ust] = $this->http('PUT', rtrim($bucket, '/') . '/' . rawurlencode($name) . '?access_token=' . urlencode($token), [
					'headers'    => ['Content-Type: application/octet-stream'],
					'infile'     => $fh,
					'infilesize' => filesize($path),
					'timeout'    => 86400,
				]);
				if (is_resource($fh)) {
					fclose($fh);
				}
			} else {
				// Older Invenio: multipart to the files endpoint
				[$ust] = $this->http('POST', $api . '/' . $depositId . '/files?access_token=' . urlencode($token), [
					'body' => ['name' => $name, 'file' => new \CURLFile($path, 'application/octet-stream', $name)],
					'timeout' => 86400,
				]);
			}
			if ($ust < 200 || $ust >= 300) {
				return PublishResult::fail($this->l->t('Upload to Zenodo failed for ') . $name);
			}
		}

		// Draft left for the user to review and submit on Zenodo.
		return PublishResult::ok($depositId, $landing, $doi);
	}

	private function errorText($body): string {
		if (is_array($body)) {
			return (string)($body['message'] ?? json_encode($body));
		}
		return (string)$body;
	}
}
