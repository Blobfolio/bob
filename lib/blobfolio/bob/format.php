<?php
/**
 * BOB: Formatting Helpers
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob;

use \blobfolio\common\cast as v_cast;
use \blobfolio\common\format as v_format;
use \blobfolio\common\mb as v_mb;
use \blobfolio\common\ref\cast as r_cast;
use \blobfolio\common\ref\mb as r_mb;
use \blobfolio\common\ref\sanitize as r_sanitize;

class format {
	// -----------------------------------------------------------------
	// Basic Variable Formatting
	// -----------------------------------------------------------------

	/**
	 * Array to PHP
	 *
	 * Convert an array variable into a string representing equivalent
	 * PHP code.
	 *
	 * @param mixed $data Data.
	 * @param int $indent Indentation count.
	 * @return string Code.
	 */
	public static function array_to_php($data, int $indent=1) {
		if (!is_array($data) || !count($data)) {
			return '';
		}

		$out = array();
		$array_type = v_cast::array_type($data);

		foreach ($data as $k=>$v) {
			$line = str_repeat("\t", $indent);

			// We need to talk about the key.
			if ('sequential' !== $array_type) {
				$key = str_replace("'", "\\'", $k);
				$line .= "'$k'=>";
			}

			// Add the value.
			switch (gettype($v)) {
				case 'array':
					$line .= 'array(' . static::array_to_php($v, $indent + 1) . ')';
					break;
				case 'string':
					$value = str_replace("'", "\\'", $v);
					$line .= "'$v'";
					break;
				case 'boolean':
					$line .= ($v ? 'true' : 'false');
					break;
				case 'integer':
				case 'double':
					$line .= $v;
					break;
				default:
					$line .= 'null';
			}

			$out[] = $line;
		}

		return "\n" . implode(",\n", $out) . ",\n" . str_repeat("\t", $indent - 1);
	}

	/**
	 * Nice Bytes
	 *
	 * Convert and unit-ize a number in bytes.
	 *
	 * @param int $num Bytes.
	 * @param int $precision Precision.
	 * @return string Bytes.
	 */
	public static function bytes(int $num, int $precision=2) {
		$unit = 'B';

		// Gigabytes.
		if ($num >= 1000 * 1024 * 1024) {
			$unit = 'GB';
			$num /= (1000 * 1024 * 1024);
		}
		elseif ($num >= 1000 * 1024) {
			$unit = 'MB';
			$num /= (1000 * 1024);
		}
		elseif ($num >= 1000) {
			$unit = 'KB';
			$num /= 1000;
		}

		return static::number($num, $precision) . $unit;
	}

	/**
	 * Lines to Array
	 *
	 * Convert a string into an array with each line its own entry.
	 *
	 * @param string $str String.
	 * @param bool $trim_h Trim lines.
	 * @param bool $trim_v Remove empty lines.
	 * @return array Lines.
	 */
	public static function lines_to_array(string $str, bool $trim_h=true, bool $trim_v = true) {
		// Standardize vertical whitespace.
		$str = str_replace("\r\n", "\n", $str);
		$str = preg_replace('/\v/u', "\n", $str);

		$str = explode("\n", $str);

		if ($trim_h || $trim_v) {
			foreach ($str as $k=>$v) {
				// Trim the horizontal.
				if ($trim_h) {
					r_mb::trim($str[$k]);

					// If removing empty and empty, remove it.
					if ($trim_v && !$str[$k]) {
						unset($str[$k]);
						continue;
					}
				}
				// Otherwise remove the line if needed.
				elseif ($trim_v && !v_mb::trim($str[$k])) {
					unset($str[$k]);
					continue;
				}
			}

			$str = array_values($str);
		}

		return $str;
	}

	/**
	 * Nice Number
	 *
	 * @param mixed $num Number.
	 * @param int $precision Precision.
	 * @return string Formatted number.
	 */
	public static function number($num, int $precision=0) {
		r_sanitize::to_range($precision, 0);

		// If an array is passed, use the count instead.
		if (is_array($num)) {
			$num = count($num);
		}
		elseif (!is_int($num) && !is_float($num)) {
			r_cast::float($num, true);
		}

		return number_format($num, $precision, '.', ',');
	}

	/**
	 * Nice Time
	 *
	 * Convert and unit-ize a number in seconds.
	 *
	 * @param float $seconds Seconds.
	 * @param int $precision Precision.
	 * @return string Time.
	 */
	public static function time(float $seconds, int $precision=3) {
		$out = array();

		// Hours.
		if ($seconds >= 60 * 60) {
			$hours = floor($seconds / 60 / 60);
			$seconds -= ($hours * 60 * 60);
			$out[] = v_format::inflect($hours, '%d hour', '%d hours');
		}

		// Minutes.
		if ($seconds >= 60) {
			$minutes = floor($seconds / 60);
			$seconds -= ($minutes * 60);
			$out[] = v_format::inflect($minutes, '%d minute', '%d minutes');
		}

		$seconds = round($seconds, $precision);
		if ($seconds > 0) {
			if ($seconds < 1 || $seconds >= 1) {
				$out[] = "$seconds seconds";
			}
			else {
				$out[] = '1 second';
			}
		}

		// Oxford-comma this shit.
		if (count($out) === 3) {
			return "{$out[0]}, {$out[1]}, and {$out[2]}";
		}
		// Two items joined with an "and".
		elseif (count($out) === 2) {
			return implode(' and ', $out);
		}

		// One item, one result.
		return $out[0];
	}

	// ----------------------------------------------------------------- end variables
}
