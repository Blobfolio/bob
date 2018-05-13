<?php
/**
 * BOB: Build
 *
 * This will update dependencies, optimize the autoloader, and
 * optionally generate a new release zip.
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob\base;

use \blobfolio\bob\utility;
use \blobfolio\common\cli;
use \blobfolio\common\file as v_file;
use \blobfolio\common\ref\file as r_file;

abstract class build {
	// The package name.
	const NAME = '';

	// Does this require root?
	const ROOT = false;

	// Runtime requirements.
	const REQUIRED_CLASSES = array();
	const REQUIRED_FUNCTIONS = array();
	const REQUIRED_EXTENSIONS = array();

	const BINARIES = array();		// Binary objects.
	const DOWNLOADS = array();		// Remote URLs to fetch.
	const FILES = array();			// Local files to be expected.

	// Source information.
	const SOURCE_DIR = '';
	const COMPOSER_CONFIG = '';
	const PHPAB_AUTOLOADER = '';

	// Release information.
	const RELEASE_TYPE = '';		// Either: zip, deb, copy.
	const RELEASE_OUT = '';
	const RELEASE_ZIP_SUBDIR = '';	// Zip files in a subdir or not.

	// Additional source files not to copy when packaging.
	const SHITLIST = array();

	// Skip unnecessary steps.
	const SKIP_BINARY_DEPENDENCIES = false;
	const SKIP_BUILD = false;
	const SKIP_FILE_DEPENDENCIES = false;
	const SKIP_PACKAGE = false;

	// Binaries we might be using.
	protected static $deps = array();

	// The working directory (if we've copied stuff).
	protected static $working_dir;

	// Files that were automatically downloaded.
	protected static $downloads;



	// -----------------------------------------------------------------
	// Setup
	// -----------------------------------------------------------------

	/**
	 * Make it!
	 *
	 * @return void Nothing.
	 */
	public static function compile() {
		$start = microtime(true);

		// Make sure we can run!
		static::runtime_checks();
		static::runtime_extra();

		// Get our dependencies.
		if (!static::SKIP_BINARY_DEPENDENCIES || count(static::BINARIES)) {
			utility::log('BINARY DEPENDENCIES', 'header');
			static::pre_get_binaries();
			static::get_binaries();
			static::post_get_binaries();
		}

		// Download remote files.
		if (count(static::DOWNLOADS)) {
			utility::log('REMOTE SOURCES', 'header');
			static::$downloads = utility::get_remote(static::DOWNLOADS);
			foreach (static::DOWNLOADS as $v) {
				utility::log("{$v}…");
				if (!isset(static::$downloads[$v]) || !static::$downloads[$v]) {
					utility::log("Failed to download $v.", 'error');
				}
			}
		}

		// Make sure we have our files.
		if (!static::SKIP_FILE_DEPENDENCIES || count(static::FILES)) {
			utility::log('FILE DEPENDENCIES', 'header');
			static::pre_get_files();
			static::get_files();
			static::post_get_files();
		}

		// Build the project.
		if (!static::SKIP_BUILD) {
			utility::log('BUILDING ' . strtoupper(static::NAME), 'header');
			static::pre_build_tasks();
			static::build_tasks();
			static::post_build_tasks();
		}

		// Package release.
		if (!static::SKIP_PACKAGE) {
			utility::log('PACKAGING ' . strtoupper(static::NAME), 'header');
			static::make_working();
			static::pre_package();
			static::package();
			static::post_package();

			// Build release.
			utility::log('CLEANING UP', 'header');
			static::pre_clean();
			static::clean();
			static::post_clean();
		}

		// We're done!
		$end = microtime(true);
		$elapsed = round($end - $start, 3);
		utility::log('DONE!', 'header');
		utility::log("Finished in $elapsed seconds.", 'success');
		echo "\n\n";
	}

	/**
	 * Runtime Checks.
	 *
	 * @return void Nothing.
	 */
	protected static function runtime_checks() {
		// Require CLI mode.
		if (!cli::is_cli()) {
			utility::log('This must be run in CLI mode.', 'error', false);
		}

		// Require root.
		if (static::ROOT && !cli::is_root()) {
			utility::log('This script must be run as root.', 'error', false);
		}
		// Forbid root.
		elseif (!static::ROOT && cli::is_root()) {
			utility::log('Do not run this script as root.', 'error', false);
		}

		// Required functions?
		foreach (static::REQUIRED_FUNCTIONS as $v) {
			if (!function_exists($v)) {
				utility::log("Missing required function: $v", 'error', false);
			}
		}

		// Required extensions?
		foreach (static::REQUIRED_EXTENSIONS as $v) {
			if (!extension_loaded($v)) {
				utility::log("Missing required extension: $v", 'error', false);
			}
		}

		// Required classes?
		foreach (static::REQUIRED_CLASSES as $v) {
			if (!class_exists($v)) {
				utility::log("Missing required class: $v", 'error', false);
			}
		}

		// Make sure defined things exist.
		if (static::SOURCE_DIR && !is_dir(static::SOURCE_DIR)) {
			utility::log('Invalid source directory.', 'error', false);
		}
		if (static::COMPOSER_CONFIG && !is_file(static::COMPOSER_CONFIG)) {
			utility::log('Invalid Composer file.', 'error', false);
		}
		if (static::PHPAB_AUTOLOADER && !is_dir(dirname(static::PHPAB_AUTOLOADER))) {
			utility::log('Invalid phpab autoloader location.', 'error', false);
		}
	}

	/**
	 * Additional Runtime Checks
	 *
	 * @return void Nothing.
	 */
	protected static function runtime_extra() {
	}

	// ----------------------------------------------------------------- end setup



	// -----------------------------------------------------------------
	// Fetch Dependencies
	// -----------------------------------------------------------------

	/**
	 * Pre-Binaries
	 *
	 * This runs before automatic binary population.
	 *
	 * @return void Nothing.
	 */
	protected static function pre_get_binaries() {
	}

	/**
	 * Get Binaries
	 *
	 * @return void Nothing.
	 */
	protected static function get_binaries() {
		foreach (static::BINARIES as $v) {
			switch ($v) {
				case 'composer':
				case 'dpkg':
				case 'git':
				case 'phpab':
				case 'grunt':
					$v = '\\blobfolio\\bob\\binary\\' . $v;
			}

			// The class must exist.
			if (!class_exists($v)) {
				utility::log("Invalid binary: $v", 'error', false);
			}

			$nicename = $v;
			r_file::unixslash($nicename);
			if (false !== strpos($nicename, '/')) {
				r_file::untrailingslash($nicename);
				r_file::unleadingslash($nicename);
				$nicename = basename($nicename);
			}
			static::$deps[$nicename] = new $v();
		}
	}

	/**
	 * Post-Binaries
	 *
	 * This runs after automatic binary population.
	 *
	 * @return void Nothing.
	 */
	protected static function post_get_binaries() {
	}

	/**
	 * Pre-Files
	 *
	 * This runs before required file checks.
	 *
	 * @return void Nothing.
	 */
	protected static function pre_get_files() {
	}

	/**
	 * Get Files
	 *
	 * @return void Nothing.
	 */
	protected static function get_files() {
		$tmp = utility::get_tmp_dir();

		foreach (static::FILES as $v) {
			$v = str_replace('%TMP%', $tmp, $v);
			utility::require_file($v);
		}
	}

	/**
	 * Post-Files
	 *
	 * This runs after automatic file checks.
	 *
	 * @return void Nothing.
	 */
	protected static function post_get_files() {
	}

	// ----------------------------------------------------------------- end deps



	// -----------------------------------------------------------------
	// Build
	// -----------------------------------------------------------------

	/**
	 * Pre-Build Tasks
	 *
	 * @return void Nothing.
	 */
	protected static function pre_build_tasks() {
	}

	/**
	 * Build Tasks
	 *
	 * @return void Nothing.
	 */
	protected static function build_tasks() {

	}

	/**
	 * Post-Build Tasks
	 *
	 * @return void Nothing.
	 */
	protected static function post_build_tasks() {
	}

	// ----------------------------------------------------------------- end build



	// -----------------------------------------------------------------
	// Package
	// -----------------------------------------------------------------

	/**
	 * Make Working Directory
	 *
	 * @return void Nothing.
	 */
	protected static function make_working() {
		if (!static::$working_dir) {
			// Copy the source directory.
			if (static::SOURCE_DIR) {
				static::$working_dir = utility::get_tmp_dir() . basename(static::SOURCE_DIR) . '/';
				utility::copy(static::SOURCE_DIR, static::$working_dir);
			}
			// If there is no source directory, go ahead and make a
			// randomly-named subdirectory for use.
			else {
				static::$working_dir = utility::generate_tmp_dir();
			}
		}
	}

	/**
	 * Pre-Package Tasks
	 *
	 * @return void Nothing.
	 */
	protected static function pre_package() {
	}

	/**
	 * Package Tasks
	 *
	 * @return void Nothing.
	 */
	protected static function package() {
		// There's nothing to package if there's no output destination.
		if (!static::RELEASE_OUT) {
			return;
		}

		// We can't package if there are no working files.
		if (!static::$working_dir || !is_dir(static::$working_dir)) {
			return;
		}

		switch (static::RELEASE_TYPE) {
			case 'zip':
				utility::zip(static::RELEASE_OUT, static::$working_dir, static::RELEASE_ZIP_SUBDIR);
				break;
			case 'deb':
				utility::deb(static::$working_dir, static::RELEASE_OUT);
				break;
			default:
				utility::log('Copying files…');
				if (is_dir(static::RELEASE_OUT)) {
					v_file::rmdir(static::RELEASE_OUT);
				}
				v_file::copy(static::$working_dir, static::RELEASE_OUT);
		}
	}

	/**
	 * Post-Package Tasks
	 *
	 * @return void Nothing.
	 */
	protected static function post_package() {
	}

	// ----------------------------------------------------------------- end package



	// -----------------------------------------------------------------
	// Clean-up
	// -----------------------------------------------------------------

	/**
	 * Pre-Clean Tasks
	 *
	 * @return void Nothing.
	 */
	protected static function pre_clean() {
	}

	/**
	 * Clean Tasks
	 *
	 * @return void Nothing.
	 */
	protected static function clean() {
		if (static::$working_dir && file_exists(static::$working_dir)) {
			utility::log('Removing working directory…');
			v_file::rmdir(static::$working_dir);
		}
	}

	/**
	 * Post-Clean Tasks
	 *
	 * @return void Nothing.
	 */
	protected static function post_clean() {
	}

	// ----------------------------------------------------------------- end cleanup
}
