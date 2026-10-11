<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Controller;

use OCA\FilesPublish\Service\MetadataRecorder;
use OCA\FilesPublish\Service\PublishService;
use OCA\FilesPublish\Service\TargetRegistry;
use OCA\FilesPublish\Target\DataCiteTarget;
use OCA\FilesPublish\Target\PublishResult;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * "Assign DOI…": a DataCite DOI for a file or folder that stays here. The DOI
 * resolves to <landingBase>/doi/<suffix>, which the web server sends to the
 * item's landing page in the public catalogue; the DOI itself is recorded in
 * the item's DataCite schema (meta_data), which is how the catalogue finds the
 * item again. Only the item's owner can assign or change its DOI.
 */
class DoiController extends OCSController {
	public function __construct(
		string                   $appName,
		IRequest                 $request,
		private TargetRegistry   $registry,
		private PublishService   $publishService,
		private MetadataRecorder $recorder,
		private IRootFolder      $rootFolder,
		private IUserSession     $userSession,
		private IURLGenerator    $urlGenerator,
		private IConfig          $config,
		private IL10N            $l,
	) {
		parent::__construct($appName, $request);
	}

	private function target(): ?DataCiteTarget {
		$t = $this->registry->get(DataCiteTarget::ID);
		return ($t instanceof DataCiteTarget && $t->isConfigured()) ? $t : null;
	}

	/** The user's own node, or an error string. @return Node|string */
	private function ownNode(int $fileid) {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$node = $uid !== '' ? ($this->rootFolder->getUserFolder($uid)->getById($fileid)[0] ?? null) : null;
		if ($node === null) {
			return $this->l->t('File not found.');
		}
		if ($node->getOwner()?->getUID() !== $uid) {
			return $this->l->t('Only the owner of an item can assign it a DOI.');
		}
		return $node;
	}

	private function landingBase(): string {
		$base = $this->config->getAppValue('files_publish', 'datacite.landingBase', '');
		if ($base === '') {
			$base = (string)$this->config->getSystemValue('files_sharding_master_url', '');
		}
		if ($base === '') {
			$base = $this->urlGenerator->getAbsoluteURL('/');
		}
		return rtrim($base, '/');
	}

	private function landingUrl(DataCiteTarget $t, string $doi): string {
		return $this->landingBase() . '/doi/' . rawurlencode(substr($doi, strpos($doi, '/') + 1));
	}

	private function publisher(): string {
		$p = $this->config->getAppValue('files_publish', 'datacite.publisher', '');
		return $p !== '' ? $p : \OCP\Server::get(\OCP\Defaults::class)->getName();
	}

	private const SCHEMA_FIELDS = ['title', 'creators', 'description', 'resource_type', 'license', 'keywords', 'doi', 'url', 'date'];

	/** Form, prefill and the item's current DOI (with its live DataCite state). */
	#[NoAdminRequired]
	public function get(int $fileid): DataResponse {
		$t = $this->target();
		if ($t === null) {
			return new DataResponse(['message' => $this->l->t('DOI registration is not configured on this server.')], 404);
		}
		$node = $this->ownNode($fileid);
		if (is_string($node)) {
			return new DataResponse(['message' => $node], 403);
		}
		$uid = $this->userSession->getUser()->getUID();
		$recorded = $this->recorder->prefill($fileid, $t);
		// Suggestions: the item's name/type, then what other schemas on it say,
		// then the item's own DataCite record (wins).
		$values = $t->defaultsFor([$node->getName() => $node instanceof \OCP\Files\Folder]);
		$any = $this->recorder->anyValues($fileid);
		foreach (['title' => ['title'], 'description' => ['description', 'abstract', 'summary'], 'keywords' => ['keywords', 'subject']] as $k => $names) {
			foreach ($names as $n) {
				if (!empty($any[$n])) {
					$values[$k] = $any[$n];
					break;
				}
			}
		}
		foreach (['authors', 'creators'] as $n) {
			if (!empty($any[$n]) && is_array($j = json_decode($any[$n], true))) {
				$values['creators'] = $j;
				break;
			}
		}
		$values = array_merge($values, $recorded);
		$doi = (string)($recorded['_doi'] ?? '');
		$status = $doi !== '' ? $t->status($doi) : null;
		return new DataResponse([
			'schema'     => $t->getMetadataSchema(),
			'creators'   => $this->publishService->defaultCreators($uid),
			'values'     => $values,
			'doi'        => $doi,
			'state'      => $status['state'] ?? '',
			'landingUrl' => $doi !== '' ? $this->landingUrl($t, $doi) : '',
			'test'       => $t->isTest(),
			'ready'      => $this->recorder->hasSchema($t, self::SCHEMA_FIELDS),
		]);
	}

