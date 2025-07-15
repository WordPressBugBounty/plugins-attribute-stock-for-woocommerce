<?php
defined('ABSPATH') || die;

/**
 * @var Mewz\WCAS\Models\AttributeStock $stock
 * @var array<string, array> $filters
 * @var array $products
 * @var array $exclude_products
 * @var array $categories
 * @var array $product_types
 */

echo '<div class="options_group">';

woocommerce_wp_select([
	'label' => __('Products', 'woocommerce'),
	'id' => 'mewz_wcas_filters_products',
	'name' => 'mewz_wcas[filters][products][]',
	'class' => 'wc-product-search',
	'description' => __('Filter matches to the selected products only. Leave blank to allow all products.<br><br> Prefer using product conditions in your match rules if it makes sense to do so.', 'woocommerce-attribute-stock'),
	'desc_tip' => true,
	'value' => $filters['products'],
	'options' => $products,
	'custom_attributes' => [
		'multiple' => 'multiple',
		'data-placeholder' => __('All products', 'woocommerce-attribute-stock'),
		'data-action' => 'woocommerce_json_search_products',
		'data-exclude_type' => 'grouped,external',
	],
]);

woocommerce_wp_select([
	'label' => __('Exclude products', 'woocommerce'),
	'id' => 'mewz_wcas_filters_excl_products',
	'name' => 'mewz_wcas[filters][excl_products][]',
	'class' => 'wc-product-search',
	'description' => __('Filter matches to exclude the selected products.', 'woocommerce-attribute-stock'),
	'desc_tip' => true,
	'value' => $filters['excl_products'],
	'options' => $exclude_products,
	'custom_attributes' => [
		'multiple' => 'multiple',
		'data-placeholder' => __('No products excluded', 'woocommerce-attribute-stock'),
		'data-action' => 'woocommerce_json_search_products',
		'data-exclude_type' => 'grouped,external',
	],
]);

echo '</div><div class="options_group">';

woocommerce_wp_select([
	'label' => __('Categories', 'woocommerce'),
	'id' => 'mewz_wcas_filters_categories',
	'name' => 'mewz_wcas[filters][categories][]',
	'class' => 'wc-enhanced-select',
	'description' => __('Filter matches to products in the selected categories only. Leave blank to allow products in any category.', 'woocommerce-attribute-stock'),
	'desc_tip' => true,
	'value' => $filters['categories'],
	'options' => $categories,
	'custom_attributes' => [
		'multiple' => 'multiple',
		'data-placeholder' => __('All categories', 'woocommerce-attribute-stock'),
	],
]);

woocommerce_wp_select([
	'label' => __('Exclude categories', 'woocommerce'),
	'id' => 'mewz_wcas_filters_excl_categories',
	'name' => 'mewz_wcas[filters][excl_categories][]',
	'class' => 'wc-enhanced-select',
	'description' => __('Filter matches to exclude all products in the selected categories.', 'woocommerce-attribute-stock'),
	'desc_tip' => true,
	'value' => $filters['excl_categories'],
	'options' => $categories,
	'custom_attributes' => [
		'multiple' => 'multiple',
		'data-placeholder' => __('No categories excluded', 'woocommerce-attribute-stock'),
	],
]);

echo '</div><div class="options_group">';

woocommerce_wp_select([
	'label' => __('Product types', 'woocommerce-attribute-stock'),
	'id' => 'mewz_wcas_filters_product_types',
	'name' => 'mewz_wcas[filters][product_types][]',
	'class' => 'wc-enhanced-select',
	'description' => __('Filter matches to products of the selected types only. Leave blank to allow products of any valid type.', 'woocommerce-attribute-stock'),
	'desc_tip' => true,
	'value' => $filters['product_types'],
	'options' => $product_types,
	'custom_attributes' => [
		'multiple' => 'multiple',
		'data-placeholder' => __('All product types', 'woocommerce-attribute-stock'),
	],
]);

echo '</div>';
