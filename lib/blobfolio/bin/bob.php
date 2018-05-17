#!/usr/bin/env php
<?php
/**
 * BOB: Bootstrap
 *
 * A copy of this bootstrap is placed in the project root, making it
 * easier to get builds building.
 *
 * @flag -h|--help
 * @flag -v|--version
 * @flag [<builder>, <builder>…]
 *
 * @version 1.0.0
 * @license WTFPL
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

// The project root directory.
define('BOB_ROOT_DIR', __DIR__ . '/');
define('BOB_VERSION', __VERSION__);
define('BOB_AUTOLOADER', __AUTOLOADER__);

// Place builders here for easy access.
define('BOB_BUILDER_DIR', __BUILDER_DIR__);

// Pull in the autoloader.
require(BOB_AUTOLOADER);
use \blobfolio\bob\himself;

// Parse CLI arguments.
$flags = himself::get_cli_flags();

// Append our undocumented "release" flag, used to bump bob's package
// version.
$flags['long'][] = 'release';
$args = getopt(implode('', $flags['short']), $flags['long']);

// Turn debugging output on or off.
define('BOB_DEBUG', isset($args['debug']));

// Show help screen.
if (isset($args['h']) || isset($args['help'])) {
	himself::help();
}

// Show version screen.
if (isset($args['v']) || isset($args['version'])) {
	himself::version();
}

// Build Bob himself?
if (isset($args['release'])) {
	himself::release();
}

// Run everything?
if (isset($args['all'])) {
	$builders = array_keys(himself::get_builders());
}
// Run specific builders maybe.
else {
	$builders = array();

	// We have to parse this manually because PHP didn't add the option
	// index argument to getopt() until PHP 7.1.
	if (isset($argv[1])) {
		// First pass, find the last index of a named argument.
		$last_index = 0;
		for ($x = 1; $x < count($argv); ++$x) {
			$nice = $argv[$x];

			// Longopt.
			if (0 === strpos($argv[$x], '--')) {
				$nice = substr($argv[$x], 2);
			}
			// Shortopt.
			elseif (0 === strpos($argv[$x], '-')) {
				$nice = substr($argv[$x], 1);
			}

			// This was already handled.
			if (isset($args[$nice])) {
				$last_index = $x;
			}
		}

		// Increase by one and grab anything non-slashy.
		++$last_index;
		if (count($argv) > $last_index) {
			for ($x = $last_index; $x < count($argv); ++$x) {
				if (0 !== strpos($argv[$x], '-')) {
					$builders[] = $argv[$x];
				}
			}
		}
	}
}

// Run with specific builders.
if (is_array($builders) && count($builders)) {
	himself::start($builders);
}
// Let Bob figure it out.
else {
	himself::start();
}

exit(0);
