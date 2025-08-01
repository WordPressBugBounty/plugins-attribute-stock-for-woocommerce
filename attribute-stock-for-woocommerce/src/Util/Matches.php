<?php
namespace Mewz\WCAS\Util;

use Mewz\Framework\Util\Multilang;
use Mewz\Framework\Util\Number;
use Mewz\Framework\Util\WooCommerce;
use Mewz\QueryBuilder\DB;
use Mewz\WCAS\Models\AttributeStock;

class Matches
{
	const RULES_TABLE = 'wcas_rules';
	const CONDITIONS_TABLE = 'wcas_rule_conditions';

	/**
	 * @param bool $left_join
	 *
	 * @return \Mewz\QueryBuilder\Query
	 */
	public static function query($left_join = false)
	{
		$query = DB::table(self::RULES_TABLE, 'r')->distinct();

		if ($left_join) {
			$query->left_join(self::CONDITIONS_TABLE, 'c');
		} else {
			$query->join(self::CONDITIONS_TABLE, 'c');
		}

		$query->on('c.rule_id = r.id');

		return $query;
	}

	/**
	 * @param float $stock_qty
	 * @param float $multiplier
	 *
	 * @return int
	 */
	public static function calc_limit_qty($stock_qty, $multiplier = 1)
	{
		if ($multiplier == 1 || $multiplier === '') {
			return wc_stock_amount($stock_qty);
		} elseif ($multiplier <= 0) {
			return 0;
		} else {
			return wc_stock_amount($stock_qty / $multiplier);
		}
	}

	/**
	 * Finds all attribute stock items matching a product + attributes (including internal stock).
	 *
	 * @param \WC_Product $product The product object
	 * @param array $attributes Key/value pairs where key is an attribute id/name/taxonomy
	 *                          and value is a term id/slug or an array of term ids and/or slugs.
	 *                          Passed directly to {@see Matches::match_raw_stock()}.
	 * @param string $context 'view' or 'edit'
	 *
	 * @return array
	 */
	public static function match_product_stock($product, array $attributes, $context = 'view')
	{
		if (!$product instanceof \WC_Product) {
			return [];
		}

		$attribute_id_sets = Attributes::get_attribute_id_sets($attributes);

		$raw_matches = self::match_raw_stock($attribute_id_sets, $product->get_parent_id() ?: $product->get_id());
		$raw_matches = apply_filters('mewz_wcas_product_stock_raw_matches', $raw_matches, $product, $attributes, $context);

		$stock_matches = [];

		if ($raw_matches) {
			if (Multilang::active()) {
				$product = Products::bypass_multilang_product($product);
			}

			if (!empty($raw_matches)) {
				if (count($raw_matches) >= 3) {
					_prime_post_caches(array_keys($raw_matches), false);
				}

				$valid_matches = [];
				$no_multiplier_rule_ids = [];
				$multiplier_override = Products::get_multiplier($product, 'attribute', null);

				foreach ($raw_matches as $stock_id => $rules) {
					$stock = AttributeStock::instance($stock_id, $context);

					if (!$stock->valid() || !self::validate_filters($stock, $product, true, false)) {
						continue;
					}

					$valid_rules = [];
					$multiplex = $stock->multiplex();
					$lock_multipliers = $stock->lock_multipliers();

					foreach ($rules as $rule_id => $multiplier) {
						if ($multiplier !== '' && $multiplier < 0) {
							break; // a negative multiplier is a stop rule
						}

						$valid_rules[$rule_id] = $multiplier;

						if ($multiplier === '' && ($multiplier_override === null || $lock_multipliers)) {
							$no_multiplier_rule_ids[] = $rule_id;
						}

						if (!$multiplex) break;
					}

					if (!$valid_rules) continue;

					$valid_matches[$stock_id] = [
						'stock' => $stock,
						'rules' => $valid_rules,
						'multiplex' => $multiplex,
						'lock_multipliers' => $lock_multipliers,
					];
				}

				if ($valid_matches) {
					$term_multipliers = $no_multiplier_rule_ids ? Attributes::match_rule_multipliers($no_multiplier_rule_ids, $attribute_id_sets) : [];

					foreach ($valid_matches as $stock_id => $match) {
						$stock = $match['stock'];

						if ($multiplier_override === null || $match['lock_multipliers']) {
							$multipliers = [];

							foreach ($match['rules'] as $rule_id => $multiplier) {
								$multipliers[] = $multiplier === '' ? ($term_multipliers[$rule_id] ?? 1.00) : (float)$multiplier;
							}

							$multiplier = $match['multiplex'] ? array_sum($multipliers) : $multipliers[0];
						} else {
							$multiplier = $match['multiplex'] ? $multiplier_override * count($match['rules']) : $multiplier_override;
						}

						$stock_matches[$stock_id] = [
							'stock_id' => $stock_id,
							'stock_qty' => $stock->quantity(),
							'multiplier' => $multiplier,
						];
					}
				}
			}
		}

		/**
		 * IMPORTANT: The results from this filter may be cached for performance reasons.
		 * This means the input ($product, $attributes) should always have the same output,
		 * or the cache must be invalidated accordingly.
		 *
		 * @see Limits::get_stock_limits()
		 */
		return apply_filters('mewz_wcas_product_stock_matches', $stock_matches, $product, $attributes, $raw_matches, $context);
	}

	/**
	 * Match attribute stock items based solely on match rules.
	 *
	 * @param array<int, int[]> $attribute_id_sets Attribute ID sets from {@see Attributes::get_attribute_id_sets()}.
	 * @param int|int[] $product_ids Optional product ID or IDs.
	 *
	 * @return array Raw stock match results, before any validation or filtering
	 */
	public static function match_raw_stock(array $attribute_id_sets = null, $product_ids = null)
	{
		$match_query = self::build_stock_match_query($attribute_id_sets, $product_ids);
		if (!$match_query) return [];

		$results = DB::$wpdb->get_results($match_query['query']);
		$matches = [];

		if ($results) {
			foreach ($results as $row) {
				$matches[$row->stock_id][$row->rule_id] = $row->multiplier;
			}
		}

		return $matches;
	}

