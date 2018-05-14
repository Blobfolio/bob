<?php
/**
 * BOB: Builder
 *
 * This is a bare-bones base builder class. Projects should extend this
 * or one of the more specific base builder classes.
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob\base;

use \blobfolio\bob\format;
use \blobfolio\bob\io;
use \blobfolio\bob\log;
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
	const SLUG = '';

	// Runtime requirements.
	const ROOT = false;						// Root or not root.
	const REQUIRED_CLASSES = array();		// Required PHP classes.
	const REQUIRED_EXTENSIONS = array();	// Required PHP modules.
	const REQUIRED_FUNCTIONS = array();		// Required functions.

	const REQUIRED_DOWNLOADS = array();		// Required remote files.
	const REQUIRED_FILES = array();			// Required files.

	// Automatic setup.
	const CLEAN_ON_SUCCESS = false;			// Delete tmp/bob when done.
	const SHITLIST = null;					// Specific shitlist.

	// Functions to run to complete the build, in order, grouped by
	// heading.
	const ACTIONS = array();

	protected static $_working_dir;



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

		// Download requirements.
		if (count(static::REQUIRED_DOWNLOADS)) {
			log::print('Downloading required file(s)…');
			static::pre_required_downloads();
			io::download(static::REQUIRED_DOWNLOADS);
			static::post_required_downloads();
		}

		// Make sure required files are present.
		static::pre_required_files();
		foreach (static::REQUIRED_FILES as $v) {
			io::require_file($v);
		}
		static::post_required_files();

		static::pre_actions();

		// All rightie, run through our list-o-actions!
		foreach (static::ACTIONS as $title=>$actions) {
			log::title($title);

			foreach ($actions as $action) {
				// Some miscellaneous callable source.
				if (is_callable($action)) {
					$action();
				}
				// A local method.
				elseif (method_exists($class, $action)) {
					static::$action();
				}
				else {
					log::error("Invalid method: $action");
				}
			}
		}

		static::post_actions();

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
		v_file::rmdir(io::get_tmp_dir());
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
	 * Overload: Pre-Downloads
	 *
	 * Run specific code before automatic downloading.
	 *
	 * @return void Nothing.
	 */
	protected static function pre_required_downloads() {
	}

	/**
	 * Overload: Post-Downloads
	 *
	 * Run specific code after automatic downloading.
	 *
	 * @return void Nothing.
	 */
	protected static function post_required_downloads() {
	}

	/**
	 * Overload: Pre-Files
	 *
	 * Run specific code before automatic file checks.
	 *
	 * @return void Nothing.
	 */
	protected static function pre_required_files() {
	}

	/**
	 * Overload: Post-Files
	 *
	 * Run specific code after automatic file checks.
	 *
	 * @return void Nothing.
	 */
	protected static function post_required_files() {
	}

	/**
	 * Overload: Pre-Actions
	 *
	 * Runs before compile loops over static::ACTIONS.
	 *
	 * @return void Nothing.
	 */
	protected static function pre_actions() {
	}

	/**
	 * Overload: Post-Actions
	 *
	 * Runs after compile loops over static::ACTIONS.
	 *
	 * @return void Nothing.
	 */
	protected static function post_actions() {
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

	/**
	 * Overload: Make Working Directory
	 *
	 * Make a working directory. This isn't automatically called by
	 * Mike, though some child classes might use it.
	 *
	 * @param string $seed Seed with source file(s).
	 * @return string Path.
	 */
	protected static function make_working_dir(string $seed='') {
		if (is_null(static::$_working_dir)) {
			static::$_working_dir = io::make_dir();

			// Seed with files.
			if ($seed && is_dir($seed)) {
				io::copy($seed, static::$_working_dir);
			}
		}

		return static::$_working_dir;
	}

	// ----------------------------------------------------------------- end overload
}
