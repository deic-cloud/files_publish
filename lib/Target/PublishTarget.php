<?php

declare(strict_types=1);

namespace OCA\FilesPublish\Target;

/**
 * A publishing destination (Zenodo, Figshare, … and later a media platform or
 * a native-share target). The app shell is target-agnostic; everything a
 * destination needs to differ lives behind this contract.
 *
 * Lifecycle of a publish:
 *   1. the file action offers every configured target (id/label/icon)
 *   2. the user fills the target's metadata schema (common + extras)
 *   3. authorize() — OAuth redirect, or none for token/native targets
 *   4. publish() — create record, upload file(s) (or create shares), return result
 *   5. the result's DOI + landing URL are shown; state is stored on the file
 *      (via meta_data) so a later publish updates rather than duplicates.
 */
interface PublishTarget {
	public function getId(): string;

	public function getLabel(): string;

	/** App-relative path to the target's icon, e.g. 'img/zenodo.svg'. */
	public function getIcon(): string;

	/** True once an admin has configured this target (credentials/URL). */
	public function isConfigured(): bool;

	/**
	 * Maximum total size (bytes) of a single publish, for a pre-flight block.
	 * 0 = no limit. Bounds both the repository's per-record policy and our own
	 * synchronous upload pipeline; admin-configurable, no live probe.
	 */
	public function maxUploadBytes(): int;

	/** 'public-doi' | 'community' | 'controlled-group' — informs the UI copy. */
	public function getAudienceModel(): string;

	/**
	 * Metadata fields the dialog should render. Each field:
	 *   ['key','label','type'(text|textarea|select),'required'(bool),
	 *    'options'?(array),'default'?,'hint'?].
	 * Common fields (title/description/authors/keywords) plus target extras
	 * (Zenodo upload_type; Figshare categories/defined_type/license).
	 */
	public function getMetadataSchema(): array;

	/**
	 * Build the OAuth authorize URL to send the user to, or '' when the
	 * target needs no interactive auth (personal token / native share).
	 * $state round-trips the pending publish (file ids + stored metadata key).
	 */
	public function getAuthorizeUrl(string $state): string;

	/**
	 * Non-interactive credentials, when the target can authenticate without an
	 * OAuth round-trip (e.g. a personal API token). Returns ['access_token'=>…]
	 * to feed straight into publish(), or [] when only OAuth is available.
	 * When this returns a token, getAuthorizeUrl() should return '' so the flow
	 * skips the authorize step.
	 *
	 * @return array<string,mixed>
	 */
	public function getDirectAuth(): array;

	/**
	 * Publish $files (absolute local paths keyed by display name) with the
	 * collected $metadata, using $auth (e.g. ['access_token'=>…]).
	 * Returns PublishResult. May upload to an API or create native shares.
	 *
	 * @param array<string,string> $files     name => absolute path
	 * @param array<string,mixed>  $metadata  validated against getMetadataSchema()
	 * @param array<string,mixed>  $auth
	 */
	public function publish(array $files, array $metadata, array $auth): PublishResult;

	/** Whether this target can deposit a metadata-only record that links to
	 *  externally-hosted data (kept on ScienceData) instead of uploading. */
	public function supportsLinkDeposit(): bool;

	/**
	 * Deposit a metadata-only record that references $urls (public ScienceData
	 * share links) rather than uploading the bytes — for large data that
	 * should stay on ScienceData while still getting a citable record/DOI.
	 *
	 * @param array<string,mixed> $metadata
	 * @param string[]            $urls
	 * @param array<string,mixed> $auth
	 */
	public function publishLink(array $metadata, array $urls, array $auth): PublishResult;
}
