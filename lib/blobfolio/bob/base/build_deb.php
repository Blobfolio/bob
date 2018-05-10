<?php
/**
 * BOB: Build Debian Package
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob\base;

use \blobfolio\bob\utility;
use \blobfolio\common\cli;
use \blobfolio\common\file as v_file;
use \blobfolio\common\mb as v_mb;

abstract class build_deb extends build {
	// The package name.
	const NAME = '';

	// Where the dpkg template files are stored.
	const SKEL_DIR = '';

	// Type of release: zip, deb, copy.
	const RELEASE_TYPE = 'deb';
	// Where to save release.
	const RELEASE_OUT = '';

	// Binary dependencies. The values should be callable.
	const BINARIES = array();

	// File dependencies. The values should be paths.
	const FILES = array();



	// -----------------------------------------------------------------
	// Package
	// -----------------------------------------------------------------

	/**
	 * Get Version
	 *
	 * Projects will handle this differently depending on how and where
	 * sources come from.
	 *
	 * @return string Version.
	 */
	protected static function get_package_version() {
		return '';
	}

	/**
	 * Get Size
	 *
	 * We can usually calculate this automatically.
	 *
	 * @return int Size.
	 */
	protected static function get_package_size() {
		$size = 0;

		// Size the whole working directory.
		if (static::$working_dir && is_dir(static::$working_dir)) {
			$size += v_file::dirsize(static::$working_dir);

			// Subtract the DEBIAN folder.
			if (is_dir(static::$working_dir . 'DEBIAN/')) {
				$size -= v_file::dirsize(static::$working_dir . 'DEBIAN/');
			}
		}

		return $size;
	}

	/**
	 * Pre-Package Tasks
	 *
	 * @return void Nothing.
	 */
	protected static function pre_package() {
		// Copy the skeleton files.
		if (static::SKEL_DIR) {
			if (!is_dir(static::SKEL_DIR)) {
				utility::log('Invalid skeleton directory.', 'error');
			}

			utility::log('Copying DEBIAN files…');
			v_file::copy(static::SKEL_DIR, static::$working_dir);
		}

		// Let users copy other stuff.
		static::pre_package_extra();

		// If there is a control file, try to patch it.
		$control = static::$working_dir . 'DEBIAN/control';
		if (is_file($control)) {
			utility::log('Patching DEBIAN control…');
			$tmp = file_get_contents($control);
			$tmp = str_replace(
				array(
					'%VERSION%',
					'%SIZE%',
				),
				array(
					static::get_package_version(),
					static::get_package_size(),
				),
				$tmp
			);
			file_put_contents($control, $tmp);
		}
	}

	/**
	 * Pre-Package Extra
	 *
	 * This should be used to copy any non-DEBIAN files to the working
	 * directory.
	 *
	 * @return void Nothing.
	 */
	protected static function pre_package_extra() {
	}

	// ----------------------------------------------------------------- end package
}
