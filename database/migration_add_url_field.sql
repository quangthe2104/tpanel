-- Migration: Add url field to websites table
-- Purpose: Store website URL for HTTP-based backup downloads
-- Date: 2025-11-16

ALTER TABLE `websites` 
ADD COLUMN `url` VARCHAR(255) NULL AFTER `domain`;

-- Update existing records: set url from domain if domain exists
UPDATE `websites` 
SET `url` = CONCAT('https://', `domain`) 
WHERE `domain` IS NOT NULL AND `domain` != '' AND `url` IS NULL;

