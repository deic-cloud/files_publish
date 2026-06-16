<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Target;

/**
 * Figshare (figshare.com / data.dtu.dk). OAuth2 authorization_code; article
 * record + 3-step chunked upload (initiate / PUT parts / complete) with an
 * MD5 checksum, then reserve a DOI. Left unpublished for the user to review
 * and submit on Figshare.
 *
 * Admin config keys: baseUrl (API), authBaseUrl, portalUrl, clientAppID,
 * clientSecret, personalToken, defaultCategory, defaultLicense.
 *
 * Two auth modes: OAuth2 authorization_code, or — preferred for institutional
 * accounts where self-service OAuth is locked down — a personal API token,
 * which Figshare accepts in the same `Authorization: token …` header. When a
 * personal token is set we skip the OAuth round-trip entirely.
 */
class FigshareTarget extends AbstractHttpTarget {
	public function getId(): string {
		return 'figshare';
	}

	public function getLabel(): string {
		return $this->l->t('Figshare');
	}

	public function isConfigured(): bool {
		// A personal token is sufficient on its own; otherwise need the OAuth app.
		return $this->personalToken() !== ''
			|| ($this->cfg('clientAppID') !== '' && $this->apiBase() !== '');
	}

	private function personalToken(): string {
		return $this->cfg('personalToken');
	}

	public function getDirectAuth(): array {
		$token = $this->personalToken();
		return $token !== '' ? ['access_token' => $token] : [];
	}

	private function apiBase(): string {
		return rtrim($this->cfg('baseUrl', 'https://api.figshare.com/v2'), '/');
	}

	/** figshare.com personal accounts: 20 GB. Institutional (data.dtu.dk)
	 *  pools are larger — raise the admin "Max publish size" there. */
	protected function defaultMaxGB(): float {
		return 20;
	}

	private function authBase(): string {
		return rtrim($this->cfg('authBaseUrl', 'https://figshare.com'), '/');
	}

	/**
	 * Where the user reviews & submits the draft, and the public landing lives
	 * — the institutional Figshare portal (e.g. https://data.dtu.dk), which is
	 * distinct from both the authorize base (figshare.com) and the API base
	 * (api.figshare.com). Falls back to the authorize base when unset.
	 */
	private function portalBase(): string {
		$p = rtrim($this->cfg('portalUrl', ''), '/');
		return $p !== '' ? $p : $this->authBase();
	}

	public function getMetadataSchema(): array {
		return [
			['key' => 'title',        'label' => $this->l->t('Title'),       'type' => 'text',     'required' => true],
			['key' => 'description',  'label' => $this->l->t('Description'), 'type' => 'textarea', 'required' => true],
			['key' => 'creators',     'label' => $this->l->t('Authors'),     'type' => 'authors',  'required' => true,
				'hint' => $this->l->t('Prefilled from your profile and ORCID iD; edit as needed.')],
			['key' => 'keywords',     'label' => $this->l->t('Keywords'),    'type' => 'text',     'required' => true,
				'hint' => $this->l->t('Comma-separated; Figshare requires at least one.')],
			['key' => 'defined_type', 'label' => $this->l->t('Type'),        'type' => 'select',   'required' => true,
				'default' => 'dataset',
				'options' => [
					'dataset'  => $this->l->t('Dataset'),
					'figure'   => $this->l->t('Figure'),
					'media'    => $this->l->t('Media'),
					'software' => $this->l->t('Software'),
					'paper'    => $this->l->t('Paper'),
				]],
		];
	}

	public function getAuthorizeUrl(string $state): string {
		// A personal token means no interactive auth: signal "skip OAuth".
		if ($this->personalToken() !== '') {
			return '';
		}
		if (!$this->isConfigured()) {
			return '';
		}
		return $this->authBase() . '/account/applications/authorize?' . http_build_query([
			'client_id'     => $this->cfg('clientAppID'),
			'response_type' => 'code',
			'scope'         => 'all',
			'state'         => $state,
			'redirect_uri'  => $this->configService->get('figshare', 'redirectUri'),
		]);
	}

