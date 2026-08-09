-- Adds the optional player-controlled name used by compact standings views.
-- Safe to rerun. Existing display names are left unchanged.

SET @schema_name = DATABASE();

SET @sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = @schema_name
              AND table_name = 'ML_Users'
        )
        AND NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @schema_name
              AND table_name = 'ML_Users'
              AND column_name = 'ShortDisplayName'
        ),
        'ALTER TABLE `ML_Users` ADD COLUMN `ShortDisplayName` VARCHAR(12) NULL AFTER `UserName`',
        'SELECT 1'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = @schema_name
              AND table_name = 'QA_ML_Users'
        )
        AND NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @schema_name
              AND table_name = 'QA_ML_Users'
              AND column_name = 'ShortDisplayName'
        ),
        'ALTER TABLE `QA_ML_Users` ADD COLUMN `ShortDisplayName` VARCHAR(12) NULL AFTER `UserName`',
        'SELECT 1'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
