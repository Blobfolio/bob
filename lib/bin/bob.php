#!/usr/bin/env php
<?php
/**
 * BOB: Bootstrap
 *
 * A copy of this bootstrap is placed in the project root, making it
 * easier to get builds building.
 *
 * @version 0.5.0
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

// We need to note where we are.
define('BOB_ROOT_DIR', __DIR__ . '/');

// The path to the autoloader.
define('BOB_AUTOLOAD', '%AUTOLOAD%');

// Run Bob.
\blobfolio\bob\himself::start();
