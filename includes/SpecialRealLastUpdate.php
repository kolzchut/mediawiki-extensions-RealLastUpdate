<?php
/**
 * Special page to display pages with their real last human update dates
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup SpecialPage
 */

namespace MediaWiki\Extension\RealLastUpdate;

use HTMLForm;
use SpecialPage;

class SpecialRealLastUpdate extends SpecialPage {
	/** @var RealLastUpdatePager|null */
	protected ?RealLastUpdatePager $pager = null;

	/** @var array */
	protected array $formFilter = [];

	public function __construct() {
		parent::__construct( 'RealLastUpdate' );
	}

	/**
	 * @param string|null $par
	 */
	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();
		$out = $this->getOutput();
		$out->addModuleStyles( 'mediawiki.special' );

		// Parse request parameters
		$request = $this->getRequest();
		$this->formFilter['namespace'] = $request->getIntOrNull( 'namespace' );
		$this->formFilter['articletype'] = $request->getVal( 'articletype' );
		$this->formFilter['contentarea'] = $request->getVal( 'contentarea' );

		// Show the filter form
		$this->showFilterForm();

		// Show the pager
		$this->pager = new RealLastUpdatePager( $this, $this->formFilter );
		$out->addParserOutputContent( $this->pager->getFullOutput() );
	}

	/**
	 * Show the filter form with namespace, article type and content area filters
	 */
	private function showFilterForm() {
		$formDescriptor = [
			'namespace' => [
				'type' => 'namespaceselect',
				'name' => 'namespace',
				'id' => 'namespace',
				'label-message' => 'reallastupdate-namespace',
				'default' => $this->formFilter['namespace'] ?? '',
				'all' => '',
			]
		];

		// Add article type filter if ArticleType extension is loaded
		if ( \ExtensionRegistry::getInstance()->isLoaded( 'ArticleType' ) ) {
			$articleTypes = [];
			$validTypes = \MediaWiki\Extension\ArticleType\ArticleType::getValidArticleTypes();

			// Create a dropdown with article types
			foreach ( $validTypes as $type ) {
				$articleTypes[$type] = $type;
			}

			if ( !empty( $articleTypes ) ) {
				$formDescriptor['articletype'] = [
					'type' => 'select',
					'name' => 'articletype',
					'id' => 'articletype',
					'label-message' => 'reallastupdate-articletype',
					'options' => array_merge( [ $this->msg( 'reallastupdate-all' )->text() => '' ], $articleTypes ),
					'default' => $this->formFilter['articletype'] ?? '',
				];
			}
		}

		// Add content area filter if ArticleContentArea extension is loaded
		if ( class_exists( 'MediaWiki\Extension\ArticleContentArea\ArticleContentArea' ) ) {
			$contentAreas = [];
			$validAreas = \MediaWiki\Extension\ArticleContentArea\ArticleContentArea::getAssignedContentAreas();

			// Create a dropdown with content areas
			foreach ( $validAreas as $key => $label ) {
				$contentAreas[$label] = $key;
			}

			if ( !empty( $contentAreas ) ) {
				$formDescriptor['contentarea'] = [
					'type' => 'select',
					'name' => 'contentarea',
					'id' => 'contentarea',
					'label-message' => 'reallastupdate-contentarea',
					'options' => array_merge( [ $this->msg( 'reallastupdate-all' )->text() => '' ], $contentAreas ),
					'default' => $this->formFilter['contentarea'] ?? '',
				];
			}
		}

		$form = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$form->setMethod( 'get' )
			->setSubmitTextMsg( 'reallastupdate-filter' )
			->setWrapperLegendMsg( 'reallastupdate-filter-legend' )
			->prepareForm()
			->displayForm( false );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'pages';
	}
}
