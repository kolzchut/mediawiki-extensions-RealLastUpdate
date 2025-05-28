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

## Technical Details

The extension stores two page properties that can be accessed through the MediaWiki API:
- `RealLastUpdateTime`: The timestamp of the last edit by a human
- `RealLastUpdateRev`: The revision ID of the last edit by a human

You can access these values through the standard API by requesting page properties:
```
api.php?action=query&prop=pageprops&titles=YourPageTitle&ppprop=RealLastUpdateTime|RealLastUpdateRev
```

### Database Structure

The extension uses a dedicated database table called `real_last_update` to store the last human edit information:

- `rlud_page_id`: The page ID (primary key)
- `rlud_rev_id`: The revision ID of the last human edit
- `rlud_timestamp`: The timestamp of the last human edit

### Cross-Wiki Support

When using this extension across multiple wikis, it can track and display last update information from a source wiki. 
For this functionality to work properly:

1. Configure `$wgRealLastUpdateSourceWiki` to specify the source wiki name
2. Ensure an interwiki prefix is set up for the source wiki that matches the name in `$wgRealLastUpdateSourceWiki`
3. The special page will display and allow sorting by the source wiki's last update timestamp
4. The timestamp will link to the corresponding page on the source wiki using the interwiki link

### Example API Response

```json
{
  "query": {
    "pages": {
      "123": {
        "pageid": 123,
        "ns": 0,
        "title": "YourPageTitle",
        "pageprops": {
          "RealLastUpdateTime": "20220514160823",
          "RealLastUpdateRev": "5678"
        }
      }
    }
  }
}
```
## License

This extension is licensed under the GNU General Public License v2.0 or later.
