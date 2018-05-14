<?php
/**
 * BOB: Build
 *
 * This is just a base script. The idea is to extend it to actually have
 * it do something useful.
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob\base;

use \blobfolio\bob\format;
use \blobfolio\bob\log;
use \blobfolio\bob\io;
use \blobfolio\common\cli;
use \blobfolio\common\data;
use \blobfolio\common\file as v_file;
use \blobfolio\common\ref\file as r_file;
use \blobfolio\common\ref\mb as r_mb;

abstract class mike {
	// Project Name.
	const NAME = '';
	const DESCRIPTION = '';
	const CONFIRMATION = '';

	// Runtime requirements.
	const ROOT = false;						// Root or not root.
	const REQUIRED_CLASSES = array();		// Required PHP classes.
	const REQUIRED_EXTENSIONS = array();	// Required PHP modules.
	const REQUIRED_FUNCTIONS = array();		// Required functions.

	const REQUIRED_DOWNLOADS = array();		// Required remote files.
	const REQUIRED_FILES = array();			// Required files.

	// Automatic setup.
	const CLEAN_ON_SUCCESS = false;			// Delete tmp/bob when done.

	// Functions to run to complete the build, in order, grouped by
	// heading.
	const ACTIONS = array();

	// Miscellaneous settings.
	const CACHE_LIFETIME = 7200;			// Time to cache download.
	const REMOTE_CHUNK = 50;				// URLs to pull en masse.

	protected static $_binaries = array();	// Initialized binaries.
	protected static $_cache_time;			// Local filesystem time.
	protected static $_downloads = array();	// Downloaded files.

	protected static $_tmp_dir;				// Temporary files.
	protected static $_working_dir;			// Working files.



	// -----------------------------------------------------------------
	// Setup
	// -----------------------------------------------------------------

	/**
	 * Compile
	 *
	 * @return void Nothing.
	 */
	public static function compile() {
		$start = microtime(true);
		$class = get_called_class();
		static::pre_compile();

		// Check requirements.
		static::_check_requirements();
		static::check_requirements();

		// Print the intro.
		if (static::NAME) {
			log::title(static::NAME);

			if (static::DESCRIPTION) {
				log::print(static::DESCRIPTION, false);
				echo "\n";
			}

			if (static::CONFIRMATION) {
				log::print(static::CONFIRMATION, false);
				echo "\n";

				if (!log::confirm('Continue building?', true)) {
					log::warning('Bob aborted.');
					exit(1);
				}
			}
		}

		// Make sure required files are present.
		foreach (static::REQUIRED_FILES as $v) {
			io::require_file($v);
		}

		// Download requirements.
		if (count(static::REQUIRED_DOWNLOADS)) {
			log::print('Downloading required file(s)…');
			static::download(static::SETUP_DOWNLOADS);
		}

		// All rightie, run through our list-o-actions!
		foreach (static::ACTIONS as $title=>$actions) {
			log::title($title);

			foreach ($actions as $action) {
				if (!method_exists($class, $action)) {
					log::error("Invalid method: $action");
				}

				// Call it.
				static::$action();
			}
		}

		// Clean up!
		if (static::CLEAN_ON_SUCCESS) {
			log::title('Cleaning up');
			static::_clean();
			static::clean();
		}

		// We're done!
		$end = microtime(true);
		$elapsed = round($end - $start, 3);
		$memory = memory_get_peak_usage(true);

		log::title('Done!');
		log::info('Finished in ' . format::time($elapsed) . '.');
		log::info('Used ' . format::bytes($memory) . '.');

		static::post_compile();

		echo "\n\n";
	}

	/**
	 * Runtime Requirements
	 *
	 * Quickly run through any items enumerated in the class constants.
	 *
	 * @return void Nothing.
	 */
	protected static function _check_requirements() {
		// Always require CLI connections.
		if (!cli::is_cli()) {
			log::error('Bob must be run in CLI mode.', false);
		}

		// Require root.
		if (static::ROOT && !cli::is_root()) {
			log::error('A root user must run this build.', false);
		}
		// Require non-root.
		elseif (!static::ROOT && cli::is_root()) {
			log::error('A non-root user must run this build.', false);
		}

		// Required classes.
		foreach (static::REQUIRED_CLASSES as $v) {
			if (!class_exists($v)) {
				log::error("Missing class: $v", false);
			}
		}

		// Required extensions.
		foreach (static::REQUIRED_EXTENSIONS as $v) {
			if (!extension_loaded($v)) {
				log::error("Missing extension: $v", false);
			}
		}

		// Required functions.
		foreach (static::REQUIRED_FUNCTIONS as $v) {
			if (!function_exists($v)) {
				log::error("Missing function: $v", false);
			}
		}
	}

	/**
	 * Clean Up
	 *
	 * @return void Nothing.
	 */
	protected static function _clean() {
		if (static::$_tmp_dir && is_dir(static::$_tmp_dir)) {
			v_file::rmdir(static::$_tmp_dir);
		}
	}

	// ----------------------------------------------------------------- end setup



	// -----------------------------------------------------------------
	// Overload Methods
	// -----------------------------------------------------------------

	/**
	 * Overload: Pre-Compile
	 *
	 * Run specific code before any build actions have been taken.
	 *
	 * @return void Nothing.
	 */
	protected static function pre_compile() {
	}

	/**
	 * Overload: Runtime Requirements
	 *
	 * Run any additional compatibility checks before build operations
	 * kick off.
	 *
	 * @return void Nothing.
	 */
	protected static function check_requirements() {
	}

	/**
	 * Overload: Clean
	 *
	 * Runs after default cleaning has cleaned.
	 *
	 * @return void Nothing.
	 */
	protected static function clean() {
	}

	/**
	 * Overload: Post-Compile
	 *
	 * Run specific code after all build actions have completed.
	 *
	 * @return void Nothing.
	 */
	protected static function post_compile() {
	}

	// ----------------------------------------------------------------- end overload



	// -----------------------------------------------------------------
	// Filesystem
	// -----------------------------------------------------------------

	/**
	 * Get Tmp Dir
	 *
	 * First and foremost, find a suitable place to write temporary
	 * data.
	 *
	 * For convenience, a relative file path can be submitted, in which
	 * case the result will append that to the temporary directory. This
	 * does not validate the path; it just saves subsequent
	 * concatenation.
	 *
	 * @param string $file Relative file.
	 * @return string Directory and/or path to file.
	 */
	public static function get_tmp_dir(string $file='') {
		// Do we need to set one?
		if (is_null(static::$_tmp_dir)) {
			static::$_tmp_dir = sys_get_temp_dir();
			r_file::trailingslash(static::$_tmp_dir, true);
			if (!static::$_tmp_dir) {
				log::error('Could not find temporary directory.');
			}

			// Throw our files into a subdirectory.
			static::$_tmp_dir .= 'bob/';
			if (!is_dir(static::$_tmp_dir)) {
				v_file::mkdir(static::$_tmp_dir, 0777);
				if (!is_dir(static::$_tmp_dir)) {
					log::error('Could not create temporary directory.');
				}
			}
		}

		// Return the path for a file?
		r_mb::trim($file);
		if ($file) {
			r_file::unleadingslash($file);
			return static::$_tmp_dir . $file;
		}

		// Just the path, ma'am.
		return static::$_tmp_dir;
	}

	/**
	 * Make Directory
	 *
	 * Generate a unique directory within our temporary path.
	 *
	 * @return string Path.
	 */
	public static function make_dir() {
		$dir = static::get_tmp_dir(data::random_string(5));
		while (file_exists($dir)) {
			$dir = static::get_tmp_dir(data::random_string(5));
		}

		// Actually make it.
		v_file::mkdir($dir, 0755);
		return $dir;
	}

	/**
	 * Make File
	 *
	 * Generate a unique file within the temporary path.
	 *
	 * @return string Path.
	 */
	public static function make_file() {
		$file = static::get_tmp_dir(data::random_string(5));
		while (file_exists($file)) {
			$file = static::get_tmp_dir(data::random_string(5));
		}

		// Actually make it.
		touch($file);
		chmod($file, 0644);
		return $file;
	}

	// ----------------------------------------------------------------- end files



	// -----------------------------------------------------------------
	// Cache
	// -----------------------------------------------------------------

	/**
	 * Cache Path
	 *
	 * Convert a URL to a local file name.
	 *
	 * @param string $url URL.
	 * @return bool|string Path or false.
	 */
	protected static function _get_cache_path(string $url) {
		// Copy the URL to another variable for sanitizing so we can
		// check whether or not changes were made.
		$tmp = $url;
		r_sanitize::url($tmp);
		if (!$tmp || ($tmp !== $url)) {
			log::error("Invalid URL: $url");
		}

		// No need to overthink it.
		return static::get_tmp_dir(md5($tmp));
	}

	/**
	 * Is Cached
	 *
	 * @param string $url URL.
	 * @return bool True/false.
	 */
	protected static function _is_cached(string $url) {
		$file = static::_get_cache_path($url);
		if (!is_file($file)) {
			return false;
		}

		// Establish what time the local filesystem thinks it is.
		if (is_null(static::$_cache_time)) {
			clearstatcache();
			$test = static::make_file();
			file_put_contents($test, 'Hello World');
			static::$_cache_time = (int) filemtime($test);
			static::$_cache_time -= static::CACHE_LIFETIME;
			unlink($test);
			clearstatcache();
		}

		// If the file is older than our cache life, delete it.
		$file_age = (int) filemtime($file);
		if ($file_age < static::$_cache_time) {
			unlink($file);
			return false;
		}

		// Make sure the path is in our downloads list.
		static::$_downloads[$url] = $file;

		return true;
	}

	/**
	 * Get Cache
	 *
	 * @param string $url URL.
	 * @return mixed Contents or false.
	 */
	protected static function _get_cache(string $url) {
		if (!static::_is_cached($url)) {
			return false;
		}

		$file = static::_get_cache_path($url);
		return file_get_contents($file);
	}

	/**
	 * Save Cache
	 *
	 * @param string $url URL.
	 * @param mixed $content Content.
	 * @return bool True/false.
	 */
	protected static function _save_cache(string $url, $content) {
		$file = static::_get_cache_path($url);

		file_put_contents($file, $content);
		if (!is_file($file)) {
			return false;
		}

		// Make sure the path is in our downloads list.
		static::$_downloads[$url] = $file;

		chmod($file, 0644);
		return true;
	}

	// ----------------------------------------------------------------- end cache



	// -----------------------------------------------------------------
	// Remote Files
	// -----------------------------------------------------------------

	/**
	 * Get Remote
	 *
	 * Download one or more files in parallel.
	 *
	 * @param array $urls URLs.
	 * @return array Map of URLs to local paths.
	 */
	public static function download($urls) {
		if (!function_exists('curl_multi_init')) {
			log::error('Missing extension: CURL');
		}

		// This will hold the local paths for any URLs we fetch.
		$out = array();

		// Sanitize the URL list.
		r_cast::array($urls);
		foreach ($urls as $k=>$v) {
			r_sanitize::url($urls[$k]);
			if ($urls[$k] !== $v) {
				log::error("Invalid URL: {$urls[$k]}");
			}

			// If it is already in cache, we don't need to download it.
			if (static::_is_cached($urls[$k])) {
				$out[$urls[$k]] = static::_get_cache_path($urls[$k]);
				unset($urls[$k]);
			}
		}

		// If everything was in cache, we're done!
		if (!count($urls)) {
			ksort($out);
			return $out;
		}

		sort($urls);

		// Process the URLs in chunks.
		$urls = array_chunk($urls, static::REMOTE_CHUNK);
		foreach ($urls as $chunk) {
			$multi = curl_multi_init();
			$curls = array();

			// Set up curl request for each URL.
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
					// Save a local copy.
					static::_save_cache($url, curl_multi_getcontent($curls[$url]));

					// Add path details to our response.
					$out[$url] = static::_get_cache_path($url);
				}
				else {
					log::error("Download failed: $url.");
				}

				curl_multi_remove_handle($multi, $curls[$url]);
			}

			curl_multi_close($multi);
		}

		// Return what we've got!
		ksort($out);
		return $out;
	}

	/**
	 * Get Remote File
	 *
	 * Download if needed and return the content of a file.
	 *
	 * @param string $url URL.
	 * @return mixed Content.
	 */
	public static function get_url(string $url) {
		$tmp = static::download($url);
		return file_get_contents($tmp[$url]);
	}

	// ----------------------------------------------------------------- end remote files
}
