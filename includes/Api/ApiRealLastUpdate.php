<?php
/**
 * API module to get real last update information
 */

namespace MediaWiki\Extension\RealLastUpdate\Api;

use ApiQuery;
use ApiQueryBase;
use MediaWiki\Extension\RealLastUpdate\RealLastUpdate;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module to get real last update information
 */
class ApiRealLastUpdate extends ApiQueryBase {
	/**
	 * Constructor
	 *
	 * @param ApiQuery $mainModule
	 * @param string $moduleName
	 * @param string $modulePrefix
	 */
	public function __construct(
		ApiQuery $mainModule,
		$moduleName,
		$modulePrefix = ''
	) {
		parent::__construct( $mainModule, $moduleName, $modulePrefix );
	}

	/**
	 * Execute the API module
	 */
	public function execute() {
		// Get the titles from the PageSet
		$pageSet = $this->getPageSet();
		$titles = $pageSet->getGoodTitles();

		if ( !count( $titles ) ) {
			return;
		}

		// Debug logging for API requests
		$this->addDebugInfo( "RealLastUpdate API processing " . count( $titles ) . " titles" );

		$followRedirects = $this->getParameter( 'followredirects' );
		$result = $this->getResult();

		foreach ( $titles as $pageId => $title ) {
			$isRedirect = $title->isRedirect();

			$this->addDebugInfo( "Processing title: " . $title->getPrefixedText() . " (ID: $pageId)" );

			$path = [ 'query', 'pages', $pageId ];
			$result->addValue( $path, 'pageid', $pageId );
			$result->addValue( $path, 'title', $title->getPrefixedText() );

			if ( $isRedirect && $followRedirects ) {
				$this->addDebugInfo( "Following redirect for: " . $title->getPrefixedText() );
				$result->addValue( $path, 'redirect', true );

				// First redirect
				$targetTitle = $title->getRedirectTarget();

				if ( !$targetTitle || !$targetTitle->exists() ) {
					$result->addValue( $path, 'redirecttarget', [
						'missing' => true
					] );
					continue;
				}

				$targetPageId = $targetTitle->getArticleID();
				$targetIsRedirect = $targetTitle->isRedirect();

				// Check for second level redirect
				if ( $targetIsRedirect && $followRedirects ) {
					$this->addDebugInfo( "Following second-level redirect from: " . $targetTitle->getPrefixedText() );

					$finalTitle = $targetTitle->getRedirectTarget();

					if ( !$finalTitle || !$finalTitle->exists() ) {
						$result->addValue( $path, 'redirecttarget', [
							'pageid' => $targetPageId,
							'title' => $targetTitle->getPrefixedText(),
							'redirect' => true,
							'finaltarget' => [
								'missing' => true
							]
						] );
						continue;
					}

					$finalPageId = $finalTitle->getArticleID();

					// Add redirect info
					$result->addValue( $path, 'redirecttarget', [
						'pageid' => $targetPageId,
						'title' => $targetTitle->getPrefixedText(),
						'redirect' => true,
						'finaltarget' => [
							'pageid' => $finalPageId,
							'title' => $finalTitle->getPrefixedText()
						]
					] );

					// Get data for final target
					$lastUpdate = RealLastUpdate::getLastRealEdit( $finalPageId );
					if ( $lastUpdate ) {
						$this->addDebugInfo( "Found final target update data: rev={$lastUpdate['rev_id']}, ts={$lastUpdate['timestamp']}" );
						$result->addValue( $path, 'reallastupdate', [
							'revision' => $lastUpdate['rev_id'],
							'timestamp' => $lastUpdate['timestamp']
						] );
					} else {
						$result->addValue( $path, 'reallastupdate', [
							'missing' => true
						] );
					}
				} else {
					// Only one level redirect
					$result->addValue( $path, 'redirecttarget', [
						'pageid' => $targetPageId,
						'title' => $targetTitle->getPrefixedText()
					] );

					// Get data for target
					$lastUpdate = RealLastUpdate::getLastRealEdit( $targetPageId );
					if ( $lastUpdate ) {
						$this->addDebugInfo( "Found target update data: rev={$lastUpdate['rev_id']}, ts={$lastUpdate['timestamp']}" );
						$result->addValue( $path, 'reallastupdate', [
							'revision' => $lastUpdate['rev_id'],
							'timestamp' => $lastUpdate['timestamp']
						] );
					} else {
						$result->addValue( $path, 'reallastupdate', [
							'missing' => true
						] );
					}
				}
			} else {
				// Not a redirect or not following redirects
				if ( $isRedirect ) {
					$result->addValue( $path, 'redirect', true );
				}

				$lastUpdate = RealLastUpdate::getLastRealEdit( $pageId );
				if ( $lastUpdate ) {
					$this->addDebugInfo( "Found update data: rev={$lastUpdate['rev_id']}, ts={$lastUpdate['timestamp']}" );
					$result->addValue( $path, 'reallastupdate', [
						'revision' => $lastUpdate['rev_id'],
						'timestamp' => $lastUpdate['timestamp']
					] );
				} else {
					$this->addDebugInfo( "No update data found for page $pageId" );
					$result->addValue( $path, 'reallastupdate', [
						'missing' => true
					] );
				}
			}
		}
	}

	/**
	 * Add debug information to the API response if requested
	 *
	 * @param string $info Debug information to add
	 */
	private function addDebugInfo( $info ) {
		if ( $this->getMain()->getParameter( 'debug' ) ) {
			$this->getResult()->addValue(
				[ 'query', 'debuginfo' ],
				null,
				$info
			);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams(): array {
		return [
			'debug' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
				\ApiBase::PARAM_HELP_MSG => 'api-help-param-debug',
			],
			'followredirects' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
				\ApiBase::PARAM_HELP_MSG => 'api-help-param-followredirects',
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages(): array {
		return [
			'action=query&prop=reallastupdate&titles=Main_Page' => 'apihelp-query+reallastupdate-example-1',
			'action=query&prop=reallastupdate&titles=Main_Page|Help|About' => 'apihelp-query+reallastupdate-example-2',
			'action=query&generator=allpages&prop=reallastupdate' => 'apihelp-query+reallastupdate-example-3',
			'action=query&prop=reallastupdate&titles=SomeRedirect&followredirects=1' => 'apihelp-query+reallastupdate-example-4',
		];
	}
}