	private function authHeader(string $token): array {
		return ['Authorization: token ' . $token, 'Content-Type: application/json'];
	}

	private function articleMetadata(array $m): array {
		$authors = [];
		foreach (($m['creators'] ?? []) as $c) {
			if (!empty($c['name'])) {
				$authors[] = ['name' => $c['name']];
			}
		}
		$keywords = array_values(array_filter(array_map('trim', explode(',', $m['keywords'] ?? ''))));
		$meta = [
			'title'        => $m['title'] ?? '',
			'description'  => $m['description'] ?? '',
			'authors'      => $authors ?: [['name' => 'Unknown']],
			'keywords'     => $keywords ?: ['data'],
			'defined_type' => $m['defined_type'] ?? 'dataset',
		];
		$cat = (int)$this->cfg('defaultCategory', '0');
		if ($cat > 0) {
			$meta['categories'] = [$cat];
		}
		$lic = $this->cfg('defaultLicense');
		if ($lic !== '') {
			$meta['license'] = is_numeric($lic) ? (int)$lic : $lic;
		}
		return $meta;
	}

	public function publish(array $files, array $metadata, array $auth): PublishResult {
		$token = $auth['access_token'] ?? '';
		if ($token === '') {
			return PublishResult::fail($this->l->t('Not authorized with Figshare.'));
		}
		$api = $this->apiBase();

		// 1. Create the article
		[$status, $body] = $this->http('POST', $api . '/account/articles', [
			'headers' => $this->authHeader($token),
			'body'    => json_encode($this->articleMetadata($metadata)),
		]);
		if ($status < 200 || $status >= 300 || empty($body['location'])) {
			return PublishResult::fail($this->l->t('Figshare rejected the article: ') . $this->errorText($body));
		}
		// location is the article API URL; derive the id
		$articleUrl = (string)$body['location'];
		$articleId  = (int)preg_replace('#.*/articles/#', '', $articleUrl);

		// 2. Upload each file (initiate → parts → complete)
		foreach ($files as $name => $path) {
			$err = $this->uploadFile($token, $articleId, $name, $path);
			if ($err !== '') {
				return PublishResult::fail($err);
			}
		}

		// 3. Reserve a DOI (does not publish)
		$doi = '';
		[$dst, $dbody] = $this->http('POST', $api . '/account/articles/' . $articleId . '/reserve_doi', [
			'headers' => $this->authHeader($token),
		]);
		if ($dst >= 200 && $dst < 300 && !empty($dbody['doi'])) {
			$doi = (string)$dbody['doi'];
		}

		$landing = $this->portalBase() . '/account/articles/' . $articleId;
		return PublishResult::ok((string)$articleId, $landing, $doi);
	}

