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
$args = getopt(implode('', $flags['short']), $flags['long'], $end);

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
	$builders = array_slice($argv, $end);
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
