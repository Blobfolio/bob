<?php
/**
 * BOB: Test
 *
 * A quick test script to make sure shit isn't blowing up.
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

use \blobfolio\bob\base\mike;
use \blobfolio\bob\log;

require(__DIR__ . '/lib/vendor/autoload.php');

log::title('Life Is Great');

if (log::confirm('Are you hungry?', true)) {
	log::print('Yes.');
}
else {
	log::print('No.');
}

$answer = log::prompt('What is your favorite animal?', 'dog', array('bat', 'cat', 'dog'));
log::print("You said: $answer");
