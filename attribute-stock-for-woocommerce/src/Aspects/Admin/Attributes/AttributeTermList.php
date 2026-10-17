<?php
namespace Mewz\WCAS\Aspects\Admin\Attributes;

use Mewz\Framework\Base\Aspect;
use Mewz\Framework\Util\Number;
use Mewz\QueryBuilder\DB;
use Mewz\WCAS\Models\AttributeStock;
use Mewz\WCAS\Util\Attributes;
use Mewz\WCAS\Util\Matches;

class AttributeTermList extends Aspect
{
	public $enqueue = '@admin/attributes';
	public $show_columns;

	public function __hooks()
	{
		$taxonomy = $this->context->taxonomy;

		add_action('admin_head', [$this, 'admin_head']);

		add_filter("manage_edit-{$taxonomy}_columns", [$this, 'columns']);
		add_filter("manage_edit-{$taxonomy}_sortable_columns", [$this, 'sortable_columns']);
		add_filter("manage_{$taxonomy}_custom_column", [$this, 'column_output'], 10, 3);
		add_filter('terms_clauses', [$this, 'terms_clauses'], 10, 3);
	}

	public function admin_head()
	{
		$this->show_columns = $this->show_columns();

		if (!$this->show_columns) {
			remove_filter("manage_{$this->context->taxonomy}_custom_column", [$this, 'column_output']);
		}

		add_filter('admin_body_class', [$this, 'admin_body_class']);
	}

	public function show_columns()
	{
		global $wp_list_table;

		if ($wp_list_table && empty($wp_list_table->items)) {
			return [];
		}

		$attribute_id = Attributes::get_attribute_id($this->context->taxonomy);

		if (!$attribute_id) {
			return [];
		}

		$show_columns['stock'] = Matches::query(true)
			->left_join('posts', 'p')->on('p.ID = r.stock_id')
			->where('p.post_type', AttributeStock::POST_TYPE)
			->where('p.post_status', 'publish')
			->where('c.type_id', $attribute_id)
			->where('c.value_id', '>', 0)
			->clear('distinct')
			->exists();

		$show_columns['multiplier'] = DB::table('term_taxonomy', 'tt')
			->left_join('termmeta', 'tm')->on('tm.term_id = tt.term_id')
			->where('tt.taxonomy', $this->context->taxonomy)
			->where('tm.meta_key', 'mewz_wcas_multiplier')
			->where_not('tm.meta_value', '')
			->exists();

		return array_filter($show_columns);
	}

	public function admin_body_class($class)
	{
		if (empty($this->show_columns['stock'])) {
			$class .= ' mewz-wcas-hide-stock-column';
		}

		if (empty($this->show_columns['multiplier'])) {
			$class .= ' mewz-wcas-hide-multiplier-column';
		}

	    return $class;
	}

	public function columns($columns)
	{
		$add_columns = [
			'mewz_wcas_stock' => __('Stock', 'woocommerce'),
			'mewz_wcas_multiplier' => __('Multiplier', 'woocommerce-attribute-stock'),
		];

		// insert before "Count" column
		$pos = array_search('posts', array_keys($columns));

		if ($pos !== false) {
			$columns = array_slice($columns, 0, $pos, true) + $add_columns + array_slice($columns, $pos, null, true);
		} else {
			$columns += $add_columns;
		}

	    return $columns;
	}

	/**
	 * @param array $sortable
	 *
	 * @return array
	 */
	public function sortable_columns($sortable)
	{
		if (is_array($this->show_columns) && empty($this->show_columns['stock'])) {
			return $sortable;
		}

		$sortable['mewz_wcas_stock'] = [
			'mewz_wcas_stock',
			false,
			__('Stock', 'woocommerce'),
			__('Table ordered by Stock.', 'woocommerce-attribute-stock'),
		];

		return $sortable;
	}

	/**
	 * Sorts the term list by the lowest available stock quantity of each term's
	 * matching attribute stock items, mirroring the displayed Stock column values.
	 *
	 * @see show_columns()
	 *
	 * @param array $clauses
	 * @param array $taxonomies
	 * @param array $args
	 *
	 * @return array
	 */
	public function terms_clauses($clauses, $taxonomies, $args)
	{
		$orderby = $args['orderby'] ?? '';
		if (is_array($orderby)) {
			$orderby = (string)reset($orderby);
		}

		if ($orderby !== 'mewz_wcas_stock' || !in_array($this->context->taxonomy, (array)$taxonomies, true) || ($args['fields'] ?? '') === 'count') {
			return $clauses;
		}

		$attribute_id = Attributes::get_attribute_id($this->context->taxonomy);
		if (!$attribute_id) {
			return $clauses;
		}

		$order = strtoupper($clauses['order'] ?? '') === 'DESC' ? 'DESC' : 'ASC';

		$clauses['join'] .= ' LEFT JOIN (' . $this->stock_sort_subquery($attribute_id) . ') mewz_wcas_term_stock ON mewz_wcas_term_stock.term_id = t.term_id';
		$clauses['orderby'] = 'ORDER BY (mewz_wcas_term_stock.sort_qty IS NULL) ASC, mewz_wcas_term_stock.sort_qty ' . $order . ', t.name ASC';
		$clauses['order'] = '';

		return $clauses;
	}

