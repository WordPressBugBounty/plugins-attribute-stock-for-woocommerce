<?php
namespace Mewz\WCAS\Models;

use Mewz\Framework\Base\PostModel;
use Mewz\Framework\Util\Number;
use Mewz\WCAS\Util\Components;
use Mewz\WCAS\Util\Matches;

class AttributeStock extends PostModel
{
	const POST_TYPE = 'attribute_stock';
	const MODEL_TYPE = 'attribute_stock';

	protected static $props = [
		'thumbnail_id' => null,
		'sku' => '',
		'quantity' => '',
		'low_stock' => '',
		'backorders' => '',
		'internal' => false,
		'multiplex' => false,
		'lock_multipliers' => false,
		'product_sku' => false,
		'product_image' => false,
		'filters' => [],
	];

	protected static $alias_props = [
		'image_id' => 'thumbnail_id',
		'notes' => 'content',
	];

	protected const FILTERS = [
		'products' => [],
		'excl_products' => [],
		'categories' => [],
		'excl_categories' => [],
		'product_types' => [],
	];

	/**
	 * @return bool
	 */
	public function enabled()
	{
		return $this->status() === 'publish';
	}

	/**
	 * @param bool $value
	 */
	public function set_enabled($value = true)
	{
		$this->set_status($value ? 'publish' : 'draft');
	}

	/**
	 * @return int|null
	 */
	public function image_id()
	{
		return (int)$this->get('image_id') ?: null;
	}

	/**
	 * @param int|null $value
	 */
	public function set_image_id($value)
	{
		$this->set('image_id', $value ? (int)$value : null);
	}

	/**
	 * @return string
	 */
	public function sku()
	{
		return $this->get('sku');
	}

	/**
	 * @param string $value
	 */
	public function set_sku($value)
	{
		$this->set('sku', $value);
	}

	/**
	 * @return numeric
	 */
	public function quantity()
	{
		$value = $this->get('quantity');

		return $this->context === 'view' ? (float)$value : $value;
	}

	/**
	 * @param numeric $value
	 */
	public function set_quantity($value)
	{
		$this->set('quantity', Number::safe_decimal($value));
	}

	/**
	 * @param numeric $amount
	 *
	 * @return float|false New quantity
	 */
	public function adjust_quantity($amount)
	{
		$amount = (float)$amount;

		if (!$amount) {
			return false;
		}

		$quantity = (float)$this->get('quantity', 'edit') + $amount;
		$this->set_quantity($quantity);

		return $quantity;
	}

	/**
	 * @return numeric Defaults to WooCommerce setting
	 */
	public function low_stock()
	{
		$value = $this->get('low_stock');

		if ($this->context !== 'view') {
			return $value;
		} elseif ((string)$value === '') {
			return get_option('woocommerce_notify_low_stock_amount', 2);
		} else {
			return (float)$value;
		}
	}

	/**
	 * @param numeric $value
	 */
	public function set_low_stock($value)
	{
		$this->set('low_stock', Number::safe_decimal($value));
	}

	/**
	 * @return string '' | 'no' | 'yes' | 'notify'
	 */
	public function backorders()
	{
		return $this->get('backorders');
	}

	/**
	 * @param string $value
	 */
	public function set_backorders($value)
	{
		if (!in_array($value, ['', 'no', 'yes', 'notify'], true)) {
			$value = '';
		}

		$this->set('backorders', $value);
	}

	/**
	 * @return string
	 */
	public function notes()
	{
		return $this->get('notes');
	}

	/**
	 * @param string $value
	 */
	public function set_notes($value)
	{
		$this->set('notes', trim($value));
	}

	/**
	 * @return bool
	 */
	public function internal()
	{
		return (bool)$this->get('internal');
	}

	/**
	 * @param bool $value
	 */
	public function set_internal($value)
	{
		$this->set('internal', (bool)$value);
	}

	/**
	 * @return bool
	 */
	public function multiplex()
	{
		return (bool)$this->get('multiplex');
	}

	/**
	 * @param bool $value
	 */
	public function set_multiplex($value)
	{
		$this->set('multiplex', (bool)$value);
	}

	/**
	 * @return bool
	 */
	public function lock_multipliers()
	{
		return (bool)$this->get('lock_multipliers');
	}

	/**
	 * @param bool $value
	 */
	public function set_lock_multipliers($value)
	{
		$this->set('lock_multipliers', (bool)$value);
	}

