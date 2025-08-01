<?php
namespace Mewz\WCAS\Aspects\Admin\Stock;

use Mewz\Framework\Base\Aspect;
use Mewz\Framework\Util\Admin;
use Mewz\Framework\Util\Number;
use Mewz\QueryBuilder\DB;
use Mewz\QueryBuilder\Query;
use Mewz\WCAS\Models\AttributeStock;
use Mewz\WCAS\Util\Attributes;
use Mewz\WCAS\Util\Components;
use Mewz\WCAS\Util\Matches;
use Mewz\WCAS\Util\Products;

class StockList extends Aspect
{
	public $enqueue = '@admin/stock-list';
	public $columns = [];
	public $time = 0;

	public function __hooks()
	{
		$post_type = AttributeStock::POST_TYPE;

		add_action('admin_notices', [$this, 'admin_notices']);
		add_filter("manage_{$post_type}_posts_columns", [$this, 'columns'], 0);
		add_filter("manage_{$post_type}_posts_columns", [$this, 'filter_columns'], 99);
		add_filter("manage_edit-{$post_type}_sortable_columns", [$this, 'sortable_columns'], 0);
		add_action("manage_{$post_type}_posts_custom_column", [$this, 'column_output'], 0, 2);
		add_filter('display_post_states', [$this, 'display_post_states'], 10, 2);
		add_filter('view_mode_post_types', [$this, 'view_mode_post_types']);
	}

	public function __scripts()
	{
		$this->scripts->export_data('stockListData', [
			'restUrl' => rest_url('mewz/wcas'),
			'restNonce' => wp_create_nonce('wp_rest'),
			'locale' => str_replace('_', '-', get_bloginfo('language')),
		]);
	}

	public function admin_notices()
	{
		if (!wc_string_to_bool(get_option('woocommerce_manage_stock'))) {
			Admin::display_notice('warning', '<p>' . sprintf(__('Stock management is disabled in your <a href="%s">WooCommerce settings</a>. Attribute stock won\'t function unless stock management is enabled.', 'woocommerce-attribute-stock'), esc_url(admin_url('admin.php?page=wc-settings&tab=products&section=inventory'))) . '</p>', false, true);
		}
	}

	public function columns($columns)
	{
		unset($columns['date']);

		$cb = $columns['cb'];
		unset($columns['cb']);

		$columns = [
			'cb' => $cb,
			'image' => __('Image', 'default'),
		] + $columns;

		$columns['actions'] = __('Actions', 'woocommerce');
		$columns['sku'] = __('SKU', 'woocommerce');
		$columns['quantity'] = __('Stock', 'woocommerce');
		$columns['notes'] = __('Notes', 'default');
		$columns['settings'] = __('Settings', 'default');
		$columns['components'] = __('Components', 'woocommerce-attribute-stock');
		$columns['products'] = __('Products', 'woocommerce');
		$columns['attributes'] = __('Attributes', 'woocommerce');
		$columns['filters'] = __('Filters', 'woocommerce-attribute-stock');
		$columns['taglist'] = __('Tags', 'woocommerce');
		$columns['created'] = __('Created', 'woocommerce');
		$columns['modified'] = __('Updated', 'woocommerce');

		return $columns;
	}

	public function sortable_columns($sortable)
	{
		if ($this->columns) {
			$sortable = [];

			foreach ($this->columns as $key => $label) {
				$sortable[$key] = $key;
			}
		}

		unset(
			$sortable['cb'],
			$sortable['image'],
			$sortable['actions']
		);

		return $sortable;
	}

	public function filter_columns($columns)
	{
		$valid_columns = $this->get_valid_columns();

		foreach ($valid_columns as $column => $valid) {
			if (!$valid) unset($columns[$column]);
		}

		$this->columns = $columns;

		return $columns;
	}