	/**
	 * Builds a subquery that calculates the lowest available stock quantity of each
	 * attribute term, based on the same matching logic used by the Stock column.
	 *
	 * Terms with no matching stock get no row, allowing them to be sorted last.
	 *
	 * @see output_stock_column()
	 *
	 * @param int $attribute_id
	 *
	 * @return string
	 */
	public function stock_sort_subquery($attribute_id)
	{
		$rules = DB::prefix(Matches::RULES_TABLE);
		$conds = DB::prefix(Matches::CONDITIONS_TABLE);
		$posts = DB::prefix('posts');
		$postmeta = DB::prefix('postmeta');
		$post_type = DB::value(AttributeStock::POST_TYPE);
		$attr_id = (int)$attribute_id;

		return "
			SELECT s.term_id, MIN(s.available_qty) AS sort_qty
			FROM (
				SELECT
					m.term_id,
					CASE
						WHEN 0 + m.mult <= 0 THEN 0
						ELSE 0 + m.meta_value / m.mult
					END AS available_qty
				FROM (
					SELECT
						c.value_id AS term_id,
						qty.meta_value,
						COALESCE(NULLIF((
							SELECT r2.multiplier
							FROM {$rules} r2
							JOIN {$conds} c2 ON c2.rule_id = r2.id
							WHERE r2.stock_id = r.stock_id
							  AND c2.type_id = {$attr_id}
							  AND c2.value_id = c.value_id
							ORDER BY r2.priority, r2.id
							LIMIT 1
						), ''), '1') AS mult
					FROM {$rules} r
					JOIN {$conds} c ON c.rule_id = r.id
					JOIN {$posts} p ON p.ID = r.stock_id
					JOIN {$postmeta} qty ON qty.post_id = p.ID AND qty.meta_key = '_quantity'
					LEFT JOIN {$postmeta} al ON al.post_id = p.ID AND al.meta_key = 'attribute_level' AND al.meta_value = {$attr_id}
					WHERE c.type_id = {$attr_id}
					  AND c.value_id > 0
					  AND p.post_type = {$post_type}
					  AND p.post_status = 'publish'
					  AND (
					      al.post_id IS NULL
					      OR NOT EXISTS(
						      SELECT 1
						      FROM {$rules} r3
						      JOIN {$conds} c3 ON c3.rule_id = r3.id
						      JOIN {$posts} p3 ON p3.ID = r3.stock_id
						      LEFT JOIN {$postmeta} al3 ON al3.post_id = p3.ID AND al3.meta_key = 'attribute_level' AND al3.meta_value = {$attr_id}
						      WHERE c3.type_id = {$attr_id}
						        AND c3.value_id = c.value_id
						        AND p3.post_type = {$post_type}
						        AND p3.post_status = 'publish'
						        AND al3.post_id IS NULL
					      )
					  )
				) m
			) s
			GROUP BY s.term_id
		";
	}

	public function column_output($output, $column_name, $term_id)
	{
		switch ($column_name) {
			case 'mewz_wcas_stock':
				$output = $this->output_stock_column($term_id);
				break;

			case 'mewz_wcas_multiplier':
				$output = $this->output_multiplier_column($term_id);
				break;
		}

		return $output;
	}

	public function output_stock_column($term_id)
	{
		if (is_array($this->show_columns) && empty($this->show_columns['stock'])) {
			return '';
		}

		$attribute_id = Attributes::get_attribute_id($this->context->taxonomy);

		$stock_ids = Matches::query_stock($attribute_id, $term_id, 'view', 'id');
		$stock_count = count($stock_ids);

		if ($stock_count === 1) {
			$stock = AttributeStock::instance($stock_ids[0]);

			$edit_url = $stock->edit_url(['_wp_http_referer' => $_SERVER['REQUEST_URI']]);
			$title = in_array($attribute_id, $stock->meta('attribute_level', false)) ? __('Edit attribute-level stock', 'woocommerce-attribute-stock') : __('Edit stock', 'woocommerce-attribute-stock');
			$quantity = Matches::get_term_display_quantity($stock, $attribute_id, $term_id);

			return '<a href="' . esc_url($edit_url) . '" class="mewz-wcas-stock-link" title="' . esc_attr($title) . '">' . $quantity . '</a>';
		}

		if ($stock_count > 1) {
			$qty_range = Matches::get_attribute_display_range($stock_ids, $attribute_id, $term_id);
			$url = AttributeStock::admin_url(['attribute' => $attribute_id, 'term' => $term_id]);

			return '<a href="' . esc_url($url) . '" class="mewz-wcas-stock-link mewz-wcas-stock-range" title="' . esc_attr__('List stock items', 'woocommerce-attribute-stock') . '">' . $qty_range . '</a>';
		}

		return '';
	}

	public function output_multiplier_column($term_id)
	{
		if (is_array($this->show_columns) && empty($this->show_columns['multiplier'])) {
			return '';
		}

		$multiplier = get_term_meta($term_id, 'mewz_wcas_multiplier', true);
		if ($multiplier === '') return '';

		return '<span class="mewz-wcas-term-multiplier"><span class="times">&times;</span>' . Number::local_format($multiplier) . '</span>';
	}
}