	/**
	 * @return bool
	 */
	public function product_sku()
	{
		return (bool)$this->get('product_sku');
	}

	/**
	 * @param bool $value
	 */
	public function set_product_sku($value)
	{
		$this->set('product_sku', (bool)$value);
	}

	/**
	 * @return bool
	 */
	public function product_image()
	{
		return (bool)$this->get('product_image');
	}

	/**
	 * @param bool $value
	 */
	public function set_product_image($value)
	{
		$this->set('product_image', (bool)$value);
	}

	/**
	 * @return array{
	 *     products: int[],
	 *     excl_products: int[],
	 *     categories: int[],
	 *     excl_categories: int[],
	 *     product_types: string[],
	 * }
	 */
	public function filters()
	{
		$value = $this->get('filters');

		if ($value && is_array($value)) {
			return array_merge(self::FILTERS, $value);
		} else {
			return self::FILTERS;
		}
	}

	/**
	 * @param array<string, array> $value
	 */
	public function set_filters($value)
	{
		$value = is_array($value) ? array_filter($value) : [];

		if ($value) {
			// ensure lists of integer IDs
			foreach (['products', 'excl_products', 'categories', 'excl_categories'] as $key) {
			    if (isset($value[$key])) {
				    $value[$key] = array_keys(array_flip($value[$key]));
			    }
			}
		}

		$this->set('filters', $value ?: null);
	}

	/**
	 * @param string $context
	 *
	 * @return array
	 */
	public function components($context = null)
	{
		return Components::get_components($this->id(), $context ?? $this->context);
	}

	/**
	 * @param array|false $value
	 */
	public function save_components($value)
	{
		Components::save_components($this->id(), $value);
	}

	/**
	 * @param string $context
	 *
	 * @return array
	 */
	public function match_rules($context = null)
	{
		return Matches::get_rules($this->id(), $context ?? $this->context);
	}

	/**
	 * @param array|false $value
	 */
	public function save_match_rules($value)
	{
		Matches::save_rules($this->id(), $value);
	}

	/**
	 * @see \WP_Term_Query
	 *
	 * @param array $args
	 *
	 * @return \WP_Term[]|int[]|string[]|numeric-string
	 */
	public function tags($args = [])
	{
		return get_terms([
			'taxonomy' => 'product_tag',
			'object_ids' => $this->id(),
		] + $args + [
			'orderby' => 'name',
		]);
	}

	/**
	 * @inheritDoc
	 */
	public function duplicate($data = [], $context = 'view')
	{
		$copy = parent::duplicate($data, $context);

		if ($copy) {
			$copy->save_match_rules($this->match_rules('edit'));
			$copy->save_components($this->components('edit'));

			if ($tags = $this->tags(['fields' => 'ids'])) {
				wp_set_object_terms($copy->id(), $tags, 'product_tag');
			}

			foreach ($this->meta('attribute_level', false) as $attribute_id) {
				$copy->add_meta('attribute_level', $attribute_id);
			}

			do_action(static::hook_name('duplicated_extra'), $copy, $this, $data);
		}

		return $copy;
	}

	public function formatted_quantity()
	{
		global $wp_locale;

		$quantity = $this->get_contextual('quantity', 'view');
		$parts = explode('.', Number::safe_decimal($quantity), 2);
		$sign = $quantity < 0 ? '-' : '';

		$html = '<span class="qty-int">' . $sign . number_format_i18n(abs($parts[0])) . '</span>';

		if (isset($parts[1])) {
			$dp = $wp_locale ? $wp_locale->number_format['decimal_point'] : '.';
			$html .= '<span class="qty-dec">' . $dp . $parts[1] . '</span>';
		}

		$html = apply_filters(static::hook_name('formatted_quantity'), $html, $this);

		$classes = [];

		if ($quantity <= $this->get_contextual('low_stock', 'view')) {
			$classes[] = 'low-stock';
		}

		$classes = apply_filters(static::hook_name('formatted_quantity_classes'), $classes, $this);

		return '<span class="stock-quantity' . ($classes ? ' ' . implode(' ', $classes) : '') . '">' . $html . '</span>';
	}

	/****************************************************************************************************/
	/* DEPRECATED METHODS
	/****************************************************************************************************/

	/**
	 * @deprecated 2.0.0 Use internal()
	 *
	 * @return bool
	 */
	public function limit_products()
	{
		_deprecated_function(__METHOD__, '2.0.0', __CLASS__ . '::internal');

		return !$this->internal();
	}

