<?php
namespace Mewz\WCAS\Compatibility\Aspects;

use Mewz\Framework\Base\Aspect;

class Flatsome extends Aspect
{
	public function __hooks()
	{
		add_action('mewz_wcas_product_stock_changed', [$this, 'product_stock_changed'], 10, 2);
	}

	public function product_stock_changed($product_id, $product)
	{
		if (!apply_filters('flatsome_swatches_cache_enabled', true)) {
			remove_filter('mewz_wcas_product_stock_changed', [$this, 'product_stock_changed']);
			return;
		}

		$product_id = $product->get_parent_id() ?: $product_id;

		delete_transient('flatsome_swatches_cache_' . $product_id);
	}
}
