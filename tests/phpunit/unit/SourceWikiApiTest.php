<?php

namespace MediaWiki\Extension\RealLastUpdate\Tests\Unit;

use MediaWiki\Extension\RealLastUpdate\SourceWikiApi;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\RealLastUpdate\SourceWikiApi
 */
class SourceWikiApiTest extends MediaWikiUnitTestCase {

	public function testReadsAPageWithData(): void {
		$pageData = json_decode(
			'{"pageid":12,"ns":0,"title":"Page","reallastupdate":{"revision":345,"timestamp":"20261101120000"}}',
			true
		);

		$this->assertSame(
			[ 'rev_id' => 345, 'timestamp' => '20261101120000' ],
			SourceWikiApi::getLastUpdate( $pageData )
		);
	}

	public static function provideNoData(): array {
		return [
			// The default formatversion=1 sends the boolean marker as an empty string.
			'missing, formatversion=1' => [
				'{"pageid":12,"ns":0,"title":"Page","reallastupdate":{"missing":""}}'
			],
			'missing, formatversion=2' => [
				'{"pageid":12,"ns":0,"title":"Page","reallastupdate":{"missing":true}}'
			],
			'missing beside a followed redirect' => [
				'{"pageid":12,"ns":0,"title":"Page","redirect":"",' .
					'"redirecttarget":{"pageid":13,"title":"Target"},"reallastupdate":{"missing":""}}'
			],
			'no reallastupdate key' => [
				'{"pageid":12,"ns":0,"title":"Page"}'
			],
			'no timestamp' => [
				'{"pageid":12,"ns":0,"title":"Page","reallastupdate":{"revision":345}}'
			],
			'not an object' => [
				'{"pageid":12,"ns":0,"title":"Page","reallastupdate":""}'
			],
		];
	}

	/**
	 * @dataProvider provideNoData
	 */
	public function testAPageWithoutDataIsSkipped( string $json ): void {
		$pageData = json_decode( $json, true );

		$this->assertNull( SourceWikiApi::getLastUpdate( $pageData ) );
	}
}
