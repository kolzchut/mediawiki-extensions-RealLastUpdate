<?php

/**
 * Update cross-wiki last real update information
 */

namespace MediaWiki\Extension\RealLastUpdate\Maintenance;

use Maintenance;
use MediaWiki\Extension\RealLastUpdate\RealLastUpdate;
use MediaWiki\MediaWikiServices;
use TitleFormatter;
use TitleParser;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script to update cross-wiki last real update information
 */
class UpdateCrossWikiLastUpdates extends Maintenance {
	/** @var TitleParser */
	private $titleParser;

	/** @var TitleFormatter */
	private $titleFormatter;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Update cross-wiki real last update information for translated pages' );
		$this->addOption( 'batch-size', 'Number of pages to process per batch', false, true, 'b' );
		$this->addOption( 'verbose', 'Show more detailed output', false, false, 'v' );
		$this->addOption( 'debug', 'Show debug information', false, false, 'd' );
		$this->addOption( 'csv-missing', 'Output details of missing cross-wiki updates to CSV file', false, true );
		$this->addOption( 'report', 'Output a comprehensive report CSV file', false, true );
		$this->setBatchSize( 100 );
		$this->requireExtension( 'RealLastUpdate' );
	}

	public function execute() {
		// Initialize MediaWiki title services
		$services = MediaWikiServices::getInstance();
		$this->titleParser = $services->getTitleParser();
		$this->titleFormatter = $services->getTitleFormatter();

		$sourceWiki = RealLastUpdate::getConfigVar( 'RealLastUpdateSourceWiki' );
		$debug = $this->hasOption( 'debug' );
		$csvFile = $this->getOption( 'csv-missing', false );
		$reportFile = $this->getOption( 'report', false );

		// Prepare CSV file if requested
		$csvHandle = null;
		if ( $csvFile ) {
			$csvHandle = fopen( $csvFile, 'w' );
			if ( !$csvHandle ) {
				$this->error( "Could not open CSV file for writing: $csvFile", 1 );
				return;
			}
			// Write CSV header
			fputcsv( $csvHandle, [
				'Local Page ID',
				'Local Page Title',
				'Source Page Title'
			] );
		}

		// Prepare report file if requested
		$reportHandle = null;
		if ( $reportFile ) {
			$reportHandle = fopen( $reportFile, 'w' );
			if ( !$reportHandle ) {
				$this->error( "Could not open report file for writing: $reportFile", 1 );
				return;
			}
			// Write report header
			fputcsv( $reportHandle, [
				'Local Page ID',
				'Local Page Title',
				'Source Title',
				'Source Page ID',
				'Is Redirect',
				'Redirect Target',
				'Redirect Target Page ID',
				'Has RLU Data',
				'RLU Revision ID',
				'RLU Timestamp',
				'Status'
			] );
		}

		// Exit if no source wiki is defined
		if ( !$sourceWiki ) {
			$this->error(
				"Source wiki not defined. Set \$wgRealLastUpdateSourceWiki in your LocalSettings.php.",
				1
			);
			return;
		}

		// Exit if this is the source wiki
		$currentWiki = RealLastUpdate::getConfigVar( 'Wiki' );
		if ( $currentWiki === $sourceWiki ) {
			$this->output( "This is the source wiki ($sourceWiki). No cross-wiki updates needed.\n" );
			return;
		}

		$batchSize = $this->getOption( 'batch-size', $this->getBatchSize() );
		$verbose = $this->hasOption( 'verbose' );

		$this->output( "Updating cross-wiki real last update information from $sourceWiki wiki...\n" );

		// Get database connections
		$dbr = $this->getDB( DB_REPLICA );
		$dbw = $this->getDB( DB_PRIMARY );

		// Get foreign wiki database connection if configured
		$sourceWikiDb = RealLastUpdate::getForeignWikiDB( $sourceWiki );

		$start = 0;
		$count = 0;
		$updatedCount = 0;
		$missingCount = 0;
		$missingPages = [];
		// Add tracking for redirects
		$totalRedirects = 0;
		$redirectsWithRLU = 0;

		do {
			$this->output( "Processing batch starting at offset $start\n" );

			// Get pages with language links to source wiki
			$res = $dbr->select(
				[ 'page', 'langlinks' ],
				[ 'page_id', 'page_title', 'll_title' ],
				[
					'll_lang' => $sourceWiki,
					'page_is_redirect' => 0
				],
				__METHOD__,
				[
					'LIMIT' => $batchSize,
					'OFFSET' => $start
				],
				[
					'langlinks' => [ 'JOIN', 'll_from = page_id' ]
				]
			);

			$pageCount = $res->numRows();
			if ( $pageCount == 0 ) {
				break;
			}

			$sourceTitles = [];
			$pageMapping = [];
			$pageTitles = [];

			// Build mappings from local page ID to source title
			foreach ( $res as $row ) {
				$pageId = (int)$row->page_id;
				$sourceTitle = $row->ll_title;
				$sourceTitles[$sourceTitle] = true;
				$pageMapping[$pageId] = $sourceTitle;
				$pageTitles[$pageId] = $row->page_title;
				if ( $verbose ) {
					$this->output( "  Page $pageId maps to $sourceWiki:$sourceTitle\n" );
				}
			}

			if ( $debug ) {
				$this->output( "  Found " . count( $pageMapping ) . " pages with interlanguage links\n" );
			}

			// Array to store comprehensive report data
			$reportData = [];

			if ( $sourceWikiDb ) {
				// Direct database access method
				$this->output( "  Fetching data from source wiki database...\n" );
				$sourceData = $this->getSourceDataFromDB(
					$sourceWikiDb,
					array_keys( $sourceTitles ),
					$sourceWiki,
					$totalRedirects,
					$redirectsWithRLU,
					$reportData
				);
			} else {
				// API method
				$this->output( "  Fetching data from source wiki API...\n" );
				$sourceData = $this->getSourceDataFromAPI(
					array_keys( $sourceTitles ),
					$sourceWiki,
					$totalRedirects,
					$redirectsWithRLU,
					$reportData
				);
			}

			if ( $debug ) {
				$this->output( "  Retrieved data for " . count( $sourceData ) . " pages from source wiki\n" );
			}

			// Update local database with source information
			foreach ( $pageMapping as $pageId => $sourceTitle ) {
				$reportEntry = [
					'local_page_id' => $pageId,
					'local_page_title' => $pageTitles[$pageId],
					'source_title' => $sourceTitle,
					'source_page_id' => $reportData[$sourceTitle]['page_id'] ?? 'unknown',
					'is_redirect' => isset( $reportData[$sourceTitle]['is_redirect'] ) && $reportData[$sourceTitle]['is_redirect'] ? 'yes' : 'no',
					'redirect_target' => $reportData[$sourceTitle]['redirect_target'] ?? '',
					'redirect_target_page_id' => $reportData[$sourceTitle]['redirect_target_page_id'] ?? '',
					'has_rlu_data' => 'no',
					'rlu_rev_id' => '',
					'rlu_timestamp' => '',
					'status' => 'missing'
				];

				if ( isset( $sourceData[$sourceTitle] ) ) {
					$data = $sourceData[$sourceTitle];
					if ( $verbose ) {
						$this->output( "  Updating page $pageId with data from $sourceWiki:$sourceTitle: " .
							"rev={$data['rev_id']}, timestamp={$data['timestamp']}\n" );
					}

					$dbw->upsert(
						'real_last_update_cross_wiki',
						[
							'rlucw_page_id' => $pageId,
							'rlucw_source_title' => $sourceTitle,
							'rlucw_source_timestamp' => $data['timestamp'],
							'rlucw_source_revid' => $data['rev_id']
						],
						[ 'rlucw_page_id' ],
						[
							'rlucw_source_title' => $sourceTitle,
							'rlucw_source_timestamp' => $data['timestamp'],
							'rlucw_source_revid' => $data['rev_id']
						],
						__METHOD__
					);

					$updatedCount++;

					// Update report entry
					$reportEntry['has_rlu_data'] = 'yes';
					$reportEntry['rlu_rev_id'] = $data['rev_id'];
					$reportEntry['rlu_timestamp'] = $data['timestamp'];
					$reportEntry['status'] = 'updated';
				} else {
					$missingCount++;
					if ( $csvHandle ) {
						$missingPages[] = [
							'page_id' => $pageId,
							'page_title' => $pageTitles[$pageId],
							'source_wiki' => $sourceWiki,
							'source_title' => $sourceTitle
						];
					}

					if ( $verbose ) {
						$this->output( "  No data found for page $sourceWiki:$sourceTitle\n" );
					}
				}

				// Write to report file if requested
				if ( $reportHandle ) {
					fputcsv( $reportHandle, [
						$reportEntry['local_page_id'],
						$reportEntry['local_page_title'],
						$reportEntry['source_title'],
						$reportEntry['source_page_id'],
						$reportEntry['is_redirect'],
						$reportEntry['redirect_target'],
						$reportEntry['redirect_target_page_id'],
						$reportEntry['has_rlu_data'],
						$reportEntry['rlu_rev_id'],
						$reportEntry['rlu_timestamp'],
						$reportEntry['status']
					] );
				}
			}

			$start += $batchSize;
			$count += $pageCount;
			$this->output( "Processed $pageCount pages in this batch\n" );

			// Wait for replication to catch up
			// $this->waitForReplication();

		} while ( $pageCount == $batchSize );

		$this->output( "Done! Processed $count pages, updated $updatedCount cross-wiki relationships.\n" );
		if ( $missingCount > 0 ) {
			$this->output( "Warning: $missingCount pages were skipped because no data was found in the source wiki.\n" );

			// Write missing pages to CSV if requested
			if ( $csvHandle ) {
				foreach ( $missingPages as $page ) {
					fputcsv( $csvHandle, [
						$page['page_id'],
						$page['page_title'],
						$page['source_title']
					] );
				}
				fclose( $csvHandle );
				$this->output( "Details of missing cross-wiki updates written to $csvFile\n" );
			}
			$this->output( "Redirect statistics: Found $totalRedirects redirects, $redirectsWithRLU of which had RLU data.\n" );
		}

		// Close report file if opened
		if ( $reportHandle ) {
			fclose( $reportHandle );
			$this->output( "Comprehensive report written to $reportFile\n" );
		}
	}

	/**
	 * Normalize a title to DB format (spaces to underscores)
	 *
	 * @param string $title Title to normalize
	 * @return string Normalized title for DB operations
	 */
	private function normalizeForDB( string $title ): string {
		try {
			// First try to parse using MediaWiki's TitleParser
			$titleObj = $this->titleParser->parseTitle( $title );
			return $titleObj->getDBkey();
		} catch ( \Exception $e ) {
			// Fallback to manual normalization
			return str_replace( ' ', '_', $title );
		}
	}

	/**
	 * Normalize a title to API format (underscores to spaces)
	 *
	 * @param string $title Title to normalize
	 * @return string Normalized title for API operations
	 */
	private function normalizeForAPI( string $title ): string {
		try {
			// First try to parse using MediaWiki's TitleParser
			$titleObj = $this->titleParser->parseTitle( $title, NS_MAIN );
			return $this->titleFormatter->getPrefixedText( $titleObj );
		} catch ( \Exception $e ) {
			// Fallback to manual normalization
			return str_replace( '_', ' ', $title );
		}
	}

	/**
	 * Get source data directly from database
	 *
	 * @param \Wikimedia\Rdbms\IDatabase $sourceDb Database connection to source wiki
	 * @param array $titles Array of page titles to look up
	 * @param string $sourceWiki Source wiki code
	 * @param int &$totalRedirects Counter for total redirects found
	 * @param int &$redirectsWithRLU Counter for redirects with RLU data
	 * @param array &$reportData Array to store report data
	 * @return array Associative array of source data by title
	 */
	private function getSourceDataFromDB(
		$sourceDb,
		array $titles,
		string $sourceWiki,
		&$totalRedirects = 0,
		&$redirectsWithRLU = 0,
		&$reportData = []
	) {
		$result = [];
		$debug = $this->hasOption( 'debug' );

		// Normalize titles for DB queries (spaces to underscores)
		$dbTitles = [];
		$titleMapping = []; // Maps DB title back to original title

		foreach ( $titles as $title ) {
			$dbTitle = $this->normalizeForDB( $title );
			$dbTitles[] = $dbTitle;
			$titleMapping[$dbTitle] = $title;

			// Initialize report data entry
			$reportData[$title] = [
				'page_id' => null,
				'is_redirect' => false,
				'redirect_target' => null,
				'redirect_target_page_id' => null
			];

			if ( $debug && $dbTitle !== $title ) {
				$this->output( "  DB lookup: '$title' (normalized to '$dbTitle')\n" );
			}
		}

		// First check for redirects
		$redirectTargets = [];
		$redirectSources = [];
		$originalTitleMap = []; // Maps DB redirect target to original title

		$redirectRes = $sourceDb->select(
			[ 'page', 'redirect' ],
			[ 'page_id', 'page_title', 'rd_title', 'rd_namespace' ],
			[
				'page_title' => $dbTitles,
				'page_namespace' => 0,
				'page_is_redirect' => 1
			],
			__METHOD__,
			[],
			[ 'redirect' => [ 'JOIN', 'rd_from = page_id' ] ]
		);

		foreach ( $redirectRes as $row ) {
			$originalTitle = $titleMapping[$row->page_title] ?? $row->page_title;
			$redirectTarget = $row->rd_title;

			// Use the display version of titles in debug output
			$displayTarget = $this->normalizeForAPI( $redirectTarget );
			$displayOriginal = $this->normalizeForAPI( $originalTitle );

			// Store redirect information
			$redirectTargets[$originalTitle] = $redirectTarget;
			$redirectSources[$redirectTarget] = $originalTitle;
			$originalTitleMap[$redirectTarget] = $originalTitle;
			$totalRedirects++;

			// Update report data
			if ( isset( $reportData[$originalTitle] ) ) {
				$reportData[$originalTitle]['page_id'] = (int)$row->page_id;
				$reportData[$originalTitle]['is_redirect'] = true;
				$reportData[$originalTitle]['redirect_target'] = $displayTarget;
			}

			if ( $debug ) {
				$this->output( "  Found redirect: '$displayOriginal' → '$displayTarget'\n" );
			}

			// Add redirect targets to the list of titles to look up
			// Normalize the redirect target title before adding it
			if ( !in_array( $redirectTarget, $dbTitles ) ) {
				$dbTitles[] = $redirectTarget;
				// We don't need to add it to titleMapping because we'll handle it specially

				if ( $debug ) {
					$this->output( "  Added redirect target '$displayTarget' to lookup list\n" );
				}
			}
		}

		// Get page IDs for all titles (both original and redirect targets)
		$res = $sourceDb->select(
			'page',
			[ 'page_id', 'page_title', 'page_is_redirect' ],
			[ 'page_title' => $dbTitles, 'page_namespace' => 0 ],
			__METHOD__
		);

		$pageIds = [];
		foreach ( $res as $row ) {
			$pageTitle = $row->page_title;
			$pageId = (int)$row->page_id;
			$displayTitle = $this->normalizeForAPI( $pageTitle );

			// Check if this is a redirect target
			if ( isset( $redirectSources[$pageTitle] ) ) {
				// This page is a redirect target, map it to the original title
				$sourceTitle = $redirectSources[$pageTitle];
				if ( $debug ) {
					$this->output( "  Mapping redirect target '{$pageTitle}' to source '{$sourceTitle}'\n" );
				}

				// Use the original title (pre-normalization) for the result mapping
				$originalTitle = $titleMapping[$sourceTitle] ?? $sourceTitle;

				// Update report data for the source page
				if ( isset( $reportData[$originalTitle] ) ) {
					$reportData[$originalTitle]['redirect_target_page_id'] = $pageId;
				}

				if ( $debug ) {
					$this->output( "  Redirect target '$displayTitle' has page ID $pageId\n" );
				}

				// For redirect targets, we'll map the page ID to the original title
				// so we can look up RLU data for the target but associate it with the source
				$pageIds[$originalTitle] = $pageId;
			} else {
				// Regular page, map to its original title if it exists
				$originalTitle = $titleMapping[$pageTitle] ?? null;

				if ( $originalTitle ) {
					$pageIds[$originalTitle] = $pageId;

					// Update report data
					if ( isset( $reportData[$originalTitle] ) ) {
						$reportData[$originalTitle]['page_id'] = $pageId;
					}

					if ( $debug ) {
						$this->output( "  Page '$displayTitle' has page ID $pageId\n" );
					}
				}
			}
		}

		if ( empty( $pageIds ) ) {
			return $result;
		}

		// Get real last update data for these pages
		$res = $sourceDb->select(
			'real_last_update',
			[ 'rlud_page_id', 'rlud_timestamp', 'rlud_rev_id' ],
			[ 'rlud_page_id' => array_values( $pageIds ) ],
			__METHOD__
		);

		foreach ( $res as $row ) {
			$pageId = (int)$row->rlud_page_id;
			$pageTitle = array_search( $pageId, $pageIds );

			if ( $pageTitle !== false ) {
				$result[$pageTitle] = [
					'rev_id' => $row->rlud_rev_id,
					'timestamp' => $row->rlud_timestamp
				];

				// Check if this page was a redirect target
				if ( isset( $redirectTargets[$pageTitle] ) ) {
					$redirectsWithRLU++;
				}

				if ( $debug ) {
					$this->output( "  Found RLU data for '$pageTitle' (ID: $pageId): " .
						"rev={$row->rlud_rev_id}, ts={$row->rlud_timestamp}\n" );
				}
			}
		}

		return $result;
	}

	/**
	 * Get source data via API calls
	 *
	 * @param array $titles Array of page titles to look up
	 * @param string $sourceWiki Source wiki code
	 * @param int &$totalRedirects Counter for total redirects found
	 * @param int &$redirectsWithRLU Counter for redirects with RLU data
	 * @param array &$reportData Array to store report data
	 * @return array Associative array of source data by title
	 */
	private function getSourceDataFromAPI( array $titles, string $sourceWiki, &$totalRedirects = 0, &$redirectsWithRLU = 0 ) {
		$result = [];
		$apiUrl = RealLastUpdate::getConfigVar( 'RealLastUpdateSourceWikiApi' );
		$verbose = $this->hasOption( 'verbose' );
		$debug = $this->hasOption( 'debug' );

		if ( !$apiUrl ) {
			$this->error(
				"Source wiki API URL not configured. Set \$wgRealLastUpdateSourceWikiApi in your LocalSettings.php", 1
			);
			return $result;
		}

		// Get HTTP request factory
		$httpRequestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();

		// Process in smaller chunks to avoid exceeding URL length limits
		$chunks = array_chunk( $titles, 50 );
		$totalTitles = count( $titles );
		$processedTitles = 0;

		// Initialize report data entries
		foreach ( $titles as $title ) {
			$reportData[$title] = [
				'page_id' => null,
				'is_redirect' => false,
				'redirect_target' => null,
				'redirect_target_page_id' => null
			];
		}

		foreach ( $chunks as $chunkIndex => $chunk ) {
			// Map of original titles to API-normalized titles
			$titleMapping = [];

			// Normalize titles for API (underscores to spaces) and properly encode
			$encodedTitles = [];
			foreach ( $chunk as $title ) {
				$apiTitle = $this->normalizeForAPI( $title );
				$titleMapping[$apiTitle] = $title; // Map back to original format
				$encodedTitles[] = rawurlencode( $apiTitle );

				if ( $debug && $apiTitle !== $title ) {
					$this->output( "  API lookup: '$title' (normalized to '$apiTitle')\n" );
				}
			}

			$titlesParam = implode( '|', $encodedTitles );
			$url = $apiUrl . '?action=query&prop=reallastupdate&titles=' . $titlesParam . '&redirects=1&format=json';

			if ( $debug ) {
				$this->output( "  API Request: " . substr( $url, 0, 100 ) . "...\n" );
			}

			$response = $httpRequestFactory->get( $url, [
				'timeout' => 30,
				'followRedirects' => true
			] );

			if ( !$response ) {
				$this->output( "  Warning: Could not connect to source wiki API\n" );
				continue;
			}

			$data = json_decode( $response, true );
			if ( !$data || !isset( $data['query']['pages'] ) ) {
				$this->output( "  Warning: Invalid response from source wiki API\n" );
				if ( $debug ) {
					$this->output( "  Response: " . $response . "\n" );
				}
				continue;
			}

			// Dump full API response for debugging
			if ( $debug ) {
				$this->output( "  FULL API RESPONSE:\n" . json_encode( $data, JSON_PRETTY_PRINT ) . "\n" );
			}

			// Build redirect map if redirects occurred
			$redirectMap = [];
			if ( isset( $data['query']['redirects'] ) ) {
				// Count the redirects in this batch
				$totalRedirects += count( $data['query']['redirects'] );

				foreach ( $data['query']['redirects'] as $redirect ) {
					$from = $redirect['from'];
					$to = $redirect['to'];
					$redirectMap[$from] = $to;

					// Find original title
					$originalTitle = $titleMapping[$from] ?? $from;

					// Update report data
					if ( isset( $reportData[$originalTitle] ) ) {
						$reportData[$originalTitle]['is_redirect'] = true;
						$reportData[$originalTitle]['redirect_target'] = $to;
					}

					if ( $debug ) {
						$this->output( "  Redirect: '$from' → '$to'\n" );
					}
				}
			}

			// Process the results
			foreach ( $data['query']['pages'] as $pageId => $pageData ) {
				// Skip non-existent pages
				if ( $pageId < 0 ) {
					if ( $verbose ) {
						$this->output( "  Page '{$pageData['title']}' does not exist in source wiki\n" );
					}
					continue;
				}

				$title = $pageData['title'];
				$processedTitles++;

				// Find original title - if this is a redirect target, find the source
				$redirectSource = array_search( $title, $redirectMap );
				$originalTitle = null;

				if ( $redirectSource !== false ) {
					// This is a redirect target
					$originalTitle = $titleMapping[$redirectSource] ?? $redirectSource;

					// Update report data for redirect target page ID
					if ( isset( $reportData[$originalTitle] ) ) {
						$reportData[$originalTitle]['redirect_target_page_id'] = (int)$pageId;
					}
				} else {
					// Regular page
					$originalTitle = $titleMapping[$title] ?? null;

					// Update report data for page ID
					if ( $originalTitle && isset( $reportData[$originalTitle] ) ) {
						$reportData[$originalTitle]['page_id'] = (int)$pageId;
					}
				}

				// Get RLU data if available
				if ( isset( $pageData['reallastupdate'] ) && $originalTitle ) {
					$rlu = $pageData['reallastupdate'];

					$result[$originalTitle] = [
						'rev_id' => $rlu['revision'],
						'timestamp' => $rlu['timestamp']
					];

					// Check if this was a redirect target
					if ( $redirectSource !== false ) {
						$redirectsWithRLU++;
					}

					if ( $debug ) {
						$this->output( "  Found RLU data for '$originalTitle': rev={$rlu['revision']}, ts={$rlu['timestamp']}\n" );
					}
				} elseif ( $originalTitle && $verbose ) {
					$this->output( "  No RLU data for '$originalTitle' in source wiki\n" );
				}
			}
		}

		return $result;
	}

	/**
	 * Find the original title in our mapping, taking into account redirects and normalization
	 *
	 * @param string $title Current title from API response
	 * @param array $titleMapping Mapping of API titles to original titles
	 * @param array $redirectMap Map of redirects from->to
	 * @param array $normalizationMap Map of normalized titles
	 * @return string|false Original title or false if not found
	 */
	private function findOriginalTitle( $title, $titleMapping, $redirectMap, $normalizationMap ) {
		$debug = $this->hasOption( 'debug' );

		if ( $debug ) {
			$this->output( "  Looking for original title for '$title'\n" );
		}

		// Direct match in title mapping
		if ( isset( $titleMapping[$title] ) ) {
			if ( $debug ) {
				$this->output( "    Direct match: '$title' → '{$titleMapping[$title]}'\n" );
			}
			return $titleMapping[$title];
		}

		// Check if title was normalized by the API
		$preNormalizedTitle = array_search( $title, $normalizationMap );
		if ( $preNormalizedTitle !== false && isset( $titleMapping[$preNormalizedTitle] ) ) {
			if ( $debug ) {
				$this->output( "    Match via normalization: '$preNormalizedTitle' → '$title' → '{$titleMapping[$preNormalizedTitle]}'\n" );
			}
			return $titleMapping[$preNormalizedTitle];
		}

		// Check if title is a redirect target
		$sourceTitle = array_search( $title, $redirectMap );
		if ( $sourceTitle !== false && isset( $titleMapping[$sourceTitle] ) ) {
			if ( $debug ) {
				$this->output( "    Match via redirect: '$sourceTitle' → '$title' → '{$titleMapping[$sourceTitle]}'\n" );
			}
			return $titleMapping[$sourceTitle];
		}

		// Case-insensitive matching as last resort
		foreach ( $titleMapping as $apiTitle => $origTitle ) {
			if ( strtolower( $title ) === strtolower( $apiTitle ) ) {
				if ( $debug ) {
					$this->output( "    Case-insensitive match: '$title' → '$apiTitle' → '$origTitle'\n" );
				}
				return $origTitle;
			}
		}

		// No match found
		if ( $debug ) {
			$this->output( "    No match found for '$title'\n" );
		}
		return false;
	}
}

$maintClass = UpdateCrossWikiLastUpdates::class;
require_once RUN_MAINTENANCE_IF_MAIN;
