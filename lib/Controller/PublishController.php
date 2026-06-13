<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Controller;

use OCA\FilesPublish\Service\PublishService;
use OCA\FilesPublish\Service\TargetRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Runs the upload for a parked job once auth is in hand, then shows the
 * result (DOI + repository landing/edit link). Session-authenticated; opened
 * in the OAuth popup.
 */
class PublishController extends Controller {
	public function __construct(
		string                  $appName,
		IRequest                $request,
		private TargetRegistry  $registry,
		private PublishService  $publishService,
		private IUserSession    $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function run(string $target, string $job): TemplateResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			return $this->result('error', 'Please log in.');
		}
		$t = $this->registry->get($target);
		$jobData = $this->publishService->takeJob($job);
		if ($t === null || $jobData === null) {
			return $this->result('error', 'This publish request has expired. Please try again.');
		}
		$token = $this->publishService->takeToken($target);
		$auth  = $token !== '' ? ['access_token' => $token] : [];

		$tmpFiles = [];
		try {
			$files = $this->publishService->resolveFiles($uid, $jobData['fileids'], $tmpFiles);
			if (!$files) {
				return $this->result('error', 'Could not read the selected file(s).');
			}
			$res = $t->publish($files, $jobData['metadata'], $auth);
		} catch (\Throwable $e) {
			$this->logger->error('files_publish: publish run failed: ' . $e->getMessage());
			$res = \OCA\FilesPublish\Target\PublishResult::fail('Unexpected error during publishing.');
		} finally {
			foreach ($tmpFiles as $f) {
				@unlink($f);
			}
		}

		if (!$res->success) {
			return $this->result('error', $res->message ?: 'Publishing failed.');
		}
		foreach ($jobData['fileids'] as $fileid) {
			$this->publishService->recordResult($uid, (int)$fileid, $target, $res);
		}
		return $this->result('ok', '', $res->doi, $res->landingUrl, $t->getLabel());
	}

	private function result(string $status, string $detail, string $doi = '', string $landingUrl = '', string $targetLabel = ''): TemplateResponse {
		return new TemplateResponse('files_publish', 'result', [
			'status'      => $status,
			'detail'      => $detail,
			'doi'         => $doi,
			'landingUrl'  => $landingUrl,
			'targetLabel' => $targetLabel,
		], TemplateResponse::RENDER_AS_GUEST);
	}
}
