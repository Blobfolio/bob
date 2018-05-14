<?php
/**
 * BOB: IO Helpers
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob;

use \blobfolio\common\file as v_file;
use \blobfolio\common\ref\file as r_file;
use \blobfolio\common\ref\format as r_format;
use \blobfolio\bob\log;

class io {
	/**
	 * Compress PHP Script(s)
	 *
	 * @param string $path Directories and/or files.
	 * @param string $pattern Additional pattern.
	 * @return void Nothing.
	 */
	public static function compress_php($path, string $pattern='') {
		r_cast::array($path);

		foreach ($path as $k=>$v) {
			// Sanitize path.
			r_file::path($path[$k], true);
			if (!$path[$k]) {
				unset($path[$k]);
				continue;
			}

			// Recurse directories.
			if (is_dir($path[$k])) {
				$path[$k] = v_file::scandir($path[$k], true, false);
			}
		}

		// Flatten results.
		r_format::array_flatten($path);
		$path = array_unique($path);

		// Loop and compress whatever matches.
		foreach ($path as $v) {
			if (
				('.php' === substr(strtolower($v), -4)) ||
				($pattern && preg_match($pattern, $v))
			) {
				file_put_contents($v, php_strip_whitespace($v));
			}
		}
	}

	/**
	 * Require File
	 *
	 * Make sure a file is in the temporary directory.
	 *
	 * @param string $file Relative file name.
	 * @return void Nothing.
	 */
	public static function require_file(string $file) {
		r_file::unleadingslash($file);
		$path = base\mike::get_tmp_dir($file);

		// It is already there!
		if (!$file || file_exists($path)) {
			return;
		}

		log::warning("File needed: $file");

		$message = log::BULLET . 'Copy it to ' . base\mike::get_tmp_dir() . ' then press any key to continue.';
		$tmp = array();
		while (false !== ($line = static::wordwrap_cut($message))) {
			$tmp[] = $line;
		}
		$tmp = implode("\n", $tmp);

		// Pester them until the file exists.
		while (!file_exists($path)) {
			echo $tmp;
			if ($handle = fopen('php://stdin', 'r')) {
				$answer = fgets($handle);
			}
			else {
				log::error('Could not read input.');
			}
		}

		// Move the cursor to a new line.
		echo "\n";
	}
}
