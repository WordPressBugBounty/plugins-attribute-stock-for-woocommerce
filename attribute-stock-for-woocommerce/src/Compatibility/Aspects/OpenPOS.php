<?php
namespace Mewz\WCAS\Compatibility\Aspects;

use Mewz\Framework\Base\Aspect;

class OpenPOS extends Aspect
{
	public function __hooks()
	{
		add_filter('mewz_wcas_order_item_stock_change_callers', [$this, 'order_item_stock_change_callers']);
		add_action('op_add_order_final_after', [$this, 'op_add_order_final_after'], 15);
	}

	public function order_item_stock_change_callers($callers)
	{
		$callers[] = 'op_maybe_reduce_stock_levels';
		$callers[] = 'op_maybe_increase_stock_levels';

	    return $callers;
	}

	public function op_add_order_final_after($data)
	{
		do_action('woocommerce_reduce_order_stock', wc_get_order($data['order_id']));
	}
}
