<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

namespace MediaWiki\Extension\RealLastUpdate;

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IDatabase;

class RealLastUpdate {
	private const TABLE_NAME = 'real_last_update';

	/**
	 * Get a configuration variable
	 *
	 * @param string $name
	 * @return mixed
	 */
	public static function getConfigVar( string $name ) {
		$config = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'reallastupdate' );
		return $config->get( $name );
	}

	/**
	 * Get database connection
	 *
	 * @param int $db DB_PRIMARY/DB_REPLICA
	 * @return IDatabase
	 */
	private static function getDB( int $db = DB_REPLICA ): IDatabase {
		$loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
		return $loadBalancer->getConnection( $db );
	}

	/**
	 * Update the last real edit information for a page
	 *
	 * @param int $pageId The page ID
	 * @param int $revId The revision ID
	 * @param string $timestamp The timestamp of the revision
	 * @return bool Success
	 */
	public static function updateLastRealEdit( int $pageId, int $revId, string $timestamp ): bool {
		$dbw = self::getDB( DB_PRIMARY );

		return $dbw->upsert(
			self::TABLE_NAME,
			[
				'rlud_page_id' => $pageId,
				'rlud_rev_id' => $revId,
				'rlud_timestamp' => $timestamp
			],
			[ 'rlud_page_id' ],
			[
				'rlud_rev_id' => $revId,
				'rlud_timestamp' => $timestamp
			],
			__METHOD__
		);
	}

	/**
	 * Get the last real edit information for a page
	 *
	 * @param int $pageId The page ID
	 * @param bool $forceRefresh Whether to force a refresh from revision history
	 * @return array|false Array with 'rev_id' and 'timestamp', or false if not found
	 */
	public static function getLastRealEdit( int $pageId, bool $forceRefresh = false ) {
		if ( $forceRefresh ) {
			return self::findLastRealRevision( $pageId );
		}

		$dbr = self::getDB();

		$row = $dbr->selectRow(
			self::TABLE_NAME,
			[ 'rlud_rev_id', 'rlud_timestamp' ],
			[ 'rlud_page_id' => $pageId ],
			__METHOD__
		);

		if ( $row ) {
			return [
				'rev_id' => (int)$row->rlud_rev_id,
				'timestamp' => $row->rlud_timestamp
			];
		}

		// No saved data, let's find it from revision history
		return self::findLastRealRevision( $pageId );
	}

	/**
	 * Find the last real revision made by a human for a page
	 *
	 * @param int $pageId The page ID
	 * @return array|false Array with 'rev_id' and 'timestamp', or false if not found
	 */
	public static function findLastRealRevision( int $pageId ) {
		$dbr = self::getDB();
		$actorsToIgnore = self::getIgnoredActorIds();

		// Skip redirect pages
		$wikiPage = \WikiPage::newFromID( $pageId );
		if ( $wikiPage && $wikiPage->isRedirect() ) {
			return false;
		}

		// Query for the most recent revision where the actor is not in our ignored list
		$revisionStore = MediaWikiServices::getInstance()->getRevisionStore();
		$queryInfo = $revisionStore->getQueryInfo();
		$conds = [
			'rev_page' => $pageId
		];

		if ( !empty( $actorsToIgnore ) ) {
			$conds[] = 'rev_actor NOT IN (' . $dbr->makeList( $actorsToIgnore ) . ')';
		}

		$row = $dbr->selectRow(
			$queryInfo['tables'],
			[ 'rev_id', 'rev_timestamp' ],
			$conds,
			__METHOD__,
			[ 'ORDER BY' => 'rev_timestamp DESC' ],
			$queryInfo['joins']
		);

		if ( $row ) {
			$result = [
				'rev_id' => (int)$row->rev_id,
				'timestamp' => $row->rev_timestamp
			];

			self::updateLastRealEdit( $pageId, $result['rev_id'], $result['timestamp'] );

			return $result;
		}

		return false;
	}

	/**
	 * Get array of actor IDs to ignore (bots)
	 *
	 * @return array Array of actor IDs
	 */
	public static function getIgnoredActorIds(): array {
		static $actorIds = null;

		if ( $actorIds !== null ) {
			return $actorIds;
		}

		$dbr = self::getDB();
		$botGroups = self::getConfigVar( 'RealLastUpdateBotGroups' );

		if ( empty( $botGroups ) ) {
			return [];
		}

		$actorIds = $dbr->selectFieldValues(
			[ 'user_groups', 'actor' ],
			'actor_id',
			[ 'ug_group' => $botGroups ],
			__METHOD__,
			[ 'DISTINCT' ],
			[ 'actor' => [ 'JOIN', 'actor_user = ug_user' ] ]
		);

		return $actorIds ?: [];
	}

	/**
	 * Get a revision by its ID
	 *
	 * @param int $revId
	 * @return RevisionRecord|null
	 */
	public static function getRevisionById( int $revId ): ?RevisionRecord {
		$revisionStore = MediaWikiServices::getInstance()->getRevisionStore();
		return $revisionStore->getRevisionById( $revId );
	}

	/**
	 * Check if a user is a human (not in bot groups)
	 *
	 * @param UserIdentity $user
	 * @return bool
	 */
	public static function isHuman( UserIdentity $user ): bool {
		$botGroups = self::getConfigVar( 'RealLastUpdateBotGroups' );

		if ( empty( $botGroups ) ) {
			// No bot groups defined, consider all users as humans
			return true;
		}

		if ( !$user->isRegistered() ) {
			// Consider anonymous users as humans
			return true;
		}

		$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();
		$userGroups = $userGroupManager->getUserGroups( $user );

		if ( array_intersect( $userGroups, $botGroups ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get SELECT fields and joins for retrieving the real last update data
	 *
	 * @param string $pageIdFieldName if we want to compare to a differently named page_id field
	 * @return array[] With three keys:
	 *   - tables: (string[]) to include in the `$table` to `IDatabase->select()`
	 *   - fields: (string[]) to include in the `$vars` to `IDatabase->select()`
	 *   - join_conds: (array) to include in the `$join_conds` to `IDatabase->select()`
	 *  All tables, fields, and joins are aliased, so `+` is safe to use.
	 */
	public static function getJoin( string $pageIdFieldName = 'page_id' ): array {
		$tables['real_last_update'] = self::TABLE_NAME;
		$fields['real_last_update_timestamp'] = 'real_last_update.rlud_timestamp';
		$fields['real_last_update_revid'] = 'real_last_update.rlud_rev_id';
		$joins['real_last_update'] = [
			'LEFT OUTER JOIN',
			"$pageIdFieldName = real_last_update.rlud_page_id"
		];

		return [
			'tables' => $tables,
			'fields' => $fields,
			'join_conds' => $joins
		];
	}
}

