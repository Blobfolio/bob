<?php
/**
 * BOB: Bootstrap Callbacks
 *
 * When Composer is installed or updated, a self-executable Bob is
 * copied to the project root directory. That script is a light
 * wrapper for various callbacks included here.
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob;

use \blobfolio\bob\format;
use \blobfolio\bob\io;
use \blobfolio\bob\log;
use \blobfolio\common\data;
use \blobfolio\common\file as v_file;
use \blobfolio\common\ref\cast as r_cast;
use \blobfolio\common\ref\file as r_file;
use \blobfolio\common\ref\mb as r_mb;
use \Composer\Script\Event;

class himself {

	// Command line flags.
	const FLAGS = array(
		array(
			'flags'=>array('--all'),
			'description'=>'Run all builders.',
			'example'=>'bob --all',
		),
		array(
			'flags'=>array('-h', '--help'),
			'description'=>'Print this screen.',
			'example'=>'bob --help',
		),
		array(
			'flags'=>array('-v', '--version'),
			'description'=>"Display Bob's version information.",
			'example'=>'bob --version',
		),
		array(
			'flags'=>array('[builder1] [builder2]…'),
			'description'=>'Bypass the guesswork; launch one or more builders.',
			'example'=>array(
				'bob my_builder another_builder',
				'bob /path/to/my_builder.php',
				'bob \\my\\builder\\class',
			),
		),
	);

	protected static $_builders;



	// -----------------------------------------------------------------
	// Bob
	// -----------------------------------------------------------------

	/**
	 * Help
	 *
	 * Print Bob usage details and exit.
	 *
	 * @return void Nothing.
	 */
	public static function help() {
		log::title('Bob: Help');
		log::print("\033[2mExample:\033[0m bob -args [builders]", false, false);

		// Generate documentation automatically.
		foreach (static::FLAGS as $v) {
			// The flags.
			log::print("\033[95;1m" . implode(' ', $v['flags']) . "\033[0m", false, false);

			// The description.
			log::print("   {$v['description']}", false, false);

			// Example(s).
			r_cast::array($v['example']);
			foreach ($v['example'] as $tmp) {
				log::print("   \033[2mExample:\033[0m {$tmp}", false, false);
			}

			log::print('', false, false);
		}

		exit(0);
	}

	/**
	 * Binary Install
	 *
	 * Copy our bin to the project root folder. This should happen
	 * automatically any time Composer is installed or updated, provided
	 * the parent project's config is set up correctly.
	 *
	 * @param Event $e Event.
	 * @return void Nothing.
	 */
	public static function install(Event $e) {
		$config = $e->getComposer()->getConfig();

		// The project root.
		$root_dir = v_file::path(getcwd());
		r_file::trailingslash($root_dir);

		// The vendor directory.
		$vendor_dir = v_file::path($config->get('vendor-dir'));
		r_file::trailingslash($vendor_dir);

		// The Bob template.
		$template = dirname(__DIR__) . '/bin/bob.php';
		if (!is_file($template)) {
			log::error('Could not locate Bob.');
		}
		$template = file_get_contents($template);

		// Tease out a version number.
		if (!preg_match('/^ \* @version\s+([\d\.]+)/m', $template, $match)) {
			log::error('Could not parse Bob.');
		}
		$version = trim($match[1]);

		// Announce it!
		log::title("Installing Bob v$version");

		// Customize the launcher based on how Composer was configured.
		$replace = array(
			'__AUTOLOADER__'=>"{$vendor_dir}autoload.php",
			'__BUILDER_DIR__'=>"{$root_dir}builders/",
		);

		// Make the builder directory if absent.
		if (!file_exists($replace['__BUILDER_DIR__'])) {
			log::print('Making builder directory…');
			if (!v_file::mkdir($replace['__BUILDER_DIR__'], 0755)) {
				log::error('Could not make builder directory.');
			}
		}

		// Make the paths relative.
		$pattern = '#^' . preg_quote($root_dir, '#') . '#';
		foreach ($replace as $k=>$v) {
			$replace[$k] = preg_replace($pattern, '', $replace[$k]);
			$replace[$k] = "__DIR__ . '/{$replace[$k]}'";
		}
		$replace['__VERSION__'] = "'$version'";

		// Save the launcher.
		log::print('Saving Bob launcher…');
		$template = str_replace(array_keys($replace), array_values($replace), $template);
		$file = "{$root_dir}bob";
		file_put_contents($file, $template);
		if (!is_file($file)) {
			log::error('Could not save Bob.');
		}
		chmod($file, 0755);

		// Add Bob to the gitignore.
		log::print('Adding git exception…');
		$file = "{$root_dir}.gitignore";
		if (is_file($file)) {
			$template = file_get_contents($file);
			$template = format::lines_to_array($template);
		}
		else {
			$template = array();
		}
		if (!in_array('/bob', $template, true)) {
			$template[] = '/bob';
		}
		sort($template);
		file_put_contents($file, implode("\n", $template));
		chmod($file, 0644);

		// We're done!
		log::success('Bob has been saved!');
		log::print("\033[95;1mReference:\033[0m ./bob --help");
	}

	/**
	 * Start Build
	 *
	 * Run one or more builders.
	 *
	 * @param mixed $builders Builders.
	 * @return void Nothing.
	 */
	public static function start($builders=null) {
		if (!is_null($builders)) {
			r_cast::array($builders);

			foreach ($builders as $k=>$v) {
				if (false === ($builders[$k] = static::get_builder($v))) {
					log::error("Missing builder: $v", false);
				}
			}
		}
		else {
			// All builders.
			$tmp = static::get_builders();

			// If there is just one, run with it.
			if (count($tmp) === 1) {
				$tmp = array_keys($tmp);
				$builders = array($tmp[0]);
			}
			// If there are more than one, ask what to run.
			else {
				foreach ($tmp as $k=>$v) {
					$tmp[$k] = pathinfo($v, PATHINFO_FILENAME);
				}
				$default = data::array_pop_top($tmp);

				$builder = log::prompt('Choose a builder:', $default, $tmp);
				$builder = array_search($builder, $tmp, true);
				$builders = array($builder);
			}
		}

		// Run them!
		foreach ($builders as $build) {
			$build::compile();
		}

		exit(0);
	}

	/**
	 * Version
	 *
	 * @return void Nothing.
	 */
	public static function version() {
		if (!defined('BOB_VERSION')) {
			log::error('Could not determine version.', false);
		}

		log::print('Bob v' . BOB_VERSION, false);
		exit(0);
	}

	// ----------------------------------------------------------------- end bob



	// -----------------------------------------------------------------
	// Miscellaneous Helpers
	// -----------------------------------------------------------------

	/**
	 * Get CLI Flags
	 *
	 * Return CLI flags formatted for getopt().
	 *
	 * @return array Flags.
	 */
	public static function get_cli_flags() {
		$out = array(
			'short'=>array(),
			'long'=>array(),
		);

		foreach (static::FLAGS as $v) {
			foreach ($v['flags'] as $flag) {
				// Short option.
				if (preg_match('/^\-([a-z])((=|\s).+)?$/', $flag, $matches)) {
					if (isset($matches[2])) {
						$out['short'][] = "{$matches[1]}:";
					}
					else {
						$out['short'][] = $matches[1];
					}
				}
				// Long option.
				elseif (preg_match('/^\-\-([a-z\-\d]+)((=|\s).+)?$/', $flag, $matches)) {
					if (isset($matches[2])) {
						$out['long'][] = "{$matches[1]}:";
					}
					else {
						$out['long'][] = $matches[1];
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Find Builders
	 *
	 * Scan the builder directory for builders.
	 *
	 * @return array Builders.
	 */
	public static function get_builders() {
		// Autopopulate with builders from builder directory.
		if (!is_array(static::$_builders)) {
			// We need a builder directory.
			if (!defined('BOB_BUILDER_DIR') || !is_dir(BOB_BUILDER_DIR)) {
				log::error('Could not find builder directory.', false);
			}

			static::$_builders = array();

			$files = v_file::scandir(BOB_BUILDER_DIR, true, false);
			foreach ($files as $file) {
				// Not a class file.
				if (false === ($class = static::_get_class_name($file))) {
					continue;
				}

				// Add it so long as it is a Mike subclass.
				if (is_subclass_of($class, '\\blobfolio\\bob\\base\\mike')) {
					static::$_builders[$class] = $file;
				}
			}

			if (!count(static::$_builders)) {
				log::error('No builders were found.', false);
			}

			asort(static::$_builders);
		}

		return static::$_builders;
	}

	/**
	 * Get Builder Class
	 *
	 * Try to match a slug-like entry with a builder class.
	 *
	 * @param string $slug Slug.
	 * @return string|bool Class or false.
	 */
	public static function get_builder(string $slug) {
		$slug = trim($slug);
		if (!$slug) {
			return false;
		}

		$builders = static::get_builders();

		// Direct hit.
		if (isset($builders[$slug])) {
			return $slug;
		}

		// Hit with slash?
		if (isset($builders["\\{$slug}"])) {
			return "\\{$slug}";
		}

		// Maybe it's a path?
		$tmp = v_file::path($slug);
		if (false !== ($class = array_search($slug, $builders, true))) {
			return $class;
		}

		// Look the builder to see if there are any basename hits.
		$tmp = $slug;
		if (false !== strpos($tmp, '/')) {
			$tmp = basename($tmp);
		}
		if ('.php' !== substr($tmp, -4)) {
			$tmp .= '.php';
		}

		foreach ($builders as $k=>$v) {
			if (basename($v) === $tmp) {
				return $k;
			}
		}

		return false;
	}

	/**
	 * Parse Class Name
	 *
	 * @param string $file File.
	 * @return bool|string Class or false.
	 */
	protected static function _get_class_name(string $file) {
		// Obviously bad.
		if (!$file || !is_file($file) || ('.php' !== substr($file, -4))) {
			return false;
		}

		// Open it.
		$tmp = file_get_contents($file);

		// We expect a namespace.
		if (!preg_match('/^namespace\s+([^\s;]+)/m', $tmp, $matches)) {
			return false;
		}
		$namespace = '\\' . ltrim($matches[1], '\\');
		$namespace = rtrim($namespace, '\\') . '\\';

		// We'll assume the class name matches the file name.
		$class = pathinfo($file, PATHINFO_FILENAME);

		// Glue them together and see if it works.
		if (class_exists("{$namespace}{$class}")) {
			return "{$namespace}{$class}";
		}

		return false;
	}

	/**
	 * Build Release
	 *
	 * Internal use only: bump version in Bob template and Composer
	 * config.
	 *
	 * @return void Nothing.
	 */
	public static function release() {
		log::title('Build Bob Release');

		// The Bob template.
		$file = dirname(__DIR__) . '/bin/bob.php';
		if (!is_file($file)) {
			log::error('Could not locate Bob.');
		}
		$template = file_get_contents($file);

		// Tease out a version number.
		if (!preg_match('/^ \* @version\s+([\d\.]+)/m', $template, $match)) {
			log::error('Could not parse Bob.');
		}
		$version = trim($match[1]);

		$new_version = log::prompt('Enter a version number:', $version);
		while (!preg_match('/^\d+\.\d+\.\d+$/', $new_version)) {
			log::warning('Use semantic versioning, e.g. 1.2.3.');
			$new_version = log::prompt('Enter a version number:', $version);
		}

		log::print('Patching Bob…');

		$template = preg_replace('/^ \* @version\s+([\d\.]+)/m', " * @version $new_version", $template);
		file_put_contents($file, $template);

		if (defined('BOB_ROOT_DIR')) {
			log::print('Patching Composer…');

			$file = BOB_ROOT_DIR . 'composer.json';
			if (is_file($file)) {
				$template = json_decode(trim(file_get_contents($file)), true);
				if (
					!is_array($template) ||
					!isset($template['name']) ||
					('bob' !== $template['name'])
				) {
					log::warning('Could not parse composer.json.');
				}
				else {
					$template['version'] = $new_version;
					file_put_contents($file, json_encode($template, JSON_PRETTY_PRINT));
				}
			}
		}

		log::success("The version has been bumped to $new_version.");
		log::print("Don't forget to run \033[95;1mcomposer update\033[0m to make it all official.");

		exit(0);
	}

	// ----------------------------------------------------------------- end misc
}
