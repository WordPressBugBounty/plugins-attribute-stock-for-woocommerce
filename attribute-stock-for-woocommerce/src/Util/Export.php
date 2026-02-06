<?php
namespace Mewz\WCAS\Util;

use Mewz\Framework\Util\Number;
use Mewz\QueryBuilder\DB;
use Mewz\WCAS\Models\AttributeStock;

class Export
{
	const FIELDS = [
		'title' => '',
		'sku' => '',
		'quantity' => '',
		'low_stock' => '',
		'backorders' => '',
		'enabled' => '',
		'internal' => '',
		'multiplex' => '',
		'lock_multipliers' => '',
		'product_sku' => '',
		'product_image' => '',
		'image_id' => '',
		'components' => '',
		'match_rules' => '',
		'filters' => '',
		'tags' => '',
		'notes' => '',
	];

	public static function to_csv_download(array $stock_ids)
	{
		$filename = 'attribute-stock-' . current_time('Y-m-d-His') . '.csv';
		$filename = apply_filters('mewz_wcas_export_filename', $filename, $stock_ids);

		$fields = apply_filters('mewz_wcas_export_fields', self::FIELDS, $stock_ids);

		// if headers have already been sent, there's not much else we can do here
		if (headers_sent()) {
			wp_die(__('Request headers have already been sent. This usually happens when PHP warnings or notices are being displayed. Please fix these and try again.', 'woocommerce-attribute-stock'));
		}

		$out = fopen('php://output', 'w');

		if (!$out) {
			wp_die(__('Writing to PHP\'s output stream (php://output) has been disabled. Unable to output CSV file download.', 'woocommerce-attribute-stock'));
		}

		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Pragma: no-cache');

		fputcsv($out, array_keys($fields));

		foreach ($stock_ids as $stock_id) {
			if ($row = self::build_export_row($fields, $stock_id)) {
				fputcsv($out, $row);
			}
		}

		die;
	}

	/**
	 * @param array $row
	 * @param int $stock_id
	 *
	 * @return mixed
	 */
	public static function build_export_row(array $row, $stock_id)
	{
		$stock = new AttributeStock($stock_id, 'edit');

		$props = array_diff(array_keys($row), [
			'components',
			'match_rules',
			'filters',
			'tags',
		]);

		foreach ($props as $prop) {
			if (!method_exists($stock, $prop)) {
				continue;
			}

			$value = $stock->$prop();

			if (is_array($value)) {
				$row[$prop] = implode(', ', $value);
			} elseif (is_bool($value)) {
				$row[$prop] = $value ? 'yes' : 'no';
			} elseif (is_float($value)) {
				$row[$prop] = Number::safe_decimal($value);
			} else {
				$row[$prop] = (string)$value;
			}
		}

		if (isset($row['components'])) {
			$components = $stock->components();
			!empty($components['child']) && $row['components'] = self::build_component_list($components['child']);
		}

		isset($row['match_rules']) && $row['match_rules'] = self::build_match_rules_export_data($stock);
		isset($row['filters']) && $row['filters'] = self::build_filters_export_data($stock);

		isset($row['tags']) && $row['tags'] = implode(', ', get_terms([
			'taxonomy' => 'product_tag',
			'object_ids' => $stock_id,
			'fields' => 'names',
			'orderby' => 'name',
		]));

		return apply_filters('mewz_wcas_export_row', $row, $stock_id, $stock);
	}

	public static function build_component_list($components, $sep = ', ')
	{
		$list = [];

		foreach ($components as $stock_id => $quantity) {
			$stock = AttributeStock::instance($stock_id, 'edit');
			if (!$stock->valid()) continue;

			$ident = $stock->sku();

			if (!$ident) {
				$ident = $stock->id() . '-' . $stock->slug();
			}

			if ($quantity !== '' && $quantity != 1) {
				$ident .= " ($quantity)";
			}

			$list[] = $ident;
		}

		return implode($sep, $list);
	}

	public static function build_product_list($product_ids, $sep = ', ')
	{
		$list = [];

		foreach ($product_ids as $product_id) {
			$product = wc_get_product($product_id);
			if (!$product) continue;

			$ident = $product->get_sku('edit');

			if (!$ident) {
				$ident = $product->get_id() . '-' . $product->get_slug('edit');
			}

			$list[] = $ident;
		}

		return implode($sep, $list);
	}

	public static function build_category_list($category_ids, $sep = ', ')
	{
		$list = [];

		foreach ($category_ids as $category_id) {
			$category = get_term($category_id);

			if (!$category || is_wp_error($category)) {
				continue;
			}

			$list[] = $category->slug;
		}

		return implode($sep, $list);
	}

