<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Target;

/**
 * Zenodo / Invenio (zenodo.org, sandbox.zenodo.org, or a self-hosted clone
 * such as sciencerepository.dk). OAuth2; deposition record + bucket upload.
 * Left as a DRAFT deposit for the user to review and submit on Zenodo —
 * publishing there mints the DOI and is irreversible.
 *
 * Admin config keys: baseUrl, clientAppID, clientSecret, communities
 * (comma-separated community identifiers every deposit is submitted to).
 *
 * Deposits are recorded on the item in the meta_data "Zenodo" schema (see
 * getMetadataKeyMap). An item that already carries a deposition_id is
 * published INTO that deposit again (files added / metadata updated), as on
 * the old service; if that deposit has been published at Zenodo, a new
 * version of it is created instead of an unrelated new record.
 */
class ZenodoTarget extends AbstractHttpTarget {
	/** Zenodo upload_type → the form's Type options (Zenodo's controlled vocabulary). */
	private const UPLOAD_TYPES = ['dataset', 'publication', 'image', 'video', 'software', 'presentation', 'poster', 'lesson', 'physicalobject', 'other'];

	public function getId(): string {
		return 'zenodo';
	}

	public function getMetadataTag(): string {
		return 'Zenodo';
	}

	/**
	 * Maps form keys / deposit results onto the meta_data "Zenodo" schema
	 * (title, description, creators, upload_type, publication_type, image_type,
	 * publication_date, access_right, access_conditions, embargo_date, license,
	 * communities, keywords, deposition_id, uploaded, bucket, url). The form
	 * below is designed to match it; the recorder never adds fields.
	 */
	public function getMetadataKeyMap(): array {
		return [
			'form'   => [
				'title' => 'title', 'description' => 'description', 'creators' => 'creators',
				'upload_type' => 'upload_type', 'publication_type' => 'publication_type', 'image_type' => 'image_type',
				'keywords' => 'keywords',
			],
			// deposition_id/bucket/url/uploaded keep their old-service meanings:
			// the deposit to add to, its upload URL, its page, "this item's files are in it".
			'result' => ['record_id' => 'deposition_id', 'bucket' => 'bucket', 'url' => 'url', 'uploaded' => 'uploaded', 'date' => 'publication_date'],
		];
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

	/** Zenodo: 50 GB total per record (deposit) by default. */
	protected function defaultMaxGB(): float {
		return 50;
	}

	public function getMetadataSchema(): array {
		return [
			['key' => 'title',       'label' => $this->l->t('Title'),       'type' => 'text',     'required' => true],
			['key' => 'description', 'label' => $this->l->t('Description'), 'type' => 'textarea', 'required' => true],
			['key' => 'creators',    'label' => $this->l->t('Authors'),     'type' => 'authors',  'required' => true,
				'hint' => $this->l->t('Prefilled from your profile and ORCID; edit as needed.')],
			['key' => 'upload_type', 'label' => $this->l->t('Type'),        'type' => 'select',   'required' => true,
				'default' => 'dataset',
				'options' => [
					'dataset'        => $this->l->t('Dataset'),
					'publication'    => $this->l->t('Publication'),
					'image'          => $this->l->t('Image'),
					'video'          => $this->l->t('Video/Audio'),
					'software'       => $this->l->t('Software'),
					'presentation'   => $this->l->t('Presentation'),
					'poster'         => $this->l->t('Poster'),
					'lesson'         => $this->l->t('Lesson'),
					'physicalobject' => $this->l->t('Physical object'),
					'other'          => $this->l->t('Other'),
				]],
			// Zenodo requires these two only for the matching Type; the dialog shows them then.
			['key' => 'publication_type', 'label' => $this->l->t('Publication type'), 'type' => 'select', 'required' => true,
				'when' => ['upload_type' => 'publication'], 'default' => 'article',
				'options' => [
					'article'              => $this->l->t('Journal article'),
					'preprint'             => $this->l->t('Preprint'),
					'report'               => $this->l->t('Report'),
					'thesis'               => $this->l->t('Thesis'),
					'book'                 => $this->l->t('Book'),
					'section'              => $this->l->t('Book section'),
					'conferencepaper'      => $this->l->t('Conference paper'),
					'workingpaper'         => $this->l->t('Working paper'),
					'technicalnote'        => $this->l->t('Technical note'),
					'datamanagementplan'   => $this->l->t('Data management plan'),
					'softwaredocumentation' => $this->l->t('Software documentation'),
					'deliverable'          => $this->l->t('Project deliverable'),
					'milestone'            => $this->l->t('Project milestone'),
					'proposal'             => $this->l->t('Proposal'),
					'patent'               => $this->l->t('Patent'),
					'annotationcollection' => $this->l->t('Annotation collection'),
					'taxonomictreatment'   => $this->l->t('Taxonomic treatment'),
					'other'                => $this->l->t('Other'),
				]],
			['key' => 'image_type', 'label' => $this->l->t('Image type'), 'type' => 'select', 'required' => true,
				'when' => ['upload_type' => 'image'], 'default' => 'figure',
				'options' => [
					'figure'  => $this->l->t('Figure'),
					'plot'    => $this->l->t('Plot'),
					'drawing' => $this->l->t('Drawing'),
					'diagram' => $this->l->t('Diagram'),
					'photo'   => $this->l->t('Photo'),
					'other'   => $this->l->t('Other'),
				]],
			['key' => 'keywords',    'label' => $this->l->t('Keywords'),    'type' => 'text',     'required' => false,
				'hint' => $this->l->t('Comma-separated.')],
		];
	}

	/**
	 * Type guessed from what is selected: a folder or several items → dataset;
	 * a single file by extension. Only a suggestion — the user can change it.
	 */
	public function defaultsFor(array $filenames): array {
		if (count($filenames) !== 1) {
			return [];
		}
		$name = (string)array_key_first($filenames);
		if ($filenames[$name] === true) { // folder
			return ['upload_type' => 'dataset'];
		}
		$ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
		$photo = ['jpg', 'jpeg', 'heic', 'heif', 'raw', 'cr2', 'nef', 'dng'];
		$image = ['png', 'gif', 'tif', 'tiff', 'bmp', 'svg', 'webp', 'eps', 'ai', 'psd', 'xcf'];
		$video = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', 'mpg', 'mpeg', 'wmv', 'flv', 'mp3', 'wav', 'flac', 'ogg', 'aac', 'm4a', 'opus'];
		$code  = ['py', 'ipynb', 'r', 'rmd', 'jl', 'm', 'c', 'cpp', 'h', 'hpp', 'java', 'js', 'ts', 'go', 'rs', 'sh', 'pl', 'php', 'f', 'f90', 'cu', 'scala', 'kt', 'swift'];
		$pres  = ['ppt', 'pptx', 'key', 'odp'];
		$pub   = ['pdf', 'doc', 'docx', 'odt', 'tex', 'rtf', 'epub'];
		if (in_array($ext, $photo, true)) {
			return ['upload_type' => 'image', 'image_type' => 'photo'];
		}
		if (in_array($ext, $image, true)) {
			return ['upload_type' => 'image', 'image_type' => 'figure'];
		}
		if (in_array($ext, $video, true)) {
			return ['upload_type' => 'video'];
		}
		if (in_array($ext, $code, true)) {
			return ['upload_type' => 'software'];
		}
		if (in_array($ext, $pres, true)) {
			return ['upload_type' => 'presentation'];
		}
		if (in_array($ext, $pub, true)) {
			return ['upload_type' => 'publication', 'publication_type' => 'article'];
		}
		return ['upload_type' => 'dataset'];
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
		$uploadType = (string)($m['upload_type'] ?? 'dataset');
		if (!in_array($uploadType, self::UPLOAD_TYPES, true)) {
			$uploadType = 'dataset';
		}
		$meta = [
			'title'       => $m['title'] ?? '',
			'description' => nl2br($m['description'] ?? ''),
			'upload_type' => $uploadType,
			'creators'    => $creators ?: [['name' => $m['title'] ?? 'Unknown']],
		];
		if ($uploadType === 'publication') {
			$meta['publication_type'] = (string)($m['publication_type'] ?: 'other');
		}
		if ($uploadType === 'image') {
			$meta['image_type'] = (string)($m['image_type'] ?: 'other');
		}
		$keywords = array_values(array_filter(array_map('trim', explode(',', (string)($m['keywords'] ?? '')))));
		if ($keywords) {
			$meta['keywords'] = $keywords;
		}
		// Default communities (admin setting): every deposit is submitted to them,
		// e.g. a national or institutional community curated by data stewards.
		$communities = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $this->cfg('communities')))));
		if ($communities) {
			$meta['communities'] = array_map(static fn ($id) => ['identifier' => $id], $communities);
		}
		return $meta;
	}

	/**
	 * The deposit to add files to: the item's existing one (updated with the
	 * given metadata; a new version of it if it has already been published),
	 * or a freshly created draft. Returns [id, bucket, html, doi] or a fail.
	 *
	 * @return array{0: string, 1: string, 2: string, 3: string}|PublishResult
	 */
	private function openDeposit(string $token, array $meta, string $existingId): array|PublishResult {
		$api = $this->baseUrl() . '/api/deposit/depositions';
		$q   = '?access_token=' . urlencode($token);

		if ($existingId !== '') {
			[$status, $body] = $this->http('GET', $api . '/' . rawurlencode($existingId) . $q);
			if ($status === 200 && !empty($body['id'])) {
				if (!empty($body['submitted'])) {
					// Published already: files cannot be added; make a new version.
					[$vs, $vb] = $this->http('POST', $api . '/' . rawurlencode($existingId) . '/actions/newversion' . $q);
					$draft = $vb['links']['latest_draft'] ?? '';
					if ($vs < 200 || $vs >= 300 || $draft === '') {
						return PublishResult::fail($this->l->t('Zenodo could not create a new version of deposit %s: ', [$existingId]) . $this->errorText($vb));
					}
					[$ds, $body] = $this->http('GET', $draft . $q);
					if ($ds !== 200 || empty($body['id'])) {
						return PublishResult::fail($this->l->t('Zenodo could not open the new version of deposit %s.', [$existingId]));
					}
				}
				$id = (string)$body['id'];
				// Apply the (possibly edited) metadata to the deposit we are adding to.
				[$us, $ub] = $this->http('PUT', $api . '/' . rawurlencode($id) . $q, [
					'headers' => ['Content-Type: application/json'],
					'body'    => json_encode(['metadata' => $meta]),
				]);
				if ($us >= 200 && $us < 300 && !empty($ub['id'])) {
					$body = $ub;
				} else {
					$this->logger->warning('files_publish: Zenodo did not accept the metadata update for deposit ' . $id . ': ' . $this->errorText($ub));
				}
				return [
					$id,
					(string)($body['links']['bucket'] ?? ''),
					(string)($body['links']['html'] ?? ($this->baseUrl() . '/deposit/' . $id)),
					(string)($body['metadata']['prereserve_doi']['doi'] ?? ($body['doi'] ?? '')),
				];
			}
			// Gone (deleted at Zenodo) or not this user's: fall back to a new deposit.
			$this->logger->info('files_publish: Zenodo deposit ' . $existingId . ' not available (HTTP ' . $status . '); creating a new deposit.');
		}

		[$status, $body] = $this->http('POST', $api . $q, [
			'headers' => ['Content-Type: application/json'],
			'body'    => json_encode(['metadata' => $meta]),
		]);
		if ($status < 200 || $status >= 300 || empty($body['id'])) {
			return PublishResult::fail($this->l->t('Zenodo rejected the deposit: ') . $this->errorText($body));
		}
		$id = (string)$body['id'];
		return [
			$id,
			(string)($body['links']['bucket'] ?? ''),
			(string)($body['links']['html'] ?? ($this->baseUrl() . '/deposit/' . $id)),
			(string)($body['metadata']['prereserve_doi']['doi'] ?? ''),
		];
	}

	public function publish(array $files, array $metadata, array $auth): PublishResult {
		$token = $auth['access_token'] ?? '';
		if ($token === '') {
			return PublishResult::fail($this->l->t('Not authorized with Zenodo.'));
		}
		$api = $this->baseUrl() . '/api/deposit/depositions';

		// 1. The deposit: the item's existing one, or a new draft
		$opened = $this->openDeposit($token, $this->zenodoMetadata($metadata), trim((string)($metadata['deposition_id'] ?? '')));
		if ($opened instanceof PublishResult) {
			return $opened;
		}
		[$depositId, $bucket, $landing, $doi] = $opened;

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
		return PublishResult::ok($depositId, $landing, $doi, $bucket, true);
	}

	public function publishLink(array $metadata, array $urls, array $auth): PublishResult {
		$token = $auth['access_token'] ?? '';
		if ($token === '') {
			return PublishResult::fail($this->l->t('Not authorized with Zenodo.'));
		}
		$meta = $this->zenodoMetadata($metadata);
		// Record the data location in the description and as related identifiers.
		$linksHtml = implode('<br/>', array_map(static fn ($u) => '<a href="' . $u . '">' . $u . '</a>', $urls));
		$meta['description'] = ($meta['description'] ?? '')
			. '<p>' . $this->l->t('The data for this record is hosted on ScienceData:') . '<br/>' . $linksHtml . '</p>';
		$meta['related_identifiers'] = array_map(static fn ($u) => [
			'identifier' => $u, 'relation' => 'isIdenticalTo', 'scheme' => 'url',
		], array_values($urls));

		$opened = $this->openDeposit($token, $meta, trim((string)($metadata['deposition_id'] ?? '')));
		if ($opened instanceof PublishResult) {
			return $opened;
		}
		[$depositId, $bucket, $landing, $doi] = $opened;

		// A tiny pointer file so the draft isn't empty and is clickable.
		if ($bucket !== '') {
			$pointer = $this->l->t('This record describes data hosted on ScienceData.') . "\n\n" . implode("\n", $urls) . "\n";
			$this->http('PUT', rtrim($bucket, '/') . '/DATA-ON-SCIENCEDATA.txt?access_token=' . urlencode($token), [
				'headers' => ['Content-Type: text/plain'],
				'body'    => $pointer,
			]);
		}
		// The data itself was not uploaded (uploaded stays unset on the item).
		return PublishResult::ok($depositId, $landing, $doi, $bucket, false);
	}

	private function errorText($body): string {
		if (is_array($body)) {
			return (string)($body['message'] ?? json_encode($body));
		}
		return (string)$body;
	}
}
