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
use Html;
use IndexPager;
use MalformedTitleException;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserOptionsLookup;
use TablePager;
use TitleParser;
use TitleValue;

/**
 * TablePager for displaying pages with their real last update information
 */
class RealLastUpdatePager extends TablePager {
	/**
	 * @var SpecialRealLastUpdate
	 */
	protected SpecialRealLastUpdate $specialPage;

	/**
	 * @var array
	 */
	protected array $filterOptions;

	/**
	 * @var bool
	 */
	protected $showAllDates;

	/**
	 * @var LinkRenderer
	 */
	protected LinkRenderer $linkRenderer;

	/**
	 * @var UserOptionsLookup
	 */
	protected UserOptionsLookup $userOptionsLookup;

	/**
	 * @var TitleParser
	 */
	protected TitleParser $titleParser;

	/**
	 * @param SpecialRealLastUpdate $specialPage
	 * @param array $filterOptions
	 * @param UserOptionsLookup|null $userOptionsLookup
	 * @param TitleParser|null $titleParser
	 */
	public function __construct(
		$specialPage,
		$filterOptions,
		UserOptionsLookup $userOptionsLookup = null,
		TitleParser $titleParser = null
	) {
		parent::__construct( $specialPage->getContext() );

		// Allow falling back to service container for backwards compatibility
		$services = MediaWikiServices::getInstance();
		$this->userOptionsLookup = $userOptionsLookup ?? $services->getUserOptionsLookup();
		$this->titleParser = $titleParser ?? $services->getTitleParser();

		$this->getOutput()->addModules( 'ext.reallastupdate.special' );
		$this->specialPage = $specialPage;
		$this->filterOptions = $filterOptions;

		// Get user preference for showing all dates
		$this->showAllDates = $this->userOptionsLookup
			->getOption( $this->getUser(), 'reallastupdate-showalldates', false );
	}

