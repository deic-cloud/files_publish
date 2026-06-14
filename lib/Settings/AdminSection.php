<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IL10N         $l,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return 'files-publish';
	}

	public function getName(): string {
		return $this->l->t('Data publishing');
	}

	public function getPriority(): int {
		return 55;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('core', 'actions/public.svg');
	}
}
