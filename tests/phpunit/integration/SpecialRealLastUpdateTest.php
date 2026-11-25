<?php

namespace MediaWiki\Extension\RealLastUpdate\Tests\Integration;

use MediaWiki\Extension\RealLastUpdate\SpecialRealLastUpdate;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use PermissionsError;
use SpecialPageTestBase;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\RealLastUpdate\SpecialRealLastUpdate
 * @group Database
 */
class SpecialRealLastUpdateTest extends SpecialPageTestBase {

	/** A namespace only sysops may read in these tests. */
	private const NS_RESTRICTED = 120;

	/** The instance the last executeSpecialPage() call ran, kept to inspect it. */
	private ?SpecialRealLastUpdate $page = null;

	protected function setUp(): void {
		parent::setUp();
		// Core's defaults let anonymous users edit; the sites do not.
		$this->setGroupPermissions( '*', 'edit', false );
		$this->setGroupPermissions( 'user', 'edit', true );

		if ( !$this->getServiceContainer()->getNamespaceInfo()->exists( self::NS_RESTRICTED ) ) {
			$this->overrideConfigValue( MainConfigNames::ExtraNamespaces, [
				self::NS_RESTRICTED => 'RealLastUpdateRestricted',
				self::NS_RESTRICTED + 1 => 'RealLastUpdateRestricted_talk',
			] );
		}

		// A per-namespace read restriction, as an extension such as Lockdown sets
		// through this same hook.
		$userGroupManager = $this->getServiceContainer()->getUserGroupManager();
		$this->setTemporaryHook( 'getUserPermissionsErrors',
			static function ( $title, $user, $action, &$result ) use ( $userGroupManager ) {
				if ( $action === 'read'
					&& $title->getNamespace() === self::NS_RESTRICTED
					&& !in_array( 'sysop', $userGroupManager->getUserEffectiveGroups( $user ), true )
				) {
					$result = [ 'badaccess-group0' ];
					return false;
				}
				return true;
			}
		);
	}

	protected function newSpecialPage() {
		$this->page = new SpecialRealLastUpdate( $this->getServiceContainer()->getNamespaceInfo() );
		return $this->page;
	}

	/**
	 * An editor who cannot read the restricted namespace, the case the
	 * listing must not leak to.
	 *
	 * @return User
	 */
	private function editorWithoutRestrictedRead(): User {
		$user = $this->getTestUser()->getUser();
		$this->assertTrue(
			$this->getServiceContainer()->getPermissionManager()->userHasRight( $user, 'edit' ),
			'The fixture user must hold edit, or the test proves nothing'
		);
		return $user;
	}

	private function addRestrictedPage(): string {
		$this->getExistingTestPage(
			Title::makeTitle( self::NS_RESTRICTED, 'RealLastUpdate hidden listed page' )
		);
		return 'RealLastUpdate hidden listed page';
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

	public function testNamespaceSelectorLeavesOutUnreadableNamespaces(): void {
		$option = static fn ( int $namespace ) => '/<option value=["\']' . $namespace . '["\']/';

		[ $html ] = $this->executeSpecialPage( '', null, 'qqx', $this->editorWithoutRestrictedRead() );
		$this->assertMatchesRegularExpression( $option( NS_MAIN ), $html );
		$this->assertDoesNotMatchRegularExpression( $option( self::NS_RESTRICTED ), $html );

		[ $html ] = $this->executeSpecialPage( '', null, 'qqx', $this->getTestSysop()->getUser() );
		$this->assertMatchesRegularExpression(
			$option( self::NS_RESTRICTED ),
			$html,
			'A reader of the namespace must still be offered it'
		);
	}

	public function testUnreadableNamespaceIsNotQueried(): void {
		$hidden = $this->addRestrictedPage();
		$request = new FauxRequest( [ 'namespace' => (string)self::NS_RESTRICTED ] );

		[ $html ] = $this->executeSpecialPage( '', $request, 'qqx', $this->editorWithoutRestrictedRead() );

		$this->assertStringContainsString( '(reallastupdate-namespace-unreadable)', $html );
		$this->assertStringNotContainsString( $hidden, $html );
		$this->assertNull(
			TestingAccessWrapper::newFromObject( $this->page )->pager,
			'No report query may be built for a namespace the viewer cannot read'
		);
	}

	public function testReaderOfTheNamespaceGetsItsPages(): void {
		$hidden = $this->addRestrictedPage();
		$request = new FauxRequest( [ 'namespace' => (string)self::NS_RESTRICTED ] );

		[ $html ] = $this->executeSpecialPage( '', $request, 'qqx', $this->getTestSysop()->getUser() );

		$this->assertStringContainsString( $hidden, $html );
	}

	public function testAllNamespacesLeavesOutUnreadableOnes(): void {
		$hidden = $this->addRestrictedPage();
		$this->getExistingTestPage( 'RealLastUpdate listed page' );
		$request = new FauxRequest( [ 'namespace' => '', 'limit' => '500' ] );

		[ $html ] = $this->executeSpecialPage( '', $request, 'qqx', $this->editorWithoutRestrictedRead() );

		$this->assertStringContainsString( 'RealLastUpdate listed page', $html );
		$this->assertStringNotContainsString( $hidden, $html );
	}
}
