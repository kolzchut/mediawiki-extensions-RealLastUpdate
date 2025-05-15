<?php
/**
 * TablePager for displaying pages with their real last update information
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
 * @ingroup Pager
 */

namespace MediaWiki\Extension\RealLastUpdate;

use ExtensionRegistry;
use IndexPager;
use TablePager;
use Title;

/**
 * TablePager for displaying pages with their real last update information
 */
class RealLastUpdatePager extends TablePager {
	/**
	 * @var SpecialRealLastUpdate
	 */
	protected $specialPage;

	/**
	 * @var array
	 */
	protected $filterOptions;

	/**
	 * @var bool
	 */
	protected $showAllDates;

	/**
	 * @param SpecialRealLastUpdate $specialPage
	 * @param array $filterOptions
	 */
	public function __construct( $specialPage, $filterOptions ) {
		parent::__construct( $specialPage->getContext() );

		$this->getOutput()->addModules( 'ext.reallastupdate.special' );
		$this->specialPage = $specialPage;
		$this->filterOptions = $filterOptions;
		// Oldest first
		$this->mDefaultDirection = IndexPager::DIR_ASCENDING;

		// Get user preference for showing all dates
		$this->showAllDates = $this->getUser()->getOption( 'reallastupdate-showalldates', false );
	}

	/**
	 * @inheritDoc
	 */
	public function getQueryInfo() {
		$queryInfo = [
			'tables' => [ 'page', 'real_last_update', 'revision' ],
			'fields' => [
				'page_id',
				'page_namespace',
				'page_title',
				'real_last_update_timestamp' => 'real_last_update.rlud_timestamp',
				'real_last_update_revid' => 'real_last_update.rlud_rev_id',
				'regular_update_timestamp' => 'MAX(revision.rev_timestamp)',
				'regular_update_revid' => 'MAX(revision.rev_id)',
			],
			'conds' => [],
			'join_conds' => [
				'real_last_update' => [ 'LEFT JOIN', 'page_id = real_last_update.rlud_page_id' ],
				'revision' => [ 'LEFT JOIN', 'page_id = revision.rev_page' ],
			],
			'options' => [ 'GROUP BY' => [
				'page_id', 'page_namespace', 'page_title', 'real_last_update.rlud_timestamp',
				'real_last_update.rlud_rev_id'
			] ],
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
		if ( $this->filterOptions['contentarea'] &&
			ExtensionRegistry::getInstance()->isLoaded( 'ArticleContentArea' )
		) {
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
			'regular_update_timestamp' => $this->msg( 'reallastupdate-regular-timestamp' )->text(),
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
		return $field === 'real_last_update_timestamp' || $field === 'regular_update_timestamp';
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
				$formatted = $lang->userTimeAndDate( $value, $this->getUser() );

				// If we have a revision ID, link to the diff
				if ( $row->real_last_update_revid ) {
					$title = Title::makeTitle( $row->page_namespace, $row->page_title );
					$url = $title->getLocalURL( [ 'oldid' => $row->real_last_update_revid ] );
					return \Linker::makeExternalLink( $url, $formatted );
				}

				return $formatted;

			case 'regular_update_timestamp':
				if ( !$value ) {
					return $this->msg( 'reallastupdate-no-regular-data' )->escaped();
				}

				$lang = $this->getLanguage();
				$formattedDate = $lang->timeanddate( $value, true );
				// If we have a revision ID, link to the diff
				if ( $row->regular_update_revid ) {
					$title = Title::makeTitle( $row->page_namespace, $row->page_title );
					$url = $title->getLocalURL( [ 'oldid' => $row->regular_update_revid ] );
					$formattedDate = \Linker::makeExternalLink( $url, $formattedDate );
				}

				// Check if the values are identical and we're not showing all dates
				if ( $row->real_last_update_timestamp &&
					$value === $row->real_last_update_timestamp ) {

					// Create container with both spans for identical timestamps
					return '<div class="reallastupdate-container">' .
						'<span class="reallastupdate-identical-text">' .
						$this->msg( 'reallastupdate-identical-to-human' )->escaped() .
						'</span>' .
						'<span class="reallastupdate-identical-date" style="display: none;">' .
						$formattedDate .
						'</span>' .
						'</div>';
				} else {
					return $formattedDate;
				}

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

	/**
	 * add a container for our toggle button
	 *
	 * @return string HTML
	 */
	public function getStartBody(): string {
		$body = parent::getStartBody();

		// Prepare a location for the toggle button. It will be added by Javascript code
		$toggleHTML = \Html::element(
			'div',
			[ 'class' => 'reallastupdate-toggle-container' ],
		);

		return $body . $toggleHTML;
	}
}
