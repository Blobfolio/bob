<?php
/**
 * BOB: Builder Debian Package
 *
 * This base builder is tailored for releasing Debian plugins.
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob\base;

use \blobfolio\bob\format;
use \blobfolio\bob\io;
use \blobfolio\bob\log;
use \blobfolio\common\cli;
use \blobfolio\common\data;
use \blobfolio\common\file as v_file;
use \blobfolio\common\mb as v_mb;
use \blobfolio\common\ref\file as r_file;
use \blobfolio\common\ref\mb as r_mb;

abstract class mike_deb extends mike {
	// Project Name.
	const NAME = '';
	const DESCRIPTION = '';
	const CONFIRMATION = '';
	const SLUG = '';

	// Runtime requirements.
	const ROOT = false;						// Root or not root.
	const REQUIRED_CLASSES = array();		// Required PHP classes.
	const REQUIRED_EXTENSIONS = array();	// Required PHP modules.
	const REQUIRED_FUNCTIONS = array();		// Required functions.

	const REQUIRED_DOWNLOADS = array();		// Required remote files.
	const REQUIRED_FILES = array();			// Required files.

	// Automatic setup.
	const CLEAN_ON_SUCCESS = false;			// Delete tmp/bob when done.

	// Functions to run to complete the build, in order, grouped by
	// heading.
	const ACTIONS = array(
		'Compiling Source'=>array(
			'_build_compile_source',
		),
		'Packaging Release'=>array(
			'_build_working',
			'_patch_version',
			'_build_release',
		),
	);

	// Build a release, either deb or copy.
	const RELEASE_TYPE = 'deb';

	// Compress PHP files in these (relative) directories.
	const RELEASE_COMPRESS = array();

	protected static $_working_dir;			// Working files.
	protected static $_version;



	// -----------------------------------------------------------------
	// Setup
	// -----------------------------------------------------------------

	/**
	 * Build: Update Source
	 *
	 * @return void Nothing.
	 */
	protected static function _build_compile_source() {
		// Make sure we have a slug.
		if (!static::SLUG) {
			log::error('The project slug must be defined.');
		}

		// Extra user steps.
		static::build_compile_source();
	}

	/**
	 * Overload: Build Source
	 *
	 * @return void Nothing.
	 */
	protected static function build_compile_source() {
	}

	/**
	 * Build: Working Dir
	 *
	 * @return void Nothing.
	 */
	protected static function _build_working() {
		log::print('Setting up working directory…');
		static::make_working_dir(static::get_skel_dir());
	}

	/**
	 * Overload: Working Dir
	 *
	 * @return void Nothing.
	 */
	protected static function build_working() {
	}

	/**
	 * Build: Release
	 *
	 * @return void Nothing.
	 */
	protected static function _build_release() {
		if ('deb' === static::RELEASE_TYPE) {
			io::deb(static::$_working_dir, static::get_release_path());

			log::print('Removing working directory…');
			v_file::rmdir(static::$_working_dir);
			static::$_working_dir = null;
		}
		elseif ('copy' === static::RELEASE_TYPE) {
			$path = static::get_release_path();
			if (is_dir($path)) {
				v_file::rmdir($path);
			}

			// Copy and delete because "move" isn't an option. Haha.
			log::print('Copying one last time…');
			v_file::copy(static::$_working_dir, static::get_release_path());

			log::print('Removing working directory…');
			v_file::rmdir(static::$_working_dir);
			static::$_working_dir = null;
		}
	}

	/**
	 * Overload: Build Release
	 *
	 * @return void Nothing.
	 */
	protected static function build_release() {
	}

	/**
	 * Patch Version
	 *
	 * @return void Nothing.
	 */
	protected static function _patch_version() {
		// If there is a control file, try to patch it.
		$control = static::$_working_dir . 'DEBIAN/control';
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

		// Let projects patch more!
		static::patch_version();
	}

	/**
	 * Overload: Patch Version
	 *
	 * @return void Nothing.
	 */
	protected static function patch_version() {
	}

	// ----------------------------------------------------------------- end setup



	// -----------------------------------------------------------------
	// Project Paths and Options
	// -----------------------------------------------------------------

	/**
	 * Get Version
	 *
	 * Projects should probably handle this themselves, but just in
	 * case, we can always ask.
	 *
	 * @return string Version.
	 */
	protected static function get_package_version() {
		if (!isset(static::$_version)) {
			// Don't know? Gotta ask.
			static::$_version = log::prompt('Enter a version number:');
			while (!preg_match('/^\d+\.\d+\.\d+$/', static::$_version)) {
				log::warning('Use semantic versioning, e.g. 1.2.3.');
				static::$_version = log::prompt('Enter a version number:');
			}
		}

		return static::$_version;
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
		if (static::$_working_dir && is_dir(static::$_working_dir)) {
			$size += v_file::dirsize(static::$_working_dir);

			// Subtract the DEBIAN folder.
			if (is_dir(static::$_working_dir . 'DEBIAN/')) {
				$size -= v_file::dirsize(static::$_working_dir . 'DEBIAN/');
			}
		}

		return $size;
	}

	/**
	 * Get Skel Directory
	 *
	 * This should point to miscellaneous template files.
	 *
	 * @return string Source.
	 */
	protected static function get_skel_dir() {
		$path = '';

		// Try to discover it. Individual builders can always override
		// this.
		if (defined('BOB_ROOT_DIR')) {
			$path = BOB_ROOT_DIR . 'skel/';
			if (!is_dir($path)) {
				$path = '';
			}
		}

		return $path;
	}

	/**
	 * Get Release Path
	 *
	 * When building a zip, the path should end in .zip. When copying,
	 * it should be an empty directory.
	 *
	 * @return string Source.
	 */
	protected static function get_release_path() {
		// Start with the plugin.
		if (!defined('BOB_ROOT_DIR')) {
			log::error('Missing BOB_ROOT_DIR.');
		}

		$path = BOB_ROOT_DIR;

		// Doing a zip?
		if ('deb' === static::RELEASE_TYPE) {
			$path .= static::SLUG . '.deb';
		}
		// Copying files?
		elseif ('copy' === static::RELEASE_TYPE) {
			$path .= static::SLUG . '/';
		}
		else {
			$path = '';
		}

		return $path;
	}

	// ----------------------------------------------------------------- end deb
}
