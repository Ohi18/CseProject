-- Migration: Add service_id column to slots table
-- This allows linking bookings to specific services

ALTER TABLE `slots` 
ADD COLUMN `service_id` INT(11) NULL AFTER `saloon_id`,
ADD KEY `fk_slots_service` (`service_id`);

-- Add foreign key constraint
ALTER TABLE `slots`
ADD CONSTRAINT `fk_slots_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE SET NULL ON UPDATE CASCADE;





