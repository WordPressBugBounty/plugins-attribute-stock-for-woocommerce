<?php
namespace Mewz\Framework\Util;

class Number
{
	public static $comma_point = false;

	/**
	 * @param numeric $value
	 *
	 * @return numeric-string
	 */
	public static function safe_decimal($value)
	{
		if (!is_numeric($value)) {
			return '';
		}

		$float = (float)$value;

		if ($float === -0.0) {
			return '0';
		}

		return self::$comma_point ? str_replace(',', '.', $float) : (string)$float;
	}

	/**
	 * @param numeric $value
	 * @param int $precision
	 *
	 * @return string
	 */
	public static function local_format($value, $precision = 5)
	{
		$output = number_format_i18n((float)$value, $precision);

		if ($precision > 0) {
			$output = rtrim(rtrim($output, '0'), '., ');
		}

		return $output;
	}

	/**
	 * @param mixed $value
	 * @param bool $sort
	 *
	 * @return int[]
	 */
	public static function to_id_list($value, $sort = false)
	{
		if (!is_array($value)) {
			$value = [$value];
		}

		$ids = [];

		foreach ($value as $v) {
			if ((is_int($v) || (string)$v === (string)(int)$v) && $v >= 0) {
				$ids[$v] = true;
		    }
		}

		if ($ids) {
			$ids = array_keys($ids);

			if ($sort) {
				sort($ids, SORT_NUMERIC);
			}
		}

		return $ids;
	}
}

Number::$comma_point = ((string)0.1)[1] === ',';