	public static function build_match_rules_export_data(AttributeStock $stock, $sep = "\n")
	{
		$match_rules = $stock->match_rules();
		$attributes = Attributes::get_attributes();

		$lines = [];

		foreach ($match_rules as $rule) {
			$line_attr = [];

			foreach ($rule['conditions'] as $type_id => $value_ids) {
				if ($type_id === 0) {
					if ($product_list = self::build_product_list($value_ids, '|')) {
						$line_attr[] = '[products]: ' . $product_list;
					}
					continue;
				}

				if (!isset($attributes[$type_id])) {
					continue;
				}

				if ($value_ids) {
					$term_slugs = [];

					foreach ($value_ids as $value_id) {
						$term = get_term($value_id);

						if (!$term || is_wp_error($term)) {
							continue;
						}

						$term_slugs[] = $term->slug;
					}

					$terms = implode('|', $term_slugs);
				} else {
					$terms = '*';
				}

				$line_attr[] = $attributes[$type_id]->name . ': ' . $terms;
			}

			$line = implode(', ', $line_attr);

			if ($line) {
				if ($rule['multiplier'] !== '' && $rule['multiplier'] != 1) {
					$line .= ' (' . $rule['multiplier'] . ')';
				}

				$lines[] = $line;
			}
		}

		return implode($sep, $lines);
	}

	public static function build_filters_export_data(AttributeStock $stock, $sep = "\n")
	{
		$filters = $stock->filters();
		$lines = [];

		foreach ($filters as $key => $values) {
			if (!$values) continue;

			switch ($key) {
				case 'products':
				case 'excl_products':
					$lines[] = "$key: " . self::build_product_list($values, '|');
					break;
				case 'categories':
				case 'excl_categories':
					$lines[] = "$key: " . self::build_category_list($values, '|');
					break;
				default:
					$lines[] = "$key: " . implode(', ', $values);
			}
		}

		return implode($sep, $lines);
	}

	public static function import_row($row, $exclude_match_ids = null)
	{
		$row = apply_filters('mewz_wcas_import_row', $row);
		if (!$row) return false;

		$data = [];
		$match_rules = null;

		$list_keys = [
			'components',
			'match_rules',
			'filters',
			'tags',
		];

		foreach ($row as $key => $value) {
			if ($value === '' || in_array($key, $list_keys, true)) {
				continue;
			}

			if (self::is_unset_value($value)) {
				$value = '';

				if (in_array($key, ['title', 'sku'])) {
					continue;
				}
			}

			$data[$key] = $value;
		}

		if (!empty($row['match_rules'])) {
			if (self::is_unset_value($row['match_rules'])) {
				$match_rules = [];
			} elseif ($row['match_rules'][0] === '[' && $rule_data = json_decode($row['match_rules'], true)) {
				$match_rules = $rule_data;
			} elseif ($rule_data = self::match_rules($row['match_rules'])) {
				$match_rules = $rule_data;
			}
		}

		if (isset($data['sku']) || isset($data['title'])) {
			$stock = self::match_stock_item($data, $exclude_match_ids);
			$update = $stock->valid();

			if (!$update && !isset($data['title'])) {
				$data['title'] = $data['sku'];
			}
		} elseif ($match_rules) {
			$data['title'] = self::build_title_from_match_rules($match_rules);
			$stock = new AttributeStock(null, 'edit');
			$update = false;
		} else {
			// no identifier fields to import
			return false;
		}

		if (!empty($row['filters'])) {
			if (self::is_unset_value($row['filters'])) {
				$data['filters'] = [];
			} elseif ($row['filters'][0] === '{' && $filters = json_decode($row['filters'], true)) {
				$data['filters'] = $filters;
			} elseif ($filters = self::match_filters($row['filters'])) {
				$data['filters'] = $filters;
			}
		}

		$bool_keys = [
			'enabled',
			'internal',
			'multiplex',
			'lock_multipliers',
			'product_sku',
			'product_image',
		];

		foreach ($bool_keys as $key) {
			if (isset($data[$key])) {
				$data[$key] = wc_string_to_bool($data[$key]);
			}
		}

		$data = apply_filters('mewz_wcas_import_data', $data, $stock, $row);

		$stock->bind($data);

		if (!empty($data['add_quantity'])) {
			$stock->adjust_quantity($data['add_quantity']);
		}

		if ($stock->save() === false) {
			return false;
		}

		if (is_array($match_rules)) {
			$stock->save_match_rules($match_rules);
		}

		if (!empty($row['components'])) {
			if (self::is_unset_value($row['components'])) {
				$stock->save_components(['child' => false]);
			} elseif ($components = self::match_components($row['components'])) {
				$stock->save_components(['child' => $components]);
			}
		}

		if (!empty($row['tags'])) {
			if (self::is_unset_value($row['tags'])) {
				wp_delete_object_term_relationships($stock->id(), 'product_tag');
			} else {
				$tag_ids = [];

				foreach (self::str_list_to_array($row['tags']) as $tag) {
					$tag = wp_create_term($tag, 'product_tag');
					$tag_ids[] = (int)$tag['term_id'];
				}

				if ($tag_ids) {
					wp_set_object_terms($stock->id(), $tag_ids, 'product_tag');
				}
			}
		}

		do_action('mewz_wcas_imported_row', $row, $stock, $update);

		return [
			'stock' => $stock,
			'action' => $update ? 'updated' : 'added',
		];
	}