	/**
	 * @inheritDoc
	 */
	public function getQueryInfo(): array {
		$queryInfo = [
			'tables' => [ 'page', 'real_last_update', 'revision' ],
			'fields' => [
				'page_id',
				'page_namespace',
				'page_title',
				// Use simple keys for all computed fields
				'rlud_timestamp' => 'real_last_update.rlud_timestamp',
				'real_last_update_revid' => 'real_last_update.rlud_rev_id',
				'rev_timestamp' => 'MAX(revision.rev_timestamp)',
				'regular_update_revid' => 'MAX(revision.rev_id)',
			],
			'conds' => [
				'page_is_redirect' => false
			],
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

		// Only join with cross-wiki table if we're not on the source wiki
		if ( !RealLastUpdate::isSourceWiki() ) {
			$crossWikiJoin = RealLastUpdate::getCrossWikiJoin();
			$queryInfo['tables'] = array_merge( $queryInfo['tables'], $crossWikiJoin['tables'] );
			$queryInfo['fields'] = array_merge( $queryInfo['fields'], $crossWikiJoin['fields'] );
			$queryInfo['join_conds'] = array_merge( $queryInfo['join_conds'], $crossWikiJoin['join_conds'] );

			if ( !isset( $queryInfo['fields']['rlucw_source_timestamp'] ) ) {
				$queryInfo['fields']['rlucw_source_timestamp'] = 'real_last_update_cross_wiki.rlucw_source_timestamp';
			}
			if ( !isset( $queryInfo['fields']['rlucw_source_title'] ) ) {
				$queryInfo['fields']['rlucw_source_title'] = 'real_last_update_cross_wiki.rlucw_source_title';
			}
			 // Use a simple key for the computed field
			$queryInfo['fields']['rlud_diff_days'] = "IF(real_last_update.rlud_timestamp IS NOT NULL AND real_last_update_cross_wiki.rlucw_source_timestamp IS NOT NULL, DATEDIFF(STR_TO_DATE(real_last_update_cross_wiki.rlucw_source_timestamp, '%Y%m%d%H%i%S'), STR_TO_DATE(real_last_update.rlud_timestamp, '%Y%m%d%H%i%S')), NULL)";
			$queryInfo['options']['GROUP BY'][] = 'real_last_update_cross_wiki.rlucw_source_timestamp';
			$queryInfo['options']['GROUP BY'][] = 'real_last_update_cross_wiki.rlucw_source_title';
		}

		return $queryInfo;
	}

	/**
	 * @inheritDoc
	 */
	public function getFieldNames(): array {
		$fields = [
			'page_title' => $this->msg( 'reallastupdate-page' )->text(),
			'rlud_timestamp' => $this->msg( 'reallastupdate-timestamp' )->text(),
			'rev_timestamp' => $this->msg( 'reallastupdate-regular-timestamp' )->text(),
		];

		// Only show source wiki and diff columns if we're not on the source wiki
		if ( !RealLastUpdate::isSourceWiki() ) {
			$fields['rlucw_source_timestamp'] = $this->msg( 'reallastupdate-source-timestamp' )->text();
			$fields['rlud_diff_days'] = $this->msg( 'reallastupdate-diff-days' )->text();
		}

		return $fields;
	}

	/**
	 * @inheritDoc
	 */
	public function getDefaultSort(): string {
		return 'rlud_timestamp';
	}

	/**
	 * @inheritDoc
	 */
	public function isFieldSortable( $field ): bool {
		// Only allow sorting by diff column if not on source wiki
		if ( RealLastUpdate::isSourceWiki() && $field === 'rlud_diff_days' ) {
			return false;
		}
		return $field === 'rlud_timestamp' ||
			   $field === 'rev_timestamp' ||
			   $field === 'rlucw_source_timestamp' ||
			   $field === 'rlud_diff_days';
	}

	/**
	 * @inheritDoc
	 */
	public function formatValue( $name, $value ): ?string {
		$row = $this->mCurrentRow;
		switch ( $name ) {
			case 'page_title':
				$titleValue = TitleValue::tryNew( (int)$row->page_namespace, $row->page_title );
				return $this->getLinkRenderer()->makeKnownLink( $titleValue );

			case 'rlud_timestamp':
				if ( !$value ) {
					return $this->msg( 'reallastupdate-no-data' )->escaped();
				}

				$lang = $this->getLanguage();
				$formattedDate = $lang->userTimeAndDate( $value, $this->getUser() );

				// If we have a revision ID, link to the diff
				if ( $row->real_last_update_revid ) {
					$titleValue = TitleValue::tryNew( (int)$row->page_namespace, $row->page_title );
					return $this->getLinkRenderer()->makeKnownLink(
						$titleValue,
						$formattedDate,
						[],
						[
							'oldid' => $row->real_last_update_revid,
							'action' => 'diff'
						]
					);
				}

				return $formattedDate;

			case 'rev_timestamp':
				if ( !$value ) {
					return $this->msg( 'reallastupdate-no-regular-data' )->escaped();
				}

				$lang = $this->getLanguage();
				$formattedDate = $lang->userTimeAndDate( $value, $this->getUser() );

				// Check if the values are identical and we're not showing all dates
				if ( $row->rlud_timestamp &&
					$value === $row->rlud_timestamp ) {

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

			case 'rlucw_source_timestamp':
				if ( RealLastUpdate::isSourceWiki() ) {
					// If we're on the source wiki, we don't show this column
					return null;
				}
				if ( !$value ) {
					return $this->msg( 'reallastupdate-no-data' )->escaped();
				}

				$lang = $this->getLanguage();
				$formattedDate = $lang->userTimeAndDate( $value, $this->getUser() );

				// If we have source title, create an interwiki link to the source page
				if ( isset( $row->rlucw_source_title ) && $row->rlucw_source_title ) {
					$sourceWiki = RealLastUpdate::getConfigVar( 'RealLastUpdateSourceWiki' );
					if ( $sourceWiki ) {
						// Create interwiki link: sourceWiki:PageTitle
						$interwikiLink = $sourceWiki . ':' . $row->rlucw_source_title;
						try {
							$title = $this->titleParser->parseTitle( $interwikiLink );
						} catch ( MalformedTitleException $e ) {
							// Fallback if title parsing fails
							return $formattedDate;
						}
						return $this->getLinkRenderer()->makeKnownLink(
							$title,
							$formattedDate
						);
					}
				}

				return $formattedDate;

			case 'rlud_diff_days':
				if ( RealLastUpdate::isSourceWiki() ) {
					// If we're on the source wiki, we don't show this column
					return null;
				}
				if ( $value === null || $value === '' ) {
					return $this->msg( 'reallastupdate-no-diff-days' )->escaped();
				}
				return $this->msg( 'reallastupdate-diff-days-value', $value )->escaped();

			default:
				return $value;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getDefaultQuery(): array {
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
		$toggleHTML = Html::element(
			'div',
			[ 'class' => 'reallastupdate-toggle-container' ],
		);

		return $body . $toggleHTML;
	}

	/** @inheritDoc */
	protected function getDefaultDirections(): bool {
		// This is actually the default anyway, but we override it to ensure
		return self::DIR_ASCENDING;
	}

	/**
	 * Override buildQueryInfo to inject HAVING for computed fields when paging and remove from WHERE
	 */
	protected function buildQueryInfo( $offset, $limit, $order ) {
		list( $tables, $fields, $conds, $fname, $options, $join_conds ) = parent::buildQueryInfo( $offset, $limit, $order );

		// Only add HAVING for rlud_diff_days if needed
		if ( isset( $this->mSort ) && $this->mSort === 'rlud_diff_days' && $offset !== '' ) {
			$op = ($order === self::QUERY_ASCENDING) ? '>=' : '<=';
			// Remove any condition on rlud_diff_days from WHERE
			if ( is_array( $conds ) ) {
				foreach ( $conds as $k => $v ) {
					if ( is_string( $v ) && strpos( $v, 'rlud_diff_days' ) !== false ) {
						unset( $conds[$k] );
					}
				}
			}
			if ( !isset( $options['HAVING'] ) ) {
				$options['HAVING'] = [];
			}
			$options['HAVING'][] = 'rlud_diff_days ' . $op . ' ' . intval( $offset );
		}

		return [ $tables, $fields, $conds, $fname, $options, $join_conds ];
	}

	/**
	 * @inheritDoc
	 */
	public function getIndexField() {
			// Return the field names as they appear in the result set, not SQL expressions
			if ( isset( $this->mSort ) ) {
				if ( $this->mSort === 'rlud_diff_days' ) {
					return [ 'rlud_diff_days' ];
				}
				if ( $this->mSort === 'rlud_timestamp' ) {
					return [ 'rlud_timestamp' ];
				}
				if ( $this->mSort === 'rev_timestamp' ) {
					return [ 'rev_timestamp' ];
				}
				if ( $this->mSort === 'rlucw_source_timestamp' ) {
					return [ 'rlucw_source_timestamp' ];
				}
				// Fallback to the column name
				return [ $this->mSort ];
			}
			// Default sort
			return [ 'rlud_timestamp' ];
	}
}
