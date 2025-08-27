-- Migration script to convert DATETIME fields to UNIX timestamps
USE transcribomatic;

-- Add new UNIX timestamp columns
ALTER TABLE Users ADD COLUMN createdAtUnix INT UNSIGNED DEFAULT (UNIX_TIMESTAMP());
ALTER TABLE Users ADD COLUMN updatedAtUnix INT UNSIGNED DEFAULT (UNIX_TIMESTAMP());
ALTER TABLE ApiUsage ADD COLUMN createdAtUnix INT UNSIGNED DEFAULT (UNIX_TIMESTAMP());
ALTER TABLE UsageLog ADD COLUMN createdAtUnix INT UNSIGNED DEFAULT (UNIX_TIMESTAMP());

-- Convert existing DATETIME values to UNIX timestamps
UPDATE Users SET createdAtUnix = UNIX_TIMESTAMP(createdAt), updatedAtUnix = UNIX_TIMESTAMP(updatedAt);
UPDATE ApiUsage SET createdAtUnix = UNIX_TIMESTAMP(createdAt);
UPDATE UsageLog SET createdAtUnix = UNIX_TIMESTAMP(createdAt);

-- Drop old DATETIME columns
ALTER TABLE Users DROP COLUMN createdAt, DROP COLUMN updatedAt;
ALTER TABLE ApiUsage DROP COLUMN createdAt;
ALTER TABLE UsageLog DROP COLUMN createdAt;

-- Rename new columns to original names
ALTER TABLE Users CHANGE COLUMN createdAtUnix createdAt INT UNSIGNED DEFAULT (UNIX_TIMESTAMP());
ALTER TABLE Users CHANGE COLUMN updatedAtUnix updatedAt INT UNSIGNED DEFAULT (UNIX_TIMESTAMP());
ALTER TABLE ApiUsage CHANGE COLUMN createdAtUnix createdAt INT UNSIGNED DEFAULT (UNIX_TIMESTAMP());
ALTER TABLE UsageLog CHANGE COLUMN createdAtUnix createdAt INT UNSIGNED DEFAULT (UNIX_TIMESTAMP());