<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
$bytes = (int)($_['bytes'] ?? 0);
$target = $_['targetLabel'] ?? '';
$expired = !empty($_['expired']);

// Rough upload-time estimate. Single transatlantic TLS stream to Figshare's
// AWS (us-east-1) is latency-bound well below the local uplink; ~10 MB/s
// matches what we observe in practice. CDNs/caching don't help uploads.
$rate = 10 * 1024 * 1024;
$eta  = $bytes > 0 ? (int)ceil($bytes / $rate) + 3 : 0; // +3s record/DOI overhead
if ($eta <= 0) {
	$etaText = '';
} elseif ($eta < 45) {
	$n = max(5, (int)(round($eta / 5) * 5));
	$etaText = $l->n('about %n second', 'about %n seconds', $n);
} else {
	$m = max(1, (int)round($eta / 60));
	$etaText = $l->n('about %n minute', 'about %n minutes', $m);
}
$sizeText = $bytes > 0 ? \OCP\Util::humanFileSize($bytes) : '';
?>
<div class="fp-popup" style="max-width:560px;margin:8vh auto;text-align:center;font-family:var(--font-face,sans-serif);">

	<?php if ($expired): ?>
		<h2><?php p($l->t('Publishing failed')); ?></h2>
		<p><?php p($l->t('This publish request has expired. Please try again.')); ?></p>
	<?php else: ?>

	<div id="fpProg">
		<h2><?php p($l->t('Uploading to %s…', [$target])); ?></h2>
		<p>
			<?php if ($sizeText !== ''): ?>
				<?php p($l->t('Uploading %s.', [$sizeText])); ?>
			<?php endif; ?>
			<?php p($l->t('Large files can take a while — please keep this window open.')); ?>
		</p>
		<?php if ($etaText !== ''): ?>
		<p style="color:var(--color-text-maxcontrast);"><?php p($l->t('Estimated time: %s', [$etaText])); ?></p>
		<?php endif; ?>
		<div class="fp-bar"><div class="fp-bar-fill"></div></div>
	</div>

	<div id="fpDone" style="display:none;">
		<h2><?php p($l->t('Draft created on %s', [$target])); ?></h2>
		<p id="fpDoiWrap" style="display:none;font-size:1.1em;">
			<?php p($l->t('Reserved DOI:')); ?> <strong id="fpDoi"></strong>
			<br><span style="font-size:.8em;color:var(--color-text-maxcontrast);"><?php p($l->t('(becomes active once you submit the record)')); ?></span>
		</p>
		<p><?php p($l->t('Your files have been uploaded as a draft. Review the metadata and submit it on the repository to mint the DOI and make it public:')); ?></p>
		<p><a id="fpLanding" class="button primary" target="_blank" rel="noopener"><?php p($l->t('Review and submit')); ?></a></p>
		<p style="color:var(--color-text-maxcontrast);"><?php p($l->t('You can close this window.')); ?></p>
	</div>

	<div id="fpErr" style="display:none;">
		<h2><?php p($l->t('Publishing failed')); ?></h2>
		<p id="fpErrMsg"></p>
	</div>

	<?php endif; ?>
</div>

<style nonce="<?php p($_['cspNonce'] ?? ''); ?>">
	.fp-bar { width: 80%; max-width: 400px; height: 8px; margin: 22px auto 0; background: var(--color-background-dark); border-radius: 4px; overflow: hidden; }
	.fp-bar-fill { width: 40%; height: 100%; border-radius: 4px; background: var(--color-primary-element, #0082c9); animation: fpSlide 1.3s ease-in-out infinite; }
	@keyframes fpSlide { 0% { margin-left: -40%; } 100% { margin-left: 100%; } }
</style>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
(function () {
	var expired = <?php echo $expired ? 'true' : 'false'; ?>;
	function tell(status) {
		if (window.opener) { try { window.opener.postMessage({ filesPublish: status }, '*'); } catch (e) {} }
	}
	if (expired) { tell('error'); return; }

	function show(id) { document.getElementById(id).style.display = ''; }
	function hide(id) { document.getElementById(id).style.display = 'none'; }

	function done(data) {
		hide('fpProg');
		if (data.doi) {
			document.getElementById('fpDoi').textContent = data.doi;
			show('fpDoiWrap');
		}
		var a = document.getElementById('fpLanding');
		if (data.landingUrl) { a.setAttribute('href', data.landingUrl); } else { a.style.display = 'none'; }
		show('fpDone');
		tell('ok');
	}
	function fail(msg) {
		hide('fpProg');
		document.getElementById('fpErrMsg').textContent = msg || <?php echo json_encode($l->t('Publishing failed')); ?>;
		show('fpErr');
		tell('error');
	}

	fetch(<?php echo json_encode($_['runUrl']); ?>, { credentials: 'same-origin' })
		.then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
		.then(function (data) { if (data && data.ok) { done(data); } else { fail(data && data.message); } })
		.catch(function (e) { fail(String(e)); });
})();
</script>
