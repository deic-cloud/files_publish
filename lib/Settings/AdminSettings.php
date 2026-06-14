<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Settings;

use OCA\FilesPublish\Service\ConfigService;
use OCA\FilesPublish\Service\TargetRegistry;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function __construct(
		private TargetRegistry $registry,
		private ConfigService  $configService,
		private IURLGenerator  $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript('files_publish', 'admin');
		Util::addStyle('files_publish', 'admin');

		$targets = [];
		foreach ($this->registry->all() as $t) {
			$id = $t->getId();
			$targets[] = [
				'id'          => $id,
				'label'       => $t->getLabel(),
				'configured'  => $t->isConfigured(),
				'baseUrl'     => $this->configService->get($id, 'baseUrl'),
				'authBaseUrl' => $this->configService->get($id, 'authBaseUrl'),
				'clientAppID' => $this->configService->get($id, 'clientAppID'),
				'extra'       => $id === 'figshare'
					? [
						'defaultCategory' => $this->configService->get($id, 'defaultCategory'),
						'defaultLicense'  => $this->configService->get($id, 'defaultLicense'),
					] : [],
				'redirectUri' => $this->urlGenerator->linkToRouteAbsolute('files_publish.oauth.callback', ['target' => $id]),
			];
		}

		return new TemplateResponse('files_publish', 'admin', [
			'targets'            => $targets,
			'defaultAffiliation' => $this->configService->get('', 'defaultAffiliation'),
		]);
	}

	public function getSection(): string {
		return 'files-publish';
	}

	public function getPriority(): int {
		return 55;
	}
}