	public function publishLink(array $metadata, array $urls, array $auth): PublishResult {
		$token = $auth['access_token'] ?? '';
		if ($token === '') {
			return PublishResult::fail($this->l->t('Not authorized with Figshare.'));
		}
		$api = $this->apiBase();
		// Create with only known-good fields (same set as the verified upload
		// path) plus the data location in the description — which always works.
		// references + a native linked file are added best-effort AFTER create,
		// so an unconfirmed field can never 400 the whole deposit.
		$meta = $this->articleMetadata($metadata);
		$linksHtml = implode('<br/>', array_map(static fn ($u) => '<a href="' . $u . '">' . $u . '</a>', $urls));
		$meta['description'] = ($meta['description'] ?? '')
			. '<p>' . $this->l->t('The data for this record is hosted on ScienceData:') . '<br/>' . $linksHtml . '</p>';

		[$status, $body] = $this->http('POST', $api . '/account/articles', [
			'headers' => $this->authHeader($token),
			'body'    => json_encode($meta),
		]);
		if ($status < 200 || $status >= 300 || empty($body['location'])) {
			return PublishResult::fail($this->l->t('Figshare rejected the article: ') . $this->errorText($body));
		}
		$articleId = (int)preg_replace('#.*/articles/#', '', (string)$body['location']);

		// Best-effort: record the URLs as article references.
		$this->http('PUT', $api . '/account/articles/' . $articleId, [
			'headers' => $this->authHeader($token),
			'body'    => json_encode(['references' => array_values($urls)]),
		]);
		// A small pointer file uploaded normally (NOT a Figshare "linked file",
		// which stamps the record with an inaccurate "Linked content is NOT
		// stored on …" disclaimer). Mirrors the Zenodo pointer-file path so the
		// draft has content and reads cleanly; best-effort.
		$tmp = tempnam(sys_get_temp_dir(), 'fp_figshare_ptr');
		if ($tmp !== false) {
			file_put_contents($tmp,
				$this->l->t('This record describes data hosted on ScienceData.') . "\n\n" . implode("\n", $urls) . "\n");
			$this->uploadFile($token, $articleId, 'DATA-ON-SCIENCEDATA.txt', $tmp);
			@unlink($tmp);
		}

		$doi = '';
		[$dst, $dbody] = $this->http('POST', $api . '/account/articles/' . $articleId . '/reserve_doi', [
			'headers' => $this->authHeader($token),
		]);
		if ($dst >= 200 && $dst < 300 && !empty($dbody['doi'])) {
			$doi = (string)$dbody['doi'];
		}
		return PublishResult::ok((string)$articleId, $this->portalBase() . '/account/articles/' . $articleId, $doi);
	}

	/** Figshare initiate/parts/complete chunked upload. Returns '' on success or an error string. */
	private function uploadFile(string $token, int $articleId, string $name, string $path): string {
		$api  = $this->apiBase();
		$size = filesize($path);
		$md5  = md5_file($path);

		// initiate
		[$st, $body] = $this->http('POST', $api . '/account/articles/' . $articleId . '/files', [
			'headers' => $this->authHeader($token),
			'body'    => json_encode(['name' => $name, 'size' => $size, 'md5' => $md5]),
		]);
		if ($st < 200 || $st >= 300 || empty($body['location'])) {
			return $this->l->t('Could not start Figshare upload for ') . $name . ' — ' . $this->errorText($body);
		}
		// fetch the file info to get the upload service URL + part list
		[$ist, $info] = $this->http('GET', (string)$body['location'], ['headers' => $this->authHeader($token)]);
		if ($ist < 200 || $ist >= 300 || empty($info['upload_url'])) {
			return $this->l->t('Could not prepare Figshare upload for ') . $name;
		}
		$fileId = (int)($info['id'] ?? 0);
		[$pst, $parts] = $this->http('GET', (string)$info['upload_url'], ['headers' => $this->authHeader($token)]);
		if ($pst < 200 || $pst >= 300 || empty($parts['parts'])) {
			return $this->l->t('Could not list Figshare upload parts for ') . $name;
		}

		// PUT each part
		$fh = fopen($path, 'rb');
		if ($fh === false) {
			return $this->l->t('Cannot read ') . $name;
		}
		foreach ($parts['parts'] as $part) {
			$partSize = ((int)$part['endOffset'] - (int)$part['startOffset']) + 1;
			fseek($fh, (int)$part['startOffset']);
			$chunk = fread($fh, $partSize);
			[$put] = $this->http('PUT', $info['upload_url'] . '/' . $part['partNo'], [
				'body'    => $chunk,
				'timeout' => 86400,
			]);
			if ($put < 200 || $put >= 300) {
				fclose($fh);
				return $this->l->t('Figshare upload failed for ') . $name;
			}
		}
		fclose($fh);

		// complete
		[$cst] = $this->http('POST', $api . '/account/articles/' . $articleId . '/files/' . $fileId, [
			'headers' => $this->authHeader($token),
		]);
		if ($cst < 200 || $cst >= 300) {
			return $this->l->t('Could not finalise Figshare upload for ') . $name;
		}
		return '';
	}

	private function errorText($body): string {
		if (is_array($body)) {
			return (string)($body['message'] ?? json_encode($body));
		}
		return (string)$body;
	}
}
