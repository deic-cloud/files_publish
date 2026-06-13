<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Controller;

use OCA\FilesPublish\Service\ConfigService;
use OCA\FilesPublish\Service\PublishService;
use OCA\FilesPublish\Service\TargetRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * OAuth redirect target for the repositories. Exchanges the authorization
 * code for an access token, stashes it in the session, and forwards to the
 * publish-run endpoint carrying the parked job id (the OAuth `state`).
 */
class OauthController extends Controller {
	public function __construct(
		string                  $appName,
		IRequest                $request,
		private TargetRegistry  $registry,
		private ConfigService   $configService,
		private PublishService  $publishService,
		private IClientService  $clientService,
		private IURLGenerator   $urlGenerator,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function callback(string $target, string $code = '', string $state = '', string $error = ''): Response {
		$t = $this->registry->get($target);
		if ($t === null) {
			return $this->result('error', 'Unknown publishing target.');
		}
		if ($error !== '' || $code === '' || $state === '') {
			return $this->result('error', 'Authorization was cancelled or failed.');
		}
		$token = $this->exchange($target, $code);
		if ($token === '') {
			return $this->result('error', 'Could not obtain an access token.');
		}
		$this->publishService->storeToken($target, $token);
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('files_publish.publish.run', ['target' => $target, 'job' => $state])
		);
	}

	private function exchange(string $target, string $code): string {
		if ($target === 'figshare') {
			// Figshare's token endpoint lives under the API base, not the auth base.
			$tokenUrl = rtrim($this->configService->get('figshare', 'baseUrl', 'https://api.figshare.com/v2'), '/') . '/token';
		} else {
			$tokenUrl = rtrim($this->configService->get('zenodo', 'baseUrl', 'https://zenodo.org'), '/') . '/oauth/token';
		}
		try {
			$response = $this->clientService->newClient()->post($tokenUrl, [
				'body' => [
					'client_id'     => $this->configService->get($target, 'clientAppID'),
					'client_secret' => $this->configService->get($target, 'clientSecret'),
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => $this->configService->get($target, 'redirectUri'),
				],
				'headers' => ['Accept' => 'application/json'],
				'timeout' => 20,
			]);
			$data = json_decode((string)$response->getBody(), true);
			return (string)($data['access_token'] ?? '');
		} catch (\Throwable $e) {
			$this->logger->error('files_publish: token exchange failed for ' . $target . ': ' . $e->getMessage());
			return '';
		}
	}

	private function result(string $status, string $detail): TemplateResponse {
		return new TemplateResponse('files_publish', 'result', [
			'status' => $status, 'detail' => $detail, 'doi' => '', 'landingUrl' => '',
		], TemplateResponse::RENDER_AS_GUEST);
	}
}
