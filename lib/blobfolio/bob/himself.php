<?php
/**
 * BOB: Bootstrap Installer
 *
 * After composer does its thing, we want to copy Bob to the project
 * root and make it executable.
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob;

use \Composer\Script\Event;
use \Composer\Installer\PackageEvent;

use \blobfolio\bob\format;
use \blobfolio\bob\log;
use \blobfolio\bob\io;
use \blobfolio\common\cli;
use \blobfolio\common\data;
use \blobfolio\common\file as v_file;
use \blobfolio\common\ref\file as r_file;
use \blobfolio\common\ref\mb as r_mb;

class himself {
	/**
	 * Install
	 *
	 * @param Event $e Event.
	 * @return void Nothing.
	 */
	public static function install(Event $e) {
		print_r($e);
	}

	/**
	 * Update
	 *
	 * @param Event $e Event.
	 * @return void Nothing.
	 */
	public static function update(Event $e) {
		$composer = $e->getComposer();
		$config = $composer->getConfig();

		var_dump($config->get('vendor-dir'));
		var_dump($config->get('bin-dir'));
		var_dump(getcwd());
	}
}
