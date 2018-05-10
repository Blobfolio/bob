<?php
/**
 * BOB: Miscellaneous Functionality
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob;

use \blobfolio\common\cast as v_cast;
use \blobfolio\common\data;
use \blobfolio\common\file as v_file;
use \blobfolio\common\ref\cast as r_cast;
use \blobfolio\common\ref\file as r_file;
use \blobfolio\common\ref\mb as r_mb;
use \blobfolio\common\ref\sanitize as r_sanitize;
use \PharData;
use \RecursiveDirectoryIterator;
use \RecursiveIteratorIterator;
use \Throwable;
use \ZipArchive;

class utility {

	// Lifetime for cache in seconds.
	const CACHE_LIMIT = 3600;

	// Number of URLs to process in parallel.
	const REMOTE_CHUNK = 50;

	// Remote fetch timeout.
	const REMOTE_TIMEOUT = 10;

	// File/directory patterns to ignore.
	const SHITLIST = array(
		'/\.(_*)DS_Store$/',
		'/\.babelrc$/',
		'/\.eslintrc\.json$/',
		'/\.git(attributes|ignore)?$/',
		'/\.htaccess$/',
		'/\.sass-cache/',
		'/composer\.(json|lock)$/',
		'/Gruntfile\.js$/',
		'/node_modules/',
		'/package(\-lock)?\.json$/',
		'/phpunit/',
		'/readme\.md$/i',
		'/vendor\/(autoload.php|bin|composer)/',
		'/vendor\/.*\.(markdown|md|yml)$/',
		'/vendor\/[^\/+]\/(build\.xml|tests?)/',
		'/yarn\.lock$/',
	);

	protected static $mtime;
	protected static $tmp_dir;



	// -----------------------------------------------------------------
	// Working Data/Cache
	// -----------------------------------------------------------------

	/**
	 * Get Tmp Dir
	 *
	 * @return string Directory.
	 */
	public static function get_tmp_dir() {
		if (is_null(static::$tmp_dir)) {
			static::$tmp_dir = sys_get_temp_dir();
			r_file::trailingslash(static::$tmp_dir, true);
			static::$tmp_dir .= 'bob/';
			if (!is_dir(static::$tmp_dir)) {
				v_file::mkdir(static::$tmp_dir, 0777);
			}
		}

		return static::$tmp_dir;
	}

	/**
	 * Make Random Dir
	 *
	 * @return string Directory.
	 */
	public static function generate_tmp_dir() {
		$tmp = utility::get_tmp_dir() . data::random_string(10);
		while (file_exists($tmp)) {
			$tmp = utility::get_tmp_dir() . data::random_string(10);
		}
		$tmp .= '/';

		// Make it.
		v_file::mkdir($tmp, 0755);

		return $tmp;
	}

	/**
	 * Cache Name
	 *
	 * Files are downloaded and cached for a period of time.
	 *
	 * @param string $url URL.
	 * @return bool|string Key or false.
	 */
	protected static function get_cache_key(string $url) {
		r_sanitize::url($url);
		if (!$url) {
			return false;
		}

		$slug = strtolower($url);
		$slug = preg_replace('#^(http|ftp)s?://#', '', $slug);
		$slug = str_replace(array('/', '\\', '?', '#'), '-', $slug);

		return static::get_tmp_dir() . $slug;
	}

	/**
	 * Get File From Cache
	 *
	 * @param string $url URL.
	 * @return mixed False or content.
	 */
	protected static function get_cache(string $url) {
		// If we can't get a cache key, it can't be cached.
		if (
			(false === ($key = static::get_cache_key($url))) ||
			(!is_file($key))
		) {
			return false;
		}

		// We need to find the system mtime if not already set.
		if (is_null(static::$mtime)) {
			$test = static::get_tmp_dir() . 'mtime';
			file_put_contents($test, 'Hello World');
			static::$mtime = (int) filemtime($test);
			static::$mtime -= static::CACHE_LIMIT;
			unlink($test);
		}

		// Remove the file if it is too old.
		$age = (int) filemtime($key);
		if ($age < static::$mtime) {
			unlink($key);
			return false;
		}

		// Return the content!
		return file_get_contents($key);
	}

	/**
	 * Save Cache
	 *
	 * @param string $url URL.
	 * @param mixed $content Content.
	 * @return bool True/false.
	 */
	protected static function save_cache(string $url, $content) {
		if (false !== ($key = static::get_cache_key($url))) {
			file_put_contents($key, $content);
			chmod($key, 0644);
			return is_file($key);
		}

		return false;
	}

	// ----------------------------------------------------------------- end local/cache



	// -----------------------------------------------------------------
	// Remote/URL
	// -----------------------------------------------------------------

	/**
	 * Fetch Remote
	 *
	 * Some build processes might need hundreds or thousands of files,
	 * so multi-proc CURL is really the only way to go.
	 *
	 * @param array $urls URLs.
	 * @return array Response file path(s).
	 */
	public static function get_remote($urls) {
		$out = array();

		// Sanitize the URL list.
		r_cast::array($urls);
		foreach ($urls as $k=>$v) {
			r_sanitize::url($urls[$k]);
			if (!$urls[$k] || !is_string($urls[$k])) {
				unset($urls[$k]);
			}
		}
		sort($urls);

		// If there are no URLs, we're done!
		if (!count($urls)) {
			return $out;
		}

		// Let's go ahead and get what we can from cache.
		foreach ($urls as $k=>$v) {
			if (false !== ($cache = static::get_cache($v))) {
				$out[$v] = static::get_cache_key($v);
				unset($urls[$k]);
			}
		}

		// If there are no URLs left to fetch remotely, we're done!
		if (!count($urls)) {
			return $out;
		}

		// Process the URLs in chunks.
		$urls = array_chunk($urls, static::REMOTE_CHUNK);
		foreach ($urls as $chunk) {
			$multi = curl_multi_init();
			$curls = array();

			// Set up curl request for each site.
			foreach ($chunk as $url) {
				$curls[$url] = curl_init($url);

				curl_setopt($curls[$url], CURLOPT_HEADER, false);
				curl_setopt($curls[$url], CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($curls[$url], CURLOPT_TIMEOUT, static::REMOTE_TIMEOUT);
				curl_setopt($curls[$url], CURLOPT_USERAGENT, 'bob');
				curl_setopt($curls[$url], CURLOPT_FOLLOWLOCATION, true);

				curl_multi_add_handle($multi, $curls[$url]);
			}

			// Process requests.
			do {
				curl_multi_exec($multi, $running);
				curl_multi_select($multi);
			} while ($running > 0);

			// Update information.
			foreach ($chunk as $url) {
				$out[$url] = (int) curl_getinfo($curls[$url], CURLINFO_HTTP_CODE);
				if ($out[$url] >= 200 && $out[$url] < 400) {
					static::save_cache($url, curl_multi_getcontent($curls[$url]));
					$out[$url] = static::get_cache_key($url);
				}
				else {
					$out[$url] = false;
				}
				curl_multi_remove_handle($multi, $curls[$url]);
			}

			curl_multi_close($multi);
		}

		ksort($out);
		return $out;
	}

	// ----------------------------------------------------------------- end remote



	// -----------------------------------------------------------------
	// Parsing
	// -----------------------------------------------------------------

	/**
	 * Convert Document to Array of Lines
	 *
	 * @param string $str String.
	 * @return array Lines.
	 */
	public static function doc_to_lines(string $str) {
		r_sanitize::whitespace($str, 1);
		r_mb::trim($str);
		$str = array_filter($str, 'strlen');
		return array_values($str);
	}

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

	// ----------------------------------------------------------------- end parsing



	// -----------------------------------------------------------------
	// Files
	// -----------------------------------------------------------------

	/**
	 * Is Shitlist File?
	 *
	 * @param string $file File.
	 * @param array $shitlist Shitlist.
	 * @return bool True/false.
	 */
	public static function is_shitlist(string $file, $shitlist=null) {
		// Merge the user shitlist, if applicable.
		$tmp = static::SHITLIST;
		if (is_array($shitlist)) {
			foreach ($shitlist as $v) {
				if ($v && is_string($v)) {
					$tmp[] = $v;
				}
			}
		}
		sort($tmp);

		// Look for hits.
		foreach ($tmp as $v) {
			if (preg_match($v, $file)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Scandir
	 *
	 * @param string $dir Directory.
	 * @param array $shitlist Shitlist.
	 * @return array Files.
	 */
	public static function scandir(string $dir, $shitlist=null) {
		$out = v_file::scandir($dir, true, false);

		// Filter results.
		foreach ($out as $k=>$v) {
			if (static::is_shitlist($out[$k], $shitlist)) {
				unset($out[$k]);
			}
		}

		sort($out);
		return $out;
	}

	/**
	 * Require File
	 *
	 * @param string $file File.
	 * @return bool True/false.
	 */
	public static function require_file(string $file) {
		if (is_file($file)) {
			return true;
		}

		static::log("Missing $file.", 'warning', false);
		while (!is_file($file)) {
			static::prompt("Copy the missing file and press \033[95m<ENTER>\033[0m to continue.", false);
		}
	}

	/**
	 * Copy
	 *
	 * @param string $from From.
	 * @param string $to To.
	 * @param array $shitlist Shitlist.
	 * @return bool True/false.
	 */
	public static function copy(string $from, string $to, $shitlist=null) {
		r_file::path($from, true);
		if (!$from) {
			return false;
		}

		r_file::path($to, false);
		if (!$to || ($from === $to)) {
			return false;
		}

		// Ignore shitlist files.
		if (static::is_shitlist($from, $shitlist) || static::is_shitlist($to, $shitlist)) {
			return false;
		}

		// Recurse directories.
		if (@is_dir($from)) {
			r_file::trailingslash($from);
			r_file::trailingslash($to);

			if (!@is_dir($to)) {
				$dir_chmod = (@fileperms($from) & 0777 | 0755);
				if (!v_file::mkdir($to, $dir_chmod)) {
					return false;
				}
			}

			// Copy all files and directories within.
			if ($handle = @opendir($from)) {
				while (false !== ($file = @readdir($handle))) {
					// Ignore dots.
					if (('.' === $file) || ('..' === $file)) {
						continue;
					}

					// Recurse.
					static::copy("{$from}{$file}", "{$to}{$file}", $shitlist);
				}
				closedir($handle);
			}

			return true;
		}
		// Let PHP handle it.
		elseif (@is_file($from)) {
			$dir_from = dirname($from);
			$dir_to = dirname($to);

			// Make the TO directory if it doesn't exist.
			if (!@is_dir($dir_to)) {
				$dir_chmod = (@fileperms($dir_from) & 0777 | 0755);
				if (!v_file::mkdir($dir_to, $dir_chmod)) {
					return false;
				}
			}

			// Copy the file.
			if (!@copy($from, $to)) {
				return false;
			}
			$file_chmod = (@fileperms($from) & 0777 | 0644);
			@chmod($to, $file_chmod);

			return true;
		}

		return false;
	}

	/**
	 * Compress PHP Scripts
	 *
	 * @param string $dir Directory.
	 * @return bool True/false.
	 */
	public static function compress_php(string $dir) {
		// This should be a directory.
		if (is_dir($dir)) {
			$tmp = v_file::scandir($dir);
		}
		// But if a single PHP file path was passed, we can handle that.
		elseif (is_file($dir) && ('.php' === substr(strtolower($dir)))) {
			$tmp = array($dir);
		}

		// Loop through files and compress any PHP scripts.
		if (count($tmp)) {
			foreach ($tmp as $v) {
				if ('.php' === substr(strtolower($v), -4)) {
					file_put_contents($v, php_strip_whitespace($v));
				}
			}

			return true;
		}

		return false;
	}

	// ----------------------------------------------------------------- files



	// -----------------------------------------------------------------
	// Package
	// -----------------------------------------------------------------

	/**
	 * Zip Directory
	 *
	 * @param string $zip Zip file.
	 * @param string $dir Directory.
	 * @param string $subdir Throw in subdir.
	 * @return bool True/false.
	 */
	public static function zip(string $zip, string $dir, string $subdir='') {
		// Announce it.
		static::log('Packaging zip…');

		// We need ZipArchive.
		if (!class_exists('ZipArchive')) {
			static::log('ZipArchive is not installed.', 'error');
		}

		// Check the source path.
		r_file::path($dir, true);
		if (!$dir || !is_dir($dir)) {
			static::log('Invalid zip directory.', 'error');
		}

		// And the output path.
		r_file::path($zip, false);
		if ('.zip' !== substr(strtolower($zip), -4)) {
			static::log('Invalid zip file.', 'error');
		}
		// Remove existing zip.
		elseif (is_file($zip)) {
			static::log('Removing old archive…');
			unlink($zip);
		}

		$handle = new ZipArchive();
		$handle->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

		$base_absolute = '#^' . preg_quote($dir, '#') . '#';
		$base_relative = ltrim($subdir, '/');
		r_file::trailingslash($base_relative);

		// Loop it.
		static::log('Compressing files…');
		$files = static::scandir($dir);
		foreach ($files as $v) {
			$file_absolute = $v;
			if ($subdir) {
				$file_relative = preg_replace($base_absolute, $base_relative, $file_absolute);
			}
			else {
				$file_relative = preg_replace($base_absolute, '', $file_absolute);
			}

			$handle->addFile($file_absolute, $file_relative);
		}

		$handle->close();
		return true;
	}

	/**
	 * Build Deb
	 *
	 * @param string $dir Directory.
	 * @param string $deb Output file.
	 */
	public static function deb(string $dir, string $deb='') {
		r_file::path($dir, true);
		if (!$dir || !is_dir($dir)) {
			static::log('Invalid deb directory.', 'error');
		}

		// We might need to do some hacky parsing for the output file.
		if (!$deb) {
			$deb = $dir;
		}

		if (is_dir($deb)) {
			r_file::trailingslash($deb);
			$deb .= basename($dir) . '.deb';
		}

		if ('.deb' !== substr($deb, -4)) {
			static::log('Invalid deb file.', 'error');
		}

		// Try to add a version to the package name.
		if (is_file("{$dir}DEBIAN/control")) {
			$tmp = file_get_contents("{$dir}DEBIAN/control");
			if (preg_match('/^Version:\s*([^\s]+)/m', $tmp, $match)) {
				$version = $match[1];
				if (false === strpos($deb, "_{$version}.deb")) {
					$deb = substr($deb, 0, -4) . "_{$version}.deb";
				}
			}
		}

		$dpkg = new binary\dpkg();
		$dpkg->build($dir, $deb);
		return is_file($deb);
	}

	/**
	 * Extract Tar
	 *
	 * @param string $tar Tar.
	 * @param string $out Out.
	 * @return bool True/false.
	 */
	public static function untar(string $tar, string $out) {
		if (!class_exists('PharData')) {
			static::log('PharData is not installed.', 'error');
		}

		if (!function_exists('gzopen')) {
			static::log('Gzip is not installed.', 'error');
		}

		// Make sure the tar makes sense.
		r_file::path($tar, true);
		if (!$tar || !preg_match('/\.tar(\.gz)?$/i', $tar)) {
			static::log('Invalid tar archive.', 'error');
		}

		// Lightly sanitize the out too.
		r_file::path($out, false);
		if (!$out) {
			static::log('Invalid output location.', 'error');
		}
		// Delete the directory if it exists.
		if (is_dir($out)) {
			v_file::rmdir($out);
		}

		// We might have to extract it first.
		if ('.gz' === substr($tar, -3)) {
			try {
				if ($gz_handle = gzopen($tar, 'rb')) {
					$tar = substr($tar, 0, -3);
					if ($tar_handle = fopen($tar, 'wb')) {
						while (!gzeof($gz_handle)) {
							fwrite($tar_handle, gzread($gz_handle, 4096));
						}
						fclose($tar_handle);
						gzclose($gz_handle);
					}
					else {
						utility::log('A decompressed tar could not be saved.', 'error');
					}
				}
				else {
					utility::log('The archive could not be decompressed.', 'error');
				}
			} catch (Throwable $e) {
				utility::log('The archive could not be decompressed.', 'error');
			}
		}

		try {
			$phar = new PharData($tar);
			$phar->extractTo($out);
			return true;
		} catch (Throwable $e) {
			static::log('The archive could not be extracted.', 'error');
		}
	}

	// ----------------------------------------------------------------- end package



	// -----------------------------------------------------------------
	// CLI
	// -----------------------------------------------------------------

	/**
	 * Prompt
	 *
	 * Prompt for response.
	 *
	 * @param string $question Question.
	 * @param bool $required Require answer.
	 * @param string $default Default.
	 * @return bool|string Answer or false.
	 */
	public static function prompt(string $question, bool $required=false, string $default='') {
		if ($default) {
			$question .= " \033[2m[$default]\033[0m";
		}
		r_sanitize::whitespace($question);

		// Ask.
		$answer = '';
		while (!$answer) {
			static::log($question);
			echo '      ';
			if ($handle = fopen('php://stdin', 'r')) {
				$answer = fgets($handle);
				r_sanitize::whitespace($answer);
				if (!$answer && $default) {
					$answer = $default;
				}

				fclose($handle);
			}
			else {
				return false;
			}

			// Don't loop forever.
			if (!$required) {
				break;
			}
		}

		return $answer ? $answer : false;
	}

	/**
	 * Log
	 *
	 * Output something to the terminal.
	 *
	 * @param string $line Line.
	 * @param string $style Style.
	 * @param bool $bullet Bullet.
	 * @return void Nothing.
	 */
	public static function log(string $line, string $style='', bool $bullet=true) {
		switch ($style) {
			case 'header':
				$divider = "\033[2m" . str_repeat('-', 50) . "\033[0m";
				r_mb::strtoupper($line);
				$line = "\n$divider\n\033[34;1m$line\033[0m\n$divider";
				break;
			case 'error':
				$line = "\033[31;1mError:\033[0m $line";
				break;
			case 'warning':
				$line = "\033[33;1mWarning:\033[0m $line";
				break;
			case 'success':
				$line = "\033[32;1mSuccess:\033[0m $line";
				break;
		}

		// Add bullet?
		if ($bullet && ('header' !== $style)) {
			$line = "   \033[2m++\033[0m $line";
		}

		echo "$line\n";

		// Die on error.
		if ('error' === $style) {
			exit(1);
		}
	}

	/**
	 * Progress Reporting
	 *
	 * A poor man's progress indicator: just pass a percent in decimal
	 * notation (e.g. a fraction between 0 and 1) and it will print out
	 * as a percent. Call the function again and the output will replace
	 * the prior output. In other words, it looks kinda animated.
	 *
	 * @param float $percent Percent (0-1).
	 * @return void Nothing.
	 */
	public static function progress(float $percent) {
		r_sanitize::to_range($percent, 0.0, 100.0);
		$percent *= 100;
		$percent = round($percent);
		$percent = str_pad("$percent%", 4, ' ', STR_PAD_RIGHT);

		echo "   \033[2m++\033[0m $percent\r";
	}

	// ----------------------------------------------------------------- end cli
}
