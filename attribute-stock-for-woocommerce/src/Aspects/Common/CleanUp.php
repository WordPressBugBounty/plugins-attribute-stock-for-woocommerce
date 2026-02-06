<?php
namespace Mewz\WCAS\Aspects\Common;

use Mewz\Framework\Base\Aspect;
use Mewz\QueryBuilder\DB;
use Mewz\WCAS\Models\AttributeStock;
use Mewz\WCAS\Util;

class CleanUp extends Aspect
{
	public function __hooks()
	{
		// clear caches
		add_action('clean_post_cache', [$this, 'clear_post_cache'], 10, 2);
		add_action('mewz_attribute_stock_saved', [$this, 'clear_stock_cache'], 0);
		add_action('mewz_attribute_stock_before_save', [$this, 'clear_attribute_cache'], 100);
		add_action('update_option_mewz_wcas_limit_product_stock', [$this, 'clear_stock_cache']);
		add_action('update_option_mewz_wcas_allow_backorders', [$this, 'clear_stock_cache']);
		add_action('update_option_mewz_wcas_unmatched_any_variations', [$this, 'clear_stock_cache']);
		add_action('mewz_wcas_match_rules_saved', [$this, 'clean_match_rules']);
		add_action('mewz_wcas_clean_match_rules', [$this, 'clean_match_rules']);
		add_action('mewz_wcas_components_saved', [$this, 'clear_components_cache']);
		add_action('added_term_meta', [$this, 'clear_term_meta_cache'], 10, 4);
		add_action('updated_term_meta', [$this, 'clear_term_meta_cache'], 10, 4);
		add_action('deleted_term_meta', [$this, 'clear_term_meta_cache'], 10, 4);
		add_action('mewz_wcas_product_stock_changed', [$this, 'clear_product_page_cache'], 10, 2);

		// delete associated data
		add_action('delete_post', [$this, 'delete_post'], 10, 2);
		add_action('delete_term', [$this, 'delete_term'], 10, 3);
		add_action('woocommerce_attribute_deleted', [$this, 'deleted_attribute']);
	}

	public function clear_post_cache($post_id, \WP_Post $post)
	{
		if ($post->post_type === AttributeStock::POST_TYPE) {
			$this->clear_stock_cache();
		}
		elseif (in_array($post->post_type, ['product', 'product_variation'])) {
			$this->cache->invalidate('product_' . $post_id);

			if ($post->post_parent) {
				$this->cache->invalidate('product_' . $post->post_parent);
			}
		}
	}

	public function clear_stock_cache()
	{
		$this->cache->invalidate('stock');

		// clear product transients
		wc_delete_product_transients();
	}

	public function clear_attribute_cache($stock)
	{
		// clear attribute transients
		$attribute_ids = Util\Matches::query()
			->where('r.stock_id', $stock->id())
			->col('c.type_id');

		$taxonomies = [];

		foreach ($attribute_ids as $attribute_id) {
			if ($taxonomy = Util\Attributes::get_attribute_name((int)$attribute_id, true)) {
				$taxonomies[] = $taxonomy;
			}
		}

		if ($taxonomies) {
			\WC_Cache_Helper::invalidate_attribute_count($taxonomies);
		}
	}

	public function clean_match_rules()
	{
		// ensure no dangling, empty or invalid rules/conditions
		DB::table(Util\Matches::CONDITIONS_TABLE, 'c')
			->left_join(Util\Matches::RULES_TABLE, 'r')->on('r.id = c.rule_id')
			->where('r.id IS NULL OR (c.type_id = 0 AND c.value_id = 0)')
			->delete();

		DB::table(Util\Matches::RULES_TABLE, 'r')
			->left_join(Util\Matches::CONDITIONS_TABLE, 'c')->on('c.rule_id = r.id')
			->left_join('posts', 'p')->on('p.ID = r.stock_id')
			->where('c.id IS NULL OR p.ID IS NULL')
			->delete();

		$this->cache->invalidate('match_rules');
	}

	public function clear_components_cache()
	{
		$this->cache->invalidate('components');
	}

	public function clear_term_meta_cache($meta_id, $object_id, $meta_key, $meta_value)
	{
		if ($meta_key === 'mewz_wcas_multiplier') {
			$this->cache->delete('term_multipliers');
			$this->cache->invalidate('multipliers');
		}
	}

	public function clear_product_page_cache($product_id, $product)
	{
		$post = get_post($product_id);
		if (!$post) return;

	    if ($post->post_parent > 0) {
			$post = get_post($post->post_parent);
		    if (!$post) return;
		}

	    // we only want to indicate that the product page cache should be cleared,
	    // we don't need to clear the full post cache for stock changes
	    do_action('clean_post_cache', $post->ID, $post);
	}

	public function delete_post($post_id, \WP_Post $post)
	{
		if ($post->post_type === AttributeStock::POST_TYPE) {
			Util\Matches::save_rules($post_id, false);
			Util\Components::save_components($post_id, false);
		}
		elseif ($post->post_type === 'product') {
			Util\Matches::remove_condition(0, $post_id);
		}
	}

	public function delete_term($term_id, $tt_id, $taxonomy)
	{
		if (strpos($taxonomy, 'pa_') === 0) {
			Util\Compatibility::safe_post_type([Util\Matches::class, 'remove_condition'], $taxonomy, $term_id);
		}
	}

	public function deleted_attribute($attribute_id)
	{
		Util\Matches::remove_condition($attribute_id);
	}
}
