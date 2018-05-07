<?php
/**
 * BOB: Composer Binary
 *
 * @see {https://getcomposer.org}
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob\binary;

use \blobfolio\bob\utility;
use \blobfolio\common\ref\file as r_file;

class composer extends \blobfolio\bob\base\binary {
	const NAME = 'Composer';
	const REMOTE = 'https://getcomposer.org/composer.phar';



	// -----------------------------------------------------------------
	// Commands
	// -----------------------------------------------------------------

	/**
	 * Install
	 *
	 * @param string $dir Directory.
	 * @param string $conf Configuration.
	 * @return bool True/false.
	 */
	public function install(string $dir, string $conf='') {
		utility::log('Composer: updating libraries…');

		if (!$this->exists()) {
			utility::log('Composer is not initialized.', 'error', true);
			return false;
		}

		// The directory needs to be valid.
		r_file::path($dir, true);
		if (!$dir || !is_dir($dir)) {
			utility::log('Invalid Composer project directory.', 'error', true);
			return false;
		}

		// If no configuration was specified, assume one exists in the
		// project directory.
		if (!$conf) {
			$conf = "{$dir}composer.json";
		}

		// If the configuration is missing, we're done.
		if (!is_file($conf)) {
			utility::log('Missing Composer configuration.', 'error', true);
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

		// Compile the command.
		if (false === ($cmd = $this->get_command(array(
			'install',
			'--no-dev',
			'-q',
		)))) {
			utility::log('CLI command (probably) failed.', 'error', true);
			return false;
		}

		// Try to execute it!
		$out = $this->exec($cmd, $dir);

		// Remove the config we copied over if applicable.
		if ($exists) {
			unlink("{$dir}composer.json");
		}

		// Remove the lock if it exists now.
		if (is_file("{$dir}composer.lock")) {
			unlink("{$dir}composer.lock");
		}

		return (false !== $out);
	}

	// ----------------------------------------------------------------- end commands
}
