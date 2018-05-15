<?php
/**
 * BOB: Logging Interface
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob;

use \blobfolio\bob\format;
use \blobfolio\common\mb as v_mb;
use \blobfolio\common\ref\cast as r_cast;
use \blobfolio\common\ref\mb as r_mb;
use \blobfolio\common\ref\sanitize as r_sanitize;

class log {
	const WRAP = 70;				// Maximum line length.
	const MARKER_START = "\033";	// Beginning of formatted output.
	const MARKER_END = 'm';			// End of formatting rule.

	// Various formatting styles.
	const BULLET = "   \033[2m++\033[0m ";
	const BULLET2 = "   \033[95;1m??\033[0m ";
	const DEBUG = "\033[2m[%s]\033[0m ";
	const ERROR = "\033[31;1mError:\033[0m ";
	const WARNING = "\033[33;1mWarning:\033[0m ";
	const SUCCESS = "\033[32;1mSuccess:\033[0m ";
	const INFO = "\033[96;1mInfo:\033[0m ";


	// -----------------------------------------------------------------
	// Main Output
	// -----------------------------------------------------------------

	/**
	 * Actually Print Message
	 *
	 * @param string $message Message.
	 * @param bool $inline Inline.
	 * @param bool $preline Insert preline.
	 * @return void Nothing.
	 */
	public static function print(string $message, bool $inline=true, bool $preline=null) {
		// Prefix with our bullet style. This happens for pretty much
		// everything other than headers.
		if ($inline) {
			$message = static::BULLET . $message;
		}

		// Wrap long lines.
		static::wordwrap($message);

		// Outline messages usually get a leading line break.
		if (is_null($preline)) {
			$preline = !$inline;
		}
		if ($preline) {
			$message = "\n$message";
		}

		// Send it!
		echo "$message\n";
	}

	/**
	 * Prompt For Input
	 *
	 * @param string $message Prompt.
	 * @param string $default Default answer.
	 * @param mixed $set Default answer or possible answers.
	 * @return string Answer.
	 */
	public static function prompt(string $message, string $default='', $set=null) {
		$pretty = '';

		r_sanitize::whitespace($default);
		r_mb::strtolower($default);

		// Verify the fixed list of responses, if any.
		if (!is_array($set) || count($set) < 2) {
			$set = null;
		}
		else {
			// Generate pretty versions to print.
			$pretty = array();
			foreach ($set as $k=>$v) {
				if (!is_string($v) || !$v) {
					unset($set[$k]);
					continue;
				}

				r_mb::strtolower($set[$k]);
				r_sanitize::whitespace($set[$k]);
				if (!$set[$k]) {
					unset($set[$k]);
					continue;
				}

				if ($default === $set[$k]) {
					$pretty[] = "\033[1m" . v_mb::strtoupper($set[$k]) . "\033[0m";
				}
				else {
					$pretty[] = $set[$k];
				}
			}

			if (count($pretty) < 2) {
				$set = null;
				$pretty = '';
			}
			else {
				$set = array_values($set);
				$pretty = "\033[95;1m[\033[0m" . implode("\033[95;1m, \033[0m", $pretty) . "\033[95;1m]\033[0m";
			}
		}

		// We need to generate an error if the default is not in the
		// set.
		if ($default && $set && !in_array($default, $set, true)) {
			static::error('Invalid prompt options.');
		}

		// Append the choices to the question if it is short enough.
		if ($pretty) {
			$test = "$message $pretty";
			if (static::print_length($test) <= static::WRAP) {
				$message = $test;
				$pretty = '';
			}
		}
		// Append the default to the question.
		elseif ($default) {
			$message = "$message \033[2m($default)\033[0m";
		}

		$answer = '';
		while (!$answer) {
			// Ask it.
			static::print(static::BULLET2 . $message, false, false);
			if ($pretty) {
				static::print($pretty);
			}

			// Answer it.
			if ($handle = fopen('php://stdin', 'r')) {
				// Align the response with the ++.
				echo '      ';

				// Load the answer.
				$answer = fgets($handle);
				r_sanitize::whitespace($answer);
				r_mb::strtolower($answer);

				// Assign it to the default?
				if (!$answer && $default) {
					$answer = $default;
				}

				// Check answer against the set.
				if ($answer && is_array($set)) {
					if (false === ($key = array_search($answer, $set, true))) {
						$answer = '';
					}
					else {
						$answer = $set[$key];
					}
				}

				// Close the connection.
				fclose($handle);

				// Scold the user if they were stupid.
				if (!$answer) {
					static::warning('Invalid response.');
				}
			}
			else {
				static::error('Could not read input.');
			}
		}

		return $answer;
	}

	/**
	 * Confirm
	 *
	 * Shorthand for a simple Yes/No prompt. Returns a bool.
	 *
	 * @param string $message Message.
	 * @param bool $default Default.
	 */
	public static function confirm(string $message, bool $default=null) {
		$set = array('y', 'n');
		if (!is_null($default)) {
			$default = $default ? 'y' : 'n';
		}
		else {
			$default = '';
		}

		return ('y' === static::prompt($message, $default, $set));
	}

	/**
	 * Progress
	 *
	 * A poor man's progress indicator: just pass a percent in decimal
	 * notation (e.g. 0.34) and it will print out as a percent. Call it
	 * again and the previous line will be replaced with the new value.
	 *
	 * @param float $percent Percent.
	 * @return void Nothing.
	 */
	public static function progress(float $percent) {
		$percent *= 100;
		$percent = (int) round($percent);
		r_sanitize::to_range($percent, 0, 100);
		$percent = str_pad("$percent%", 4, ' ', STR_PAD_RIGHT);

		echo "   \033[2m++\033[0m $percent\r";
	}

	// ----------------------------------------------------------------- end output



	// -----------------------------------------------------------------
	// Status Wrappers
	// -----------------------------------------------------------------

	/**
	 * Success Message
	 *
	 * @param string $message Message.
	 * @param bool $inline Inline.
	 * @return void Nothing.
	 */
	public static function success(string $message, bool $inline=true) {
		static::print(static::SUCCESS . $message, $inline);
	}

	/**
	 * Info Message
	 *
	 * @param string $message Message.
	 * @param bool $inline Inline.
	 * @return void Nothing.
	 */
	public static function info(string $message, bool $inline=true) {
		static::print(static::INFO . $message, $inline);
	}

	/**
	 * Debug
	 *
	 * Print a message, but only if debugging is enabled.
	 *
	 * @param string $message Message.
	 * @return void Nothing.
	 */
	public static function debug(string $message) {
		if (BOB_DEBUG && $message) {
			static::print(sprintf(static::DEBUG, date('c')) . $message, false, false);
		}
	}

	/**
	 * Warning Message
	 *
	 * @param string $message Message.
	 * @param bool $inline Inline.
	 * @return void Nothing.
	 */
	public static function warning(string $message, bool $inline=true) {
		static::print(static::WARNING . $message, $inline);
	}

	/**
	 * Error Message
	 *
	 * @param string $message Message.
	 * @param bool $inline Inline.
	 * @param bool $die Die.
	 * @return void Nothing.
	 */
	public static function error(string $message, bool $inline=true, bool $die=true) {
		static::print(static::ERROR . $message, $inline);
		if ($die) {
			exit(1);
		}
	}

	/**
	 * Title
	 *
	 * @param string $title Title.
	 * @return void Nothing.
	 */
	public static function title(string $title) {
		r_mb::strtoupper($title);
		r_mb::trim($title);
		r_mb::str_pad($title, static::WRAP, ' ', STR_PAD_BOTH);

		$divider = "\033[2m" . str_repeat('-', static::WRAP) . "\033[0m";
		$title = "$divider\n\033[1m$title\033[0m\n$divider";
		static::print($title, false);
	}

	/**
	 * Total
	 *
	 * @param mixed $count Count.
	 * @param string $label Label.
	 * @return void Nothing.
	 */
	public static function total($count, string $label='records') {
		// For arrays, use its size.
		if (is_array($count)) {
			$count = count($count);
		}
		// For everything else, intify.
		else {
			r_cast::int($count, true);
		}

		// Format the line.
		$message = "Total $label: " . format::number($count);

		// If the value is zero, consider it a warning.
		if (!$count) {
			static::warning($message);
		}
		// Otherwise it is FYI.
		else {
			static::info($message);
		}
	}

	// ----------------------------------------------------------------- end wrappers



	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Word Wrap
	 *
	 * CLI wordwrap is actually quite difficult thanks to coloration
	 * flags.
	 *
	 * @param string $str String.
	 * @return void Nothing.
	 */
	public static function wordwrap(string &$str) {
		$out = array();

		while (false !== ($line = static::wordwrap_cut($str))) {
			$out[] = $line;
		}

		$str = implode("\n", $out);
	}

	/**
	 * Word Wrap: Cut Line
	 *
	 * Return a line's worth of string, shortening the source string
	 * accordingly.
	 *
	 * @param string $str String.
	 * @return bool|string String.
	 */
	public static function wordwrap_cut(string &$str) {
		// If the string has no length, we're done!
		if (!$str) {
			$str = '';
			return false;
		}

		// If the length is less than our line cap, we don't have to
		// think too hard about it.
		if (static::print_length($str) <= static::WRAP) {
			$line = $str;
			$str = '';
			return $line;
		}

		$in_marker = false;	// We are in a formatting rule.
		$printed = false;	// We've seen a non-breakable char.
		$break_num = 0;		// True position of last breakable char.
		$char_num = 0;		// True position of current char.
		$line_num = 0;		// Line length (minus markers, etc.).
		$line = '';			// The in-progress line to return.

		// Use a multi-byte-safe split to break out the "chars".
		$chars = v_mb::str_split($str);

		foreach ($chars as $k=>$v) {
			$char_num = $k;

			// We are in marker.
			if ($in_marker) {
				// We've reached the end of the marker!
				if (static::MARKER_END === $v) {
					$in_marker = false;
				}

				// Always append.
				$line .= $v;
				continue;
			}
			// We are entering a new marker?
			elseif (static::MARKER_START === $v) {
				$in_marker = true;
				$line .= $v;
				continue;
			}

			// If we've reached a line break, we're done.
			if (preg_match('/\v/u', $v)) {
				--$char_num;
				break;
			}

			// Is this a breakable space?
			$breakable = static::is_breakable($v);
			if ($printed && $breakable) {
				$break_num = $k;
			}
			elseif (!$printed && !$breakable) {
				$printed = true;
			}

			// Add to the line.
			$line .= $v;
			++$line_num;

			// Are we done?
			if (static::WRAP === $line_num) {
				// We might want to peek ahead to see what's there.
				$key = $k + 1;
				if (isset($chars[$key])) {
					// If the next character is a marker sequence, let's
					// finish it.
					if (static::MARKER_START === $chars[$key]) {
						while (isset($chars[$key])) {
							$line .= $chars[$key];
							$char_num = $key;
							if (static::MARKER_END === $chars[$key]) {
								break;
							}
							++$key;
						}
					}
					// If the next character isn't breakable, rewind.
					elseif ($break_num && !static::is_breakable($chars[$key])) {
						$line = v_mb::substr($str, 0, $break_num + 1);
						$char_num = $break_num;
					}
				}

				break;
			}
		}

		// Chop the string.
		$str = v_mb::substr($str, $char_num + 1);
		$str = ltrim($str);

		// Return the line.
		return $line;
	}

	/**
	 * Breakable Char?
	 *
	 * @param string $char Char.
	 * @return bool True/false.
	 */
	protected static function is_breakable(string $char) {
		return (
			preg_match('/\s/u', $char) ||
			('-' === $char) ||
			('–' === $char) ||
			('—' === $char)
		);
	}

	/**
	 * Print Length
	 *
	 * @param string $str String.
	 * @return int Length.
	 */
	protected static function print_length(string $str) {
		r_mb::str_split($str);

		$count = 0;
		$in_marker = false;

		foreach ($str as $v) {
			if ($in_marker) {
				if (static::MARKER_END === $v) {
					$in_marker = false;
				}
			}
			elseif (static::MARKER_START === $v) {
				$in_marker = true;
			}
			else {
				++$count;
			}
		}

		return $count;
	}

	// ----------------------------------------------------------------- end helpers
}
