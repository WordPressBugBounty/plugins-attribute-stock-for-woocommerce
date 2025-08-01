<?php
namespace Mewz\WCAS\Core;

use Mewz\Framework\Core;
use Mewz\Framework\Util\PostType;
use Mewz\QueryBuilder\DB;
use Mewz\QueryBuilder\Query;
use Mewz\WCAS\Models\AttributeStock;
use Mewz\WCAS\Util\Matches;
use Mewz\WCAS\Util\Components;
use Mewz\WCAS\Util\Settings;

class Installer extends Core\Installer
{
	public static function tables()
	{
		return [
			Matches::RULES_TABLE,
			Matches::CONDITIONS_TABLE,
			Components::TABLE,
		];
	}

	public static function schema()
	{
		global $wpdb;

		$collate = $wpdb->get_charset_collate();

		return "
			CREATE TABLE " . $wpdb->prefix . Matches::RULES_TABLE . " (
			  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			  stock_id BIGINT UNSIGNED NOT NULL,
			  multiplier VARCHAR(100) NOT NULL,
			  priority INT(11) NOT NULL,
			  PRIMARY KEY  (id),
			  KEY stock_id (stock_id),
			  KEY priority (priority),
			  KEY stock_priority (stock_id, priority)
			) {$collate};

			CREATE TABLE " . $wpdb->prefix . Matches::CONDITIONS_TABLE . " (
			  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			  rule_id BIGINT UNSIGNED NOT NULL,
			  type_id BIGINT UNSIGNED NOT NULL,
			  value_id BIGINT UNSIGNED NOT NULL,
			  PRIMARY KEY  (id),
			  KEY rule_id (rule_id),
			  KEY type_id (type_id),
			  KEY value_id (value_id),
			  KEY type_value (type_id, value_id),
			  KEY lookup (rule_id, type_id, value_id)
			) {$collate};
			
			CREATE TABLE " . $wpdb->prefix . Components::TABLE . " (
			  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			  parent_id BIGINT UNSIGNED NOT NULL,
			  child_id BIGINT UNSIGNED NOT NULL,
			  quantity VARCHAR(100) NOT NULL,
			  PRIMARY KEY  (id),
			  KEY parent_id (parent_id),
			  KEY child_id (child_id)
			) {$collate};
		";
	}

	public static function register_tables()
	{
		DB::register(self::tables());
	}

	public function init_data($operation)
	{
		$this->add_capabilities();

		if (MEWZ_WCAS_LITE) return;

		if ($operation !== 'update') {
			$this->maybe_optimize_tables();
			$this->maybe_sync_outofstock_products();
		}
	}

	public function migrations()
	{
		$db_version = $this->plugin->db_version;

		$this->clean_before_migrations();

		if (version_compare($db_version, '1.6.0', '<')) {
			$this->migrate_skus_to_metadata();
		}

		if (version_compare($db_version, '1.9.0', '<')) {
			$this->maybe_sync_outofstock_products();
		}

		if (version_compare($db_version, '2.0.0', '<')) {
			$this->migrate_db_table_names();
			$this->migrate_post_type();
			$this->migrate_metadata_props_200();
			$this->migrate_settings();
			$this->migrate_zero_excludes();
		}

		if (version_compare($db_version, '2.1.1', '<')) {
			$this->maybe_optimize_tables();
		}

		if (version_compare($db_version, '2.2.0', '<')) {
			$this->migrate_conditions_table();
			$this->migrate_stock_filters();
		}
	}

	public function uninstall()
	{
		if (defined('MEWZ_DELETE_DATA') && MEWZ_DELETE_DATA) {
			self::delete_options();
			self::delete_attribute_stock();
			self::delete_post_meta();
			self::drop_tables();
		}
	}

	public static function delete_options()
	{
		DB::table('options')->like('option_name', '?%', 'mewz_wcas_')->delete();
	}

	public static function delete_attribute_stock()
	{
		$stock_ids = AttributeStock::query(['post_status' => null], 'edit', 'id');

		foreach ($stock_ids as $stock_id) {
			(new AttributeStock($stock_id, 'object'))->delete(true);
		}
	}

	public static function delete_post_meta()
	{
		DB::table('postmeta')->like('meta_key', '?%', '_mewz_wcas_')->delete();
	}

	public static function drop_tables()
	{
		$tables = static::tables();
		if (!$tables) return false;

		$query = new Query();

		foreach ($tables as $table) {
			$query->table($table);
		}

		return $query->drop(true, true);
	}

	public function add_capabilities()
	{
		PostType::add_capabilities(AttributeStock::POST_TYPE, ['shop_manager', 'administrator']);
	}

	public function maybe_optimize_tables()
	{
		global $wpdb;

		if (get_option($this->plugin->prefix . '_skip_optimize_tables')) {
			return;
		}

		$suppressed = $wpdb->suppress_errors();

		$has_meta_value = $wpdb->get_results("SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name = 'meta_value'");

		if ($has_meta_value) {
			$wpdb->query("ALTER TABLE {$wpdb->postmeta} DROP INDEX `meta_value`");
		}

		$has_key_value = $wpdb->get_results("SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name = 'key_value'");

		if (!$has_key_value) {
			$wpdb->query("ALTER TABLE {$wpdb->postmeta} ADD INDEX `key_value` (`meta_key`, `meta_value`(20))");
		}

		$wpdb->suppress_errors($suppressed);
	}

	public function maybe_sync_outofstock_products()
	{
		if (Settings::sync_product_visibility_bool()) {
			$this->plugin->tasks->add('update_all_outofstock');
		}
	}

	// MIGRATIONS

