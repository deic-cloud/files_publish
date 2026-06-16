<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Controller;

use OCA\FilesPublish\Service\PublishService;
use OCA\FilesPublish\Service\TargetRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Two-step publish so the popup shows immediate feedback instead of a blank
 * page during a long upload:
 *   progress() — renders the "Uploading…" page (with a rough time estimate)
 *   execute()  — does the actual create/upload/DOI work and returns JSON; the
 *                progress page fetches it and then shows the result.
 * Session-authenticated; opened in the OAuth/publish popup.
 */
class PublishController extends Controller {
	public function __construct(
		string                  $appName,
		IRequest                $request,
		private TargetRegistry  $registry,
		private PublishService  $publishService,
		private IUserSession    $userSession,
		private IURLGenerator   $urlGenerator,
		private ISession        $session,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function progress(string $target, string $job): TemplateResponse {
		$t = $this->registry->get($target);
		$jobData = $this->publishService->peekJob($job);
		return new TemplateResponse('files_publish', 'progress', [
			'targetLabel' => $t !== null ? $t->getLabel() : $target,
			'runUrl'      => $this->urlGenerator->linkToRoute('files_publish.publish.execute', ['target' => $target, 'job' => $job]),
			'bytes'       => (int)($jobData['bytes'] ?? 0),
			'expired'     => ($t === null || $jobData === null),
		], TemplateResponse::RENDER_AS_GUEST);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function execute(string $target, string $job): DataResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			return new DataResponse(['ok' => false, 'message' => 'Please log in.'], 401);
		}
		$t = $this->registry->get($target);
		$jobData = $this->publishService->takeJob($job);
		if ($t === null || $jobData === null) {
			return new DataResponse(['ok' => false, 'message' => 'This publish request has expired. Please try again.'], 410);
		}
		// Prefer a session token from the OAuth callback; otherwise fall back to
		// the target's non-interactive credentials (e.g. a personal API token).
		$token = $this->publishService->takeToken($target);
		$auth  = $token !== '' ? ['access_token' => $token] : $t->getDirectAuth();

		// Release the session lock before the (seconds-to-minutes) upload so the
		// user's other tabs and background requests aren't blocked meanwhile.
		$this->session->close();

		$tmpFiles = [];
		try {
			$files = $this->publishService->resolveFiles($uid, $jobData['fileids'], $tmpFiles);
			if (!$files) {
				return new DataResponse(['ok' => false, 'message' => 'Could not read the selected file(s).']);
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
			return new DataResponse(['ok' => false, 'message' => $res->message ?: 'Publishing failed.']);
		}
		foreach ($jobData['fileids'] as $fileid) {
			$this->publishService->recordResult($uid, (int)$fileid, $target, $res);
		}
		return new DataResponse([
			'ok'          => true,
			'doi'         => $res->doi,
			'landingUrl'  => $res->landingUrl,
			'targetLabel' => $t->getLabel(),
		]);
	}
}
