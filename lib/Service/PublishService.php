<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Service;

use OCA\FilesPublish\Target\PublishResult;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates a publish: resolves selected file ids to local paths (zipping
 * a folder on the fly), prefills author info, parks the pending job across
 * the OAuth round-trip in the session, and records the outcome on the file.
 */
class PublishService {
	public function __construct(
		private IRootFolder    $rootFolder,
		private IUserManager   $userManager,
		private IConfig        $config,
		private ISession       $session,
		private LoggerInterface $logger,
	) {
	}

	// ── Pending job (survives the OAuth popup round-trip) ──────────────────────

	/** @param array{target:string,fileids:int[],metadata:array} $job */
	public function storeJob(array $job): string {
		$id  = bin2hex(random_bytes(16));
		$all = $this->session->get('files_publish_jobs') ?: [];
		$all[$id] = $job;
		$this->session->set('files_publish_jobs', $all);
		return $id;
	}

	public function takeJob(string $id): ?array {
		$all = $this->session->get('files_publish_jobs') ?: [];
		$job = $all[$id] ?? null;
		if ($job !== null) {
			unset($all[$id]);
			$this->session->set('files_publish_jobs', $all);
		}
		return $job;
	}

	public function storeToken(string $target, string $token): void {
		$this->session->set('files_publish_token_' . $target, $token);
	}

	public function takeToken(string $target): string {
		$t = (string)($this->session->get('files_publish_token_' . $target) ?? '');
		$this->session->remove('files_publish_token_' . $target);
		return $t;
	}

	// ── Files → local paths ────────────────────────────────────────────────────

	/**
	 * Resolve file ids to [displayName => absolutePath]. A single folder is
	 * zipped to a temp file (its basename + .zip). Temp files to clean up are
	 * returned by reference.
	 *
	 * @param int[] $fileids
	 * @param string[] $tmpFiles  (out) paths to unlink after publishing
	 * @return array<string,string>
	 */
	public function resolveFiles(string $uid, array $fileids, array &$tmpFiles): array {
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$out = [];
		foreach ($fileids as $fileid) {
			$nodes = $userFolder->getById((int)$fileid);
			if (!$nodes) {
				continue;
			}
			$node = $nodes[0];
			if ($node instanceof Folder) {
				$zip = $this->zipFolder($node, $tmpFiles);
				if ($zip !== null) {
					$out[$node->getName() . '.zip'] = $zip;
				}
			} elseif ($node instanceof File) {
				$local = $this->localPath($node);
				if ($local !== null) {
					$out[$node->getName()] = $local;
				}
			}
		}
		return $out;
	}

	private function localPath(File $file): ?string {
		try {
			$path = $file->getStorage()->getLocalFile($file->getInternalPath());
			return ($path !== false && is_file($path)) ? $path : null;
		} catch (\Throwable $e) {
			$this->logger->error('files_publish: localPath failed: ' . $e->getMessage());
			return null;
		}
	}

	private function zipFolder(Folder $folder, array &$tmpFiles): ?string {
		$tmp = tempnam(sys_get_temp_dir(), 'files_publish_') . '.zip';
		$tmpFiles[] = $tmp;
		$zip = new \ZipArchive();
		if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			return null;
		}
		$this->addFolderToZip($zip, $folder, '');
		$zip->close();
		return $tmp;
	}

	private function addFolderToZip(\ZipArchive $zip, Folder $folder, string $prefix): void {
		foreach ($folder->getDirectoryListing() as $node) {
			$name = $prefix . $node->getName();
			if ($node instanceof Folder) {
				$zip->addEmptyDir($name);
				$this->addFolderToZip($zip, $node, $name . '/');
			} elseif ($node instanceof File) {
				$local = $this->localPath($node);
				if ($local !== null) {
					$zip->addFile($local, $name);
				}
			}
		}
	}

	// ── Author prefill (profile + ORCID) ───────────────────────────────────────

	/** @return array<int,array{name:string,affiliation:string,orcid:string}> */
	public function defaultCreators(string $uid): array {
		$user = $this->userManager->get($uid);
		$name = $user?->getDisplayName() ?: $uid;
		$orcid = '';
		if (class_exists(\OCA\UserOrcid\Lib::class)) {
			$orcid = \OCA\UserOrcid\Lib::getOrcid($uid);
		}
		$affiliation = $this->config->getAppValue('files_publish', 'defaultAffiliation', '');
		return [['name' => $name, 'affiliation' => $affiliation, 'orcid' => $orcid]];
	}

	// ── Result recorded on the file (for re-publish / citation) ────────────────

	public function recordResult(string $uid, int $fileid, string $target, PublishResult $r): void {
		$payload = json_encode([
			'target'     => $target,
			'recordId'   => $r->recordId,
			'doi'        => $r->doi,
			'landingUrl' => $r->landingUrl,
			'at'         => date('c'),
		]);
		$this->config->setUserValue($uid, 'files_publish', 'state_' . $fileid, $payload);
	}

	public function getResult(string $uid, int $fileid): ?array {
		$raw = $this->config->getUserValue($uid, 'files_publish', 'state_' . $fileid, '');
		return $raw === '' ? null : json_decode($raw, true);
	}
}