	public function get_valid_columns()
	{
		$qb = DB::table('posts', 'p')
			->where('p.post_type', AttributeStock::POST_TYPE)
			->where_not('p.post_status', 'auto-draft')
			->select('1')
			->limit(1);

		if (get_query_var('post_status') !== 'trash') {
			$qb->where_not('p.post_status', 'trash');
		}

		$queries = [
			'image' => (clone $qb)
				->left_join('postmeta', 'pm')->on('pm.post_id = p.ID')
				->where('pm.meta_key', '_thumbnail_id')
				->where_not('pm.meta_value', ['', '0']),

			'sku' => (clone $qb)
				->left_join('postmeta', 'pm')->on('pm.post_id = p.ID')
				->where('pm.meta_key', '_sku')
				->where_not('pm.meta_value', ''),

			'notes' => (clone $qb)
				->where_not('p.post_content', ''),

			'settings' => (clone $qb)
				->left_join('postmeta', 'pm')->on('pm.post_id = p.ID')
				->left_join(Components::TABLE, 'c')->on('c.child_id = p.ID')
				->where("
					(pm.meta_key = '_backorders' AND pm.meta_value != '')
					OR (pm.meta_key IN ('_internal', '_multiplex', '_lock_multipliers') AND pm.meta_value = '1')
					OR c.child_id IS NOT NULL
				"),

			'components' => (clone $qb)
				->join(Components::TABLE, 'c')->on('c.child_id = p.ID'),

			'products' => (clone $qb)
				->left_join(Matches::RULES_TABLE, 'r')->on('r.stock_id = p.ID')
				->left_join(Matches::CONDITIONS_TABLE, 'c')->on('c.rule_id = r.id')
				->where('c.type_id = 0')
				->where('c.value_id > 0'),

			'attributes' => (clone $qb)
				->left_join(Matches::RULES_TABLE, 'r')->on('r.stock_id = p.ID')
				->left_join(Matches::CONDITIONS_TABLE, 'c')->on('c.rule_id = r.id')
				->where('c.type_id > 0'),

			'filters' => (clone $qb)
				->left_join('postmeta', 'pm')->on('pm.post_id = p.ID')
				->where('pm.meta_key', '_filters')
				->where_not('pm.meta_value', ['', 'a:0:{}']),

			'taglist' => (clone $qb)
				->left_join('term_relationships', 'tr')->on('tr.object_id = p.ID')
				->left_join('term_taxonomy', 'tt')->on('tt.term_taxonomy_id = tr.term_taxonomy_id')
				->where('tt.taxonomy', 'product_tag'),
		];

		$queries = apply_filters('mewz_wcas_valid_columns', $queries, $qb);

		$selects = [];
		$valid = [];

		foreach ($queries as $column => $query) {
			if (is_bool($query)) {
				$valid[$column] = $query;
			} else {
				$sql = $query instanceof Query ? $query->sql() : $query;
				$column = DB::esc($column);
				$selects[] = "EXISTS(\n$sql\n) AS $column";
			}
		}

		if ($selects) {
			$sql = "SELECT\n" . implode(",\n", $selects);
			$results = DB::$wpdb->get_row($sql, ARRAY_A);

			if ($results) {
				$valid += $results;
			}
		}

		return $valid;
	}

	public function column_output($column, $post_id)
	{
		global $post;

		if ($post->post_type !== AttributeStock::POST_TYPE) {
			return;
		}

		$stock = AttributeStock::instance($post);

		switch ($column) {
			case 'image':
				$this->output_image_column($stock);
				break;

			case 'actions':
				do_action('mewz_wcas_stock_row_actions_output', $stock);
				break;

			case 'sku':
				echo esc_html($stock->sku());
				break;

			case 'quantity':
				$this->output_quantity_column($stock);
				break;

			case 'notes':
				$notes = $stock->notes();
				echo $notes !== '' ? '<span class="notes-text" title="' . esc_attr($notes) . '">' . esc_html($notes) . '</span>' : '';
				break;

			case 'settings':
				$this->output_settings_column($stock);
				break;

			case 'components':
				$this->output_components_column($stock);
				break;

			case 'products':
				$this->output_products_column($stock);
				break;

			case 'attributes':
				$this->output_attributes_column($stock);
				break;

			case 'filters':
				$this->output_filters_column($stock);
				break;

			case 'taglist':
				$this->output_tags_column($stock);
				break;

			case 'created':
				echo '<abbr title="' . esc_attr($stock->created(false, 'admin-full')) . '">' . esc_html($stock->created(false, 'admin-date')) . '</abbr>';
				break;

			case 'modified':
				echo '<abbr title="' . esc_attr($stock->modified(false, 'admin-full')) . '">' . esc_html($stock->modified(false, 'admin-date')) . '</abbr>';
				break;
		}
	}

	public function output_image_column(AttributeStock $stock)
	{
		$img = wp_get_attachment_image($stock->image_id(), [32, 32]);
		$url = $stock->edit_url();

		$class = 'stock-image';
		if (!$img) $class .= ' no-image';
		if (!$stock->enabled()) $class .= ' disabled';

		if ($url) {
			echo '<a href="' . esc_url($stock->edit_url()) . '" class="' . $class . '">' . $img . '</a>';
		} else {
			echo '<span class="' . $class . '">' . $img . '</span>';
		}
	}

	public function output_quantity_column(AttributeStock $stock)
	{
		echo $stock->formatted_quantity();

		if ($stock->trashed() || !current_user_can('edit_post', $stock->id()) || !apply_filters('mewz_wcas_allow_stock_inline_edit', true, $stock)) {
			return;
		}

		// show inline quantity edit controls if allowed
		if (current_user_can('edit_post', $stock->id()) && apply_filters('mewz_wcas_allow_stock_inline_edit', true, $stock)) {
			?><span class="inline-edit-controls inline-edit-quantity" data-stock-id="<?= $stock->id() ?>" data-value="<?= Number::safe_decimal($stock->quantity()) ?>"><?php
				?><button type="button" class="action-button edit-button edit-quantity-button" data-action="set_quantity" title="<?= esc_attr__('Edit Stock', 'woocommerce-attribute-stock') ?>"></button><?php
				?><button type="button" class="action-button adjust-quantity-button" data-action="adjust_quantity" title="<?= esc_attr__('Add/Subtract Stock', 'woocommerce-attribute-stock') ?>"></button><?php
			?></span><?php
		}

		// show available stock from child components if present
		$component_stock = Components::calc_quantity_from_components($stock->id());

		if ($component_stock !== false) {
			$this->view->render('admin/stock/list-chips', [
				'type' => 'component',
				'chips' => [[
					'value' => Number::local_format($component_stock, 0),
				]],
			]);
		}
	}

	public function output_settings_column(AttributeStock $stock)
	{
		static $titles = null;

		if ($titles === null) {
			$backorder_options = wc_get_product_backorder_options();
			$backorders_title = __('Backorders: %s', 'woocommerce-attribute-stock');

			$titles = apply_filters('mewz_wcas_stock_settings_badge_titles', [
				'component'         => __('Component stock', 'woocommerce-attribute-stock'),
				'backorders-no'     => sprintf($backorders_title, $backorder_options['no']),
				'backorders-yes'    => sprintf($backorders_title, $backorder_options['yes']),
				'backorders-notify' => sprintf($backorders_title, $backorder_options['notify']),
				'internal'          => __('Internal stock', 'woocommerce-attribute-stock'),
				'multiplex'         => __('Multiplex matching', 'woocommerce-attribute-stock'),
				'lock-multipliers'  => __('Lock multipliers', 'woocommerce-attribute-stock'),
			], $stock);
		}

		$internal = $stock->internal();
		$backorders = $internal ? '' : $stock->backorders();
		$backorders_key = 'backorders-' . ($backorders ?: 'default');

		$settings = apply_filters('mewz_wcas_stock_settings_badges', [
			'component'        => isset($this->columns['components']) && !empty($stock->components()['parent']),
			$backorders_key    => (bool)$backorders,
			'internal'         => $internal,
			'multiplex'        => $stock->multiplex(),
			'lock-multipliers' => $stock->lock_multipliers(),
		], $stock);

		if ($settings = array_filter($settings)) {
			echo '<div class="mewz-wcas-settings-badges">';

			foreach ($settings as $key => $_) {
				$title = $titles[$key];
				$letter = strtoupper($title[0]);

				echo '<span class="mewz-wcas-settings-badge setting-' . esc_attr($key) . '" title="' . esc_attr($title) . '" rel="tiptip">' . esc_html($letter) . '</span>';
			}

			echo '</div>';
		}
	}

	public function output_components_column(AttributeStock $stock)
	{
		$components = $stock->components();
		$chips = [];

		foreach ($components['child'] as $comp_id => $quantity) {
			$comp = AttributeStock::instance($comp_id);

			if (!$comp->valid('edit')) {
				continue;
			}

			$sku = $comp->sku();
			$title = $comp->title();

			if (strlen($sku) && (!strlen($title) || strlen($sku) < strlen($title))) {
				$label = $sku;
			} else {
				$label = $title;
				$title = strlen($sku) ? $sku : '';
			}

			if ($quantity !== '' && $quantity != 1) {
				$quantity = Number::local_format($quantity, 3);
			} else {
				$quantity = '';
			}

			$chips[] = [
				'value' => $label,
				'url' => $comp->edit_url(),
				'title' => $title,
				'meta' => $quantity,
			];
		}

		$this->view->render('admin/stock/list-chips', [
			'type' => 'component',
			'chips' => $chips,
		]);
	}

	public function output_products_column(AttributeStock $stock)
	{
		$match_rules = $stock->match_rules();
		if (!$match_rules) return;

		$product_ids = [];

		foreach ($match_rules as $rule) {
			if (!empty($rule['conditions'][0])) {
				$product_ids[] = $rule['conditions'][0];
			}
		}

		$product_ids = array_keys(array_flip(array_merge(...$product_ids)));

		if ($product_ids) {
			$this->render_product_filter_chips($product_ids);
		}
	}

	public function output_attributes_column(AttributeStock $stock)
	{
		$match_rules = $stock->match_rules();
		if (!$match_rules) return;

		$attr_options = Attributes::get_used_attribute_options($_REQUEST['post_status'] ?? null);
		if (!$attr_options) return;

		$attributes = Attributes::get_attributes();
		$any_term = __('Any', 'woocommerce-attribute-stock');
		$stock_attributes = [];
		$chips = [];

		foreach ($match_rules as $rule) {
			foreach ($rule['conditions'] as $type_id => $value_ids) {
				if (isset($attr_options[$type_id], $attributes[$type_id])) {
					foreach ($value_ids ?: [0] as $value_id) {
						$stock_attributes[$type_id][$value_id] = true;
					}
				}
			}
		}

		foreach ($attr_options as $attr_id => $attr_option) {
			if (!isset($stock_attributes[$attr_id])) {
				continue;
		    }

			$stock_attr = $stock_attributes[$attr_id];
			$taxonomy = $attributes[$attr_id]->taxonomy;
			$term_names = [];

			foreach ($attr_option['terms'] as $term_option) {
				if (isset($stock_attr[$term_option[0]])) {
					$name = $term_option[0] === 0 ? $any_term : $term_option[1];
					$term_names[$term_option[0]] = $name;
			    }
			}

			$url = '';

			if (current_user_can('manage_product_terms')) {
				if (count($term_names) > 1 || key($term_names) === 0) {
					$url = "edit-tags.php?taxonomy={$taxonomy}&post_type=product";
				} elseif (current_user_can('edit_product_terms')) {
					$value_id = key($term_names);
					$url = "term.php?taxonomy={$taxonomy}&tag_ID={$value_id}&post_type=product&wp_http_referer=%2Fwp-admin%2Fedit-tags.php%3Ftaxonomy%3D{$taxonomy}%26post_type%3Dproduct";
				}
			}

			$chips[] = [
				'label' => $attr_option['label'],
				'value' => implode(', ', $term_names),
				'url' => $url ? admin_url($url) : false,
			];
		}

		$this->view->render('admin/stock/list-chips', [
			'type' => 'attribute',
			'chips' => $chips,
		]);
	}

	public function output_tags_column(AttributeStock $stock)
	{
		$tags = $stock->tags();
		if (!$tags) return;

		$chips = [];

		foreach ($tags as $tag) {
			$chips[] = [
				'value' => $tag->name,
				'url' => AttributeStock::admin_url('product_tag=' . $tag->slug),
			];
		}

		$this->view->render('admin/stock/list-chips', [
			'type' => 'tag',
			'chips' => $chips,
		]);
	}

	public function output_filters_column(AttributeStock $stock)
	{
		$filters = $stock->filters();

		echo '<div class="mewz-wcas-chips">';

		$this->render_product_filter_chips($filters['products']);
		$this->render_product_filter_chips($filters['excl_products'], 'exclude');
		$this->render_category_filter_chips($filters['categories']);
		$this->render_category_filter_chips($filters['excl_categories'], 'exclude');
		$this->render_product_types_filter_chips($filters['product_types']);

		echo '</div>';
	}

	public function render_product_filter_chips($product_ids, $class = '')
	{
		if (!$product_ids) return;

		$chips = [];

		foreach ($product_ids as $product_id) {
			$product = wc_get_product($product_id);
			if (!$product) continue;

			$chips[] = [
				'value' => $product->get_name(),
				'url' => get_edit_post_link($product->get_parent_id() ?: $product->get_id(), 'raw'),
			];
		}

		$this->view->render('admin/stock/list-chips', [
			'type' => 'product',
			'wrap' => false,
			'chip_class' => $class,
			'chips' => $chips,
		]);
	}

	public function render_category_filter_chips($category_ids, $class = '')
	{
		if (!$category_ids) return;

		$chips = [];

		foreach ($category_ids as $category_id) {
			$category_name = Products::get_category_tree_label($category_id, ' > ', true);

			if (!$category_name || is_wp_error($category_name)) {
				continue;
			}

			if (current_user_can('manage_product_terms') && current_user_can('edit_product_terms')) {
				$url = admin_url("term.php?taxonomy=product_cat&tag_ID={$category_id}&post_type=product");
			} else {
				$url = false;
			}

			$chips[] = [
				'value' => $category_name,
				'url' => $url,
			];
		}

		$this->view->render('admin/stock/list-chips', [
			'wrap' => false,
			'type' => 'category',
			'chip_class' => $class,
			'chips' => $chips,
		]);
	}

	public function render_product_types_filter_chips($product_types)
	{
		if (!$product_types) return;

		$product_types = array_intersect_key(Products::get_product_types(), array_flip($product_types));

		if (!$product_types) return;

		if (current_user_can('edit_products')) {
			$url = admin_url('edit.php?post_type=product&product_type=' . implode(',', array_keys($product_types)));
		} else {
			$url = false;
		}

		$chips[] = [
			'value' => implode(', ', $product_types),
			'url' => $url,
		];

		$this->view->render('admin/stock/list-chips', [
			'type' => 'product-type',
			'wrap' => false,
			'chips' => $chips,
		]);
	}

	public function display_post_states($post_states, $post)
	{
		if ($post->post_type === AttributeStock::POST_TYPE && isset($post_states['draft'])) {
			$post_states['draft'] = __('Disabled', 'woocommerce');
		}

		return $post_states;
	}

	public function view_mode_post_types($post_types)
	{
		unset($post_types[AttributeStock::POST_TYPE]);

		return $post_types;
	}
}
