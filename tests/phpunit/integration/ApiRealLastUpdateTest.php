<?php

namespace MediaWiki\Extension\RealLastUpdate\Tests\Integration;

use ApiTestCase;
use MediaWiki\MainConfigNames;
use MediaWiki\Title\Title;

/**
 * @covers \MediaWiki\Extension\RealLastUpdate\Api\ApiRealLastUpdate
 * @group API
 * @group Database
 * @group medium
 */
class ApiRealLastUpdateTest extends ApiTestCase {

	/** A namespace only sysops may read in these tests. */
	private const NS_RESTRICTED = 120;

	/** A page in a readable namespace that only sysops may read, as a per-title rule would make it. */
	private const RESTRICTED_SINGLE_TITLE = 'RealLastUpdate_single_hidden_page';

	/** The content language the wiki booted with. */
	private static string $siteLanguageCode;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$siteLanguageCode = $GLOBALS['wgLanguageCode'];
	}

	protected function setUp(): void {
		parent::setUp();

		// The API test base switches the content language to 'en'. Semantic
		// MediaWiki, loaded on the sites, refuses to rebuild the namespace list
		// under a language other than the one it booted with, so put it back.
		$this->setContentLang( self::$siteLanguageCode );

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
					&& ( $title->getNamespace() === self::NS_RESTRICTED
						|| $title->getDBkey() === self::RESTRICTED_SINGLE_TITLE )
					&& !in_array( 'sysop', $userGroupManager->getUserEffectiveGroups( $user ), true )
				) {
					$result = [ 'badaccess-group0' ];
					return false;
				}
				return true;
			}
		);
	}

	private function query( Title $title, $performer, array $extra = [] ): array {
		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'prop' => 'reallastupdate',
			'titles' => $title->getPrefixedText(),
		] + $extra, null, false, $performer );
		return $result;
	}

	private function anonymous() {
		return $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
	}

	public function testAnonymousCallerGetsDataForAReadablePage(): void {
		$page = $this->getExistingTestPage( 'RealLastUpdate API public page' );

		$result = $this->query( $page->getTitle(), $this->anonymous() );

		$this->assertArrayHasKey(
			'revision',
			$result['query']['pages'][$page->getId()]['reallastupdate'],
			'An anonymous caller must still get data for a page it can read, or the next test proves nothing'
		);
	}

	public function testAnonymousCallerGetsNoDataForAnUnreadablePage(): void {
		$page = $this->getExistingTestPage(
			Title::makeTitle( self::NS_RESTRICTED, 'RealLastUpdate API hidden page' )
		);

		$result = $this->query( $page->getTitle(), $this->anonymous() );

		$this->assertArrayNotHasKey( 'reallastupdate', $result['query']['pages'][$page->getId()] );
		$this->assertArrayHasKey( 'reallastupdate', $result['warnings'] ?? [] );
	}

	public function testReaderOfTheNamespaceGetsData(): void {
		$page = $this->getExistingTestPage(
			Title::makeTitle( self::NS_RESTRICTED, 'RealLastUpdate API hidden page' )
		);

		$result = $this->query( $page->getTitle(), $this->getTestSysop()->getUser() );

		$this->assertArrayHasKey( 'revision', $result['query']['pages'][$page->getId()]['reallastupdate'] );
	}

	public function testFollowedRedirectDoesNotRevealAnUnreadableTarget(): void {
		$target = $this->getExistingTestPage(
			Title::makeTitle( self::NS_RESTRICTED, 'RealLastUpdate API hidden target' )
		);
		$redirect = Title::makeTitle( NS_MAIN, 'RealLastUpdate API redirect to hidden' );
		$redirectId = $this->editPage(
			$redirect,
			'#REDIRECT [[' . $target->getTitle()->getPrefixedText() . ']]'
		)->getNewRevision()->getPageId();

		$result = $this->query( $redirect, $this->anonymous(), [ 'rlufollowredirects' => 1 ] );

		$pageResult = $result['query']['pages'][$redirectId];
		$this->assertTrue( $pageResult['redirect'] ?? false, 'The fixture must be a redirect' );
		$this->assertArrayNotHasKey( 'redirecttarget', $pageResult );
		$this->assertArrayNotHasKey( 'reallastupdate', $pageResult );
		$this->assertArrayHasKey( 'reallastupdate', $result['warnings'] ?? [] );
	}

	public function testPerTitleRestrictionInAReadableNamespace(): void {
		$page = $this->getExistingTestPage( Title::makeTitle( NS_MAIN, self::RESTRICTED_SINGLE_TITLE ) );

		$result = $this->query( $page->getTitle(), $this->anonymous() );
		$this->assertArrayNotHasKey( 'reallastupdate', $result['query']['pages'][$page->getId()] );

		$result = $this->query( $page->getTitle(), $this->getTestSysop()->getUser() );
		$this->assertArrayHasKey( 'revision', $result['query']['pages'][$page->getId()]['reallastupdate'] );
	}
}
