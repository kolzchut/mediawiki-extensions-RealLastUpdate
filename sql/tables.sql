-- Database schema for RealLastUpdate extension

-- Table to store the last real update information
CREATE TABLE IF NOT EXISTS /*_*/real_last_update (
    -- The page ID
    rlud_page_id INT UNSIGNED NOT NULL PRIMARY KEY,
    -- The revision ID of the last human edit
    rlud_rev_id INT UNSIGNED NOT NULL,
    -- The timestamp of the last human edit
    rlud_timestamp VARBINARY(14) NOT NULL
) /*$wgDBTableOptions*/;

-- Index for faster lookups
CREATE INDEX /*i*/rlud_timestamp ON /*_*/real_last_update (rlud_timestamp);
