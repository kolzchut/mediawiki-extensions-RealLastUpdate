<?php
/**
 * Reads the source wiki's prop=reallastupdate answers
 */

namespace MediaWiki\Extension\RealLastUpdate;

/**
 * Reads the source wiki's prop=reallastupdate answers
 */
class SourceWikiApi {
	/**
	 * The last real update in one page's entry of a prop=reallastupdate answer
	 *
	 * The module answers `reallastupdate: { missing: … }` for a page it has
	 * no data for. The marker's value is `true` in formatversion=2 and an
	 * empty string in formatversion=1, so only its presence is tested. That
	 * marker, an absent key, and an entry without both fields all mean there
	 * is nothing to record.
	 *
	 * @param array $pageData One entry of the answer's query.pages
	 * @return array|null [ 'rev_id' => int, 'timestamp' => string ], or null
	 *  when the page has no data
	 */
	public static function getLastUpdate( array $pageData ): ?array {
		$rlu = $pageData['reallastupdate'] ?? null;
		if ( !is_array( $rlu ) || array_key_exists( 'missing', $rlu ) ) {
			return null;
		}
		if ( !isset( $rlu['revision'] ) || !isset( $rlu['timestamp'] ) ) {
			return null;
		}
		return [
			'rev_id' => $rlu['revision'],
			'timestamp' => $rlu['timestamp']
		];
	}
}
