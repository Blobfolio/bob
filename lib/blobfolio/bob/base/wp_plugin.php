<?php
/**
 * BOB: Build WordPress Plugin
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob\base;

use \blobfolio\bob\utility;
use \blobfolio\common\cli;
use \blobfolio\common\file as v_file;

abstract class wp_plugin extends build {
	// The package name.
	const NAME = '';

	// The source directory.
	const SOURCE_DIR = '';

	// Composer file.
	const COMPOSER_CONFIG = '';
	// Grunt task.
	const GRUNT_TASK = '';
	// PHPAB file.
	const PHPAB_AUTOLOADER = '';

	// Namespace patching.
	const VENDOR_DIR = '';
	const NAMESPACE_SWAP = '';
	const NAMESPACELESS = array();

	// Type of release: zip, deb, copy.
	const RELEASE_TYPE = 'zip';
	// Where to save release.
	const RELEASE_OUT = '';
	// Compress any files?
	const RELEASE_COMPRESS = array();

	// Extra shitlist.
	const SHITLIST = array();

	// Binary dependencies. The values should be callable.
	const BINARIES = array(
		'composer',
		'grunt',
		'phpab',
	);

	// File dependencies. The values should be paths.
	const FILES = array();



	// -----------------------------------------------------------------
	// Build
	// -----------------------------------------------------------------

	/**
	 * Pre-Build Tasks
	 *
	 * @return void Nothing.
	 */
	protected static function pre_build_tasks() {
		// Composer.
		if (static::COMPOSER_CONFIG && isset(static::$deps['composer'])) {
			static::$deps['composer']->install(static::SOURCE_DIR, static::COMPOSER_CONFIG);
		}

		// Grunt.
		if (static::GRUNT_TASK && isset(static::$deps['grunt'])) {
			static::$deps['grunt']->run_task(static::SOURCE_DIR, static::GRUNT_TASK);
		}

		// Patch classes.
		static::patch_classes();
	}

	/**
	 * Patch Classes
	 *
	 * @return void Nothing.
	 */
	protected static function patch_classes() {
		// Run phpab first.
		if (static::PHPAB_AUTOLOADER && isset(static::$deps['phpab'])) {
			static::$deps['phpab']->generate(static::SOURCE_DIR, static::PHPAB_AUTOLOADER);
		}

		// No class patching needed.
		if (
			!static::VENDOR_DIR ||
			(!count(static::NAMESPACE_SWAP) && !count(static::NAMESPACELESS))
		) {
			return;
		}

		// Parse the autoloader to find out what classes exist.
		if (is_file(static::PHPAB_AUTOLOADER)) {
			utility::log('Parsing autoloader…');
			$tmp = file_get_contents(static::PHPAB_AUTOLOADER);
			if (
				(false !== ($start = strpos($autoloader, '$classes = array'))) &&
				(false !== ($end = strpos($autoloader, ');', $start)))
			) {
				$classes = substr($autoloader, $start, ($end - $start + 2));
				// phpcs:disable
				eval($classes);
				// phpcs:enable
			}
		}

		// No classes.
		if (!isset($classes) || !is_array($classes)) {
			return;
		}

		// Build list of replacement classes and files.
		$r_classes = array();
		$r_namespaces = array();
		$r_files = array();
		utility::log('Patching classes…');
		$lib_dir = dirname(static::VENDOR_DIR) . '/';
		foreach ($classes as $k=>$v) {
			// We only care about vendor classes.
			if (0 === strpos($v, '/vendor/')) {
				$r_files[] = $lib_dir . ltrim($v, '/');

				// Move Blobfolio vendor classes into the main
				// namespace.
				if (static::NAMESPACE_SWAP) {
					$r_class = str_replace('\\vendor\\blobfolio', '\\vendor', static::NAMESPACE_SWAP . $k);
				}
				$r_classes[$k] = $r_class;

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
			utility::log('Patching files…');
			foreach ($r_files as $file) {
				$content = file_get_contents($file);
				$replacements = 0;
				$nice_name = str_replace(static::VENDOR_DIR, '', $file);

				// Replace namespaces.
				$ns = false;
				$content = preg_replace_callback(
					'/^\s*namespace\s+(\\\\)?([a-z0-9\\\\]+)\s*;/im',
					function ($matches) use($r_namespaces) {
						global $ns;
						global $replacements;

						if (3 === count($matches) && isset($r_namespaces[$matches[2]])) {
							$ns = true;
							++$replacements;
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
						global $replacements;

						if (3 <= count($matches)) {
							$matches = array_pad($matches, 4, '');
							$matches[2] = ltrim($matches[2], '\\');
							if (array_key_exists($matches[2], $r_classes)) {
								++$replacements;
								return "use \\{$r_classes[$matches[2]]}{$matches[3]};";
							}
							elseif (array_key_exists($matches[2], $r_namespaces)) {
								++$replacements;
								return "use \\{$r_namespaces[$matches[2]]}{$matches[3]};";
							}
						}

						return $matches[0];
					},
					$content
				);

				// Supply missing namespaces.
				if (!$ns) {
					if (preg_match('/\b(class|interface|trait)\s+([a-z0-9]+)/im', $content, $matches)) {
						if (
							isset(static::NAMESPACELESS[$matches[2]]) &&
							(false !== ($start = v_mb::strpos($content, '<?php')))
						) {
							++$replacements;
							$content = v_mb::substr($content, 0, $start + 5) . "\n\nnamespace " . static::NAMESPACE_SWAP . static::NAMESPACELESS[$matches[2]] . ";\n\n" . v_mb::substr($content, $start + 5);
						}
					}
					else {
						utility::log("Missing namespace: $nice_name", 'error');
					}
				}

				// Extra patching.
				$replacements += static::patch_extra($content);

				if ($replacements) {
					file_put_contents($file, $content);
					utility::log("Patched: $nice_name \033[1m[$replacements]\033[0m");
				}
			}
		}

		// Regenerate the autoloader.
		if (static::PHPAB_AUTOLOADER && isset(static::$deps['phpab'])) {
			static::$deps['phpab']->generate(static::SOURCE_DIR, static::PHPAB_AUTOLOADER);
		}
	}

	/**
	 * Patch Extra
	 *
	 * @param string $content Content.
	 * @return int Replacements.
	 */
	protected static function patch_extra(string &$content) {
		return 0;
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
		// Patch version.
		$version = false;
		if (is_file(static::SOURCE_DIR . 'index.php')) {
			utility::log('Finding current version…');
			$tmp = file_get_contents(static::SOURCE_DIR . 'index.php');
			if (preg_match('/@version\s+([\d\.\-]+)/', $tmp, $match)) {
				$version = $match[1];
			}
		}

		// Come up with a new version.
		$new_version = '';
		if (false !== $version) {
			$new_version = utility::prompt('Enter the version number:', true, $version);
		}
		else {
			$new_version = utility::prompt('Enter the version number:', true);
		}
		if (!$new_version && $version) {
			$new_version = $version;
		}

		// Patch versions.
		if ($new_version !== $version) {
			utility::log('Patching version…');

			// Most plugins will have version info in the index headers.
			if (is_file(static::SOURCE_DIR . 'index.php')) {
				$tmp = file_get_contents(static::SOURCE_DIR . 'index.php');
				$tmp = preg_replace('/@version\s+[\d\.\-]+/', "@version $new_version", $tmp);
				$tmp = preg_replace('/\* Version:\s+[\d\.\-]+/', "* Version: $new_version", $tmp);
				file_put_contents(static::SOURCE_DIR . 'index.php', $tmp);
			}

			// A lot of plugins have a release info file next to the
			// zip.
			if ('zip' === static::RELEASE_TYPE) {
				$json_out = substr(static::RELEASE_OUT, 0, -4) . '.json';
				if (is_file($json_out)) {
					$tmp = trim(file_get_contents($json_out));
					$tmp = json_decode($tmp, true);
					if (isset($tmp['Version'])) {
						$tmp['Version'] = $new_version;
						file_put_contents($json_out, json_encode($tmp, JSON_PRETTY_PRINT));
					}
				}
			}

			// In case there's any other weird stuff to do.
			static::patch_version($new_version);
		}

		static::$working_dir = utility::get_tmp_dir() . basename(static::SOURCE_DIR) . '/';
		utility::copy(static::SOURCE_DIR, static::$working_dir, static::SHITLIST);

		// Fix permissions.
		utility::log('Fixing permissions…');
		$files = v_file::scandir(static::$working_dir);
		foreach ($files as $v) {
			if (is_dir($v)) {
				chmod($v, 0755);
			}
			else {
				chmod($v, 0644);
			}
		}

		// Compress PHP.
		if (count(static::RELEASE_COMPRESS)) {
			utility::log('Compressing PHP scripts…');
			foreach (static::RELEASE_COMPRESS as $v) {
				$v = str_replace('%TMP%', static::$working_dir);
				utility::compress_php($v);
			}
		}
	}

	/**
	 * Patch Version
	 *
	 * @param string $version Version.
	 * @return void Nothing.
	 */
	protected static function patch_version(string $version) {
	}

	/**
	 * Pre-Package Tasks
	 *
	 * @return void Nothing.
	 */
	protected static function pre_package() {
	}

	/**
	 * Post-Package Tasks
	 *
	 * @return void Nothing.
	 */
	protected static function post_package() {
	}

	// ----------------------------------------------------------------- end package
}
