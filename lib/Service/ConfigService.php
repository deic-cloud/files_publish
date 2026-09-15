<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Service;

use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * Per-target admin configuration (credentials, endpoints), kept in appconfig
 * under keys namespaced by target id, e.g. zenodo.clientAppID,
 * figshare.baseUrl. Secrets are never returned to the browser.
 */
class ConfigService {
	public function __construct(
		private IConfig $config,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * The OAuth redirect URI for a target on THIS node — computed, never stored:
	 * it must match what the admin registered at the repository, and a stored
	 * copy was only written when the admin form was saved (a node configured
	 * from the command line sent an empty redirect_uri → "Invalid redirect URI").
	 */
	public function redirectUri(string $target): string {
		return $this->urlGenerator->linkToRouteAbsolute('files_publish.oauth.callback', ['target' => $target]);
	}

	private function ckey(string $target, string $key): string {
		return $target === '' ? $key : $target . '.' . $key;
	}

	public function get(string $target, string $key, string $default = ''): string {
		return $this->config->getAppValue('files_publish', $this->ckey($target, $key), $default);
	}

	public function set(string $target, string $key, string $value): void {
		// Trim pasted whitespace — stray spaces in a client id/secret/URL are
		// always a mistake and silently break OAuth (encoded as '+').
		$this->config->setAppValue('files_publish', $this->ckey($target, $key), trim($value));
	}

	/** @param array<string,string> $values  key => value ('' on a secret leaves it unchanged) */
	public function setMany(string $target, array $values, array $secretKeys = []): void {
		foreach ($values as $key => $value) {
			if (in_array($key, $secretKeys, true) && $value === '') {
				continue; // don't overwrite a stored secret with a blank field
			}
			$this->set($target, $key, $value);
		}
	}
}
