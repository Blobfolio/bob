<?php
/**
 * BOB: Binary
 *
 * This is an abstract class for command line tools like Composer and
 * phpab.
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob\base;

use \blobfolio\bob\utility;
use \blobfolio\common\ref\cast as r_cast;
use \blobfolio\common\ref\file as r_file;
use \blobfolio\common\ref\format as r_format;
use \blobfolio\common\ref\mb as r_mb;
use \blobfolio\common\ref\sanitize as r_sanitize;
use \Throwable;

abstract class binary {
	const NAME = '';
	const REMOTE = '';
	const CHMOD = 0755;

	protected $binary;



	// -----------------------------------------------------------------
	// Setup
	// -----------------------------------------------------------------

	/**
	 * Construct
	 *
	 * @return bool True/false.
	 */
	public function __construct() {
		utility::log('Fetching ' . static::NAME . '…');

		$host = static::REMOTE;
		r_sanitize::hostname($host);
		utility::log("Downloading from {$host}…", '', true);

		// See if we can get it.
		$tmp = utility::get_remote(static::REMOTE);
		if (!isset($tmp[static::REMOTE]) || (false === $tmp[static::REMOTE])) {
			utility::log('Download failed.', 'error', true);
		}

		// Fix the permissions.
		$this->binary = $tmp[static::REMOTE];
		utility::log('Fixing permissions…', '', true);
		chmod($this->binary, static::CHMOD);

		// We'll also want to know where PHP lives.
		if (!defined('BOB_PHP_BINARY')) {
			if (false === ($tmp = static::exec('command -v php'))) {
				$tmp = 'php';
			}
			define('BOB_PHP_BINARY', $tmp);
		}

		return true;
	}

	/**
	 * Does the binary exist?
	 *
	 * @return bool True/false.
	 */
	public function exists() {
		return (
			!is_null($this->binary) &&
			((false === strpos($this->binary, '/')) || file_exists($this->binary))
		);
	}

	// ----------------------------------------------------------------- end setup



	// -----------------------------------------------------------------
	// Commands
	// -----------------------------------------------------------------

	/**
	 * Build Command
	 *
	 * @param mixed $args Arguments.
	 * @return bool|string Command.
	 */
	public function get_command($args=null) {
		if (!$this->exists()) {
			return false;
		}

		$out = array();
		if (preg_match('/\.(phar|php)/i', $this->binary)) {
			// Direct PHAR execution only works in BASH environments.
			// For Apple, et al., the command needs to be prefixed with
			// PHP.
			if (defined('BOB_PHP_BINARY')) {
				$out[] = BOB_PHP_BINARY;
			}
			else {
				$out[] = 'php';
			}
		}

		// Append our actual command.
		$out[] = escapeshellcmd($this->binary);

		if (!is_null($args)) {
			r_cast::array($args);
			r_format::array_flatten($args);
			$args = implode(' ', $args);
			r_sanitize::whitespace($args);
			if ($args) {
				$out[] = $args;
			}
		}

		$out = implode(' ', $out);
		r_sanitize::whitespace($out);
		return $out;
	}

	/**
	 * Execute Command
	 *
	 * @param string $cmd Command.
	 * @param string $cwd Current working directory.
	 * @return string|bool Response or false.
	 */
	public static function exec(string $cmd, string $cwd='') {
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
		if (false === ($out = static::exec_shell($cmd))) {
			// Try system() next.
			if (false === ($out = static::exec_system($cmd))) {
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
	protected static function exec_shell(string $cmd) {
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
	protected static function exec_system(string $cmd) {
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

	// ----------------------------------------------------------------- end command
}
