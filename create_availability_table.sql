-- Create service_availability table for managing per-service weekly schedules
-- This allows saloons to set availability times for each service they offer
-- 
-- INSTRUCTIONS: Run each section separately if you encounter any issues
-- Section 1: Create the table (run this first)
-- Section 2: Add the foreign key (run this second, only if table was created successfully)

-- ============================================
-- SECTION 1: Create table structure
-- ============================================
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

-- ============================================
-- SECTION 2: Add foreign key constraint
-- ============================================
-- Only run this if the table was created successfully above
-- If you get an error about constraint already existing, that's okay - skip this section

-- Remove existing constraint if it exists (for MariaDB 10.2.2+)
ALTER TABLE `service_availability` 
DROP FOREIGN KEY IF EXISTS `fk_availability_service`;

-- Add the foreign key constraint
ALTER TABLE `service_availability`
ADD CONSTRAINT `fk_availability_service` 
FOREIGN KEY (`service_id`) 
REFERENCES `services` (`service_id`) 
ON DELETE CASCADE 
ON UPDATE CASCADE;

-- ============================================
-- OPTIONAL: Set default availability
-- ============================================
-- Uncomment the following if you want to set default availability for existing services (9am-6pm, Monday-Saturday)
/*
INSERT IGNORE INTO `service_availability` (`service_id`, `day_of_week`, `start_time`, `end_time`, `is_available`)
SELECT 
    s.service_id,
    d.day_of_week,
    '09:00:00' as start_time,
    '18:00:00' as end_time,
    1 as is_available
FROM services s
CROSS JOIN (
    SELECT 1 as day_of_week UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
) d
WHERE NOT EXISTS (
    SELECT 1 FROM service_availability sa 
    WHERE sa.service_id = s.service_id AND sa.day_of_week = d.day_of_week
);
*/
