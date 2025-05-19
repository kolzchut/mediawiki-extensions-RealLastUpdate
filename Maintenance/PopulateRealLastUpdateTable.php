<?php
/**
 * Populate the real_last_update table with data from revision history
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
 * @ingroup Maintenance
 */

namespace MediaWiki\Extension\RealLastUpdate\Maintenance;

use Maintenance;
use MediaWiki\Extension\RealLastUpdate\RealLastUpdate;
use Title;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script to populate the real_last_update table
 */
class PopulateRealLastUpdateTable extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Populate the real_last_update table with data from revision history' );
		$this->addOption( 'batch-size', 'Number of pages to process per batch', false, true, 'b' );
		$this->setBatchSize( 100 );
		$this->requireExtension( 'RealLastUpdate' );
	}

	public function execute() {
		$dbw = $this->getDB( DB_PRIMARY );
		$dbr = $this->getDB( DB_REPLICA );
		$batchSize = $this->getOption( 'batch-size', $this->getBatchSize() );

		// First delete all existing records
		$this->output( "Deleting all existing records from real_last_update table...\n" );
		$dbw->delete( 'real_last_update', '*', __METHOD__ );
		$this->output( "Existing records deleted.\n" );

		// Get the IDs of bot users first
		$actorsToIgnore = RealLastUpdate::getIgnoredActorIds();

		if ( empty( $actorsToIgnore ) ) {
			$this->error( "Could not determine bot actor IDs. Aborting.", 1 );
			return;
		}

		$this->output( "Found " . count( $actorsToIgnore ) . " bot actor IDs to ignore.\n" );
		$this->output( "Populating real_last_update table...\n" );

		$start = 0;
		$count = 0;

		do {
			$this->output( "Processing batch starting at offset $start\n" );

			// Get a batch of page IDs
			$res = $dbr->select(
				'page',
				[ 'page_id', 'page_namespace', 'page_title' ],
				[ 'page_is_redirect' => false ],
				__METHOD__,
				[
					'ORDER BY' => 'page_id',
					'LIMIT' => $batchSize,
					'OFFSET' => $start
				]
			);

			$pageCount = $res->numRows();
			if ( $pageCount == 0 ) {
				break;
			}

			$pageIds = [];
			$titles = [];

			foreach ( $res as $row ) {
				$pageId = (int)$row->page_id;
				$pageIds[] = $pageId;
				$titles[$pageId] = Title::newFromRow( $row );
			}

			// Process each page
			foreach ( $pageIds as $pageId ) {
				$title = $titles[$pageId];
				$this->output( "Processing page $pageId: " . $title->getPrefixedText() . "\n" );

				// Use the existing method to find the last real revision
				$lastRealEdit = RealLastUpdate::findLastRealRevision( $pageId );

				if ( $lastRealEdit ) {
					$count++;
					$this->output( "  Found last human edit: rev_id={$lastRealEdit['rev_id']}, " .
						"timestamp={$lastRealEdit['timestamp']}\n" );
				} else {
					$this->output( "  No human edits found for this page.\n" );
				}
			}

			$start += $batchSize;
			$this->output( "Processed $pageCount pages in this batch\n" );

			// Wait a bit to prevent overloading
			/*
			if ( $pageCount == $batchSize ) {
				$this->waitForReplication();
			}
			*/
		} while ( $pageCount == $batchSize );

		$this->output( "Done! Updated information for $count pages.\n" );
	}
}

$maintClass = PopulateRealLastUpdateTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;
