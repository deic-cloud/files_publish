<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Service;

use OCA\FilesPublish\Target\FigshareTarget;
use OCA\FilesPublish\Target\PublishTarget;
use OCA\FilesPublish\Target\ZenodoTarget;
use OCP\IL10N;

/**
 * Holds the known publish targets. New destinations (Dataverse, a media
 * platform, a native-share target) are added by registering their adapter
 * here — nothing else in the app needs to change.
 */
class TargetRegistry {
	/** @var array<string,PublishTarget>|null */
	private ?array $targets = null;

	public function __construct(
		private ConfigService $configService,
		private IL10N         $l,
		private \Psr\Log\LoggerInterface $logger,
	) {
	}

	/** @return array<string,PublishTarget> id => target */
	public function all(): array {
		if ($this->targets === null) {
			$this->targets = [];
			foreach ([
				new ZenodoTarget($this->configService, $this->l, $this->logger),
				new FigshareTarget($this->configService, $this->l, $this->logger),
			] as $t) {
				$this->targets[$t->getId()] = $t;
			}
		}
		return $this->targets;
	}

	public function get(string $id): ?PublishTarget {
		return $this->all()[$id] ?? null;
	}

	/** @return array<string,PublishTarget> only targets an admin has configured */
	public function configured(): array {
		return array_filter($this->all(), fn(PublishTarget $t) => $t->isConfigured());
	}
}
