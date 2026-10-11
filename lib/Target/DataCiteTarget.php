<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Target;

/**
 * A DOI registered directly with DataCite for a file or folder that stays on
 * this service: the DOI points at the item's landing page in the public
 * catalogue (files_picocms), reached through <landingBase>/doi/<suffix>.
 * Not a repository deposit, so it is not offered in "Publish…" — the
 * "Assign DOI…" action and DoiController drive it.
 *
 * Steps: register a DRAFT (DataCite picks the suffix), then set its URL;
 * "Make findable" is a separate, deliberate step (a findable DOI can never be
 * deleted). Updating the metadata of an existing DOI is a PUT on the same DOI.
 *
 * Admin config keys: apiUrl (https://api.test.datacite.org for testing,
 * https://api.datacite.org for real DOIs), repositoryId, password (sensitive),
 * prefix, publisher, landingBase (default: the cluster master, else this node).
 */
class DataCiteTarget extends AbstractHttpTarget {
	public const ID = 'datacite';

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return $this->l->t('DOI (DataCite)');
	}

	public function isConfigured(): bool {
		return $this->cfg('repositoryId') !== '' && $this->cfg('password') !== '' && $this->prefix() !== '';
	}

	public function getAudienceModel(): string {
		return 'public-doi';
	}

	/** Nothing is uploaded: the data stays where it is, behind a public link. */
	public function supportsLinkDeposit(): bool {
		return true;
	}

	public function getMetadataTag(): string {
		return 'DataCite';
	}

	public function getMetadataKeyMap(): array {
		return [
			'form'   => [
				'title' => 'title', 'creators' => 'creators', 'description' => 'description',
				'resource_type' => 'resource_type', 'license' => 'license', 'keywords' => 'keywords',
			],
			'result' => ['doi' => 'doi', 'url' => 'url', 'date' => 'date'],
		];
	}

	/** DataCite resourceTypeGeneral values offered (kernel 4.5). */
	private function resourceTypes(): array {
		return [
			'Dataset'               => $this->l->t('Dataset'),
			'ComputationalNotebook' => $this->l->t('Computational notebook'),
			'Software'              => $this->l->t('Software'),
			'Image'                 => $this->l->t('Image'),
			'Audiovisual'           => $this->l->t('Audiovisual'),
			'Text'                  => $this->l->t('Text'),
			'Collection'            => $this->l->t('Collection'),
			'Other'                 => $this->l->t('Other'),
		];
	}

	/** SPDX id => [label, URI]. */
	private const LICENSES = [
		'CC-BY-4.0'    => ['Creative Commons Attribution 4.0', 'https://creativecommons.org/licenses/by/4.0/'],
		'CC0-1.0'      => ['CC0 1.0 (public domain)', 'https://creativecommons.org/publicdomain/zero/1.0/'],
		'CC-BY-SA-4.0' => ['Creative Commons Attribution-ShareAlike 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/'],
		'CC-BY-NC-4.0' => ['Creative Commons Attribution-NonCommercial 4.0', 'https://creativecommons.org/licenses/by-nc/4.0/'],
		'MIT'          => ['MIT License', 'https://opensource.org/licenses/MIT'],
		'Apache-2.0'   => ['Apache License 2.0', 'https://www.apache.org/licenses/LICENSE-2.0'],
	];

	public function getMetadataSchema(): array {
		$licenses = [];
		foreach (self::LICENSES as $id => [$label]) {
			$licenses[$id] = $label;
		}
		return [
			['key' => 'title',         'label' => $this->l->t('Title'),       'type' => 'text',     'required' => true],
			['key' => 'creators',      'label' => $this->l->t('Authors'),     'type' => 'authors',  'required' => true,
				'hint' => $this->l->t('Separate authors with ";". Add an ORCID iD as "Name <0000-0000-0000-0000>".')],
			['key' => 'description',   'label' => $this->l->t('Description'), 'type' => 'textarea', 'required' => true],
			['key' => 'resource_type', 'label' => $this->l->t('Type'),        'type' => 'select',   'required' => true,
				'default' => 'Dataset', 'options' => $this->resourceTypes()],
			['key' => 'license',       'label' => $this->l->t('License'),     'type' => 'select',   'required' => true,
				'default' => 'CC-BY-4.0', 'options' => $licenses],
			['key' => 'keywords',      'label' => $this->l->t('Keywords'),    'type' => 'text',     'required' => false,
				'hint' => $this->l->t('Comma-separated.')],
		];
	}

	public function defaultsFor(array $filenames): array {
		if (count($filenames) !== 1) {
			return [];
		}
		$name = (string)array_key_first($filenames);
		$out = ['title' => $filenames[$name] === true ? $name : (string)pathinfo($name, PATHINFO_FILENAME)];
		if ($filenames[$name] !== true) {
			$ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
			$types = ['ipynb' => 'ComputationalNotebook', 'pdf' => 'Text', 'png' => 'Image', 'jpg' => 'Image',
				'jpeg' => 'Image', 'tif' => 'Image', 'tiff' => 'Image', 'mp4' => 'Audiovisual', 'mov' => 'Audiovisual'];
			if (isset($types[$ext])) {
				$out['resource_type'] = $types[$ext];
			}
		}
		return $out;
	}

	public function getAuthorizeUrl(string $state): string {
		return '';
	}

	public function publish(array $files, array $metadata, array $auth): PublishResult {
		return PublishResult::fail($this->l->t('A DOI is assigned to data that stays on this service; nothing is uploaded.'));
	}

	// ── DataCite REST ───────────────────────────────────────────────────────

	public function prefix(): string {
		return trim($this->cfg('prefix'), " /\t");
	}

	public function apiBase(): string {
		return rtrim($this->cfg('apiUrl', 'https://api.test.datacite.org'), '/');
	}

	/** True when DOIs are registered on DataCite's test service (not resolvable via doi.org). */
	public function isTest(): bool {
		return str_contains($this->apiBase(), '.test.');
	}

	private function headers(): array {
		return [
			'Authorization: Basic ' . base64_encode($this->cfg('repositoryId') . ':' . $this->cfg('password')),
			'Content-Type: application/vnd.api+json',
			'Accept: application/vnd.api+json',
		];
	}

	/** DataCite kernel-4 attributes from the form values. */
	public function attributes(array $m, string $publisher): array {
		$creators = [];
		foreach (($m['creators'] ?? []) as $c) {
			$name = trim((string)($c['name'] ?? ''));
			if ($name === '') {
				continue;
			}
			$cr = ['name' => $name, 'nameType' => 'Personal'];
			// "Family, Given" or "Given Family" → DataCite's split name parts
			if (str_contains($name, ',')) {
				[$fam, $giv] = array_map('trim', explode(',', $name, 2));
			} else {
				$parts = preg_split('/\s+/', $name);
				$fam = (string)array_pop($parts);
				$giv = implode(' ', $parts);
			}
			if ($giv !== '') {
				$cr['givenName']  = $giv;
				$cr['familyName'] = $fam;
				$cr['name']       = $fam . ', ' . $giv;
			}
			if (!empty($c['orcid'])) {
				$cr['nameIdentifiers'] = [[
					'nameIdentifier'       => 'https://orcid.org/' . $c['orcid'],
					'nameIdentifierScheme' => 'ORCID',
					'schemeUri'            => 'https://orcid.org',
				]];
			}
			if (!empty($c['affiliation'])) {
				$cr['affiliation'] = [['name' => (string)$c['affiliation']]];
			}
			$creators[] = $cr;
		}
		$attrs = [
			'creators'        => $creators,
			'titles'          => [['title' => trim((string)($m['title'] ?? ''))]],
			'publisher'       => ['name' => $publisher],
			'publicationYear' => (int)date('Y'),
			'types'           => ['resourceTypeGeneral' => (string)($m['resource_type'] ?? 'Dataset') ?: 'Dataset'],
			'schemaVersion'   => 'http://datacite.org/schema/kernel-4',
		];
		$desc = trim((string)($m['description'] ?? ''));
		if ($desc !== '') {
			$attrs['descriptions'] = [['description' => $desc, 'descriptionType' => 'Abstract']];
		}
		$lic = (string)($m['license'] ?? '');
		if (isset(self::LICENSES[$lic])) {
			$attrs['rightsList'] = [[
				'rights'                 => self::LICENSES[$lic][0],
				'rightsUri'              => self::LICENSES[$lic][1],
				'rightsIdentifier'       => strtolower($lic),
				'rightsIdentifierScheme' => 'SPDX',
				'schemeUri'              => 'https://spdx.org/licenses/',
			]];
		}
		$kw = array_values(array_filter(array_map('trim', explode(',', (string)($m['keywords'] ?? '')))));
		if ($kw !== []) {
			$attrs['subjects'] = array_map(static fn ($k) => ['subject' => $k], $kw);
		}
		return $attrs;
	}

	/**
	 * Register a draft DOI (suffix chosen by DataCite) and point it at the
	 * landing page. @return array{0:string,1:string} [doi, error]
	 */
	public function createDraft(array $attributes, callable $urlFor): array {
		$attributes['prefix'] = $this->prefix();
		[$st, $body] = $this->http('POST', $this->apiBase() . '/dois', [
			'headers' => $this->headers(),
			'body'    => json_encode(['data' => ['type' => 'dois', 'attributes' => $attributes]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		]);
		$doi = (string)($body['data']['id'] ?? '');
		if ($st !== 201 || $doi === '') {
			return ['', $this->errorText($st, $body)];
		}
		$err = $this->update($doi, ['url' => $urlFor($doi)]);
		return [$doi, $err];
	}

	/** PUT attributes on an existing DOI. @return string error, '' on success */
	public function update(string $doi, array $attributes): string {
		[$st, $body] = $this->http('PUT', $this->apiBase() . '/dois/' . $doi, [
			'headers' => $this->headers(),
			'body'    => json_encode(['data' => ['type' => 'dois', 'attributes' => $attributes]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		]);
		return $st === 200 ? '' : $this->errorText($st, $body);
	}

	/** @return array{state:string,url:string}|null  null when DataCite does not know the DOI (or is unreachable) */
	public function status(string $doi): ?array {
		[$st, $body] = $this->http('GET', $this->apiBase() . '/dois/' . $doi, ['headers' => $this->headers(), 'timeout' => 15]);
		if ($st !== 200 || !is_array($body)) {
			return null;
		}
		$a = $body['data']['attributes'] ?? [];
		return ['state' => (string)($a['state'] ?? ''), 'url' => (string)($a['url'] ?? '')];
	}

	private function errorText(int $st, $body): string {
		if (is_array($body) && !empty($body['errors'])) {
			$msgs = array_map(static fn ($e) => trim(($e['source'] ?? '') . ' ' . ($e['title'] ?? '')), $body['errors']);
			return 'DataCite: ' . implode('; ', $msgs);
		}
		return $st === 0 ? $this->l->t('DataCite could not be reached.') : 'DataCite: HTTP ' . $st;
	}
}
