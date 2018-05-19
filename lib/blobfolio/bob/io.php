<?php
/**
 * BOB: IO Helpers
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob;

use \blobfolio\bob\log;
use \blobfolio\common\cli;
use \blobfolio\common\data;
use \blobfolio\common\file as v_file;
use \blobfolio\common\ref\cast as r_cast;
use \blobfolio\common\ref\file as r_file;
use \blobfolio\common\ref\format as r_format;
use \blobfolio\common\ref\mb as r_mb;
use \blobfolio\common\ref\sanitize as r_sanitize;
use \PharData;
use \Throwable;
use \ZipArchive;

class io {
	// Prune patterns.
	const SHITLIST = array(
		'/\.(_*)DS_Store$/',
		'/\.babelrc$/',
		'/\.eslintrc\.json$/',
		'/\.git(attributes|ignore)?$/',
		'/\.htaccess$/',
		'/\.sass-cache/',
		'/\.travis\.yml$/',
		'/composer\.(json|lock)$/',
		'/Gruntfile\.js$/',
		'/node_modules/',
		'/package(\-lock)?\.json$/',
		'/phpcs\.ruleset\.xml$/',
		'/phpunit/',
		'/readme\.md$/i',
		'/vendor\/(autoload.php|bin|composer)/',
		'/vendor\/.*\.(markdown|md|yml)$/',
		'/vendor\/[^\/+]\/(build\.xml|tests?)/',
		'/yarn\.lock$/',
	);

	// Some binaries should always be downloaded fresh.
	const COMPOSER_REPO = 'composer/composer';
	const COMPOSER_PHAR = 'https://github.com/composer/composer/releases/download/%s/composer.phar';

	const PHPAB_REPO = 'theseer/Autoload';
	const PHPAB_PHAR = 'https://github.com/theseer/Autoload/releases/download/%s/phpab-%s.phar';

	// Miscellaneous settings.
	const CACHE_LIFETIME = 7200;			// Time to cache download.
	const REMOTE_CHUNK = 75;				// URLs to pull en masse.
	const REMOTE_TIMEOUT = 20;				// Download connect timeout.

	// URL patterns for Github.
	const GITHUB_RELEASE_API = 'https://api.github.com/repos/%s/%s/releases';
	const GITHUB_RELEASE_URL = 'https://github.com/%s/%s/archive/%s.tar.gz';

	protected static $_cache_time;			// Local filesystem time.
	protected static $_commands = array();	// Found commands.
	protected static $_downloads = array();	// Downloaded files.

	protected static $_tmp_dir;				// Temporary files.

	protected static $_composer;			// Location of Composer binary.
	protected static $_phpab;				// Location of PHPAB binary.



	// -----------------------------------------------------------------
	// CLI/System
	// -----------------------------------------------------------------

	/**
	 * Execute Command
	 *
	 * @param string $cmd Command.
	 * @param string $cwd Current working directory.
	 * @param bool $sudo Sudo.
	 * @return string|bool Response or false.
	 */
	public static function exec(string $cmd, string $cwd='', bool $sudo=false) {
		// Prefix with sudo?
		if ($sudo) {
			// The root state of the user executing this script.
			$user_sudo = cli::is_root();
			// Whether or not a sudo is already in the command.
			$cmd_sudo = !!(preg_match('/^sudo\s/i', $cmd));

			// The user is already root.
			if ($user_sudo && $cmd_sudo) {
				$cmd = preg_replace('/^sudo\s*/i', '', $cmd);
			}
			// The command needs sudo.
			elseif (!$user_sudo && !$cmd_sudo) {
				$cmd = "sudo $cmd";
			}
		}

		// If we want a special directory, it should be a valid one.
		if ($cwd) {
			r_file::path($cwd, true);
			if (!is_dir($cwd)) {
				return false;
			}
		}

		// Change the directory.
		if ($cwd) {
			$old_cwd = getcwd();
			chdir($cwd);
		}

		// Try shell_exec() first.
		if (false === ($out = static::_exec_shell($cmd))) {
			// Try system() next.
			if (false === ($out = static::_exec_system($cmd))) {
				$out = false;
			}
		}

		// Get rid of trailing whitespace.
		if (is_string($out)) {
			r_mb::trim($out);
		}

		// Change the directory back.
		if ($cwd) {
			chdir($old_cwd);
		}

		return $out;
	}

	/**
	 * Execute: Shell Exec
	 *
	 * @param string $cmd Command.
	 * @return string|bool Response or false.
	 */
	protected static function _exec_shell(string $cmd) {
		// Obviously bad data.
		if (!$cmd || !function_exists('shell_exec')) {
			return false;
		}

		try {
			// phpcs:disable
			$out = shell_exec($cmd);
			// phpcs:enable
			return is_null($out) ? true : $out;
		} catch (Throwable $e) {
			return false;
		}
	}

	/**
	 * Execute: System()
	 *
	 * @param string $cmd Command.
	 * @return string|bool Response or false.
	 */
	protected static function _exec_system(string $cmd) {
		// Obviously bad data.
		if (!$cmd || !function_exists('system')) {
			return false;
		}

		try {
			// System leaks to the screen; try to buffer it.
			ob_start();
			$out = system($cmd);
			ob_get_clean();

			return $out ? $out : true;
		} catch (Throwable $e) {
			return false;
		}
	}

	/**
	 * Find Command
	 *
	 * See if there is a path registered for a command.
	 *
	 * @param string $cmd Command.
	 * @param bool $soft Soft check.
	 * @return string|bool Command or false.
	 */
	public static function find_command(string $cmd, bool $soft=true) {
		// Do we already have it?
		if (isset(static::$_commands[$cmd])) {
			return static::$_commands[$cmd];
		}

		// Hard Coded?
		$const = strtoupper("BOB_{$cmd}_BINARY");
		if (defined($const)) {
			static::$_commands[$cmd] = constant($const);
		}

		// Try running the "command" command to find the full path.
		elseif (false === (static::$_commands[$cmd] = static::exec('command -v ' . escapeshellarg($cmd)))) {
			if ($soft) {
				static::$_commands[$cmd] = $cmd;
			}
			else {
				log::error("Could not find command: $cmd");
			}
		}

		// Before we call it a day, make sure the path seems executable.
		if (
			(false !== strpos($cmd, '/')) &&
			!file_exists($cmd)
		) {
			log::error("Command is (probably) not runnable: $cmd");
		}

		return static::$_commands[$cmd];
	}

	/**
	 * Get Command
	 *
	 * Note: the command should be unescaped.
	 * Note: the arguments should be pre-escaped.
	 *
	 * @param string $cmd Command.
	 * @param array $args Arguments.
	 * @param bool $php This is a PHP script.
	 * @return string Command.
	 */
	public static function get_command(string $cmd, $args=null, bool $php=false) {
		if (false === strpos($cmd, '/') && !file_exists($cmd)) {
			$cmd = static::find_command($cmd);
		}

		// Make it a PHP command.
		if ($php) {
			$cmd = static::get_php_command($cmd);
		}
		else {
			$cmd = escapeshellcmd($cmd);
		}

		$out = array($cmd);

		// Clean up the arguments.
		if (!is_null($args)) {
			r_cast::array($args);
			r_format::array_flatten($args);
			$args = implode(' ', $args);
			r_sanitize::whitespace($args);
			if ($args) {
				$out[] = $args;
			}
		}

		return trim(implode(' ', $out));
	}

	/**
	 * PHP Command
	 *
	 * BASH env flags get lost when piping through system, so to execute
	 * a PHP command (in e.g. a phar), the PHP path needs to be appended
	 * first.
	 *
	 * @param string $cmd Script.
	 * @return string|bool Command or false.
	 */
	public static function get_php_command(string $cmd) {
		$php = static::find_command('php');
		return escapeshellcmd($php) . ' ' . escapeshellarg($cmd);
	}

	// ----------------------------------------------------------------- end cli



	// -----------------------------------------------------------------
	// File & Directory Traversal
	// -----------------------------------------------------------------

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
	 * Copy
	 *
	 * @param string $from From.
	 * @param string $to To.
	 * @param array $shitlist Shitlist.
	 * @return void Nothing.
	 */
	public static function copy(string $from, string $to, $shitlist=null) {
		r_file::path($from, true);
		if (!$from) {
			return;
		}

		r_file::path($to, false);
		if (!$to || ($from === $to)) {
			return;
		}

		// Ignore shitlist files.
		if (static::is_shitlist($from, $shitlist) || static::is_shitlist($to, $shitlist)) {
			return;
		}

		// Recurse directories.
		if (@is_dir($from)) {
			r_file::trailingslash($from);
			r_file::trailingslash($to);

			if (!@is_dir($to)) {
				$dir_chmod = (@fileperms($from) & 0777 | 0755);
				if (!v_file::mkdir($to, $dir_chmod)) {
					return;
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
				@closedir($handle);
			}

			return;
		}
		// Let PHP handle it.
		elseif (@is_file($from)) {
			$dir_from = dirname($from);
			$dir_to = dirname($to);

			// Make the TO directory if it doesn't exist.
			if (!@is_dir($dir_to)) {
				$dir_chmod = (@fileperms($dir_from) & 0777 | 0755);
				if (!v_file::mkdir($dir_to, $dir_chmod)) {
					return;
				}
			}

			// Copy the file.
			if (!@copy($from, $to)) {
				return;
			}
			$file_chmod = (@fileperms($from) & 0777 | 0644);
			@chmod($to, $file_chmod);

			return;
		}
	}

	/**
	 * Fix Permissions
	 *
	 * Directories to 755, files to 644.
	 *
	 * @param string $dir Directory.
	 * @return void Nothing.
	 */
	public static function fix_permissions(string $dir) {
		$files = v_file::scandir($dir);
		foreach ($files as $v) {
			if (is_dir($v)) {
				chmod($v, 0755);
			}
			elseif (is_file($v)) {
				chmod($v, 0644);
			}
		}
	}

	/**
	 * Prune
	 *
	 * Prune shitlisted paths from a directory.
	 *
	 * @param string $dir Directory.
	 * @param array $shitlist Shitlist.
	 * @return void Nothing.
	 */
	public static function prune(string $dir, $shitlist=null) {
		// Ignore bad directories.
		if (!is_dir($dir)) {
			return;
		}

		// There could be a LOT of files, so let's check subdirectories
		// first.
		$paths = v_file::scandir($dir, false, true);
		foreach ($paths as $v) {
			if (is_dir($v) && static::is_shitlist($v, $shitlist)) {
				v_file::rmdir($v);
			}
		}

		// Once more, files only this time.
		$paths = v_file::scandir($dir, true, false);
		foreach ($paths as $v) {
			if (is_file($v) && static::is_shitlist($v, $shitlist)) {
				unlink($v);
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
		$path = static::get_tmp_dir($file);

		// It is already there!
		if (!$file || file_exists($path)) {
			return;
		}

		log::warning("File needed: $file");

		$message = log::BULLET . 'Copy it to ' . static::get_tmp_dir() . ' then press any key to continue.';
		$tmp = array();
		while (false !== ($line = log::wordwrap_cut($message))) {
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

	/**
	 * Scandir
	 *
	 * Recursively pull all files within a directory, optionally
	 * excluding shitlisted elements.
	 *
	 * @param string $dir Directory.
	 * @param array $shitlist Shitlist.
	 * @return array Files.
	 */
	public static function scandir(string $dir, $shitlist=null) {
		$out = v_file::scandir($dir, true, false);

		// Filter results.
		if (is_null($shitlist) || !empty($shitlist)) {
			foreach ($out as $k=>$v) {
				if (static::is_shitlist($out[$k], $shitlist)) {
					unset($out[$k]);
				}
			}
		}

		sort($out);
		return $out;
	}

	// ----------------------------------------------------------------- traversal



	// -----------------------------------------------------------------
	// Buliding
	// -----------------------------------------------------------------

	/**
	 * Install Composer
	 *
	 * @param string $dir Directory.
	 * @param string $conf Configuration.
	 * @param bool $optimize Static classmap.
	 * @return bool True/false.
	 */
	public static function composer_install(string $dir, string $conf, bool $optimize=false) {
		// We might need to download it.
		if (!isset(static::$_composer)) {
			log::print('Checking Composer version…');
			$info = static::github_release(static::COMPOSER_REPO, false);
			$url = sprintf(static::COMPOSER_PHAR, $info['version']);
			static::$_composer = static::_get_cache_path($url);

			// If it doesn't exist at all, download it.
			if (!is_file(static::$_composer)) {
				log::print("Downloading Composer v{$info['version']}…");
				static::$_composer = static::get_url($url, false);
				chmod(static::$_composer, 0755);
			}
			// If it exists from a previous session, make sure it is in
			// our $_downloads cache.
			elseif (!isset(static::$_downloads[$url])) {
				static::$_downloads[$url] = static::$_composer;
			}
		}

		log::print('Running Composer…');

		// The directory needs to be valid.
		r_file::path($dir, true);
		if (!$dir || !is_dir($dir)) {
			log::error('Invalid Composer project directory.');
			return false;
		}

		// If no configuration was specified, assume one exists in the
		// project directory.
		if (!$conf) {
			$conf = "{$dir}composer.json";
		}

		// If the configuration is missing, we're done.
		if (!is_file($conf)) {
			log::error('Missing Composer configuration.');
			return false;
		}

		// If the file isn't kept in the project directory, we'll need
		// to copy it over, run the install, then delete it.
		$exists = is_file("{$dir}composer.json");
		if (!$exists) {
			copy($conf, "{$dir}composer.json");
			chmod("{$dir}composer.json", 0644);
		}

		// Remove any pre-existing lock.
		if (is_file("{$dir}composer.lock")) {
			unlink("{$dir}composer.lock");
		}

		// Command arguments.
		$args = array(
			'install',
			'--no-dev',
			'-q',
		);
		if ($optimize) {
			$args[] = '-a';
		}

		// Compile the command.
		if (false === ($cmd = static::get_command(static::$_composer, $args, true))) {
			log::error('CLI command (probably) failed.');
			return false;
		}

		// Try to execute it!
		$result = static::exec($cmd, $dir);

		// Remove the config we copied over if applicable.
		if (!$exists) {
			unlink("{$dir}composer.json");
		}

		// Remove the lock if it exists now.
		if (is_file("{$dir}composer.lock")) {
			unlink("{$dir}composer.lock");
		}

		return (false !== $result);
	}

	/**
	 * Git Clone
	 *
	 * @param string $url Repo URL.
	 * @param string $out Output path.
	 * @return bool True/false.
	 */
	public static function git_clone(string $url, string $out) {
		r_sanitize::url($url);
		if (!$url) {
			log::error('Invalid git URL.');
		}
		$nice = basename($url);
		log::print("Cloning {$nice} repository…");

		// Check the out path.
		r_file::path($out, false);
		if (!$out) {
			log::error('Invalid output directory.');
		}

		$args = array(
			'clone',
			escapeshellarg($url),
			escapeshellarg($out),
		);

		$cmd = static::get_command('git', $args);
		$result = static::exec($cmd);
		return (false !== $result);
	}

	/**
	 * Github (Latest) Release
	 *
	 * @param string $repo Repo slug (maker/project).
	 * @param bool $download Download.
	 * @return array Release information.
	 */
	public function github_release(string $repo, bool $download=true) {
		$out = array(
			'account'=>'',
			'project'=>'',
			'version'=>'',
			'url'=>'',
			'tar'=>'',
		);

		// Validate the repo and tease out account/project.
		if (!preg_match('/^([a-z\d\-\_]+)\/([a-z\d\-\_]+)$/i', $repo, $matches)) {
			log::error("The repo value should be formatted like \033[2maccount/project\033[0m.");
		}
		$out['account'] = $matches[1];
		$out['project'] = $matches[2];

		// Pull release information.
		$url = sprintf(
			static::GITHUB_RELEASE_API,
			$out['account'],
			$out['project']
		);
		$tmp = static::get_url($url);
		if (!$tmp) {
			log::error('Could not pull Github release information.');
		}
		$json = json_decode($tmp, true);
		if (!isset($json[0]['tag_name'])) {
			log::error('Could not pull Github release information.');
		}

		// Version is tag.
		$out['version'] = $json[0]['tag_name'];

		// We should have enough to build a URL.
		$out['url'] = sprintf(
			static::GITHUB_RELEASE_URL,
			$out['account'],
			$out['project'],
			$out['version']
		);

		// The version might be prefixed with a "v", which we don't want
		// any more.
		if (0 === strpos($out['version'], 'v')) {
			$out['version'] = substr($out['version'], 1);
		}

		// Should we download the file?
		if ($download) {
			$tmp = static::download($out['url']);
			if (!isset($tmp[$out['url']]) || (false === $tmp[$out['url']])) {
				log::error('Could not download release.');
			}
			$out['tar'] = $tmp[$out['url']];
		}

		return $out;
	}


	/**
	 * Grunt Task
	 *
	 * @param string $dir Directory.
	 * @param string $task Task.
	 * @return bool True/false.
	 */
	public static function grunt_task(string $dir, string $task) {
		// The directory needs to be valid.
		r_file::path($dir, true);
		if (!$dir || !is_dir($dir)) {
			log::error('Invalid Grunt project directory.');
			return false;
		}

		r_mb::trim($task);
		if (!$task) {
			log::error('Invalid Grunt task.');
		}

		// Try to install NPM.
		if (!is_dir("{$dir}node_modules")) {
			if (!is_file("{$dir}Gruntfile.js") || !is_file("{$dir}packages.json")) {
				log::error('Grunt is not installed in this directory.');
			}
			else {
				log::warning('Grunt not installed; trying to fix that now…');
				$cmd = static::get_command('npm');
				static::exec(escapeshellcmd('npm') . ' i');
			}
		}

		// Try to run the command!
		log::print("Running Grunt \033[2m{$task}\033[0m…");
		$cmd = static::get_command('grunt', array($task));
		$result = static::exec($cmd, $dir);

		return (false !== $result);
	}

	/**
	 * Generate PHPAB Autoloader
	 *
	 * @param string $dir Directory.
	 * @param string $file Out file.
	 * @return bool True/false.
	 */
	public static function phpab_autoload(string $dir, string $file='') {
		// We might need to download it.
		if (!isset(static::$_phpab)) {
			log::print('Checking phpab version…');
			$info = static::github_release(static::PHPAB_REPO, false);
			$url = sprintf(static::PHPAB_PHAR, $info['version'], $info['version']);
			static::$_phpab = static::_get_cache_path($url);

			// If it doesn't exist at all, download it.
			if (!is_file(static::$_phpab)) {
				log::print("Downloading phpab v{$info['version']}…");
				static::$_phpab = static::get_url($url, false);
				chmod(static::$_phpab, 0755);
			}
			// If it exists from a previous session, make sure it is in
			// our $_downloads cache.
			elseif (!isset(static::$_downloads[$url])) {
				static::$_downloads[$url] = static::$_phpab;
			}
		}

		log::print('Generating phpab classmap…');

		// The directory needs to be valid.
		r_file::path($dir, true);
		if (!$dir || !is_dir($dir)) {
			log::error('Invalid phpab project directory.');
			return false;
		}

		// If no out file was specified, assume one exists in the
		// project directory.
		if (!$file) {
			$file = "{$dir}lib/autoload.php";
		}

		$args = array(
			'-e ' . escapeshellarg("{$dir}node_modules/*"),
			'-e ' . escapeshellarg("{$dir}tests/*"),
			'-n',
			'--tolerant',
			'-o ' . escapeshellarg($file),
			escapeshellarg($dir),
		);

		$cmd = static::get_command(static::$_phpab, $args, true);

		// Try to execute it!
		$result = static::exec($cmd, $dir);
		return (false !== $result);
	}

	// ----------------------------------------------------------------- end building



	// -----------------------------------------------------------------
	// Packing and Unpacking
	// -----------------------------------------------------------------

	/**
	 * Debian Package
	 *
	 * @param string $dir Directory.
	 * @param string $deb Output file.
	 * @return bool True/false.
	 */
	public static function deb(string $dir, string $deb='') {
		r_file::path($dir, true);
		if (!$dir || !is_dir($dir) || !is_file("{$dir}DEBIAN/control")) {
			log::error('Invalid dpkg directory.');
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
			log::error('Invalid deb file.');
		}

		// Try to add a version to the package name.
		$tmp = file_get_contents("{$dir}DEBIAN/control");
		if (preg_match('/^Version:\s*([^\s]+)/m', $tmp, $match)) {
			$version = $match[1];
			if (false === strpos($deb, "_{$version}.deb")) {
				$deb = substr($deb, 0, -4) . "_{$version}.deb";
			}
		}

		// All files must be owned by root.
		static::exec('chown -R root:root ' . escapeshellarg($dir), '', true);

		// Remove the original.
		if (is_file($deb)) {
			log::print('Removing original .deb…');
			unlink($deb);
		}

		$cmd = static::get_command(
			'dpkg-deb',
			array(
				'--build',
				escapeshellarg($dir),
				escapeshellarg($deb),
			)
		);

		log::print('Building package…');
		static::exec($cmd, $dir);
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
			log::error('PharData is not installed.');
		}

		if (!function_exists('gzopen')) {
			log::error('Gzip is not installed.');
		}

		// Make sure the tar makes sense.
		r_file::path($tar, true);
		if (!$tar || !preg_match('/\.tar(\.gz)?$/i', $tar)) {
			log::error('Invalid tar archive.');
		}

		// Lightly sanitize the out too.
		r_file::path($out, false);
		if (!$out) {
			log::error('Invalid output location.');
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
						log::error('A decompressed tar could not be saved.');
					}
				}
				else {
					log::error('The archive could not be decompressed.');
				}
			} catch (Throwable $e) {
				log::error('The archive could not be decompressed.');
			}
		}

		try {
			$phar = new PharData($tar);
			$phar->extractTo($out);
			return true;
		} catch (Throwable $e) {
			log::error('The archive could not be extracted.');
		}
	}

	/**
	 * Zip
	 *
	 * @param string $dir Source directory.
	 * @param string $zip Zip file.
	 * @param string $subdir Place files in subdirectory.
	 * @return void Nothing.
	 */
	public static function zip(string $dir, string $zip, string $subdir='') {
		// We need the ZipArchive class.
		if (!class_exists('ZipArchive')) {
			log::error('Missing extension: ZipArchive');
		}

		// Check the source path.
		r_file::path($dir, true);
		if (!$dir || !is_dir($dir)) {
			log::error('Invalid zip directory.');
		}

		// And the output path.
		r_file::path($zip, false);
		if ('.zip' !== substr(strtolower($zip), -4)) {
			log::error('Invalid zip file.');
		}
		// Remove the existing file or else data will append.
		elseif (is_file($zip)) {
			log::print('Removing original zip…');
			unlink($zip);
		}

		$handle = new ZipArchive();
		$handle->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

		$base_absolute = '#^' . preg_quote($dir, '#') . '#';
		$base_relative = ltrim($subdir, '/');
		r_file::trailingslash($base_relative);

		// Loop it.
		log::print('Compressing files…');
		$files = static::scandir($dir);
		foreach ($files as $v) {
			$file_absolute = $v;

			// Drop in a subdirectory in the zip root.
			if ($subdir) {
				$file_relative = preg_replace($base_absolute, $base_relative, $file_absolute);
			}
			// Drop in the zip root.
			else {
				$file_relative = preg_replace($base_absolute, '', $file_absolute);
			}

			$handle->addFile($file_absolute, $file_relative);
		}

		$handle->close();
	}

	// ----------------------------------------------------------------- end packing



	// -----------------------------------------------------------------
	// Temporary Files
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
		$dir = static::get_tmp_dir(data::random_string(5) . '/');
		while (file_exists($dir)) {
			$dir = static::get_tmp_dir(data::random_string(5) . '/');
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

	// ----------------------------------------------------------------- end tmp



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
		$length = count($urls);
		$num = 0;

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

			// There could be a lot of files; it's nice to note the progress.
			$length_do = count($chunk);
			$num_last = 0;

			// Process requests.
			do {
				curl_multi_exec($multi, $running);
				curl_multi_select($multi);

				// Note our progress.
				$finished = $length_do - $running;
				if ($finished !== $num_last) {
					$num += ($finished - $num_last);
					$num_last = $finished;
					log::progress($num / $length);
				}
			} while ($running > 0);

			// Make sure our total is accurate.
			if ($num_last !== $length_do) {
				$num += ($length_do - $num_last);
				log::progress($num / $length);
			}

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

		// Print a success line.
		log::success('Download complete.');

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
	 * @param bool $content Return content.
	 * @return mixed Content.
	 */
	public static function get_url(string $url, bool $content=true) {
		$tmp = static::download($url);

		// Return content.
		if ($content) {
			return file_get_contents($tmp[$url]);
		}

		// Return file path.
		return $tmp[$url];
	}

	// ----------------------------------------------------------------- end remote files



	// -----------------------------------------------------------------
	// Misc
	// -----------------------------------------------------------------

	/**
	 * Is Shitlist
	 *
	 * Check a string against one or more rejection patterns. This is
	 * usually used with file paths, but could handle other use cases.
	 *
	 * @param string $str Target string.
	 * @param array $shitlist Shitlist patterns.
	 * @return bool True/false.
	 */
	public static function is_shitlist(string $str, $shitlist=null) {
		// An empty string is a bad string.
		if (!$str) {
			return true;
		}

		// Default to static list.
		if (is_null($shitlist)) {
			$shitlist = static::SHITLIST;
		}
		// Just make sure we have an array.
		elseif (!is_array($shitlist)) {
			r_cast::array($shitlist);
		}

		// Run through the patterns to see anything sticks.
		foreach ($shitlist as $v) {
			if (preg_match($v, $str)) {
				return true;
			}
		}

		return false;
	}

	// ----------------------------------------------------------------- end misc
}
