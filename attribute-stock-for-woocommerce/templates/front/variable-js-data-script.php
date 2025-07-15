<?php
defined('ABSPATH') || die;

/**
 * @var Mewz\Framework\Services\View $this
 * @var array $js_data
 * @var string $inline_script
 */

if (empty($js_data) || empty($inline_script)) {
	return;
}

if ($data = wp_json_encode($js_data)) {
	$data = addcslashes($data, "\\'");
} else {
	return;
}
?>

<script type="text/javascript">
	// <?= $this->plugin->name ?>: Expand product variations dynamically
	(() => {
		const variableData = JSON.parse('<?= $data ?>');

		if (!window.mewzWcas || !mewzWcas.expandProductVariations) {
			<?= $inline_script ?>;
		}

		mewzWcas.expandProductVariations(variableData);
	})();
</script>
