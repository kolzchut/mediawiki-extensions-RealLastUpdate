<?php
/**
 * API module to get real last update information
 *
 * @file
 */

namespace MediaWiki\Extension\RealLastUpdate\Api;

use ApiBase;
use ApiMain;
use ApiUsageException;
use MediaWiki\Extension\RealLastUpdate\RealLastUpdate;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module to get real last update information
 */
class ApiRealLastUpdate extends ApiBase {

	/**
	 * Constructor
	 *
	 * @param ApiMain $mainModule
	 * @param string $moduleName
	 * @param string $modulePrefix
	 */
	public function __construct(
		ApiMain $mainModule,
		$moduleName,
		$modulePrefix = ''
	) {
		parent::__construct( $mainModule, $moduleName, $modulePrefix );
	}

	/**
	 * Execute the API module
	 * @throws ApiUsageException
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$wikiPage = $this->getTitleOrPageId( $params );

		// Check if this is a redirect and fail early
		if ( $wikiPage->isRedirect() ) {
			$this->dieWithError( [ 'reallastupdate-apierror-is-redirect', $wikiPage->getTitle() ] );
		}

		$result = RealLastUpdate::getLastRealEdit( $wikiPage->getId() );
		if ( !$result ) {
			$this->dieWithError( [ 'reallastupdate-apierror-no-real-revision', $wikiPage->getTitle() ] );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'pageid' => $wikiPage->getId(),
			'title' => $wikiPage->getTitle()->getText(),
			'revision' => $result['rev_id'],
			'timestamp' => $result['timestamp'],
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams(): array {
		return [
			'pageid' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

}

