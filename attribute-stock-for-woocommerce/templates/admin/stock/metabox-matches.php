<?php
defined('ABSPATH') || die;

/**
 * @var Mewz\WCAS\Models\AttributeStock $stock
 * @var array $tabs
 * @var string $active
 */

$active ??= key($tabs);
?>

<div class="mewz-wcas-matches-metabox mewz-wcas-metabox-tabbed panel-wrap woocommerce">
	<ul class="wc-tabs product_data_tabs stock-details-tabs">
	  <?php foreach ($tabs as $key => $tab) : ?>
			<li class="<?= $key ?>_options <?= $key ?>_tab<?php if (isset($tab['class'])) echo ' ' . $tab['class']; ?><?php if ($key === $active) echo ' active'; ?>">
				<a href="#<?= $key ?>_panel"><span><?= esc_html($tab['label']) ?></span></a>
			</li>
	  <?php endforeach; ?>
	</ul>

	<?php foreach ($tabs as $key => $tab) : ?>
		<div id="<?= $key ?>_panel" class="panel panel-<?= $key ?> woocommerce_options_panel<?php if (isset($tab['panel_class'])) echo ' ' . $tab['panel_class']; ?>"<?php if ($key === $active) echo ' style="display: block;"'; ?>>
	    <?php do_action('mewz_wcas_matches_panel_' . $key, $stock, $tab); ?>
		</div>
	<?php endforeach; ?>
</div>
