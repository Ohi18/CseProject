-- SIMPLE VERSION - Use this if the main script hangs
-- This creates the table WITHOUT foreign key constraint to avoid locking issues
-- You can add the foreign key later if needed

CREATE TABLE IF NOT EXISTS `service_availability` (
  `availability_id` int(11) NOT NULL AUTO_INCREMENT,
  `service_id` int(11) NOT NULL,
  `day_of_week` tinyint(4) NOT NULL COMMENT '0=Sunday, 1=Monday, 2=Tuesday, 3=Wednesday, 4=Thursday, 5=Friday, 6=Saturday',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`availability_id`),
  KEY `idx_service_id` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Note: This version does NOT include the foreign key constraint
-- The application will work fine without it, but you won't have referential integrity
-- If you want to add the foreign key later, run this separately:
-- ALTER TABLE `service_availability`
-- ADD CONSTRAINT `fk_availability_service` 
-- FOREIGN KEY (`service_id`) 
-- REFERENCES `services` (`service_id`) 
-- ON DELETE CASCADE 
-- ON UPDATE CASCADE;