	public function clean_before_migrations()
	{
		// ensure no dangling, empty or invalid rules/conditions
		DB::table(Matches::CONDITIONS_TABLE, 'c')
			->left_join(Matches::RULES_TABLE, 'r')->on('r.id = c.rule_id')
			->where('r.id IS NULL OR (c.type_id = 0 AND c.value_id = 0)')
			->delete();

		DB::table(Matches::RULES_TABLE, 'r')
			->left_join(Matches::CONDITIONS_TABLE, 'c')->on('c.rule_id = r.id')
			->left_join('posts', 'p')->on('p.ID = r.stock_id')
			->where('c.id IS NULL OR p.ID IS NULL')
			->delete();

		// invalidate/delete any existing object cache
		$this->plugin->cache->invalidate(
			'stock',
			'match_rules',
			'components',
			'multipliers',
			'attribute_level'
		);

		$this->plugin->cache->delete('term_multipliers');
	}

	public function migrate_skus_to_metadata()
	{
		$query = DB::table('posts')->where('post_type', AttributeStock::POST_TYPE);
		$skus = $query->pairs('ID', 'post_excerpt');

		if (!$skus) return;

		foreach ($skus as $stock_id => $sku) {
			$sku = trim($sku);

			if ($sku !== '') {
				update_post_meta($stock_id, '_sku', $sku);
			}
		}

		$query->update(['post_excerpt' => '']);
	}

	public function migrate_db_table_names()
	{
		$pfx = DB::$wpdb->prefix;
		$old_tables = DB::$wpdb->get_col("SHOW TABLES LIKE '{$pfx}%wcas\\_match\\_%'");

		if (!$old_tables) return;

		$names = [
			$pfx . 'wc_mewz_wcas_match_sets' => $pfx . Matches::RULES_TABLE,
			$pfx . 'wc_mewz_wcas_match_rows'  => $pfx . 'wcas_rule_attributes',
			$pfx . 'wcas_match_sets' => $pfx . Matches::RULES_TABLE,
			$pfx . 'wcas_match_rows' => $pfx . 'wcas_rule_attributes',
		];

		foreach ($old_tables as $old_table) {
			if (!isset($names[$old_table])) {
				continue;
			}

			$new_table = $names[$old_table];

			if (!DB::table($new_table, true)->exists()) {
				DB::query("INSERT INTO {$new_table} SELECT * FROM {$old_table}");
				DB::table($old_table, true)->drop();
			}
		}
	}

	public function migrate_post_type()
	{
		DB::table('posts')
			->where('post_type', 'mewz_attribute_stock')
			->update(['post_type' => AttributeStock::POST_TYPE]);
	}

	public function migrate_metadata_props_200()
	{
		DB::table('postmeta', 'pm')
			->left_join('posts', 'p')->on('p.ID = pm.post_id')
			->where('p.post_type', AttributeStock::POST_TYPE)
			->where('pm.meta_key', '_limit_products')
			->update([
				'pm.meta_key' => '_internal',
				'pm.meta_value = 1 - pm.meta_value',
			]);

		DB::table('postmeta', 'pm')
			->left_join('posts', 'p')->on('p.ID = pm.post_id')
		    ->where('p.post_type', AttributeStock::POST_TYPE)
		    ->where('pm.meta_key', '_match_all')
		    ->update(['pm.meta_key' => '_multiplex']);
	}

	public function migrate_settings()
	{
		DB::table('options')
		    ->where('option_name', 'mewz_wcas_limit_product_stock')
		    ->update(['option_name' => 'mewz_wcas_modify_product_stock']);

		delete_option('mewz_wcas_product_multipliers');
	}

	public function migrate_zero_excludes()
	{
		if (!apply_filters('mewz_wcas_zero_multiplier_excludes', false)) {
			return;
	    }

		// update zero multipliers to -1 if zero multiplier excludes is enabled
		DB::table(Matches::RULES_TABLE)
			->where('multiplier', 0) // matches '0' and '0.00', but also ''
			->where_not('multiplier', '') // we only want to update explicit zeros
			->update(['multiplier' => '-1']);
	}

	public function migrate_conditions_table()
	{
		$attr_table = DB::prefix('wcas_rule_attributes');
		$cond_table = DB::prefix('wcas_rule_conditions');

		if (DB::table($attr_table, true)->exists()) {
			DB::query("
				INSERT INTO {$cond_table} (id, rule_id, type_id, value_id)
				SELECT id, rule_id, attribute_id, term_id
				FROM {$attr_table}
			");
		}

		DB::table($attr_table, true)->drop();
	}

	public function migrate_stock_filters()
	{
		$keymap = [
			'_products' => 'products',
			'_exclude_products' => 'excl_products',
			'_categories' => 'categories',
			'_exclude_categories' => 'excl_categories',
			'_product_types' => 'product_types',
		];

		$query = DB::table('postmeta', 'pm')
			->left_join('posts', 'p')->on('p.ID = pm.post_id')
			->where('p.post_type', AttributeStock::POST_TYPE)
			->where('pm.meta_key', array_keys($keymap))
			->select('pm.*')
			->orderby('pm.post_id');

		if ($old_filters = $query->get()) {
			$new_filters = [];

			foreach ($old_filters as $row) {
				if (!isset($new_filters[$row->post_id])) {
					$new_filters[$row->post_id] = array_fill_keys(array_values($keymap), null);
				}

				$new_filters[$row->post_id][$keymap[$row->meta_key]] = maybe_unserialize($row->meta_value);
			}

			foreach ($new_filters as $stock_id => $filters) {
				if ($filters = array_filter($filters)) {
					add_post_meta($stock_id, '_filters', $filters, true);
				}
			}
		}

		$query->delete();
	}
}
