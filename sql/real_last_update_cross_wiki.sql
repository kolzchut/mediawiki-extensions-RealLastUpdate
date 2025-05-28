CREATE TABLE IF NOT EXISTS /*_*/real_last_update_cross_wiki (
	rlucw_page_id int unsigned NOT NULL PRIMARY KEY,
	rlucw_source_title varbinary(255) NOT NULL,
	rlucw_source_timestamp varbinary(14) NULL,
	rlucw_source_revid int unsigned NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/rlucw_source ON /*_*/real_last_update_cross_wiki (rlucw_source_title);
