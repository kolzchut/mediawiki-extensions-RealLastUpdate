<?php

namespace MediaWiki\Extension\RealLastUpdate\Tests\Integration;

use MediaWiki\Extension\RealLastUpdate\SpecialRealLastUpdate;
use PermissionsError;
use SpecialPageTestBase;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\RealLastUpdate\SpecialRealLastUpdate
 * @group Database
 */
class SpecialRealLastUpdateTest extends SpecialPageTestBase {

	/** The instance the last executeSpecialPage() call ran, kept to inspect it. */
	private ?SpecialRealLastUpdate $page = null;

	protected function setUp(): void {
		parent::setUp();
		// Core's defaults let anonymous users edit; the sites do not.
		$this->setGroupPermissions( '*', 'edit', false );
		$this->setGroupPermissions( 'user', 'edit', true );
	}

	protected function newSpecialPage() {
		$this->page = new SpecialRealLastUpdate();
		return $this->page;
	}

	public function testRestrictionIsEdit(): void {
		$this->assertSame( 'edit', $this->newSpecialPage()->getRestriction() );
	}

	public function testAnonymousUserIsRefusedBeforeTheReportIsBuilt(): void {
		$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );

		try {
			$this->executeSpecialPage( '', null, 'qqx', $anon );
			$this->fail( 'An anonymous user must not get the report' );
		} catch ( PermissionsError $e ) {
			// Expected: the refusal is the permission error page.
			$this->addToAssertionCount( 1 );
		}

		$this->assertNull(
			TestingAccessWrapper::newFromObject( $this->page )->pager,
			'No pager, and so no report query, may be built for a refused user'
		);
	}

	public function testUserWithEditGetsTheReport(): void {
		$this->getExistingTestPage( 'RealLastUpdate listed page' );
		$user = $this->getTestUser()->getUser();
		$this->assertTrue(
			$this->getServiceContainer()->getPermissionManager()->userHasRight( $user, 'edit' ),
			'The fixture user must hold edit, or this test proves nothing'
		);

		[ $html ] = $this->executeSpecialPage( '', null, 'qqx', $user );

		$this->assertNotNull( TestingAccessWrapper::newFromObject( $this->page )->pager );
		$this->assertStringContainsString( '(reallastupdate-filter-legend)', $html );
		$this->assertStringContainsString( 'RealLastUpdate listed page', $html );
	}
}