	/**
	 * @param array<int, int[]> $attribute_id_sets
	 * @param int|int[] $product_ids
	 * @param string|null $select
	 *
	 * @return array|false
	 */
	public static function build_stock_match_query(array $attribute_id_sets = null, $product_ids = null, string $select = null)
	{
		if (!$attribute_id_sets && !$product_ids) {
			return false;
		}

		$conditions = [];

		if ($product_ids && $product_ids = Number::to_id_list($product_ids)) {
			$product_ids = implode(',', $product_ids);
			$conditions[] = "(c.type_id = 0 AND c.value_id IN ($product_ids))";
		}

		if ($attribute_id_sets) {
			$attribute_ids = implode(',', Number::to_id_list(array_keys($attribute_id_sets)));
			$term_ids = implode(',', Number::to_id_list(array_merge([0], ...$attribute_id_sets)));
			$conditions[] = "(c.type_id IN ($attribute_ids) AND c.value_id IN ($term_ids))";
		}

		if (!$conditions) {
			return false;
		}

		$conditions = implode("\nOR ", $conditions);

		$rules_table = DB::prefix(self::RULES_TABLE);
		$conds_table = DB::prefix(self::CONDITIONS_TABLE);
		$posts_table = DB::prefix('posts');
		$post_type = DB::value(AttributeStock::POST_TYPE);

		if (!$select) {
			$select = 'r.stock_id, r.id rule_id, r.multiplier';
		}

		$query = "
			SELECT {$select}
			FROM {$rules_table} r
			LEFT JOIN {$posts_table} p ON p.ID = r.stock_id
			LEFT JOIN {$conds_table} c ON c.rule_id = r.id
			WHERE p.post_type = {$post_type}
			  AND p.post_status = 'publish'
			  AND c.id IS NOT NULL
			GROUP BY r.id
			HAVING COUNT(DISTINCT IF(\n{$conditions}\n, c.type_id, NULL)) = COUNT(DISTINCT c.type_id)
			ORDER BY r.stock_id, r.priority
		";

		return [
			'query' => $query,
			'conditions' => $conditions,
		];
	}

	/**
	 * Checks if the configured attribute stock item filters match at least one of the provided products.
	 *
	 * @param AttributeStock $stock
	 * @param \WC_Product|\WC_Product[]|int|int[] $products
	 * @param bool $resolve_parent
	 * @param bool $bypass_multilang
	 *
	 * @return bool
	 */
	public static function validate_filters(AttributeStock $stock, $products, $resolve_parent = true, $bypass_multilang = true)
	{
		$bypass_multilang = $bypass_multilang && Multilang::active();

		if (!is_array($products)) {
			$products = [$products];
		}

		foreach ($products as $product) {
			if ($bypass_multilang) {
				$product = Products::bypass_multilang_product($product);
			}

			$valid = self::validate_filters_raw($stock, $product, $resolve_parent);
			$valid = apply_filters('mewz_wcas_validate_stock_filters', $valid, $stock, $product);

			if ($valid) return $valid;
		}

		return false;
	}

	/**
	 * @param AttributeStock $stock
	 * @param \WC_Product|int $product
	 * @param bool $resolve_parent
	 *
	 * @return bool
	 */
	public static function validate_filters_raw(AttributeStock $stock, $product, $resolve_parent = true)
	{
		$product_ids = self::get_validation_product_ids($product, $resolve_parent);
		if (!$product_ids) return false;

		$product_id = $product_ids[0];
		$filters = $stock->filters();

		// if product is specifically excluded, immediately fail
		if (self::ids_in_array($product_ids, $filters['excl_products'])) {
			return false;
		}

		// if product is specifically included, immediately pass
		if (self::ids_in_array($product_ids, $filters['products'])) {
			return true;
		}

		// if product type isn't valid, fail
		if ($valid_types = $filters['product_types']) {
			if ($product instanceof \WC_Product) {
				$product_type = $product->get_type();
			} else {
				$product_type = \WC_Product_Factory::get_product_type($product_id);
			}

			if (!in_array($product_type, $valid_types)) {
				return false;
			}
		}

		// check category filters
		$incl_cats = $filters['categories'];
		$excl_cats = $filters['excl_categories'];

		if ($incl_cats || $excl_cats) {
			$product_cats = Products::get_all_product_category_ids($product_id);

			// if none of the product's categories are descendants of the included categories, fail
			if ($incl_cats && (!$product_cats || !self::ids_in_array($product_cats, $incl_cats))) {
				return false;
			}

			// if any of the product's categories are descendants of the excluded categories, fail
			if ($excl_cats && $product_cats && self::ids_in_array($product_cats, $excl_cats)) {
				return false;
			}
		}

		// if product filters are specified but don't match, and no other filters are specified, fail
		if ($filters['products'] && !$valid_types && !$incl_cats && !$excl_cats) {
			return false;
		}

		return true;
	}

	public static function get_validation_product_ids($product, $resolve_parent = true)
	{
		static $check_bundles;

		if (!$product) return [];

		if ($product instanceof \WC_Product) {
			if ($resolve_parent && $parent_id = $product->get_parent_id()) {
				$product_id = $parent_id;
			} else {
				$product_id = $product->get_id();
			}
		} else {
			if (!is_numeric($product)) {
				return [];
			}

			$product_id = (int)$product;

			if ($resolve_parent && $parent_id = wp_get_post_parent_id($product_id)) {
				$product_id = $parent_id;
			}
		}

		$product_ids = [$product_id];

		if ($check_bundles || ($check_bundles === null && $check_bundles = function_exists('wc_pb_get_bundled_product_map'))) {
			$bundle_ids = wc_pb_get_bundled_product_map($product_id);

			if ($bundle_ids) {
				$product_ids = array_merge($product_ids, array_keys(array_flip($bundle_ids)));
			}
		}

		return $product_ids;
	}

