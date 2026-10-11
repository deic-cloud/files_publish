<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @template-implements IEventListener<LoadAdditionalScriptsEvent> */
class LoadFilesScriptsListener implements IEventListener {
	public function __construct(
		private \OCA\FilesPublish\Service\TargetRegistry $registry,
		private \OCP\AppFramework\Services\IInitialState $initialState,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent)) {
			return;
		}
		Util::addStyle('files_publish', 'files');
		// "Assign DOI…" is offered only when DataCite registration is configured.
		$t = $this->registry->get(\OCA\FilesPublish\Target\DataCiteTarget::ID);
		$this->initialState->provideInitialState('doi', $t !== null && $t->isConfigured());
		// Single bundle (@nextcloud/files action + dialog + toasts); loads after
		// the Files app so its registry scope exists.
		Util::addScript('files_publish', 'files-action', 'files');
	}
}
