<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
$ok = ($_['status'] ?? '') === 'ok';
?>
<div class="publish-result" style="max-width:560px;margin:8vh auto;text-align:center;font-family:var(--font-face,sans-serif);">
	<?php if ($ok): ?>
		<h2><?php p($l->t('Draft created on %s', [$_['targetLabel'] ?? ''])); ?></h2>
		<?php if (!empty($_['doi'])): ?>
		<p style="font-size:1.1em;">
			<?php p($l->t('Reserved DOI:')); ?>
			<strong><?php p($_['doi']); ?></strong>
			<br><span style="font-size:.8em;color:var(--color-text-maxcontrast);"><?php p($l->t('(becomes active once you submit the record)')); ?></span>
		</p>
		<?php endif; ?>
		<p><?php p($l->t('Your files have been uploaded as a draft. Review the metadata and submit it on the repository to mint the DOI and make it public:')); ?></p>
		<p><a class="button primary" href="<?php p($_['landingUrl']); ?>" target="_blank" rel="noopener"><?php p($l->t('Review and submit')); ?></a></p>
		<p style="color:var(--color-text-maxcontrast);"><?php p($l->t('You can close this window.')); ?></p>
	<?php else: ?>
		<h2><?php p($l->t('Publishing failed')); ?></h2>
		<p><?php p($_['detail'] ?? ''); ?></p>
	<?php endif; ?>
</div>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
	if (window.opener) { try { window.opener.postMessage({ filesPublish: '<?php p($ok ? 'ok' : 'error'); ?>' }, '*'); } catch (e) {} }
</script>
