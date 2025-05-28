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
use TitleValue;

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
	 * Parse a title into namespace ID and DB key
	 *
	 * @param string $title Title to parse
	 * @return array Array with 'ns' (namespace ID) and 'dbkey' (DB key) elements
	 */
	private function parseTitle( string $title ): array {
		try {
			$titleObj = $this->titleParser->parseTitle( $title );
			return [
				'ns' => $titleObj->getNamespace(),
				'dbkey' => $titleObj->getDBkey(),
				'text' => $titleObj->getText()
			];
		} catch ( \Exception $e ) {
			// If parsing fails, default to main namespace
			return [
				'ns' => 0,
				'dbkey' => str_replace( ' ', '_', $title ),
				'text' => $title
			];
		}
	}

	/**
	 * Normalize a title to DB format (spaces to underscores)
	 * while preserving namespace information
	 *
	 * @param string $title Title to normalize
	 * @return array Normalized title info with 'ns' and 'dbkey' elements
	 */
	private function normalizeForDB( string $title ): array {
		return $this->parseTitle( $title );
	}

	/**
	 * Normalize a title to API format (underscores to spaces)
	 * while preserving namespace information
	 *
	 * @param string $title Title to normalize
	 * @return array Normalized title info with 'ns', 'prefixed' and 'dbkey' elements
	 */
	private function normalizeForAPI( string $title ): array {
		$parsed = $this->parseTitle( $title );

		try {
			$titleValue = new TitleValue( $parsed['ns'], $parsed['dbkey'] );
			$prefixedText = $this->titleFormatter->getPrefixedText( $titleValue );

			return [
				'ns' => $parsed['ns'],
				'dbkey' => $parsed['dbkey'],
				'prefixed' => $prefixedText
			];
		} catch ( \Exception $e ) {
			// If formatting fails, use namespace 0 as fallback
			return [
				'ns' => 0,
				'dbkey' => $parsed['dbkey'],
				'prefixed' => $parsed['text']
			];
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
		$verbose = $this->hasOption( 'verbose' );

		// Group titles by namespace to reduce number of queries
		$namespacedTitles = [];
		$titleMapping = []; // Maps normalized lookup key back to original title

		foreach ( $titles as $title ) {
			$normalized = $this->normalizeForDB( $title );
			$ns = $normalized['ns'];
			$dbKey = $normalized['dbkey'];

			// Group by namespace
			if ( !isset( $namespacedTitles[$ns] ) ) {
				$namespacedTitles[$ns] = [];
			}
			$namespacedTitles[$ns][] = $dbKey;

			// Create a lookup key that includes namespace
			$lookupKey = "{$ns}:{$dbKey}";
			$titleMapping[$lookupKey] = $title;

			// Initialize report data entry
			$reportData[$title] = [
				'page_id' => null,
				'is_redirect' => false,
				'redirect_target' => null,
				'redirect_target_page_id' => null
			];

			if ( $debug ) {
				$displayTitle = $ns > 0 ? "NS{$ns}:{$dbKey}" : $dbKey;
				$this->output( "  DB lookup: '$title' (normalized to '{$displayTitle}')\n" );
			}
		}

		// First check for redirects - one query per namespace
		$redirectTargets = [];
		$redirectSources = [];
		$redirectTargetPages = [];
		$redirectChains = []; // Track redirect chains by source ID

		foreach ( $namespacedTitles as $ns => $dbTitles ) {
			$redirectRes = $sourceDb->select(
				[ 'page', 'redirect' ],
				[ 'page_id', 'page_title', 'rd_title', 'rd_namespace' ],
				[
					'page_title' => $dbTitles,
					'page_namespace' => $ns,
					'page_is_redirect' => 1
				],
				__METHOD__,
				[],
				[ 'redirect' => [ 'JOIN', 'rd_from = page_id' ] ]
			);

			foreach ( $redirectRes as $row ) {
				$lookupKey = "{$ns}:{$row->page_title}";
				$originalTitle = $titleMapping[$lookupKey] ?? null;
				if ( !$originalTitle ) continue;

				$redirectSourceId = (int)$row->page_id;
				$redirectTargetNs = (int)$row->rd_namespace;
				$redirectTarget = $row->rd_title;
				$redirectTargetKey = "{$redirectTargetNs}:{$redirectTarget}";

				// Format for display using TitleValue and TitleFormatter
				$sourceValue = new TitleValue( $ns, $row->page_title );
				$targetValue = new TitleValue( $redirectTargetNs, $redirectTarget );

				$displaySource = $this->titleFormatter->getPrefixedText( $sourceValue );
				$displayTarget = $this->titleFormatter->getPrefixedText( $targetValue );

				// Store redirect information
				$redirectTargets[$originalTitle] = [
					'ns' => $redirectTargetNs,
					'dbkey' => $redirectTarget,
					'source_id' => $redirectSourceId
				];
				$redirectSources[$redirectTargetKey] = $originalTitle;
				$redirectChains[$redirectSourceId] = [
					'target_ns' => $redirectTargetNs,
					'target_dbkey' => $redirectTarget,
					'source_title' => $originalTitle
				];
				$totalRedirects++;

				// Update report data
				if ( isset( $reportData[$originalTitle] ) ) {
					$reportData[$originalTitle]['page_id'] = $redirectSourceId;
					$reportData[$originalTitle]['is_redirect'] = true;
					$reportData[$originalTitle]['redirect_target'] = $displayTarget;
				}

				if ( $debug ) {
					$this->output( "  Found redirect: '$displaySource' (ID: $redirectSourceId) → '$displayTarget'\n" );
				}

				// Add redirect target to the appropriate namespace group
				if ( !isset( $namespacedTitles[$redirectTargetNs] ) ) {
					$namespacedTitles[$redirectTargetNs] = [];
				}
				if ( !in_array( $redirectTarget, $namespacedTitles[$redirectTargetNs] ) ) {
					$namespacedTitles[$redirectTargetNs][] = $redirectTarget;

					if ( $debug ) {
						$this->output( "  Added redirect target '$displayTarget' to lookup list\n" );
					}
				}
			}
		}

		// Get page IDs for all titles (both original and redirect targets) - one query per namespace
		$pageIds = [];
		// New mapping: page ID to all titles that should receive its RLU data
		$pageIdToTitles = [];
		// Store target page IDs by source redirect ID
		$redirectTargetPageIds = [];

		foreach ( $namespacedTitles as $ns => $dbTitles ) {
			$res = $sourceDb->select(
				'page',
				[ 'page_id', 'page_title', 'page_namespace', 'page_is_redirect' ],
				[ 'page_title' => $dbTitles, 'page_namespace' => $ns ],
				__METHOD__
			);

			foreach ( $res as $row ) {
				$pageTitle = $row->page_title;
				$pageNs = (int)$row->page_namespace;
				$pageId = (int)$row->page_id;
				$isRedirect = (bool)$row->page_is_redirect;
				$lookupKey = "{$pageNs}:{$pageTitle}";

				// Format for display
				$titleValue = new TitleValue( $pageNs, $pageTitle );
				$displayTitle = $this->titleFormatter->getPrefixedText( $titleValue );

				// Initialize the reverse mapping entry for this page ID
				if ( !isset( $pageIdToTitles[$pageId] ) ) {
					$pageIdToTitles[$pageId] = [];
				}

				// Check if this is a redirect target
				if ( isset( $redirectSources[$lookupKey] ) ) {
					// This page is a redirect target, map it to the original title
					$sourceTitle = $redirectSources[$lookupKey];

					if ( $debug ) {
						$this->output( "  Mapping redirect target '{$displayTitle}' (ID: $pageId) to source '{$sourceTitle}'\n" );
					}

					// Find the source ID for this redirect
					$sourceId = null;
					foreach ( $redirectTargets as $title => $target ) {
						if ( $title === $sourceTitle && $target['ns'] === $pageNs && $target['dbkey'] === $pageTitle ) {
							$sourceId = $target['source_id'];
							break;
						}
					}

					if ( $sourceId ) {
						$redirectTargetPageIds[$sourceId] = $pageId;

						if ( $debug ) {
							$this->output( "  Mapped redirect source ID $sourceId to target ID $pageId\n" );
						}
					}

					// Update report data for the source page
					if ( isset( $reportData[$sourceTitle] ) ) {
						$reportData[$sourceTitle]['redirect_target_page_id'] = $pageId;
					}

					// For redirect targets, map the page ID to the original title
					$pageIds[$sourceTitle] = $pageId;
					$redirectTargetPages[$sourceTitle] = $pageId;

					// Add this source title to the list of titles that should receive this page ID's RLU data
					$pageIdToTitles[$pageId][] = $sourceTitle;
				} else {
					// Regular page, map to its original title if it exists
					$originalTitle = $titleMapping[$lookupKey] ?? null;

					if ( $originalTitle ) {
						$pageIds[$originalTitle] = $pageId;
						// Add this title to the list of titles that should receive this page ID's RLU data
						$pageIdToTitles[$pageId][] = $originalTitle;

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
		}

		if ( empty( $pageIds ) ) {
			return $result;
		}

		// For additional debugging - log the redirect chains we found
		if ( $debug && !empty( $redirectTargetPageIds ) ) {
			$this->output( "  Redirect source → target page ID mappings:\n" );
			foreach ( $redirectTargetPageIds as $sourceId => $targetId ) {
				$sourceTitle = $redirectChains[$sourceId]['source_title'] ?? "Unknown";
				$this->output( "    Source ID: $sourceId ($sourceTitle) → Target ID: $targetId\n" );
			}
		}

		// Get all page IDs we need to check for RLU data
		$pageIdsToCheck = array_unique( array_merge(
			array_values( $pageIds ),
			array_values( $redirectTargetPageIds ),
			array_keys( $redirectTargetPageIds )
		) );

		if ( $debug ) {
			$this->output( "  Checking for RLU data on " . count( $pageIdsToCheck ) . " page IDs\n" );
		}

		// Get real last update data for these pages in a single query
		$res = $sourceDb->select(
			'real_last_update',
			[ 'rlud_page_id', 'rlud_timestamp', 'rlud_rev_id' ],
			[ 'rlud_page_id' => $pageIdsToCheck ],
			__METHOD__
		);

		// Now process the RLU data and assign it to all related titles
		foreach ( $res as $row ) {
			$rluPageId = (int)$row->rlud_page_id;
			$rluData = [
				'rev_id' => $row->rlud_rev_id,
				'timestamp' => $row->rlud_timestamp
			];

			if ( $debug ) {
				$this->output( "  Found RLU data for page ID $rluPageId: " .
					"rev={$row->rlud_rev_id}, ts={$row->rlud_timestamp}\n" );
			}

			// Direct mapping - if this page ID has titles mapped to it
			if ( isset( $pageIdToTitles[$rluPageId] ) && !empty( $pageIdToTitles[$rluPageId] ) ) {
				foreach ( $pageIdToTitles[$rluPageId] as $titleToUpdate ) {
					$result[$titleToUpdate] = $rluData;

					// Check if this page was a redirect target and count it
					if ( isset( $redirectTargetPages[$titleToUpdate] ) && $redirectTargetPages[$titleToUpdate] === $rluPageId ) {
						$redirectsWithRLU++;
					}

					if ( $debug ) {
						$this->output( "  Assigning RLU data to '$titleToUpdate' (ID: $rluPageId) - direct mapping\n" );
					}
				}
			}

			// Check if this is a redirect source with RLU data
			if ( isset( $redirectTargetPageIds[$rluPageId] ) ) {
				$targetPageId = $redirectTargetPageIds[$rluPageId];

				// Find all titles associated with the target page ID
				if ( isset( $pageIdToTitles[$targetPageId] ) && !empty( $pageIdToTitles[$targetPageId] ) ) {
					foreach ( $pageIdToTitles[$targetPageId] as $titleToUpdate ) {
						// Only add if not already assigned
						if ( !isset( $result[$titleToUpdate] ) ) {
							$result[$titleToUpdate] = $rluData;

							if ( $debug ) {
								$this->output( "  Assigning RLU data from redirect source ID $rluPageId to '$titleToUpdate' (target ID: $targetPageId)\n" );
							}
						}
					}
				}

				// Also make sure the source redirect's title gets the RLU data
				if ( isset( $redirectChains[$rluPageId] ) ) {
					$sourceTitle = $redirectChains[$rluPageId]['source_title'];
					if ( !isset( $result[$sourceTitle] ) ) {
						$result[$sourceTitle] = $rluData;
						$redirectsWithRLU++;

						if ( $debug ) {
							$this->output( "  Assigning RLU data to redirect source '$sourceTitle' (ID: $rluPageId)\n" );
						}
					}
				}
			}

			// Check if this is a redirect target with RLU data
			$sourceIds = array_keys( $redirectTargetPageIds, $rluPageId );
			if ( !empty( $sourceIds ) ) {
				foreach ( $sourceIds as $sourceId ) {
					// Find the source title for this ID
					if ( isset( $redirectChains[$sourceId] ) ) {
						$sourceTitle = $redirectChains[$sourceId]['source_title'];
						if ( !isset( $result[$sourceTitle] ) ) {
							$result[$sourceTitle] = $rluData;
							$redirectsWithRLU++;

							if ( $debug ) {
								$this->output( "  Assigning RLU data from target ID $rluPageId to redirect source '$sourceTitle' (ID: $sourceId)\n" );
							}
						}
					}
				}
			}
		}

		// Debug output for RLU results
		if ( $debug ) {
			$this->output( "  Final RLU data assignments:\n" );
			foreach ( $result as $title => $data ) {
				$pageId = $pageIds[$title] ?? 'unknown';
				$isRedirect = isset( $redirectTargets[$title] ) ? 'Yes' : 'No';
				$targetId = isset( $redirectTargets[$title] ) ? ($redirectTargetPages[$title] ?? 'unknown') : 'N/A';

				$this->output( "    Title: $title (ID: $pageId, Redirect: $isRedirect, Target ID: $targetId)\n" );
				$this->output( "      RLU data: rev={$data['rev_id']}, ts={$data['timestamp']}\n" );
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
	private function getSourceDataFromAPI(
		array $titles,
		string $sourceWiki,
		&$totalRedirects = 0,
		&$redirectsWithRLU = 0,
		&$reportData = []
	) {
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

			// Normalize titles for API with proper namespace handling
			$encodedTitles = [];
			foreach ( $chunk as $title ) {
				$normalized = $this->normalizeForAPI( $title );
				$apiTitle = $normalized['prefixed']; // Get the properly formatted prefixed title
				$titleMapping[$apiTitle] = $title; // Map API title back to original
				$encodedTitles[] = rawurlencode( $apiTitle );

				if ( $debug && $apiTitle !== $title ) {
					$this->output( "  API lookup: '$title' (normalized to '$apiTitle')\n" );
				}
			}

			$titlesParam = implode( '|', $encodedTitles );
			// Use the 'ids' parameter to ensure we get page IDs even for redirects
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
			$redirectTargetPages = [];
			$redirectSourceIds = []; // Track redirect source IDs
			$redirectTargetIds = []; // Track redirect target IDs

			if ( isset( $data['query']['redirects'] ) ) {
				// Count the redirects in this batch
				$totalRedirects += count( $data['query']['redirects'] );

				foreach ( $data['query']['redirects'] as $redirect ) {
					$from = $redirect['from'];
					$to = $redirect['to'];
					$redirectMap[$from] = $to;

					// Find original title
					if ( isset( $titleMapping[$from] ) ) {
						$originalTitle = $titleMapping[$from];

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
			}

			// Handle normalized titles from API response
			$normalizationMap = [];
			if ( isset( $data['query']['normalized'] ) ) {
				foreach ( $data['query']['normalized'] as $norm ) {
					$from = $norm['from'];
					$to = $norm['to'];
					$normalizationMap[$from] = $to;

					if ( $debug ) {
						$this->output( "  Normalized: '$from' → '$to'\n" );
					}
				}
				}

			// Create a mapping from page IDs to all titles that should receive that page's RLU data
			$pageIdToTitles = [];
			$titleToPageId = [];
			$redirectSourceToTargetId = [];

			// First pass: identify redirect relationships by page ID
			foreach ( $data['query']['pages'] as $pageId => $pageData ) {
				if ( $pageId < 0 ) continue; // Skip missing pages

				$pageId = (int)$pageId;
				$title = $pageData['title'];

				// Check if this is a redirect target
				foreach ( $redirectMap as $from => $to ) {
					if ( $to === $title ) {
						// This is a target, now find the source page ID
						foreach ( $data['query']['pages'] as $sourceId => $sourceData ) {
							if ( $sourceId > 0 && isset( $sourceData['title'] ) && $sourceData['title'] === $from ) {
								$redirectSourceToTargetId[(int)$sourceId] = $pageId;

								if ( $debug ) {
									$this->output( "  Mapped redirect source ID $sourceId ($from) to target ID $pageId ($to)\n" );
								}
								break;
							}
						}
					}
				}
			}

			// Second pass: map all titles to their page IDs
			foreach ( $data['query']['pages'] as $pageId => $pageData ) {
				// Skip non-existent pages
				if ( $pageId < 0 ) {
					if ( $verbose ) {
						$this->output( "  Page '{$pageData['title']}' does not exist in source wiki\n" );
					}
					continue;
				}

				$title = $pageData['title'];
				$pageId = (int)$pageId;
				$processedTitles++;

				// Initialize the mapping for this page ID
				if ( !isset( $pageIdToTitles[$pageId] ) ) {
					$pageIdToTitles[$pageId] = [];
				}

				// Find titles related to this page - first direct matches
				if ( isset( $titleMapping[$title] ) ) {
					$originalTitle = $titleMapping[$title];
					$pageIdToTitles[$pageId][] = $originalTitle;
					$titleToPageId[$originalTitle] = $pageId;

					// Update report data
					if ( isset( $reportData[$originalTitle] ) ) {
						$reportData[$originalTitle]['page_id'] = $pageId;
					}

					if ( $debug ) {
						$this->output( "  Direct mapping: '$title' to original '$originalTitle', page ID $pageId\n" );
					}
				}

				// Check for normalized titles
				$preNormalizedTitle = array_search( $title, $normalizationMap );
				if ( $preNormalizedTitle !== false && isset( $titleMapping[$preNormalizedTitle] ) ) {
					$originalTitle = $titleMapping[$preNormalizedTitle];
					if ( !in_array( $originalTitle, $pageIdToTitles[$pageId] ) ) {
						$pageIdToTitles[$pageId][] = $originalTitle;
						$titleToPageId[$originalTitle] = $pageId;

						// Update report data
						if ( isset( $reportData[$originalTitle] ) ) {
							$reportData[$originalTitle]['page_id'] = $pageId;
						}

						if ( $debug ) {
							$this->output( "  Normalized mapping: '$preNormalizedTitle' → '$title' to original '$originalTitle', page ID $pageId\n" );
						}
					}
				}

				// Check for redirect targets
				foreach ( $redirectMap as $from => $to ) {
					if ( $to === $title && isset( $titleMapping[$from] ) ) {
						$originalTitle = $titleMapping[$from];
						if ( !in_array( $originalTitle, $pageIdToTitles[$pageId] ) ) {
							$pageIdToTitles[$pageId][] = $originalTitle;
							$titleToPageId[$originalTitle] = $pageId;
							$redirectTargetPages[$originalTitle] = $pageId;

							// Update report data
							if ( isset( $reportData[$originalTitle] ) ) {
								$reportData[$originalTitle]['redirect_target_page_id'] = $pageId;
							}

							if ( $debug ) {
								$this->output( "  Redirect target mapping: '$from' → '$title' to original '$originalTitle', page ID $pageId\n" );
							}
						}
					}
				}
			}

			// Debug output for redirect mappings
			if ( $debug && !empty( $redirectSourceToTargetId ) ) {
				$this->output( "  API redirect source → target page ID mappings:\n" );
				foreach ( $redirectSourceToTargetId as $sourceId => $targetId ) {
					// Try to find the original title for this source ID
					$sourceTitle = "Unknown";
					foreach ( $pageIdToTitles[$sourceId] ?? [] as $title ) {
						$sourceTitle = $title;
						break;
					}
					$this->output( "    Source ID: $sourceId ($sourceTitle) → Target ID: $targetId\n" );
				}
			}

			// Third pass to process RLU data and assign it to all related titles
			foreach ( $data['query']['pages'] as $pageId => $pageData ) {
				if ( $pageId < 0 ) continue;

				$pageId = (int)$pageId;

				// If this page has RLU data, assign it to all related titles
				if ( isset( $pageData['reallastupdate'] ) ) {
					$rlu = $pageData['reallastupdate'];
					$rluData = [
						'rev_id' => $rlu['revision'],
						'timestamp' => $rlu['timestamp']
					];

					if ( $debug ) {
						$this->output( "  Found RLU data for page ID $pageId: " .
							"rev={$rlu['revision']}, ts={$rlu['timestamp']}\n" );
					}

					// Direct mapping - if this page ID has titles mapped to it
					if ( isset( $pageIdToTitles[$pageId] ) && !empty( $pageIdToTitles[$pageId] ) ) {
						foreach ( $pageIdToTitles[$pageId] as $titleToUpdate ) {
							$result[$titleToUpdate] = $rluData;

							// Check if this page was a redirect target and count it
							if ( isset( $redirectTargetPages[$titleToUpdate] ) && $redirectTargetPages[$titleToUpdate] === $pageId ) {
								$redirectsWithRLU++;
							}

							if ( $debug ) {
								$this->output( "  Assigning RLU data to '$titleToUpdate' (ID: $pageId) - direct mapping\n" );
							}
						}
					}

					// Check if this is a redirect source with RLU data
					if ( isset( $redirectSourceToTargetId[$pageId] ) ) {
						$targetPageId = $redirectSourceToTargetId[$pageId];

						// Find all titles associated with the target page ID
						if ( isset( $pageIdToTitles[$targetPageId] ) && !empty( $pageIdToTitles[$targetPageId] ) ) {
							foreach ( $pageIdToTitles[$targetPageId] as $titleToUpdate ) {
								// Only add if not already assigned
								if ( !isset( $result[$titleToUpdate] ) ) {
									$result[$titleToUpdate] = $rluData;

									if ( $debug ) {
										$this->output( "  Assigning RLU data from redirect source ID $pageId to '$titleToUpdate' (target ID: $targetPageId)\n" );
									}
								}
							}
						}
					}

					// Check if this is a redirect target with RLU data
					$sourceIds = array_keys( $redirectSourceToTargetId, $pageId );
					if ( !empty( $sourceIds ) ) {
						foreach ( $sourceIds as $sourceId ) {
							// Find all titles for this source ID
							if ( isset( $pageIdToTitles[$sourceId] ) && !empty( $pageIdToTitles[$sourceId] ) ) {
								foreach ( $pageIdToTitles[$sourceId] as $titleToUpdate ) {
									if ( !isset( $result[$titleToUpdate] ) ) {
										$result[$titleToUpdate] = $rluData;
										$redirectsWithRLU++;

										if ( $debug ) {
											$this->output( "  Assigning RLU data from target ID $pageId to redirect source '$titleToUpdate' (ID: $sourceId)\n" );
										}
									}
								}
							}
						}
					}
				} elseif ( $verbose && isset( $pageIdToTitles[$pageId] ) ) {
					$titles = implode( "', '", $pageIdToTitles[$pageId] );
					$this->output( "  No RLU data for page ID $pageId, which maps to titles: '$titles'\n" );
				}
			}

			// Debug output for final RLU data assignments
			if ( $debug ) {
				$this->output( "  Final API RLU data assignments:\n" );
				foreach ( $result as $title => $data ) {
					$pageId = $titleToPageId[$title] ?? 'unknown';
					$isRedirect = isset( $redirectMap[$title] ) ? 'Yes' : 'No';
					$targetId = isset( $redirectTargetPages[$title] ) ? $redirectTargetPages[$title] : 'N/A';

					$this->output( "    Title: $title (ID: $pageId, Redirect: $isRedirect, Target ID: $targetId)\n" );
					$this->output( "      RLU data: rev={$data['rev_id']}, ts={$data['timestamp']}\n" );
				}
			}
		}

		return $result;
	}
}

$maintClass = UpdateCrossWikiLastUpdates::class;
require_once RUN_MAINTENANCE_IF_MAIN;
