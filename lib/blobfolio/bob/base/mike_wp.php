<?php
/**
 * BOB: Builder WP Plugin
 *
 * This base builder is tailored for releasing WordPress plugins.
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
use \blobfolio\common\mb as v_mb;
use \blobfolio\common\ref\file as r_file;
use \blobfolio\common\ref\mb as r_mb;

abstract class mike_wp extends mike {
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
	const USE_IO_SHITLIST = true;			// Merge specific with global.

	// Functions to run to complete the build, in order, grouped by
	// heading.
	const ACTIONS = array(
		'Updating Plugin Source'=>array(
			'_build_update_source',
			'_patch_version',
		),
		'Packaging Release'=>array(
			'_build_working',
			'_release_compress',
			'_build_release',
		),
	);

	// Most plugin builds run these.
	const USE_COMPOSER = true;				// Composer.
	const USE_GRUNT = 'build';				// A specific Grunt task.
	const USE_PHPAB = true;					// PHPAB.

	// Some plugins need to re-namespace third-party libraries to avoid
	// collisions.
	const PATCH_CLASSES = false;			// Patch classes?
	const NAMESPACE_SWAP = '';				// Move blobfolio libs.
	const NAMESPACELESS = array();			// Namespaces for globals.

	// Build a release, either zip or copy.
	const RELEASE_TYPE = 'zip';

	// Compress PHP files in these (relative) directories.
	const RELEASE_COMPRESS = array();

	protected static $_working_dir;			// Working files.
	protected static $_version;

	// A few misc variables for us.
	protected static $replacements;
	protected static $has_ns;



	// -----------------------------------------------------------------
	// Setup
	// -----------------------------------------------------------------

	/**
	 * Build: Update Source
	 *
	 * @return void Nothing.
	 */
	protected static function _build_update_source() {
		// Make sure we have a slug.
		if (!static::SLUG) {
			log::error('The plugin slug must be defined.');
		}

		// Update Composer.
		if (static::USE_COMPOSER) {
			io::composer_install(static::get_plugin_dir(), static::get_composer_path());
		}

		// Run Grunt build task.
		if (static::USE_GRUNT) {
			io::grunt_task(static::get_plugin_dir(), static::USE_GRUNT);
		}

		// If we're patching classes, PHPAB will get run anyway.
		if (static::PATCH_CLASSES) {
			static::_patch_classes();
		}
		// Otherwise run PHPAB on its own.
		elseif (static::USE_PHPAB) {
			io::phpab_autoload(static::get_plugin_dir(), static::get_phpab_path());
		}

		// Extra user steps.
		static::build_update_source();
	}

	/**
	 * Overload: Build Source
	 *
	 * @return void Nothing.
	 */
	protected static function build_update_source() {
	}

	/**
	 * Build: Working Dir
	 *
	 * @return void Nothing.
	 */
	protected static function _build_working() {
		log::print('Setting up working directory…');
		static::make_working_dir(static::get_plugin_dir());

		log::print('Cleaning up clone…');
		io::prune(static::$_working_dir, static::get_shitlist());

		static::build_working();
	}

	/**
	 * Overload: Working Dir
	 *
	 * @return void Nothing.
	 */
	protected static function build_working() {
	}

	/**
	 * Build: Release
	 *
	 * @return void Nothing.
	 */
	protected static function _build_release() {
		if ('zip' === static::RELEASE_TYPE) {
			io::zip(static::$_working_dir, static::get_release_path(), static::SLUG);
		}
		elseif ('copy' === static::RELEASE_TYPE) {
			$path = static::get_release_path();
			if (is_dir($path)) {
				v_file::rmdir($path);
			}

			// Copy and delete because "move" isn't an option. Haha.
			log::print('Copying one last time…');
			v_file::copy(static::$_working_dir, static::get_release_path());
		}

		static::build_release();

		log::print('Removing working directory…');
		v_file::rmdir(static::$_working_dir);
		static::$_working_dir = null;
	}

	/**
	 * Overload: Build Release
	 *
	 * @return void Nothing.
	 */
	protected static function build_release() {
	}

	/**
	 * Patch Version
	 *
	 * @return void Nothing.
	 */
	protected static function _patch_version() {
		// Start by finding the current version.
		static::$_version = '';
		$plugin_dir = static::get_plugin_dir();

		// Parse the current version.
		$file = static::get_plugin_dir() . 'index.php';
		if (!is_file($file)) {
			log::error('Could not locate main plugin file.');
		}
		$content = file_get_contents($file);
		if (!preg_match('/^ \* @version\s+([\d\.]+)/m', $content, $match)) {
			log::error('Could not parse the current plugin version.');
		}
		$version = trim($match[1]);

		// Ask for a new one.
		static::$_version = log::prompt('Enter a version number:', $version);
		while (!preg_match('/^\d+\.\d+\.\d+$/', static::$_version)) {
			log::warning('Use semantic versioning, e.g. 1.2.3.');
			static::$_version = log::prompt('Enter a version number:', $version);
		}

		log::print('Patching version…');

		// Start by patching the index.
		$content = preg_replace(
			'/^ \* @version\s+([\d\.]+)/m',
			' * @version ' . static::$_version,
			$content
		);
		$content = preg_replace(
			'/^ \* Version:\s+([\d\.]+)/m',
			' * Version: ' . static::$_version,
			$content
		);

		// Save it.
		file_put_contents($file, $content);

		// There are also potentiall a handful of JSON files to update.
		$files = array(
			static::get_composer_path(),
			static::get_plugin_dir() . 'composer.json',
		);
		if ('zip' === static::RELEASE_TYPE) {
			$files[] = substr(static::get_release_path(), 0, -4) . '.json';
		}
		foreach ($files as $file) {
			if (!$file || !is_file($file)) {
				continue;
			}

			$content = trim(file_get_contents($file));
			$content = json_decode($content, true);

			$changed = false;

			// Version many ways.
			foreach (array('version', 'Version', 'VERSION') as $key) {
				if (isset($content[$key])) {
					$changed = true;
					$content[$key] = static::$_version;
				}
			}

			if ($changed) {
				file_put_contents($file, json_encode($content, JSON_PRETTY_PRINT));
			}
		}

		// Let projects patch more!
		static::patch_version();
	}

	/**
	 * Overload: Patch Version
	 *
	 * @return void Nothing.
	 */
	protected static function patch_version() {
	}

	/**
	 * Compress PHP
	 *
	 * @return void Nothing.
	 */
	protected static function _release_compress() {
		if (!empty(static::RELEASE_COMPRESS)) {
			log::print('Compressing PHP scripts…');
			$base = static::make_working_dir();

			foreach (static::RELEASE_COMPRESS as $v) {
				$dir = $base . ltrim($v, '/');
				io::compress_php($dir);
			}
		}
	}

	// ----------------------------------------------------------------- end setup



	// -----------------------------------------------------------------
	// Plugin Paths and Options
	// -----------------------------------------------------------------

	/**
	 * Get Source Directory
	 *
	 * This should be a path to the main plugin root.
	 *
	 * @return string Source.
	 */
	protected static function get_plugin_dir() {
		// Try to discover it. Individual builders can always override
		// this.
		$dirs = array('trunk', 'plugin', 'wp');
		if (static::SLUG) {
			$dirs[] = static::SLUG;
		}
		foreach ($dirs as $v) {
			$path = dirname(BOB_ROOT_DIR) . "/$v/";
			if (is_dir($path)) {
				break;
			}
		}

		return $path;
	}

	/**
	 * Get Skel Directory
	 *
	 * This should point to miscellaneous template files.
	 *
	 * @return string Source.
	 */
	protected static function get_skel_dir() {
		// Try to discover it. Individual builders can always override
		// this.
		$path = BOB_ROOT_DIR . 'skel/';
		if (!is_dir($path)) {
			$path = '';
		}

		return $path;
	}

	/**
	 * Get Composer Config
	 *
	 * @return string Source.
	 */
	protected static function get_composer_path() {
		$path = '';

		if (static::USE_COMPOSER) {
			// Try the skeleton directory.
			$root = static::get_skel_dir();
			if ($root) {
				$path = "{$root}composer.json";
				if (!is_file($path)) {
					$path = '';
				}
			}

			// But maybe it is just in the plugin directory.
			if (!$path) {
				$root = static::get_plugin_dir();
				if ($root && is_file("{$root}composer.json")) {
					$path = "{$root}composer.json";
				}
			}
		}

		return $path;
	}

	/**
	 * Get PHPAB Autoloader Path
	 *
	 * @return string Source.
	 */
	protected static function get_phpab_path() {
		$path = '';

		if (static::USE_PHPAB) {
			$root = static::get_plugin_dir();
			if ($root && is_dir("{$root}lib/")) {
				$path = "{$root}lib/autoload.php";
			}
		}

		return $path;
	}

	/**
	 * Get Release Path
	 *
	 * When building a zip, the path should end in .zip. When copying,
	 * it should be an empty directory.
	 *
	 * @return string Source.
	 */
	protected static function get_release_path() {
		// Start with the plugin.
		$path = static::get_plugin_dir();
		if (!$path) {
			return $path;
		}

		// Doing a zip?
		if ('zip' === static::RELEASE_TYPE) {
			// Probably not in build or plugins.
			$root = dirname(BOB_ROOT_DIR) . '/';

			// Look for a few common places.
			foreach (array('release', 'releases', 'repo') as $v) {
				if (is_dir("{$root}{$v}/")) {
					$path = "{$root}{$v}/";
					break;
				}
			}

			$path .= static::SLUG . '.zip';
		}
		// Copying files?
		elseif ('copy' === static::RELEASE_TYPE) {
			if ('trunk' === basename($path)) {
				$path = dirname(BOB_ROOT_DIR) . '/';
			}
			$path .= static::SLUG . '/';
		}
		else {
			$path = '';
		}

		return $path;
	}

	/**
	 * Get Shitlist
	 *
	 * @return array Shitlist.
	 */
	protected static function get_shitlist() {
		$shitlist = is_array(static::SHITLIST) ? static::SHITLIST : array();

		if (static::USE_IO_SHITLIST || !is_array(static::SHITLIST)) {
			$shitlist = array_merge($shitlist, static::SHITLIST);
			sort($shitlist);
		}

		return $shitlist;
	}

	/**
	 * Get Vendor Directory
	 *
	 * This should point to where third-party libraries are stored,
	 * usually lib/vendor.
	 *
	 * @return string Source.
	 */
	protected static function get_vendor_dir() {
		$path = static::get_plugin_dir();

		if (is_dir("{$path}lib/vendor/")) {
			$path = "{$path}lib/vendor/";
		}

		return $path;
	}

	// ----------------------------------------------------------------- end plugin



	// -----------------------------------------------------------------
	// Class Patching
	// -----------------------------------------------------------------

	/**
	 * Patch Classes
	 *
	 * @return void Nothing.
	 */
	protected static function _patch_classes() {
		$phpab = static::get_phpab_path();
		if (!static::USE_PHPAB || !$phpab) {
			log::error('Class patching requires PHPAB.');
		}

		// Re-run PHPAB just in case.
		io::phpab_autoload(static::get_plugin_dir(), static::get_phpab_path());

		// Set up some early paths.
		$vendor_dir = static::get_vendor_dir();
		if (!$vendor_dir) {
			log::error('Invalid vendor directory.');
		}
		$lib_dir = dirname($vendor_dir) . '/';

		if (!static::NAMESPACE_SWAP && !count(static::NAMESPACELESS)) {
			log::error('No namespace swaps or orphans have been specified.');
		}

		log::print('Parsing autoloader…');

		if (!is_file($phpab)) {
			log::error('Invalid PHPAB autoload file.');
		}
		$tmp = file_get_contents($phpab);
		if (
			(false !== ($start = strpos($tmp, '$classes = array'))) &&
			(false !== ($end = strpos($tmp, ');', $start)))
		) {
			$classes = substr($tmp, $start, ($end - $start + 2));
			// phpcs:disable
			eval($classes);
			// phpcs:enable
		}
		if (!isset($classes) || !is_array($classes)) {
			log::error('Could not parse autoloader.');
		}

		// Build list of replacement classes and files.
		$r_classes = array();
		$r_namespaces = array();
		$r_files = array();
		log::print('Patching classes…');
		foreach ($classes as $k=>$v) {
			// We only care about vendor classes.
			if (0 === strpos($v, '/vendor/')) {
				$r_files[] = $lib_dir . ltrim($v, '/');

				// Move Blobfolio vendor classes into the main
				// namespace.
				if (static::NAMESPACE_SWAP) {
					$r_class = str_replace('\\vendor\\blobfolio', '\\vendor', static::NAMESPACE_SWAP . $k);
					$r_classes[$k] = $r_class;
				}

				$ns = explode('\\', $k);
				if (count($ns) > 1) {
					array_pop($ns);
					$ns = implode('\\', $ns);

					// Again, move namespace for Blobfolio vendor files.
					if (static::NAMESPACE_SWAP) {
						$r_namespace = str_replace('\\vendor\\blobfolio', '\\vendor', static::NAMESPACE_SWAP . $ns);
					}
					$r_namespaces[$ns] = $r_namespace;
				}
			}
		}

		// Now patch the actual PHP files!
		if (count($r_files)) {
			log::print('Patching files…');
			foreach ($r_files as $file) {
				$content = file_get_contents($file);
				static::$replacements = 0;
				$nice = str_replace($vendor_dir, '', $file);

				// Replace namespaces.
				static::$has_ns = false;
				$content = preg_replace_callback(
					'/^\s*namespace\s+(\\\\)?([a-z0-9\\\\]+)\s*;/im',
					function ($matches) use($r_namespaces) {
						if (3 === count($matches) && isset($r_namespaces[$matches[2]])) {
							static::$has_ns = true;
							++static::$replacements;
							return "namespace {$r_namespaces[$matches[2]]};";
						}

						return $matches[0];
					},
					$content
				);

				// Replace use statements.
				$content = preg_replace_callback(
					'/^\s*use\s+(\\\\)?([a-z0-9\\\\]+)(\s.*)?;/imU',
					function ($matches) use($r_classes, $r_namespaces) {
						if (3 <= count($matches)) {
							$matches = array_pad($matches, 4, '');
							$matches[2] = ltrim($matches[2], '\\');
							if (isset($r_classes[$matches[2]])) {
								++static::$replacements;
								return "use \\{$r_classes[$matches[2]]}{$matches[3]};";
							}
							elseif (isset($r_namespaces[$matches[2]])) {
								++static::$replacements;
								return "use \\{$r_namespaces[$matches[2]]}{$matches[3]};";
							}
						}

						return $matches[0];
					},
					$content
				);

				// Supply missing namespaces.
				if (!static::$has_ns) {
					if (preg_match('/\b(class|interface|trait)\s+([a-z0-9]+)/im', $content, $matches)) {
						if (
							isset(static::NAMESPACELESS[$matches[2]]) &&
							(false !== ($start = v_mb::strpos($content, '<?php')))
						) {
							++static::$replacements;
							$content = v_mb::substr($content, 0, $start + 5) . "\n\nnamespace " . static::NAMESPACE_SWAP . static::NAMESPACELESS[$matches[2]] . ";\n\n" . v_mb::substr($content, $start + 5);
						}
					}
					else {
						log::error("Missing namespace: $nice");
					}
				}

				// Extra patching.
				static::$replacements += static::patch_classes($content);

				// Update the file.
				if (static::$replacements) {
					file_put_contents($file, $content);
					log::print("Patched: $nice \033[1m[" . static::$replacements . "]\033[0m");
				}
			}
		}

		// Re-run PHPAB to update classmap.
		io::phpab_autoload(static::get_plugin_dir(), static::get_phpab_path());
	}

	/**
	 * Overload: Class Patching
	 *
	 * @param string $content Content.
	 * @return int Replacements.
	 */
	protected static function patch_classes(string &$content) {
		return 0;
	}

	// ----------------------------------------------------------------- end class patching




}
