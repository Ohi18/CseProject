-- Migration: Add Reviews System and Saloon Description
-- This allows customers to rate and review saloons after service completion

-- Create reviews table
CREATE TABLE IF NOT EXISTS `reviews` (
  `review_id` INT(11) NOT NULL AUTO_INCREMENT,
  `saloon_id` INT(11) NOT NULL,
  `customer_id` INT(11) NOT NULL,
  `slot_id` INT(11) NOT NULL,
  `rating` INT(11) NOT NULL,
  `review_text` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`review_id`),
  KEY `fk_reviews_saloon` (`saloon_id`),
  KEY `fk_reviews_customer` (`customer_id`),
  KEY `fk_reviews_slot` (`slot_id`),
  UNIQUE KEY `unique_slot_review` (`slot_id`),
  CONSTRAINT `fk_reviews_saloon` FOREIGN KEY (`saloon_id`) REFERENCES `saloon` (`saloon_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_reviews_customer` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_reviews_slot` FOREIGN KEY (`slot_id`) REFERENCES `slots` (`slot_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add description column to saloon table (check if exists first)
SET @dbname = DATABASE();
SET @tablename = 'saloon';
SET @columnname = 'description';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' TEXT DEFAULT NULL AFTER phone_no')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