	/**
	 * @deprecated 2.0.0 Use set_internal()
	 *
	 * @param bool $value
	 */
	public function set_limit_products($value)
	{
		_deprecated_function(__METHOD__, '2.0.0', __CLASS__ . '::set_internal');

		$this->set_internal(!$value);
	}

	/**
	 * @deprecated 2.0.0 Use multiplex()
	 *
	 * @return bool
	 */
	public function match_all()
	{
		_deprecated_function(__METHOD__, '2.0.0', __CLASS__ . '::multiplex');

		return $this->multiplex();
	}

	/**
	 * @deprecated 2.0.0 Use set_multiplex()
	 *
	 * @param bool $value
	 */
	public function set_match_all($value)
	{
		_deprecated_function(__METHOD__, '2.0.0', __CLASS__ . '::set_multiplex');

		$this->set_multiplex($value);
	}

	/**
	 * @deprecated 2.2.0 Use filters()
	 *
	 * @return array A list of product IDs
	 */
	public function products()
	{
		_deprecated_function(__METHOD__, '2.2.0', __CLASS__ . '::filters');

		return $this->filters()['products'];
	}

	/**
	 * @deprecated 2.2.0 Use set_filters()
	 *
	 * @param array $value A list of product IDs
	 */
	public function set_products($value)
	{
		_deprecated_function(__METHOD__, '2.2.0', __CLASS__ . '::set_filters');

		$filters = $this->filters();
		$filters['products'] = is_array($value) ? $value : [];
		$this->set_filters($filters);
	}

	/**
	 * @deprecated 2.2.0 Use filters()
	 *
	 * @return array A list of product IDs
	 */
	public function exclude_products()
	{
		_deprecated_function(__METHOD__, '2.2.0', __CLASS__ . '::filters');

		return $this->filters()['excl_products'];
	}

	/**
	 * @deprecated 2.2.0 Use set_filters()
	 *
	 * @param array $value A list of product IDs
	 */
	public function set_exclude_products($value)
	{
		_deprecated_function(__METHOD__, '2.2.0', __CLASS__ . '::set_filters');

		$filters = $this->filters();
		$filters['excl_products'] = is_array($value) ? $value : [];
		$this->set_filters($filters);
	}

	/**
	 * @deprecated 2.2.0 Use filters()
	 *
	 * @return array A list of product category IDs
	 */
	public function categories()
	{
		_deprecated_function(__METHOD__, '2.2.0', __CLASS__ . '::filters');

		return $this->filters()['categories'];
	}

	/**
	 * @deprecated 2.2.0 Use set_filters()
	 *
	 * @param array $value A list of product category IDs
	 */
	public function set_categories($value)
	{
		_deprecated_function(__METHOD__, '2.2.0', __CLASS__ . '::set_filters');

		$filters = $this->filters();
		$filters['categories'] = is_array($value) ? $value : [];
		$this->set_filters($filters);
	}

	/**
	 * @deprecated 2.2.0 Use filters()
	 *
	 * @return array A list of product category IDs
	 */
	public function exclude_categories()
	{
		_deprecated_function(__METHOD__, '2.2.0', __CLASS__ . '::filters');

		return $this->filters()['excl_categories'];
	}

	/**
	 * @deprecated 2.2.0 Use set_filters()
	 *
	 * @param array $value A list of product category IDs
	 */
	public function set_exclude_categories($value)
	{
		_deprecated_function(__METHOD__, '2.2.0', __CLASS__ . '::set_filters');

		$filters = $this->filters();
		$filters['excl_categories'] = is_array($value) ? $value : [];
		$this->set_filters($filters);
	}

	/**
	 * @deprecated 2.2.0 Use filters()
	 *
	 * @return array
	 */
	public function product_types()
	{
		_deprecated_function(__METHOD__, '2.2.0', __CLASS__ . '::filters');

		return $this->filters()['product_types'];
	}

	/**
	 * @deprecated 2.2.0 Use set_filters()
	 *
	 * @param array $value
	 */
	public function set_product_types($value)
	{
		_deprecated_function(__METHOD__, '2.2.0', __CLASS__ . '::set_filters');

		$filters = $this->filters();
		$filters['product_types'] = is_array($value) ? $value : [];
		$this->set_filters($filters);
	}
}