	public static function build_title_from_match_rules($match_rules)
	{
		$title = [];
		$conditions = [];

		foreach ($match_rules as $rule) {
			foreach ($rule['conditions'] as $type_id => $value_ids) {
				if (!isset($conditions[$type_id])) {
					$conditions[$type_id] = [];
				}

				foreach ($value_ids as $value_id) {
					$conditions[$type_id][$value_id] = true;
				}
			}
		}

		if (isset($conditions[0])) {
			foreach ($conditions[0] as $product_id => $_) {
				$product = wc_get_product($product_id);
				if (!$product) continue;
				$title[] = $product->get_name();
			}

			unset($conditions[0]);
		}

		foreach ($conditions as $attr_id => $term_ids) {
			$title_attr = Attributes::get_attribute_label($attr_id);
			$taxonomy = Attributes::get_attribute_name($attr_id, true);

			if ($term_ids) {
				$title_terms = [];

				foreach ($term_ids as $term_id => $_) {
					$term = get_term($term_id, $taxonomy);
					$title_terms[] = $term->name;
				}

				$title[] = $title_attr . ': ' . implode('|', $title_terms);
			} else {
				$title[] = $title_attr;
			}
		}

		return implode(', ', $title);
	}

	public static function match_stock_item($data, $exclude_ids = null)
	{
		$query = DB::table('posts', 'p')
			->where('p.post_type', AttributeStock::POST_TYPE)
			->where('p.post_status', ['publish', 'draft']);

		if ($exclude_ids) {
			$query->where_not('p.ID', $exclude_ids);
		}

		if (isset($data['sku'])) {
			$query->left_join('postmeta', 'sku')->on("sku.post_id = p.ID AND sku.meta_key = '_sku'");
		}

		if (isset($data['sku'], $data['title'])) {
			$query->where('sku.meta_value = ? OR p.post_title = ?', $data['sku'], $data['title']);
		}
		elseif (isset($data['sku'])) {
			$query->where('sku.meta_value', $data['sku']);
		}
		elseif (isset($data['title'])) {
			$query->where('p.post_title', $data['title']);
		}

		$stock_id = $query->var('p.ID') ?: null;
		$stock_id = apply_filters('mewz_wcas_import_match_stock_id', $stock_id, $data);

		return new AttributeStock($stock_id, 'edit');
	}

	public static function match_rules($str_data)
	{
		preg_match_all('/^(.*?)(?:\s*\(\s*([\d.]+)\s*\))?$/m', $str_data, $matches, PREG_SET_ORDER);

		$match_rules = [];

		foreach ($matches as $match) {
			if (empty($match[1])) continue;

			$rule = [];
			$conditions = explode(',', $match[1]);

			foreach ($conditions as $condition) {
				$condition = trim($condition);
				if ($condition === '') continue;

				$condition = explode(':', $condition, 2);

				if (count($condition) !== 2) {
					continue;
				}

				$key = trim($condition[0]);
				$value = trim($condition[1]);

				if ($key === '' || $value === '') {
					continue;
				}

				if ($key === '[products]') {
					$rule['conditions'][0] = self::match_products($value);
				} else {
					$attr_id = Attributes::get_attribute_id($key);
					if (!$attr_id) continue;

					$taxonomy = Attributes::get_attribute_name($attr_id, true);
					$term_ids = [];

					if ($value !== '*') {
						foreach (explode('|', $value) as $term_slug) {
							$term_slug = trim($term_slug);
							if ($term_slug === '') continue;

							if ($term = get_term_by('slug', $term_slug, $taxonomy)) {
								$term_ids[$term->term_id] = true;
							}
						}
					}

					$rule['conditions'][$attr_id] = array_keys($term_ids);
				}
			}

			if (!empty($rule['conditions'])) {
				if (isset($match[2]) && $match[2] !== '') {
					$rule['multiplier'] = (float)$match[2];
				}

				$match_rules[] = $rule;
			}
		}

		return $match_rules;
	}

