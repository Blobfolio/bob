<?php
/**
 * BOB: Grunt Binary
 *
 * @see {https://github.com/theseer/Autoload}
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob\binary;

use \blobfolio\bob\utility;
use \blobfolio\common\ref\file as r_file;

class dpkg extends \blobfolio\bob\base\binary {
	const NAME = 'dpkg-deb';



	// -----------------------------------------------------------------
	// Setup
	// -----------------------------------------------------------------

	/**
	 * Construct
	 *
	 * This works a little differently than our PHAR archives; it has to
	 * be separately installed.
	 *
	 * @return bool True/false.
	 */
	public function __construct() {
		utility::log('Fetching ' . static::NAME . '…');

		if (!defined('BOB_DPKG_BINARY')) {
			if (false === ($tmp = static::exec('command -v dpkg-deb'))) {
				$tmp = 'dpkg-deb';
			}
			define('BOB_DPKG_BINARY', $tmp);
		}

		if (is_file(BOB_DPKG_BINARY)) {
			$this->binary = BOB_DPKG_BINARY;
			return true;
		}
		else {
			utility::log('dpkg-deb could not be found.', 'error', true);
		}
	}

	// ----------------------------------------------------------------- end setup



	// -----------------------------------------------------------------
	// Commands
	// -----------------------------------------------------------------

	/**
	 * Build Package
	 *
	 * @param string $dir Directory.
	 * @param string $deb Output file.
	 * @return bool True/false.
	 */
	public function build(string $dir, string $deb) {
		static::log('Building deb…');

		if (!$this->exists()) {
			utility::log('dpkg-deb is not initialized.', 'error');
			return false;
		}

		// The directory needs to be valid.
		r_file::path($dir, true);
		if (!$dir || !is_dir($dir) || !is_dir("{$dir}node_modules")) {
			utility::log('Invalid dpkg project directory.', 'error');
			return false;
		}

		// All files should be owned by root.
		$files = v_file::scandir($dir);
		$files[] = $dir;
		foreach ($files as $v) {
			chgrp($v, 'root');
			chown($v, 'root');
		}

		// Compile the command.
		if (false === ($cmd = $this->get_command(array(
			'--build',
			'"' . $dir . '"',
			'"' . $deb . '"',
		)))) {
			utility::log('Could not build CLI command.', 'error');
			return false;
		}

		// Try to execute it!
		$out = $this->exec($cmd, $dir);

		return (false !== $out);
	}

	// ----------------------------------------------------------------- end commands
}
