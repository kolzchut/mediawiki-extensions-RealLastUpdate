# RealLastUpdate

At Kol-Zchut, we want to know when an article was last edited by a
flesh and blood human being. MediaWiki's regular "last update" date
doesn't take that into account. Therefore, this extension saves
such a date into an article property.

This is our very simplistic algorithm, performed on every page save:
- Is the editor a human*?
  - Yes: update the property.
  - No: Do we already have a last real update date saved?
    - Yes: do nothing (keep the last date)
    - No: find the latest revision by a human and save the property
    
 
"bot" is defined by a configuration array of group names, which by
default is `[ 'bot', 'automaton' ]`. The first is a MediaWiki default,
and the last is a Kol-Zchut custom group, but shouldn't hurt by being
there.
"human" is therefore simply "not in one of the above groups".

## Installation

1. Download the extension code and place it in your MediaWiki extensions directory.
2. Add the following line to your `LocalSettings.php`:
   ```php
   wfLoadExtension( 'RealLastUpdate' );
   ```
3. Optional: Configure which user groups are considered bots:
   ```php
   $wgRealLastUpdateBotGroups = [ 'bot', 'automaton', 'your-bot-group' ];
   ```
4. Run the MediaWiki update script to create the necessary database table:
   ```
   php maintenance/update.php
   ```
5. Populate the database table with historical data:
   ```
   php maintenance/run.php PopulateRealLastUpdateTable
   ```

## Configuration

- `$wgRealLastUpdateBotGroups`: Array of user groups considered bots (default: `[ 'bot', 'automaton' ]`).
- `$wgRealLastUpdateSourceWiki`: (Optional) Name of the source wiki for cross-wiki support.
- `$wgRealLastUpdateSourceWikiApi`: (Optional) API URL for the source wiki (used for API-based cross-wiki support).
- `$wgRealLastUpdateSourceWikiDb`: (Optional) Database identifier for direct access to the source wiki database (used for DB-based cross-wiki support).

## Technical Details

The extension stores last human edit information in a dedicated database table and exposes it via a custom API module.

### Database Structure

The extension uses a dedicated database table called `real_last_update` to store the last human edit information:

- `rlud_page_id`: The page ID (primary key)
- `rlud_rev_id`: The revision ID of the last human edit
- `rlud_timestamp`: The timestamp of the last human edit

### API Module

The extension provides a custom API module for querying last human edit information:

- **Module name:** `reallastupdate`
- **Usage example:**
  ```
  api.php?action=query&prop=reallastupdate&titles=YourPageTitle
  ```
- **Parameters:**
  - `followredirects` (boolean): Follow up to two levels of redirects (default: false)
  - `debug` (boolean): Include debug information in the response (default: false)

#### Example API Response

```json
{
  "query": {
    "pages": {
      "123": {
        "pageid": 123,
        "title": "YourPageTitle",
        "reallastupdate": {
          "revision": 5678,
          "timestamp": "20220514160823"
        }
      }
    }
  }
}
```

### Cross-Wiki Support

When using this extension across multiple wikis, it can track and display last update information from a source wiki. The extension supports two methods for fetching data from the source wiki:

- **Direct Database Access:** The extension reads the `real_last_update` table directly from the source wiki's database, using the `$wgRealLastUpdateSourceWikiDb` setting. This requires appropriate database permissions and network access.
- **API Access:** The extension fetches last update information from the source wiki using its API, as configured in `$wgRealLastUpdateSourceWikiApi`. This is useful when direct database access is not possible.

To enable cross-wiki support:

1. Set `$wgRealLastUpdateSourceWiki` to the source wiki's name.
2. Set either `$wgRealLastUpdateSourceWikiDb` (for DB access) or `$wgRealLastUpdateSourceWikiApi` (for API access) as appropriate.
3. Ensure an interwiki prefix is set up for the source wiki that matches the name in `$wgRealLastUpdateSourceWiki`.
4. **Schedule the cross-wiki maintenance script** to run periodically (e.g., via cron) to keep the local data in sync:
   ```
   # Example cron entry to run every hour
   0 * * * * php extensions/WikiRights/RealLastUpdate/Maintenance/UpdateCrossWikiRealLastUpdate.php
   ```
5. The special page will display and allow sorting by the source wiki's last update timestamp. The timestamp will link to the corresponding page on the source wiki using the interwiki link.

### Special Page

The extension provides a special page, `Special:RealLastUpdate`, which lists pages and their last real update information. This page allows sorting and filtering, and integrates cross-wiki data if configured.

## License

This extension is licensed under the GNU General Public License v2.0 or later.

