<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Controller;

use OCA\FilesPublish\Service\ConfigService;
use OCA\FilesPublish\Service\PublishService;
use OCA\FilesPublish\Service\TargetRegistry;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
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
	) {
		parent::__construct($appName, $request);
	}

	/** Configured targets, for the file-action submenu and dialog. */
	#[NoAdminRequired]
	public function listTargets(): DataResponse {
		$out = [];
		foreach ($this->registry->configured() as $t) {
			$out[] = [
				'id'       => $t->getId(),
				'label'    => $t->getLabel(),
				'icon'     => $this->urlGenerator->imagePath('files_publish', basename($t->getIcon())),
				'audience' => $t->getAudienceModel(),
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
	public function begin(string $target, array $fileids, array $metadata): DataResponse {
		$t = $this->registry->get($target);
		if ($t === null || !$t->isConfigured()) {
			return new DataResponse(['error' => 'Unknown target'], 404);
		}
		$jobId = $this->publishService->storeJob([
			'target'   => $target,
			'fileids'  => array_map('intval', $fileids),
			'metadata' => $metadata,
		]);
		$authUrl = $t->getAuthorizeUrl($jobId);
		if ($authUrl !== '') {
			return new DataResponse(['step' => 'oauth', 'url' => $authUrl]);
		}
		// No interactive auth: go straight to the run endpoint
		$runUrl = $this->urlGenerator->linkToRoute('files_publish.publish.run', ['target' => $target, 'job' => $jobId]);
		return new DataResponse(['step' => 'run', 'url' => $runUrl]);
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
		$this->configService->setMany($target, $values, ['clientSecret']);
		return new DataResponse(['msg' => 'Saved']);
	}
}
