<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
?>
<div class="section" id="filesPublishAdmin">
	<h2><?php p($l->t('Data publishing')); ?></h2>
	<p class="settings-hint"><?php p($l->t('Configure the repositories users can publish files and folders to. Register each target\'s redirect URI with the repository\'s developer/application settings.')); ?></p>

	<div class="fp-row">
		<label for="fpAffiliation"><?php p($l->t('Default author affiliation')); ?></label>
		<input type="text" id="fpAffiliation" value="<?php p($_['defaultAffiliation']); ?>"
		       placeholder="<?php p($l->t('e.g. Technical University of Denmark')); ?>" />
	</div>

	<?php foreach ($_['targets'] as $t): ?>
	<fieldset class="fp-target" data-target="<?php p($t['id']); ?>">
		<legend><?php p($t['label']); ?>
			<span class="fp-state <?php p($t['configured'] ? 'on' : 'off'); ?>">
				<?php p($t['configured'] ? $l->t('configured') : $l->t('not configured')); ?>
			</span>
		</legend>

		<div class="fp-row">
			<label><?php p($l->t('Redirect URI to register')); ?></label>
			<code><?php p($t['redirectUri']); ?></code>
		</div>
		<div class="fp-row">
			<label for="fp-<?php p($t['id']); ?>-baseUrl"><?php p($l->t('API base URL')); ?></label>
			<input type="text" id="fp-<?php p($t['id']); ?>-baseUrl" data-key="baseUrl" value="<?php p($t['baseUrl']); ?>"
			       placeholder="<?php p($t['id'] === 'figshare' ? 'https://api.figshare.com/v2' : 'https://zenodo.org'); ?>" />
		</div>
		<?php if ($t['id'] === 'figshare'): ?>
		<div class="fp-row">
			<label for="fp-figshare-personalToken"><?php p($l->t('Personal token')); ?></label>
			<input type="password" id="fp-figshare-personalToken" data-key="personalToken" value=""
			       autocomplete="new-password"
			       placeholder="<?php p($t['extra']['hasPersonalToken'] ? $l->t('(set — unchanged if blank)') : $l->t('(optional — bypasses OAuth)')); ?>" />
		</div>
		<p class="fp-hint"><?php p($l->t('A Figshare personal token authenticates directly, skipping the OAuth authorize/redirect step. Recommended for institutional accounts where self-service OAuth apps are restricted. Leave blank to use the OAuth client credentials below instead.')); ?></p>
		<div class="fp-row">
			<label for="fp-figshare-authBaseUrl"><?php p($l->t('Authorize base URL')); ?></label>
			<input type="text" id="fp-figshare-authBaseUrl" data-key="authBaseUrl" value="<?php p($t['authBaseUrl']); ?>"
			       placeholder="https://figshare.com" />
		</div>
		<div class="fp-row">
			<label for="fp-figshare-portalUrl"><?php p($l->t('Web portal URL')); ?></label>
			<input type="text" id="fp-figshare-portalUrl" data-key="portalUrl" value="<?php p($t['portalUrl']); ?>"
			       placeholder="https://data.dtu.dk" />
		</div>
		<p class="fp-hint"><?php p($l->t('Where users review and submit their draft, and where the public record will live — the institutional Figshare portal (e.g. https://data.dtu.dk). Defaults to the authorize base URL if left blank.')); ?></p>
		<?php endif; ?>
		<div class="fp-row">
			<label for="fp-<?php p($t['id']); ?>-clientAppID"><?php p($l->t('Client ID')); ?></label>
			<input type="text" id="fp-<?php p($t['id']); ?>-clientAppID" data-key="clientAppID" value="<?php p($t['clientAppID']); ?>" />
		</div>
		<div class="fp-row">
			<label for="fp-<?php p($t['id']); ?>-clientSecret"><?php p($l->t('Client secret')); ?></label>
			<input type="password" id="fp-<?php p($t['id']); ?>-clientSecret" data-key="clientSecret" value=""
			       autocomplete="new-password" placeholder="<?php p($l->t('(unchanged if blank)')); ?>" />
		</div>
		<div class="fp-row">
			<label for="fp-<?php p($t['id']); ?>-maxPublishGB"><?php p($l->t('Max publish size (GB)')); ?></label>
			<input type="text" inputmode="numeric" id="fp-<?php p($t['id']); ?>-maxPublishGB" data-key="maxPublishGB"
			       value="<?php p($t['maxPublishGB']); ?>"
			       placeholder="<?php p($l->t('default %s', [$t['maxDefaultGB']])); ?>" />
		</div>
		<p class="fp-hint"><?php p($l->t('Uploads larger than this are blocked before they start (the user is steered to keep the data on ScienceData and publish a record linking to a share). This also bounds the synchronous upload — lower it if large uploads time out behind your proxy. Blank uses the default shown.')); ?></p>
		<?php if ($t['id'] === 'figshare'): ?>
		<div class="fp-row">
			<label for="fp-figshare-defaultCategory"><?php p($l->t('Default category ID')); ?></label>
			<input type="text" id="fp-figshare-defaultCategory" data-key="defaultCategory" value="<?php p($t['extra']['defaultCategory'] ?? ''); ?>" />
		</div>
		<div class="fp-row">
			<label for="fp-figshare-defaultLicense"><?php p($l->t('Default license ID')); ?></label>
			<input type="text" id="fp-figshare-defaultLicense" data-key="defaultLicense" value="<?php p($t['extra']['defaultLicense'] ?? ''); ?>" />
		</div>
		<?php endif; ?>
		<button class="fp-save" data-target="<?php p($t['id']); ?>"><?php p($l->t('Save')); ?></button>
		<span class="fp-msg" data-target="<?php p($t['id']); ?>"></span>
	</fieldset>
	<?php endforeach; ?>
</div>
