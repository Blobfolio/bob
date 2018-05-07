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

class grunt extends \blobfolio\bob\base\binary {
	const NAME = 'Grunt';



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

		if (!defined('BOB_GRUNT_BINARY')) {
			if (false === ($tmp = static::exec('command -v grunt'))) {
				$tmp = 'grunt';
			}
			define('BOB_GRUNT_BINARY', $tmp);
		}

		$this->binary = BOB_GRUNT_BINARY;
		return true;
	}

	// ----------------------------------------------------------------- end setup



	// -----------------------------------------------------------------
	// Commands
	// -----------------------------------------------------------------

	/**
	 * Run Task
	 *
	 * @param string $dir Directory.
	 * @param string $task Task.
	 * @return bool True/false.
	 */
	public function run_task(string $dir, string $task) {
		utility::log('Running Grunt task "' . $task . '"…');

		if (!$this->exists()) {
			utility::log('Grunt is not initialized.', 'error');
			return false;
		}

		if (!$task) {
			utility::log('Invalid Grunt task.', 'error');
		}

		// The directory needs to be valid.
		r_file::path($dir, true);
		if (!$dir || !is_dir($dir) || !is_dir("{$dir}node_modules")) {
			utility::log('Invalid Grunt project directory.', 'error');
			return false;
		}

		// Compile the command.
		if (false === ($cmd = $this->get_command($task))) {
			utility::log('Could not build CLI command.', 'error');
			return false;
		}

		// Try to execute it!
		$out = $this->exec($cmd, $dir);

		return (false !== $out);
	}

	// ----------------------------------------------------------------- end commands
}
