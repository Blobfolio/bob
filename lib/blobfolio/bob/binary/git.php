<?php
/**
 * BOB: Git
 *
 * @see {https://github.com/theseer/Autoload}
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob\binary;

use \blobfolio\bob\utility;
use \blobfolio\common\ref\file as r_file;

class git extends \blobfolio\bob\base\binary {
	const NAME = 'git';

	const RELEASE_API = 'https://api.github.com/repos/%s/%s/releases';
	const RELEASE_URL = 'https://github.com/%s/%s/archive/%s.tar.gz';


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

		if (!defined('BOB_GIT_BINARY')) {
			define('BOB_GIT_BINARY', static::find_command('git'));
		}

		$this->binary = BOB_GIT_BINARY;
		return true;
	}

	// ----------------------------------------------------------------- end setup



	// -----------------------------------------------------------------
	// Commands
	// -----------------------------------------------------------------

	/**
	 * Clone Repo
	 *
	 * @param string $url Repo URL.
	 * @param string $out Output path.
	 * @return bool True/false.
	 */
	public function clone(string $url, string $out) {
		$nicename = basename($url);
		static::log("Cloning {$nicename}…");

		if (!$this->exists()) {
			utility::log('Git is not initialized.', 'error');
		}

		r_sanitize::url($url);
		if (!$url) {
			utility::log('Invalid Git repo.', 'error');
		}

		r_file::path($out, false);
		if (!$out) {
			utility::log('Invalid output directory.', 'error');
		}

		// Compile the command.
		if (false === ($cmd = $this->get_command(array(
			'clone',
			escapeshellarg($url),
			escapeshellarg($out),
		)))) {
			utility::log('Could not clone repository.', 'error');
			return false;
		}

		// Try to execute it!
		$out = $this->exec($cmd, $dir);

		return (false !== $out);
	}

	/**
	 * Get Latest Release
	 *
	 * @param string $repo Repo slug (maker/project).
	 * @param bool $download Download.
	 * @return array Release information.
	 */
	public function get_latest_release(string $repo, bool $download=true) {
		$out = array(
			'account'=>'',
			'project'=>'',
			'version'=>'',
			'url'=>'',
			'tar'=>'',
		);

		// Validate the repo and tease out account/project.
		if (!preg_match('/^([a-z\d\-\_]+)\/([a-z\d\-\_]+)$/i', $repo, $matches)) {
			utility::log("The repo value should be formatted like \033[2maccount/project\033[0m.", 'error');
		}
		$out['account'] = $matches[1];
		$out['project'] = $matches[2];

		// Oh and Git should exist.
		if (!$this->exists()) {
			utility::log('Git is not initialized.', 'error');
		}

		// Pull release information.
		$url = sprintf(
			static::RELEASE_API,
			$out['account'],
			$out['project']
		);
		$tmp = utility::get_remote($url);
		if (!isset($tmp[$url]) || (false === $tmp[$url])) {
			utility::log('Could not pull Github release information.', 'error');
		}
		$json = file_get_contents($tmp[$url]);
		$json = json_decode($json, true);
		if (!isset($json[0]['tag_name'])) {
			utility::log('Could not pull Github release information.', 'error');
		}

		// Version is tag.
		$out['version'] = $json[0]['tag_name'];

		// We should have enough to build a URL.
		$out['url'] = sprintf(
			static::RELEASE_URL,
			$out['account'],
			$out['project'],
			$out['version']
		);

		// The version might be prefixed with a "v", which we don't want
		// any more.
		if (0 === strpos($out['version'], 'v')) {
			$out['version'] = substr($out['version'], 1);
		}

		// Should we download the file?
		if ($download) {
			$tmp = utility::get_remote($out['url']);
			if (!isset($tmp[$out['url']]) || (false === $tmp[$out['url']])) {
				utility::log('Could not download release.', 'error');
			}
			$out['tar'] = $tmp[$out['url']];
		}

		return $out;
	}

	// ----------------------------------------------------------------- end commands
}
