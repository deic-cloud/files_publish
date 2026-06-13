<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @template-implements IEventListener<LoadAdditionalScriptsEvent> */
class LoadFilesScriptsListener implements IEventListener {
	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent)) {
			return;
		}
		Util::addStyle('files_publish', 'files');
		Util::addScript('files_publish', 'dialog');
		Util::addScript('files_publish', 'main');
	}
}
