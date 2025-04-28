<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

namespace MediaWiki\Extension\RealLastUpdate;

use DatabaseUpdater;
use MediaWiki\Hook\OutputPageParserOutputHook;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\UserIdentity;
use Parser;
use ParserOutput;

/**
 * Hooks for RealLastUpdate extension
 */
class Hooks implements
	PageSaveCompleteHook,
	OutputPageParserOutputHook,
	LoadExtensionSchemaUpdatesHook
{
	private const PROP_TIME = 'RealLastUpdateTimestamp';
	private const PROP_REV = 'RealLastUpdateRevision';

	/**
	 * OutputPageParserOutput hook handler
	 * See https://www.mediawiki.org/wiki/Manual:Hooks/OutputPageParserOutput
	 *
	 * @inheritDoc
	 */
	public function onOutputPageParserOutput( $out, $parserOutput ): void {
		// If we already have the properties, no need to do anything
		if ( $parserOutput->getProperty( self::PROP_TIME ) && $parserOutput->getProperty( self::PROP_REV ) ) {
			return;
		}

		// No saved date or revision, we need to find the last "real" revision
		$pageId = $out->getTitle()->getArticleID();
		if ( $pageId > 0 ) {
			$lastRealEdit = RealLastUpdate::getLastRealEdit( $pageId );
			if ( $lastRealEdit ) {
				if ( method_exists( $parserOutput, 'setPageProperty' ) ) {
					// MW 1.38
					$parserOutput->setPageProperty( self::PROP_TIME, $lastRealEdit['timestamp'] );
					$parserOutput->setPageProperty( self::PROP_REV, $lastRealEdit['rev_id'] );
				} else {
					$out->setProperty( self::PROP_TIME, $lastRealEdit['timestamp'] );
					$parserOutput->setProperty( self::PROP_TIME, $lastRealEdit['timestamp'] );
					$parserOutput->setProperty( self::PROP_REV, $lastRealEdit['rev_id'] );
				}
			}
		}
	}

	/**
	 * ParserAfterTidy hook handler
	 * See https://www.mediawiki.org/wiki/Manual:Hooks/ParserAfterTidy
	 *
	 * @param Parser $parser
	 * @param string &$text
	 * @return bool|void
	 */
	public function onParserAfterTidy( $parser, &$text ) {
		$parserOutput = $parser->getOutput();
		$title = $parser->getTitle();

		// If the properties are already set, don't do anything
		if ( $parserOutput->getProperty( self::PROP_TIME ) && $parserOutput->getProperty( self::PROP_REV ) ) {
			return;
		}

		// Get the page ID
		$pageId = $title->getArticleID();
		if ( $pageId <= 0 ) {
			return;
		}

		// Get the last real edit information
		$lastRealEdit = RealLastUpdate::getLastRealEdit( $pageId );
		if ( !$lastRealEdit ) {
			return;
		}

		// Set the page properties - this happens during parsing so it will be saved to page_props
		if ( method_exists( $parserOutput, 'setPageProperty' ) ) {
			// MW 1.38+
			$parserOutput->setPageProperty( self::PROP_TIME, $lastRealEdit['timestamp'] );
			$parserOutput->setPageProperty( self::PROP_REV, $lastRealEdit['rev_id'] );
		} else {
			// Older MW versions
			$parserOutput->setProperty( self::PROP_TIME, $lastRealEdit['timestamp'] );
			$parserOutput->setProperty( self::PROP_REV, $lastRealEdit['rev_id'] );
		}
	}

	// @todo handle PageDeleteCompleteHook as well

	/**
	 * Hook handler for PageSaveComplete
	 *
	 * @inheritDoc
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		$isHuman = RealLastUpdate::isHuman( $user );
		if ( $isHuman ) {
			// If human edit, update the database with the revision information
			$pageId = $wikiPage->getId();
			RealLastUpdate::updateLastRealEdit(
				$pageId,
				$revisionRecord->getId(),
				$revisionRecord->getTimestamp()
			);

			// Purge the page cache to ensure fresh data
			$wikiPage->getTitle()->invalidateCache();
		}
	}

	/**
	 * Hook handler for LoadExtensionSchemaUpdates
	 * Adds database tables when the wiki is installed or updated
	 *
	 * @inheritDoc
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$extDir = __DIR__ . '/..';
		$updater->addExtensionTable( 'real_last_update',
			"$extDir/sql/tables.sql" );
	}
}
