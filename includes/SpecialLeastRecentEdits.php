<?php
/**
 * Special page to display pages with least recent human edits
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

use HtmlForm;
use MediaWiki\MediaWikiServices;
use SpecialPage;
use TablePager;
use Title;
use Wikimedia\Rdbms\IDatabase;

class SpecialLeastRecentEdits extends SpecialPage {
	/** @var LeastRecentEditsPager|null */
	protected $pager = null;

	/** @var array */
	protected $formFilter = [];

	public function __construct() {
		parent::__construct( 'LeastRecentEdits' );
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
		$this->pager = new LeastRecentEditsPager( $this, $this->formFilter );
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
		if ( class_exists( 'MediaWiki\Extension\ArticleType\ArticleType' ) ) {
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

/**
 * TablePager for displaying pages with least recent edits
 */
class LeastRecentEditsPager extends TablePager {
	/**
	 * @var SpecialLeastRecentEdits
	 */
	protected $specialPage;

	/**
	 * @var array
	 */
	protected $filterOptions;

	/**
	 * @param SpecialLeastRecentEdits $specialPage
	 * @param array $filterOptions
	 */
	public function __construct( $specialPage, $filterOptions ) {
		parent::__construct( $specialPage->getContext() );
		$this->specialPage = $specialPage;
		$this->filterOptions = $filterOptions;
		$this->mDefaultDirection = IndexPager::DIR_ASCENDING; // Oldest first
	}

	/**
	 * @inheritDoc
	 */
	public function getQueryInfo() {
		$queryInfo = [
			'tables' => [ 'page', 'real_last_update' ],
			'fields' => [
				'page_id',
				'page_namespace',
				'page_title',
				'real_last_update_timestamp' => 'real_last_update.rlud_timestamp',
				'real_last_update_revid' => 'real_last_update.rlud_rev_id',
			],
			'conds' => [],
			'join_conds' => [
				'real_last_update' => [ 'LEFT JOIN', 'page_id = real_last_update.rlud_page_id' ],
			],
			'options' => [],
		];

		// Filter by namespace if specified
		if ( $this->filterOptions['namespace'] !== null ) {
			$queryInfo['conds']['page_namespace'] = $this->filterOptions['namespace'];
		}

		// Add ArticleType join if extension is loaded and filter is set
		if ( class_exists( 'MediaWiki\Extension\ArticleType\ArticleType' ) && $this->filterOptions['articletype'] ) {
			$articleTypeJoin = \MediaWiki\Extension\ArticleType\ArticleType::getJoin(
				$this->filterOptions['articletype']
			);
			$queryInfo['tables'] = array_merge( $queryInfo['tables'], $articleTypeJoin['tables'] );
			$queryInfo['fields'] = array_merge( $queryInfo['fields'], $articleTypeJoin['fields'] );
			$queryInfo['join_conds'] = array_merge( $queryInfo['join_conds'], $articleTypeJoin['join_conds'] );
		}

		// Add ArticleContentArea join if extension is loaded and filter is set
		if ( class_exists( 'MediaWiki\Extension\ArticleContentArea\ArticleContentArea' ) && $this->filterOptions['contentarea'] ) {
			$contentAreaJoin = \MediaWiki\Extension\ArticleContentArea\ArticleContentArea::getJoin(
				$this->filterOptions['contentarea']
			);
			$queryInfo['tables'] = array_merge( $queryInfo['tables'], $contentAreaJoin['tables'] );
			$queryInfo['fields'] = array_merge( $queryInfo['fields'], $contentAreaJoin['fields'] );
			$queryInfo['join_conds'] = array_merge( $queryInfo['join_conds'], $contentAreaJoin['join_conds'] );
		}

		return $queryInfo;
	}

	/**
	 * @inheritDoc
	 */
	public function getFieldNames() {
		return [
			'page_title' => $this->msg( 'reallastupdate-page' )->text(),
			'real_last_update_timestamp' => $this->msg( 'reallastupdate-timestamp' )->text(),
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getDefaultSort() {
		return 'real_last_update_timestamp';
	}

	/**
	 * @inheritDoc
	 */
	public function isFieldSortable( $field ) {
		return $field === 'real_last_update_timestamp';
	}

	/**
	 * @inheritDoc
	 */
	public function formatValue( $name, $value ) {
		$row = $this->mCurrentRow;
		
		switch ( $name ) {
			case 'page_title':
				$title = Title::makeTitle( $row->page_namespace, $row->page_title );
				return $this->getLinkRenderer()->makeLink( $title );
			
			case 'real_last_update_timestamp':
				if ( !$value ) {
					return $this->msg( 'reallastupdate-no-data' )->escaped();
				}
				
				$lang = $this->getLanguage();
				$formatted = $lang->timeanddate( $value, true );
				
				// If we have a revision ID, link to the diff
				if ( $row->real_last_update_revid ) {
					$title = Title::makeTitle( $row->page_namespace, $row->page_title );
					$url = $title->getLocalURL( [ 'oldid' => $row->real_last_update_revid ] );
					return \Linker::makeExternalLink( $url, $formatted );
				}
				
				return $formatted;
				
			default:
				return $value;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getDefaultQuery() {
		$defaultQuery = parent::getDefaultQuery();
		
		if ( isset( $this->filterOptions['namespace'] ) ) {
			$defaultQuery['namespace'] = $this->filterOptions['namespace'];
		}
		
		if ( isset( $this->filterOptions['articletype'] ) && $this->filterOptions['articletype'] !== '' ) {
			$defaultQuery['articletype'] = $this->filterOptions['articletype'];
		}
		
		if ( isset( $this->filterOptions['contentarea'] ) && $this->filterOptions['contentarea'] !== '' ) {
			$defaultQuery['contentarea'] = $this->filterOptions['contentarea'];
		}
		
		return $defaultQuery;
	}
}