	/** Register a draft DOI for the item, or update the metadata of its existing DOI. */
	#[NoAdminRequired]
	public function assign(int $fileid, array $metadata): DataResponse {
		$t = $this->target();
		if ($t === null) {
			return new DataResponse(['message' => $this->l->t('DOI registration is not configured on this server.')], 404);
		}
		$node = $this->ownNode($fileid);
		if (is_string($node)) {
			return new DataResponse(['message' => $node], 403);
		}
		// Without the schema the DOI could not be recorded on the item, and its
		// landing page could never find the item: refuse before registering.
		if (!$this->recorder->hasSchema($t, self::SCHEMA_FIELDS)) {
			return new DataResponse(['message' => $this->l->t('This server has no "DataCite" metadata schema; ask an administrator.')], 409);
		}
		foreach ($t->getMetadataSchema() as $f) {
			$v = $metadata[$f['key']] ?? '';
			if (!empty($f['required']) && (is_array($v) ? $v === [] : trim((string)$v) === '')) {
				return new DataResponse(['message' => $this->l->t('Please fill the required fields.')], 400);
			}
		}
		$uid = $this->userSession->getUser()->getUID();
		if ($this->publishService->listedLink($uid, $node) === null) {
			return new DataResponse(['message' => $this->l->t('Could not create a public link (link sharing may be disabled).')], 500);
		}

		$attrs = $t->attributes($metadata, $this->publisher());
		$doi = (string)($this->recorder->prefill($fileid, $t)['_doi'] ?? '');
		if ($doi !== '') {
			$attrs['url'] = $this->landingUrl($t, $doi);
			$err = $t->update($doi, $attrs);
		} else {
			[$doi, $err] = $t->createDraft($attrs, fn (string $d) => $this->landingUrl($t, $d));
		}
		if ($doi === '') {
			return new DataResponse(['message' => $err], 502);
		}
		// Recorded even if setting the URL failed: the DOI exists at DataCite now.
		$this->recorder->record([$fileid], $t, $metadata, PublishResult::ok($doi, $this->landingUrl($t, $doi), $doi, '', false));
		if (($this->recorder->prefill($fileid, $t)['_doi'] ?? '') !== $doi) {
			return new DataResponse(['message' => $this->l->t('DOI %s was registered but could not be recorded on the item. Note it down and contact an administrator.', [$doi])], 500);
		}
		if ($err !== '') {
			return new DataResponse(['message' => $err, 'doi' => $doi], 502);
		}
		$status = $t->status($doi);
		return new DataResponse([
			'doi'        => $doi,
			'state'      => $status['state'] ?? 'draft',
			'landingUrl' => $this->landingUrl($t, $doi),
		]);
	}

	/** Make the item's draft DOI findable — permanent: it can never be deleted. */
	#[NoAdminRequired]
	public function findable(int $fileid): DataResponse {
		$t = $this->target();
		if ($t === null) {
			return new DataResponse(['message' => $this->l->t('DOI registration is not configured on this server.')], 404);
		}
		$node = $this->ownNode($fileid);
		if (is_string($node)) {
			return new DataResponse(['message' => $node], 403);
		}
		$doi = (string)($this->recorder->prefill($fileid, $t)['_doi'] ?? '');
		if ($doi === '') {
			return new DataResponse(['message' => $this->l->t('This item has no DOI yet.')], 409);
		}
		$err = $t->update($doi, ['event' => 'publish', 'url' => $this->landingUrl($t, $doi)]);
		if ($err !== '') {
			return new DataResponse(['message' => $err], 502);
		}
		return new DataResponse(['doi' => $doi, 'state' => $t->status($doi)['state'] ?? 'findable', 'landingUrl' => $this->landingUrl($t, $doi)]);
	}
}