	public static function match_components($data)
	{
		preg_match_all('/\s*([^,]+?)\s*(?>\(([\d.]+)\))?\s*(?=,|$)/m', $data, $matches);

		if (empty($matches[1])) {
			return [];
		}

		$components = [];

		foreach ($matches[1] as $i => $ident) {
			$stock_id = AttributeStock::find(['meta_key' => '_sku', 'meta_value' => $ident], 'edit', 'id');

			if (!$stock_id) {
				$post = get_page_by_path($ident, OBJECT, AttributeStock::POST_TYPE);

				if ($post) {
					$stock_id = $post->ID;
				}
			}

			if (!$stock_id) {
				$parts = explode('-', $ident, 2);

				if ($parts[0] === (string)(int)$parts[0]) {
					if (isset($parts[1]) && strlen($parts[1])) {
						$post = get_page_by_path($parts[1], OBJECT, AttributeStock::POST_TYPE);

						if ($post) {
							$stock_id = $post->ID;
						}
					}

					if (!$stock_id && get_post_type($parts[0]) === AttributeStock::POST_TYPE) {
						$stock_id = (int)$parts[0];
					}
				}
			}

			if ($stock_id > 0) {
				$quantity = $matches[2][$i] ?? '';
				$components[$stock_id] = $quantity;
			}
		}

		return $components;
	}

	public static function match_filters($str_data)
	{
		preg_match_all('/^(\w+):\s*(.+)$/m', $str_data, $matches, PREG_SET_ORDER);

		if (!$matches) {
			return [];
		}

		$filters = [];

		foreach ($matches as $match) {
			if (empty($match[1]) || empty($match[2])) {
				continue;
			}

			$key = $match[1];

			switch ($key) {
				case 'products':
				case 'excl_products':
					$filters[$key] = self::match_products($match[2]);
					break;
				case 'categories':
				case 'excl_categories':
					$filters[$key] = self::match_categories($match[2]);
					break;
				default:
					$filters[$key] = self::str_list_to_array($match[2]);
			}
		}

		return $filters;
	}

	public static function match_products($str_list)
	{
		if (!$str_list) return [];

		$idents = self::str_list_to_array($str_list);
		$product_ids = [];

		foreach ($idents as $ident) {
			if (!$ident) continue;

			$product_id = wc_get_product_id_by_sku($ident);

			if (!$product_id && $post = get_page_by_path($ident, OBJECT, 'product')) {
				$product_id = $post->ID;
			}

			if (!$product_id) {
				$product_id = Products::get_product_id_by_title($ident);
			}

			if (!$product_id) {
				$parts = explode('-', $ident, 2);

				if ($parts[0] === (string)(int)$parts[0]) {
					if (isset($parts[1]) && strlen($parts[1])) {
						$post = get_page_by_path($parts[1], OBJECT, 'product');

						if ($post) {
							$product_id = $post->ID;
						}
					}

					if (!$product_id && get_post_type($parts[0]) === 'product') {
						$product_id = (int)$parts[0];
					}
				}
			}

			if ($product_id > 0) {
				$product_ids[$product_id] = true;
			}
		}

		return array_keys($product_ids);
	}

	public static function match_categories($str_list)
	{
		if (!$str_list) return [];

		$cat_slugs = self::str_list_to_array($str_list);
		$cat_ids = [];

		foreach ($cat_slugs as $cat_slug) {
		    if ($cat_slug && $cat_term = get_term_by('slug', $cat_slug, 'product_cat')) {
			    $cat_ids[$cat_term->term_id] = true;
		    }
		}

		return array_keys($cat_ids);
	}

	public static function str_list_to_array($str_list, $sep = '[,|]')
	{
		$items = [];

		foreach (preg_split('/\s*' . $sep . '\s*/', $str_list) as $item) {
			if ($item !== '') {
				$items[] = $item;
			}
		}

		return $items;
	}

	public static function is_unset_value($value)
	{
	    return in_array($value, ['[]', '{}', '()'], true);
	}
}
