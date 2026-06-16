<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Controller;

use OCA\FilesPublish\Service\ConfigService;
use OCA\FilesPublish\Service\PublishService;
use OCA\FilesPublish\Service\TargetRegistry;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class ApiController extends OCSController {
	public function __construct(
		string                 $appName,
		IRequest               $request,
		private TargetRegistry $registry,
		private PublishService $publishService,
		private ConfigService  $configService,
		private IUserSession   $userSession,
		private IURLGenerator  $urlGenerator,
		private IL10N          $l,
	) {
		parent::__construct($appName, $request);
	}

	/** Configured targets, for the file-action submenu and dialog. */
	#[NoAdminRequired]
	public function listTargets(): DataResponse {
		$out = [];
		foreach ($this->registry->configured() as $t) {
			$out[] = [
				'id'          => $t->getId(),
				'label'       => $t->getLabel(),
				'icon'        => $this->urlGenerator->imagePath('files_publish', basename($t->getIcon())),
				'audience'    => $t->getAudienceModel(),
				'supportsLink' => $t->supportsLinkDeposit(),
			];
		}
		return new DataResponse($out);
	}

	/** Metadata schema + author prefill for a target's publish dialog. */
	#[NoAdminRequired]
	public function getSchema(string $target): DataResponse {
		$t = $this->registry->get($target);
		if ($t === null || !$t->isConfigured()) {
			return new DataResponse(['error' => 'Unknown target'], 404);
		}
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		return new DataResponse([
			'schema'   => $t->getMetadataSchema(),
			'creators' => $this->publishService->defaultCreators($uid),
			'audience' => $t->getAudienceModel(),
		]);
	}

	/**
	 * Begin a publish: park the job, return the next step — an OAuth authorize
	 * URL (opened in a popup) or a direct run URL for non-interactive targets.
	 */
	#[NoAdminRequired]
	public function begin(string $target, array $fileids, array $metadata, bool $link = false): DataResponse {
		$t = $this->registry->get($target);
		if ($t === null || !$t->isConfigured()) {
			return new DataResponse(['error' => 'Unknown target'], 404);
		}
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$ids = array_map('intval', $fileids);
		$bytes = $this->publishService->estimateBytes($uid, $ids);

		// Pre-flight size block (configurable per target, no live probe) — unless
		// this is a link deposit (no upload). Too big to upload → steer to
		// keeping the data on ScienceData and publishing a record that links to
		// a share instead.
		if (!$link) {
			$limit = $t->maxUploadBytes();
			if ($limit > 0 && $bytes > $limit) {
				return new DataResponse([
					'step'    => 'too-large',
					'bytes'   => $bytes,
					'limit'   => $limit,
					'canLink' => $t->supportsLinkDeposit(),
					'message' => $this->l->t(
						'The selection is %1$s, which exceeds the %2$s limit for %3$s. Keep the data on ScienceData and publish a record that links to a share instead.',
						[\OCP\Util::humanFileSize($bytes), \OCP\Util::humanFileSize($limit), $t->getLabel()]
					),
				]);
			}
		}

		$jobId = $this->publishService->storeJob([
			'target'   => $target,
			'fileids'  => $ids,
			'metadata' => $metadata,
			'link'     => $link,
			'bytes'    => $link ? 0 : $bytes,
		]);
		// Both paths land on the progress page; for OAuth it's reached after the
		// authorize round-trip (the callback redirects there).
		$progressUrl = $this->urlGenerator->linkToRoute('files_publish.publish.progress', ['target' => $target, 'job' => $jobId]);
		$authUrl = $t->getAuthorizeUrl($jobId);
		if ($authUrl !== '') {
			return new DataResponse(['step' => 'oauth', 'url' => $authUrl]);
		}
		return new DataResponse(['step' => 'run', 'url' => $progressUrl]);
	}

	// ── Admin ───────────────────────────────────────────────────────────────

	public function getConfig(): DataResponse {
		$cfg = [];
		foreach ($this->registry->all() as $t) {
			$id = $t->getId();
			$cfg[$id] = [
				'configured'  => $t->isConfigured(),
				'baseUrl'     => $this->configService->get($id, 'baseUrl'),
				'clientAppID' => $this->configService->get($id, 'clientAppID'),
				'redirectUri' => $this->urlGenerator->linkToRouteAbsolute('files_publish.oauth.callback', ['target' => $id]),
			];
			if ($id === 'figshare') {
				$cfg[$id]['authBaseUrl']     = $this->configService->get($id, 'authBaseUrl');
				$cfg[$id]['portalUrl']       = $this->configService->get($id, 'portalUrl');
				$cfg[$id]['defaultCategory'] = $this->configService->get($id, 'defaultCategory');
				$cfg[$id]['defaultLicense']  = $this->configService->get($id, 'defaultLicense');
			}
		}
		return new DataResponse($cfg);
	}

	public function setConfig(string $target, array $values): DataResponse {
		// '' is the global namespace (e.g. defaultAffiliation); real targets must exist.
		if ($target !== '' && $this->registry->get($target) === null) {
			return new DataResponse(['error' => 'Unknown target'], 404);
		}
		if ($target !== '') {
			// Persist the canonical redirect URI so adapters can include it in auth URLs.
			$this->configService->set($target, 'redirectUri',
				$this->urlGenerator->linkToRouteAbsolute('files_publish.oauth.callback', ['target' => $target]));
		}
		$this->configService->setMany($target, $values, ['clientSecret', 'personalToken']);
		return new DataResponse(['msg' => 'Saved']);
	}
}
