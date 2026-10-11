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

		<?php if ($t['id'] === 'datacite'): ?>
		<p class="fp-hint"><?php p($l->t('Lets owners give a file or folder a DOI while the data stays here ("Assign DOI…" in Files). The DOI points at the item\'s page in the public catalogue, at <landing base>/doi/<suffix>; the web server must send that path to the catalogue. Requires the "DataCite" metadata schema and the catalogue (files_picocms).')); ?></p>
		<div class="fp-row">
			<label for="fp-datacite-apiUrl"><?php p($l->t('API URL')); ?></label>
			<input type="text" id="fp-datacite-apiUrl" data-key="apiUrl" value="<?php p($t['extra']['apiUrl'] ?? ''); ?>"
			       placeholder="https://api.test.datacite.org" />
		</div>
		<p class="fp-hint"><?php p($l->t('https://api.test.datacite.org for test DOIs, https://api.datacite.org for real ones.')); ?></p>
		<div class="fp-row">
			<label for="fp-datacite-repositoryId"><?php p($l->t('Repository ID')); ?></label>
			<input type="text" id="fp-datacite-repositoryId" data-key="repositoryId" value="<?php p($t['extra']['repositoryId'] ?? ''); ?>" />
		</div>
		<div class="fp-row">
			<label for="fp-datacite-password"><?php p($l->t('Password')); ?></label>
			<input type="password" id="fp-datacite-password" data-key="password" value="" autocomplete="new-password"
			       placeholder="<?php p(!empty($t['extra']['hasPassword']) ? $l->t('(set — unchanged if blank)') : ''); ?>" />
		</div>
		<div class="fp-row">
			<label for="fp-datacite-prefix"><?php p($l->t('DOI prefix')); ?></label>
			<input type="text" id="fp-datacite-prefix" data-key="prefix" value="<?php p($t['extra']['prefix'] ?? ''); ?>" placeholder="10.xxxxx" />
		</div>
		<div class="fp-row">
			<label for="fp-datacite-publisher"><?php p($l->t('Publisher')); ?></label>
			<input type="text" id="fp-datacite-publisher" data-key="publisher" value="<?php p($t['extra']['publisher'] ?? ''); ?>"
			       placeholder="<?php p($t['extra']['publisherDefault'] ?? ''); ?>" />
		</div>
		<div class="fp-row">
			<label for="fp-datacite-landingBase"><?php p($l->t('Landing base URL')); ?></label>
			<input type="text" id="fp-datacite-landingBase" data-key="landingBase" value="<?php p($t['extra']['landingBase'] ?? ''); ?>"
			       placeholder="<?php p($t['extra']['landingDefault'] ?? ''); ?>" />
		</div>
		<?php else: ?>
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
			<label for="fp-figshare-personalToken"><?php p($l->t('Personal token (testing only)')); ?></label>
			<input type="password" id="fp-figshare-personalToken" data-key="personalToken" value=""
			       autocomplete="new-password"
			       placeholder="<?php p($t['extra']['hasPersonalToken'] ? $l->t('(set — unchanged if blank)') : $l->t('(testing only — leave blank)')); ?>" />
		</div>
		<p class="fp-hint"><strong><?php p($l->t('Testing only.')); ?></strong> <?php p($l->t('A personal token authenticates as its owner, so EVERY user\'s Figshare publications would be created under this one account. It bypasses OAuth (useful where self-service OAuth apps are restricted) but is NOT a multi-user solution — leave blank in production until per-user OAuth is available at the institution.')); ?></p>
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
		<?php endif; ?>
		<?php if ($t['id'] === 'zenodo'): ?>
		<div class="fp-row">
			<label for="fp-zenodo-communities"><?php p($l->t('Default communities')); ?></label>
			<input type="text" id="fp-zenodo-communities" data-key="communities" value="<?php p($t['extra']['communities'] ?? ''); ?>"
			       placeholder="<?php p($l->t('e.g. sciencedata, dtu')); ?>" />
		</div>
		<p class="fp-hint"><?php p($l->t('Zenodo community identifiers (comma-separated) every deposit is submitted to — e.g. a national or institutional community curated by data stewards. The community\'s curators accept or reject each record. Blank: none.')); ?></p>
		<?php endif; ?>
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