	protected static function ids_in_array($ids, $array) {
		foreach ($ids as $id) {
			if (in_array($id, $array)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param int $stock_id
	 * @param string $context 'view' or 'edit'
	 *
	 * @return array [rule_id => [multiplier, conditions[type_id] => [value_ids]]]
	 */
	public static function get_rules($stock_id, $context = 'view')
	{
		if (!$stock_id) return [];
		$stock_id = (int)$stock_id;

		if ($context === 'view') {
			$cache_key = "match_rules_{$stock_id}_" . WooCommerce::get_cache_incr('woocommerce-attributes');
			$value = Mewz_WCAS()->cache->get($cache_key, 'match_rules');

			if (is_array($value)) {
				return $value;
			}
		}

		$results = self::query()
			->select('r.id rule_id, r.multiplier, c.type_id, c.value_id')
			->where('r.stock_id', $stock_id)
			->asc('r.priority')
			->get();

		$rules = [];

		if ($results) {
			$attributes = Attributes::get_attributes();

			foreach ($results as $row) {
				$rule_id = (int)$row->rule_id;

				if (!isset($rules[$rule_id])) {
					$multiplier = $row->multiplier;

					if ($context === 'view' && $multiplier !== '') {
						$multiplier = (float)$multiplier;
					}

					$rules[$rule_id] = [
						'multiplier' => $multiplier,
						'conditions' => [],
					];
				}

				$type_id = (int)$row->type_id;

				if ($type_id === 0) {
					$rules[$rule_id]['conditions'][$type_id][] = (int)$row->value_id;
				} elseif (isset($attributes[$type_id])) {
					if ($row->value_id) {
						$rules[$rule_id]['conditions'][$type_id][] = (int)$row->value_id;
					} elseif (!isset($rules[$rule_id]['conditions'][$type_id])) {
						$rules[$rule_id]['conditions'][$type_id] = [];
					}
				}
			}

			$sort_callback = fn($a, $b) => isset($attributes[$a], $attributes[$b])
				? strnatcasecmp($attributes[$a]->label, $attributes[$b]->label)
				: $a <=> $b;

			foreach ($rules as $rule_id => &$rule) {
				$cond_count = count($rule['conditions']);

				if ($cond_count === 0) {
					unset($rules[$rule_id]);
				} elseif ($cond_count > 1) {
					uksort($rule['conditions'], $sort_callback);
				}
			}
		}

		if ($context === 'view') {
			Mewz_WCAS()->cache->set($cache_key, $rules, 'match_rules');
			$rules = apply_filters('mewz_wcas_get_match_rules', $rules, $stock_id);
		}

		return $rules;
	}

	/**
	 * @param int $stock_id
	 * @param array|false $rules [[multiplier, attributes[attr_id] => [term_ids]]]
	 */
	public static function save_rules($stock_id, $rules)
	{
		$stock_id = (int)$stock_id;

		do_action('mewz_wcas_match_rules_before_save', $stock_id, $rules);

		if ($rules && is_array($rules)) {
			foreach ($rules as $i => $rule) {
				if (isset($rule['conditions'][0]) && (!$rule['conditions'][0] || $rule['conditions'][0] === [0])) {
					unset($rules[$i]['conditions'][0]);
				}

				if (empty($rule['conditions'])) {
					unset($rules[$i]);
				}
			}
		}

		if (!$rules) {
			DB::table(self::RULES_TABLE)
				->where('stock_id', $stock_id)
				->delete();

			do_action('mewz_wcas_match_rules_saved', $stock_id, false);
			return;
		}

		if (!is_array($rules)) {
			return;
		}

		$results = self::query(true)
			->select('r.id rule_id, r.multiplier, r.priority, c.id condition_id, c.type_id, c.value_id')
			->where('r.stock_id', $stock_id)
			->asc('r.priority')
			->get();

		$existing_rules = [];

		foreach ($results as $row) {
			$rule_id = (int)$row->rule_id;

			if (!isset($existing_rules[$rule_id])) {
				$existing_rules[$rule_id] = [
					'rule_id' => $rule_id,
					'multiplier' => $row->multiplier,
					'priority' => (int)$row->priority,
					'conditions' => [],
				];
			}

			if ($row->condition_id) {
				$existing_rules[$rule_id]['conditions'][$row->type_id][$row->value_id] = (int)$row->condition_id;
			}
		}

		$rules = array_values($rules);
		$existing_rules = array_values($existing_rules);
		$insert_conds = [];
		$delete_conds = [];

		foreach ($rules as $i => $rule) {
			if (isset($rule['multiplier']) && $rule['multiplier'] !== '') {
				$multiplier = $rule['multiplier'];
				$multiplier = $multiplier < 0 ? '-1' : Number::safe_decimal($multiplier);
			} else {
				$multiplier = '';
			}

			$priority = $i + 1;

			if (isset($existing_rules[$i])) {
				$existing =& $existing_rules[$i];
				$rule_id = $existing['rule_id'];
				$update = [];

				if ($existing['multiplier'] !== $multiplier) {
					$update['multiplier'] = $multiplier;
				}

				if ($existing['priority'] !== $priority) {
					$update['priority'] = $priority;
				}

				if ($update) {
					DB::table(self::RULES_TABLE)
						->where('id', $rule_id)
						->update($update);
				}

				foreach ($rule['conditions'] as $type_id => $value_ids) {
					foreach ($value_ids ?: [0] as $value_id) {
						$value_id = (int)$value_id;

						if (isset($existing['conditions'][$type_id][$value_id])) {
							unset($existing['conditions'][$type_id][$value_id]);
						} else {
							$insert_conds[] = [
								'rule_id' => $rule_id,
								'type_id' => $type_id,
								'value_id' => $value_id,
							];
						}
					}
				}

				foreach ($existing['conditions'] as $conds) {
					array_push($delete_conds, ...$conds);
				}

				unset($existing_rules[$i]);
			} else {
				$rule_id = DB::insert(self::RULES_TABLE, [
					'stock_id' => $stock_id,
					'multiplier' => $multiplier,
					'priority' => $priority,
				]);

				foreach ($rule['conditions'] as $type_id => $value_ids) {
					foreach ($value_ids ?: [0] as $value_id) {
						$insert_conds[] = [
							'rule_id' => $rule_id,
							'type_id' => $type_id,
							'value_id' => (int)$value_id,
						];
					}
				}
			}
		}

		if ($existing_rules) {
			DB::table(self::RULES_TABLE)
				->where('id', array_column($existing_rules, 'rule_id'))
				->delete();
		}

		if ($delete_conds) {
			DB::table(self::CONDITIONS_TABLE)
				->where('id', array_keys(array_flip($delete_conds)))
				->delete();
		}

		if ($insert_conds) {
			DB::insert(self::CONDITIONS_TABLE, $insert_conds);
		}

		do_action('mewz_wcas_match_rules_saved', $stock_id, $rules);
	}

	/**
	 * @param int $stock_id
	 * @param int $type_id
	 * @param int $value_id
	 * @param string|float $multiplier
	 *
	 * @return array|false [rule_id, rule_attr_id]
	 */
	public static function add_single_rule($stock_id, $type_id, $value_id, $multiplier = '')
	{
		$stock_id = (int)$stock_id;
		$type_id = (int)$type_id;
		$value_id = (int)$value_id;

		if ($type_id < 0 || $value_id < 0 || $type_id === 0 && $value_id === 0) {
			return false;
		}

		if ($type_id > 0) {
			$type_id = Attributes::get_attribute_id($type_id);
			if (!$type_id) return false;
		}

		$max_priority = DB::table(self::RULES_TABLE)
			->where('stock_id', $stock_id)
			->var('MAX(priority) priority');

		$priority = (int)$max_priority + 1;

		if ($multiplier !== '') {
			$multiplier = Number::safe_decimal($multiplier);
		}

		$rule_id = (int)DB::insert(self::RULES_TABLE, [
			'stock_id' => $stock_id,
			'multiplier' => $multiplier,
			'priority' => $priority,
		]);

		if (!$rule_id) {
			return false;
		}

		$condition_id = DB::insert(self::CONDITIONS_TABLE, [
			'rule_id' => $rule_id,
			'type_id' => $type_id,
			'value_id' => $value_id,
		]);

		Mewz_WCAS()->cache->invalidate('match_rules');

		return compact('rule_id', 'condition_id');
	}

	/**
	 * Handles the removal of an attribute/term from all stock items.
	 * Related match rules will be removed, and empty stock items will be trashed.
	 *
	 * @param int|string $type_id Attribute ID/slug or 0 for products condition
	 * @param int $value_id Term ID or product ID or 0 for "any"
	 *
	 * @return int[]|false
	 */
	public static function remove_condition($type_id, $value_id = null)
	{
		if (is_string($type_id) || $type_id > 0) {
			$type_id = Attributes::get_attribute_id($type_id);
		} else {
			$type_id = (int)$type_id;
		}

		// get affected stock ids
		$query = self::query()->where('c.type_id', $type_id);

		if ($value_id !== null) {
			$query->where('c.value_id', (int)$value_id);
		}

		$stock_ids = $query->col('r.stock_id');
		if (!$stock_ids) return false;

		$rule_ids = $query->col('r.id');

		// delete condition values
		$delete_values = DB::table(self::CONDITIONS_TABLE)->where('type_id', $type_id);

		if ($value_id !== null) {
			$delete_values->where('value_id', (int)$value_id);
		}

		$deleted = $delete_values->delete();

		if ($deleted) {
			// delete rules where any conditions have been removed entirely (no more values)
			DB::table(self::RULES_TABLE, 'r')
				->left_join(self::CONDITIONS_TABLE, 'c')->on('r.id = c.rule_id AND c.type_id = ' . $type_id)
				->where('r.id', $rule_ids)
				->is_null('c.id')
				->delete();

			do_action('mewz_wcas_clean_match_rules');
		}

		// trash the stock items with no remaining match rules
		$trash_stock_ids = DB::table('posts', 'p')
			->left_join(self::RULES_TABLE, 'r')->on('r.stock_id = p.ID')
			->where('p.ID', $stock_ids)
			->where_not('post_status', 'trash')
			->is_null('r.id')
			->col('p.ID');

		if ($trash_stock_ids) {
			foreach ($trash_stock_ids as $stock_id) {
				AttributeStock::instance($stock_id, 'object')->trash();
			}

			Mewz_WCAS()->cache->invalidate('stock');
		}

		if ($type_id > 0 && !$value_id) {
			AttributeStock::delete_all_meta('attribute_level', $type_id);
			Mewz_WCAS()->cache->invalidate('attribute_level');
		}

		return $stock_ids;
	}

	public static function get_all_stock_attributes($post_status = null)
	{
		if (!$post_status || $post_status === 'all') {
			$post_status = ['publish', 'draft'];
		}

		$rule_attributes = self::query()
			->select('c.type_id, c.value_id')
			->left_join('posts', 'p')->on('p.ID = r.stock_id')
			->where('c.type_id > 0')
			->where('p.post_status', $post_status)
			->distinct()
			->get();

		$attr_level_ids = DB::table('postmeta', 'pm')
			->left_join('posts', 'p')->on('p.ID = pm.post_id')
			->where('p.post_type', AttributeStock::POST_TYPE)
			->where('p.post_status', $post_status)
			->where('pm.meta_key', 'attribute_level')
			->distinct()
			->col('pm.meta_value');

		$attributes = [];

		if ($rule_attributes) {
			foreach ($rule_attributes as $row) {
				$attributes[$row->type_id][$row->value_id] = (int)$row->value_id;
			}
		}

		if ($attr_level_ids) {
			foreach ($attr_level_ids as $attr_id) {
				$attributes[$attr_id] ??= [];
			}
		}

		return $attributes;
	}

	/**
	 * Query stock for a specified attribute and optional term. Primarily for admin usage.
	 *
	 * @param int|string $attribute
	 * @param int $term_id
	 * @param string $context 'view' or 'edit'
	 * @param string $return 'object' or 'id'
	 *
	 * @return AttributeStock[]|int[]
	 */
	public static function query_stock($attribute, $term_id = null, $context = 'view', $return = 'object')
	{
		$attribute_id = (int)Attributes::get_attribute_id($attribute);

		if ($attribute_id <= 0) {
			return [];
		}

		$cache_key = "query_stock_{$attribute_id}_{$term_id}_{$context}";
		$cache_tags = ['stock', 'match_rules', 'attribute_level'];

		$stock_ids = Mewz_WCAS()->cache->get($cache_key, $cache_tags);

		if (!is_array($stock_ids)) {
			$query = DB::table('posts', 'p')
				->asc('p.post_title')
				->distinct();

			if ($term_id === null) {
				$query->left_join('postmeta', 'pm_al')
					->on("pm_al.post_id = p.ID AND pm_al.meta_key = 'attribute_level'")
					->where('pm_al.meta_value', $attribute_id);
			} else {
				$query->left_join(self::RULES_TABLE, 'r')->on('r.stock_id = p.ID')
					->left_join(self::CONDITIONS_TABLE, 'c')->on('c.rule_id = r.id')
					->where('c.type_id', $attribute_id)
					->where('c.value_id', (int)$term_id);

				if ($context === 'edit') {
					$query->left_join('postmeta', 'pm_al')
						->on("pm_al.post_id = p.ID AND pm_al.meta_key = 'attribute_level' AND pm_al.meta_value = $attribute_id")
						->is_null('pm_al.meta_value');
				}
			}

			if ($context === 'view') {
				$query->where('p.post_status', 'publish');
			} else {
				$query->where_not('p.post_status', ['trash', 'auto-draft']);
			}

			$stock_ids = $query->col('p.ID');

			// in view context, only return attribute term level stocks if any exist
			if ($term_id !== null && $context === 'view' && $stock_ids) {
				$attr_level_ids = DB::table('postmeta')
					->where('post_id', $stock_ids)
					->where('meta_key', 'attribute_level')
					->where('meta_value', $attribute_id)
					->col('post_id');

				if (count($attr_level_ids) !== count($stock_ids)) {
					$stock_ids = array_values(array_diff($stock_ids, $attr_level_ids));
				}
			}

			Mewz_WCAS()->cache->set($cache_key, $stock_ids, $cache_tags);
		}

		if (!$stock_ids || !is_array($stock_ids)) {
			return [];
		}

		if ($return === 'object') {
			$stocks = [];

			foreach ($stock_ids as $stock_id) {
				$stocks[] = AttributeStock::instance($stock_id, $context);
			}

			return $stocks;
		} else {
			return $stock_ids;
		}
	}

	/**
	 * Queries a list of all products matching an attribute stock item.
	 *
	 * Important: This can be a fairly intensive operation. It should be used sparingly only
	 * when necessary, with appropriate use of the `$exclude` parameter.
	 *
	 * @param AttributeStock|int|array<AttributeStock|int> $stock Attribute stock item objects / IDs
	 * @param bool $query_variations Expand found variable products to matching variations
	 * @param int[] $exclude Product IDs to exclude
	 *
	 * @return array List of matching product IDs
	 */
	public static function query_matching_products($stock, $query_variations = false, $exclude = [])
	{
		$stock_ids = [];
		$product_ids = [];

		if (is_array($stock)) {
			if (count($stock) >= 3) {
				foreach ($stock as $stock_item) {
					$stock_ids[] = $stock_item instanceof AttributeStock ? $stock_item->id() : (int)$stock_item;
				}

				_prime_post_caches($stock_ids);
				$stock_ids = [];
			}

			foreach ($stock as $stock_item) {
				$matched_ids = self::query_matching_products($stock_item, false, $exclude);

				if ($matched_ids) {
					$product_ids[] = $matched_ids;
					$exclude = array_merge($exclude, $matched_ids);

					if ($query_variations) {
						$stock_ids[] = $stock_item instanceof AttributeStock ? $stock_item->id() : (int)$stock_item;
					}
				}
			}

			if ($product_ids) {
				$product_ids = array_merge(...$product_ids);
			}
		} else {
			if (!$stock instanceof AttributeStock) {
				$stock = AttributeStock::instance($stock);
			}

			$match_rules = $stock->match_rules();
			if (!$match_rules) return [];

			$rule_product_ids = [];

			foreach ($match_rules as $i => $rule) {
				if (!empty($rule['conditions'][0])) {
					$rule_product_ids[] = $rule['conditions'][0];
					unset($match_rules[$i]);
				}
			}

			if ($rule_product_ids) {
				$rule_product_ids = array_merge(...$rule_product_ids);
			}

			$tax_query = self::get_rules_tax_query($match_rules);

			if (!$tax_query && !$rule_product_ids) {
				return [];
			}

			if ($query_variations) {
				$stock_ids[] = $stock->id();
			}

			$args = [
				'post_status' => ['publish', 'private', 'pending'],
				'tax_query' => [$tax_query],
				'return' => 'ids',
				'orderby' => ['ID' => 'ASC'],
				'limit' => -1,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'no_found_rows' => true,
				'suppress_filters' => true, // TODO: Safe to do this?
			];

			$filters = $stock->filters();

			$include = $filters['products'];
			if ($include) $args['include'] = $include;

			$exclude = array_keys(array_flip(array_merge($filters['excl_products'], $exclude)));
			if ($exclude) $args['exclude'] = $exclude;

			$type = $filters['product_types'];
			if ($type) $args['type'] = $type;

			$found_ids = wc_get_products($args);
			$include = array_flip($include);

			foreach ($found_ids as $found_id) {
				$found_id = (int)$found_id;

				if (!Products::is_product_excluded($found_id, false) && (isset($include[$found_id]) || self::validate_filters($stock, $found_id, false))) {
					$product_ids[] = $found_id;
				}
			}

			if ($rule_product_ids) {
				$product_ids = array_merge($product_ids, $rule_product_ids);
			}

			$product_ids = array_keys(array_flip($product_ids));
		}

		if ($query_variations && $stock_ids && $product_ids) {
			$results = self::query_matching_variations($stock_ids, $product_ids);

			if ($results) {
				$product_ids = array_merge(array_diff($product_ids, $results['parent_ids']), $results['variation_ids']);
			}
		}

		return $product_ids;
	}

	/**
	 * @param int|int[] $stock_ids
	 * @param int|int[] $parent_ids
	 *
	 * @return array|false
	 */
	public static function query_matching_variations($stock_ids, $parent_ids)
	{
		$parent_list = [];
		$pv_values = [];

		foreach ((array)$parent_ids as $parent_id) {
			$parent_id = (int)$parent_id;

			$attributes = get_post_meta($parent_id, '_product_attributes', true);
			if (!$attributes) continue;

			$parent_list[] = $parent_id;

			foreach ($attributes as $taxonomy => $attr) {
				if (!empty($attr['is_taxonomy']) && !empty($attr['is_variation'])) {
					$pv_values[] = $parent_id;
					$pv_values[] = DB::esc($taxonomy);
				}
			}
		}

		// no parent products have any variation attributes, so no variations to find
		if (!$pv_values) return false;

		$db = DB::$wpdb;
		$joins = [];
		$match_cond = [];

		foreach ((array)$stock_ids as $stock_id) {
			$match_rules = self::get_rules($stock_id);
			if (!$match_rules) continue;

			foreach ($match_rules as $rule) {
				$rule_matches = [];

				foreach ($rule['conditions'] as $type_id => $term_ids) {
					if ($type_id === 0) {
						$rule_product_id_list = implode(',', $term_ids);
						$rule_matches[] = "p.post_parent IN ({$rule_product_id_list})";
						continue;
					}

					$taxonomy = Attributes::get_attribute_name($type_id, true);
					$tax_value = DB::value($taxonomy);

					$pv_alias = 'pv_' . $type_id;
					$attr_alias = 'attr_' . $type_id;

					if (!isset($joins[$type_id])) {
						$joins[$pv_alias] = "LEFT JOIN __mewz_wcas_pv {$pv_alias} ON ({$pv_alias}.parent_id = p.post_parent AND {$pv_alias}.taxonomy = {$tax_value})";
					}

					if ($term_ids) {
						$term_slugs = [''];

						foreach ($term_ids as $term_id) {
							$term_slugs[] = Attributes::get_term_prop($term_id, $taxonomy, 'slug');
						}

						$term_slugs = DB::value($term_slugs);
						$term_ids = DB::value($term_ids);

						$attr_meta_key = DB::value("attribute_{$taxonomy}");
						$joins[$attr_alias] = "LEFT JOIN {$db->postmeta} {$attr_alias} ON ({$attr_alias}.post_id = p.ID AND {$attr_alias}.meta_key = {$attr_meta_key})";

						$rule_matches[] = "IF(
					        {$pv_alias}.parent_id IS NOT NULL,
					        {$attr_alias}.meta_value IS NULL OR {$attr_alias}.meta_value IN {$term_slugs},
					        EXISTS(
								SELECT tr.term_taxonomy_id FROM {$db->term_relationships} tr
								LEFT JOIN {$db->term_taxonomy} tt ON (tt.term_taxonomy_id = tr.term_taxonomy_id)
								WHERE tr.object_id = p.post_parent AND tt.taxonomy = {$tax_value} AND tt.term_id IN {$term_ids}
							)
					    )";
					} else {
						$rule_matches[] = "EXISTS(
							SELECT tr.term_taxonomy_id FROM {$db->term_relationships} tr
							LEFT JOIN {$db->term_taxonomy} tt ON (tt.term_taxonomy_id = tr.term_taxonomy_id)
							WHERE tr.object_id = p.post_parent AND tt.taxonomy = {$tax_value}
						)";
					}
				}

				$match_cond[] = implode(' AND ', $rule_matches);
			}
		}

		if (!$joins || !$match_cond) {
			return false;
		}

		$joins = implode("\n", $joins);
		$parent_list = implode(',', $parent_list);
		$match_cond = '(' . implode(")\nOR (", $match_cond) . ')';
		$pv_insert = sprintf(rtrim(str_repeat("(%d,'%s'),", count($pv_values) / 2), ','), ...$pv_values);

		unset($pv_values); // allow memory to be freed

		$pv_created = $db->query("
			CREATE TEMPORARY TABLE __mewz_wcas_pv (
			    parent_id INT UNSIGNED NOT NULL,
			    taxonomy VARCHAR(32),
			    UNIQUE parent_taxonomy (parent_id, taxonomy)
			)
		");

		if ($pv_created === false) {
			mewz_wcas_log('Failed to create temporary table in Matches::query_matching_variations(): ' . $db->last_error, \WC_Log_Levels::ERROR);
			return false;
		}

		$db->query("INSERT INTO __mewz_wcas_pv (parent_id, taxonomy) VALUES {$pv_insert}");

		$results = $db->get_results("
			SELECT DISTINCT p.ID AS variation_id, p.post_parent AS parent_id
			FROM {$db->posts} as p
			{$joins}
			WHERE p.post_type = 'product_variation'
			  AND p.post_status IN ('publish', 'private')
			  AND p.post_parent IN ({$parent_list})
			  AND (
			    {$match_cond}
			  )
			ORDER BY p.ID
		");

		$db->query("DROP TEMPORARY TABLE __mewz_wcas_pv");

		if (!$results) return false;

		$variation_ids = [];
		$parent_ids = [];

		foreach ($results as $row) {
			if (!Products::is_product_excluded($row->variation_id, false)) {
				$variation_ids[] = (int)$row->variation_id;

				if (!isset($parent_ids[$row->parent_id])) {
					$parent_ids[$row->parent_id] = true;
				}
			}
		}

		if (!$parent_ids) return false;

		$parent_ids = array_keys($parent_ids);

		return compact('variation_ids', 'parent_ids');
	}

	/**
	 * @param AttributeStock|int $stock
	 * @param int|string $attribute
	 * @param int $term_id
	 *
	 * @return float
	 */
	public static function get_term_available_quantity($stock, $attribute, $term_id)
	{
		if (!$stock instanceof AttributeStock) {
			$stock = AttributeStock::instance($stock);
		}

		$stock_qty = $stock->quantity();
		$attribute_id = Attributes::get_attribute_id($attribute);

		$multiplier = self::query()
			->where('r.stock_id', $stock->id())
			->where('c.type_id', $attribute_id)
			->where('c.value_id', $term_id)
			->asc('r.priority')
			->var('r.multiplier');

		if ($multiplier === null) {
			$multiplier = get_term_meta($term_id, 'mewz_wcas_multiplier', true);
		}

		$multiplier = (string)$multiplier === '' ? 1 : (float)$multiplier;

		return self::calc_limit_qty($stock_qty, $multiplier);
	}

	/**
	 * @param AttributeStock|int $stock
	 * @param int|string $attribute
	 * @param int $term_id
	 *
	 * @return string
	 */
	public static function get_term_display_quantity($stock, $attribute, $term_id)
	{
		$available = self::get_term_available_quantity($stock, $attribute, $term_id);
		$quantity = $stock->quantity();

		$display = Number::local_format($available);

		if ($available != $quantity) {
			$display .= ' (' . Number::local_format($quantity) . ')';
		}

		return $display;
	}

	/**
	 * @param int[] $stock_ids
	 * @param int|string $attribute_id
	 * @param int $term_id
	 *
	 * @return string
	 */
	public static function get_attribute_display_range($stock_ids, $attribute_id, $term_id = null)
	{
		$quantities = [];

		foreach ($stock_ids as $stock_id) {
			$stock = AttributeStock::instance($stock_id);

			// show available stock for terms of attribute-level stock when displaying a quantity range
			if ($term_id && in_array($attribute_id, $stock->meta('attribute_level', false))) {
				$quantities[] = self::get_term_available_quantity($stock_id, $attribute_id, $term_id);
			} else {
				$quantities[] = $stock->quantity();
			}
		}

		$min_qty = min($quantities);
		$max_qty = max($quantities);

		if ($min_qty == $max_qty) {
			return Number::local_format($min_qty);
		} else {
			return Number::local_format($min_qty) . ' ... ' . Number::local_format($max_qty);
		}
	}

	/**
	 * Generates a tax_query that matches attribute match rules to product attribute terms.
	 *
	 * @param array $match_rules
	 *
	 * @return array
	 */
	public static function get_rules_tax_query($match_rules)
	{
		$tax_query_list = [];

		foreach ($match_rules as $rule) {
			$tax_queries = [];

			// build tax query
			foreach ($rule['conditions'] as $attr_id => $term_ids) {
				if ($attr_id <= 0) continue;

				$taxonomy = Attributes::get_attribute_name($attr_id, true);
				if (!$taxonomy) continue;

				$tax_query = ['taxonomy' => $taxonomy];

				if ($term_ids) {
					$tax_query['terms'] = $term_ids;
					$tax_query['operator'] = 'AND';
				} else {
					$tax_query['operator'] = 'EXISTS';
				}

				$tax_queries[] = $tax_query;
			}

			if (!$tax_queries) continue;

			if (!isset($tax_queries[1])) {
				$tax_queries = $tax_queries[0];
			}

			$tax_query_list[] = $tax_queries;
		}

		if ($tax_query_list) {
			if (!isset($tax_query_list[1])) {
				$tax_query_list = $tax_query_list[0];
			} else {
				$tax_query_list = ['relation' => 'OR'] + $tax_query_list;
			}
		}

		return $tax_query_list;
	}

	/**
	 * Gets all data necessary for matching attribute stock, multipliers, etc. for "any" variations
	 * on the frontend in JS.
	 *
	 * @param \WC_Product_Variation[] $variations
	 * @param array<string, mixed> $attributes
	 * @param \WC_Product $parent
	 *
	 * @return array|false
	 */
	public static function get_any_match_data($variations, $attributes, $parent)
	{
		if (!$attributes) return false;

		$attribute_id_sets = Attributes::get_attribute_id_sets($attributes);
		if (!$attribute_id_sets) return false;

		$match_data = [];

		if ($term_multipliers = Attributes::get_term_multipliers()) {
			$multipliers = [];
			$using_attributes = [];

			foreach ($attribute_id_sets as $attr_id => $term_ids) {
				$taxonomy = Attributes::get_attribute_name($attr_id, true);
				if (!$taxonomy) continue;

				$terms = [];

				foreach ($term_ids as $term_id) {
					if (!isset($term_multipliers[$taxonomy][$term_id])) {
						continue;
					}

					$term_slug = Attributes::get_term_prop($term_id, $taxonomy, 'slug');
					$terms[$term_slug] = $term_multipliers[$taxonomy][$term_id];

					if (!isset($using_attributes[$taxonomy][$term_slug])) {
						$using_attributes[$taxonomy][$term_slug] = $term_slug;
					}
				}

				if ($terms) {
					$multipliers[] = [$taxonomy, $terms];
				}
			}

			if ($multipliers) {
				$match_data['attributes'] = $using_attributes;
				$match_data['term_multipliers'] = $multipliers;
			}
		}

		if (!is_array($variations)) {
			$variations = [$variations];
		}

		if (Products::is_product_excluded($parent)) {
			return $match_data;
		}

		$valid_variations = [];

		foreach ($variations as $variation) {
			if (!Products::is_product_excluded($variation, false)) {
				$valid_variations[] = $variation;
			}
		}

		if ($valid_variations) {
			$variations = $valid_variations;
		} else {
			return $match_data;
		}

		$match_query = self::build_stock_match_query($attribute_id_sets, $parent->get_id(), 'r.id');

		if (!$match_query) {
			return $match_data;
		}

		$rules_table = DB::prefix(self::RULES_TABLE);
		$conds_table = DB::prefix(self::CONDITIONS_TABLE);

		$rule_data = DB::$wpdb->get_results("
			SELECT DISTINCT r.stock_id, c.rule_id, r.multiplier, c.type_id, c.value_id
			FROM {$rules_table} AS r
			INNER JOIN {$conds_table} AS c ON c.rule_id = r.id
			WHERE 
			    ({$match_query['conditions']})
				AND r.id IN ({$match_query['query']})
			ORDER BY r.stock_id, r.priority
		");

		if (!$rule_data) {
			return $match_data;
		}

		$matches = [];
		$max_quantity = 0;

		$inherit_backorders = apply_filters('mewz_wcas_inherit_product_backorders', true, $parent);
		$inherit_sku = apply_filters('mewz_wcas_inherit_product_sku', true, $parent);
		$inherit_image = apply_filters('mewz_wcas_inherit_product_image', true, $parent);

		if (count($rule_data) >= 3) {
			$stock_ids = array_keys(array_column($rule_data, null, 'stock_id'));
			_prime_post_caches($stock_ids, false);
		}

		foreach ($rule_data as $row) {
			$stock_id = (int)$row->stock_id;

			if (isset($valid_stock[$stock_id]) && $valid_stock[$stock_id] === false) {
				continue;
			}

			$stock = AttributeStock::instance($stock_id);

			if (!isset($valid_stock[$stock_id])) {
				if ($stock->valid() && !$stock->internal() && self::validate_filters($stock, $parent, false)) {
					$valid_stock[$stock_id] = true;
				} else {
					$valid_stock[$stock_id] = false;
					continue;
				}
			}

			$rule_id = (int)$row->rule_id;
			$type_id = (int)$row->type_id;

			$is_attr_row = $type_id > 0;

			if ($is_attr_row) {
				$taxonomy = Attributes::get_attribute_name($type_id, true);

				if ($term_id = (int)$row->value_id) {
					// we need the translated slug, and polylang doesn't auto translate get_term()
					if (Multilang::plugin() === 'polylang') {
						$term_id = Multilang::get_translated_object_id($term_id, 'term', $taxonomy);
					}

					$term_slug = Attributes::get_term_prop($term_id, $taxonomy, 'slug');
					if ($term_slug === false) continue;
				} else {
					$term_slug = '';
				}
			}

			if (!isset($matches[$stock_id])) {
				$match = [
					'i' => $stock_id,
					'q' => $stock->quantity(),
				];

				if ($stock->multiplex()) {
					$match['m'] = true;
				}

				if ($stock->lock_multipliers()) {
					$match['l'] = true;
				}

				if ($inherit_backorders && $backorders = $stock->backorders()) {
					$match['b'] = $backorders;
				}

				if ($inherit_sku && $stock->product_sku() && ($sku = $stock->sku()) !== '') {
					$match['s'] = $sku;
				}

				if ($inherit_image && $stock->product_image() && $image_id = $stock->image_id()) {
					$match['g'] = $image_id;
				}

				$matches[$stock_id] = $match;
			}

			if (!isset($matches[$stock_id]['r'][$rule_id])) {
				if ($row->multiplier !== '') {
					$row->multiplier = (float)$row->multiplier;
					$matches[$stock_id]['r'][$rule_id]['x'] = $row->multiplier;
				}

				$available = self::calc_limit_qty($stock->quantity(), $row->multiplier);

				if ($available > $max_quantity) {
					$max_quantity = $available;
				}
			}

			if ($is_attr_row) {
				$matches[$stock_id]['r'][$rule_id]['a'][$taxonomy][] = $term_slug;

				if (!isset($match_data['attributes'][$taxonomy][$term_slug])) {
					$match_data['attributes'][$taxonomy][$term_slug] = $term_slug;
				}
			} else {
				if (empty($matches[$stock_id]['r'][$rule_id]['p'])) {
					$matches[$stock_id]['r'][$rule_id]['p'] = (int)$row->value_id === $parent->get_id();
				}
			}
		}

		$matches = apply_filters('mewz_wcas_any_match_data_matches', $matches, $match_data + compact('variations', 'parent', 'max_quantity'));

		if ($matches) {
			$using_components = Components::using_components();
			$stock_ids = [];
			$comp_stock = [];

			foreach ($matches as $stock_id => $match) {
				// remove id keys from lists as they're not needed
				$matches[$stock_id]['r'] = array_values($match['r']);

				if ($using_components) {
					$stock_ids[] = $stock_id;

					$comp_stock[$stock_id] = [
						'stock_id' => $stock_id,
						'stock_qty' => $match['q'],
						'multiplier' => 1,
					];
				}
			}

			// remove id keys from lists as they're not needed
			$matches = array_values($matches);

			// build full component tree for all match data
			if (
				$using_components
				&& Components::has_components($stock_ids)
				&& $sorted_tree = Components::get_sorted_tree($comp_stock, false)
			) {
				if ($sorted_tree instanceof \WP_Error) {
					$component_tree = $sorted_tree;
				} else {
					$component_tree = [];

					foreach ($sorted_tree as $stock_id => $item) {
						$component = [
							'i' => $stock_id,
							'q' => $item['stock_qty'],
						];

						if (!empty($item['comp']['children'])) {
							$children = [];

							foreach ($item['comp']['children'] as $child_id => $quantity) {
								$child = [$child_id];

								if ($quantity != 1) {
									$child[] = $quantity;
								}

								$children[] = $child;
							}

							$component['c'] = $children;
						}

						$component_tree[] = $component;
					}
				}
			}
		}

		$match_data['matches'] = $matches;
		$match_data['max_quantity'] = $max_quantity;

		if (!empty($match_data['attributes'])) {
			$match_data['attributes'] = Attributes::encode_keys($match_data['attributes']);
		}

		if (!empty($component_tree)) {
			$match_data['component_tree'] = $component_tree;
		}

		$match_data = apply_filters('mewz_wcas_any_match_data', $match_data, $variations, $attributes);

		return $match_data;
	}
}
