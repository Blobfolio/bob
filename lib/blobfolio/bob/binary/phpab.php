<?php
/**
 * BOB: PHPAB Binary
 *
 * @see {https://github.com/theseer/Autoload}
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob\binary;

use \blobfolio\bob\utility;
use \blobfolio\common\ref\file as r_file;

class phpab extends \blobfolio\bob\base\binary {
	const NAME = 'phpab';
	const REMOTE = 'https://github.com/theseer/Autoload/releases/download/1.24.1/phpab-1.24.1.phar';



	// -----------------------------------------------------------------
	// Commands
	// -----------------------------------------------------------------

	/**
	 * Generate Autoloader
	 *
	 * @param string $dir Directory.
	 * @param string $file Out file.
	 * @return bool True/false.
	 */
	public function generate(string $dir, string $file='') {
		utility::log('phpab: generating autoloader…');

		if (!$this->exists()) {
			utility::log('phpab is not initialized.', 'error', true);
			return false;
		}

		// The directory needs to be valid.
		r_file::path($dir, true);
		if (!$dir || !is_dir($dir)) {
			utility::log('Invalid phpab project directory.', 'error', true);
			return false;
		}

		// If no out file was specified, assume one exists in the
		// project directory.
		if (!$file) {
			$file = "{$dir}lib/autoload.php";
		}

		// Compile the command.
		if (false === ($cmd = $this->get_command(array(
			"-e \"{$dir}node_modules/*\"",
			'--tolerant',
			"-o \"$file\"",
			"\"{$dir}\"",
		)))) {
			utility::log('CLI command (probably) failed.', 'error', true);
			return false;
		}

		// Try to execute it!
		$out = $this->exec($cmd, $dir);

		return (false !== $out);
	}

	// ----------------------------------------------------------------- end commands
}
