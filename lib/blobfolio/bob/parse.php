<?php
/**
 * BOB: Parsing Helpers
 *
 * @package bob
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\bob;

use \blobfolio\common\file as v_file;

class parse {
	/**
	 * Parse CSV Headers
	 *
	 * The blob-common function might return false or an incomplete
	 * array. For the purposes of builders, we want to die on
	 * failure.
	 *
	 * @param string $file File.
	 * @param array $columns Columns.
	 * @return array Headers.
	 */
	public static function csv_headers(string $file, array $columns) {
		log::print('Parsing CSV headers…');

		// A false response is clear failure.
		if (false === ($headers = v_file::csv_headers($file, $columns))) {
			log::error('Could not parse CSV headers.');
		}

		// We also want to make sure we have indexes for each key.
		foreach ($headers as $v) {
			if (false === $v) {
				log::error('Could not parse CSV headers.');
			}
		}

		return $headers;
	}
}
