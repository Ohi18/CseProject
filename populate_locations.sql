-- SQL script to populate location table with required locations
-- Run this in phpMyAdmin or your MySQL client

-- Insert locations (only if they don't already exist)
INSERT INTO location (name) 
SELECT 'Gulshan' WHERE NOT EXISTS (SELECT 1 FROM location WHERE name = 'Gulshan');

INSERT INTO location (name) 
SELECT 'Uttara' WHERE NOT EXISTS (SELECT 1 FROM location WHERE name = 'Uttara');

INSERT INTO location (name) 
SELECT 'Mirpur' WHERE NOT EXISTS (SELECT 1 FROM location WHERE name = 'Mirpur');

INSERT INTO location (name) 
SELECT 'Bashudhara R/A' WHERE NOT EXISTS (SELECT 1 FROM location WHERE name = 'Bashudhara R/A');

INSERT INTO location (name) 
SELECT 'Khilgaon' WHERE NOT EXISTS (SELECT 1 FROM location WHERE name = 'Khilgaon');

INSERT INTO location (name) 
SELECT 'Bailey Road' WHERE NOT EXISTS (SELECT 1 FROM location WHERE name = 'Bailey Road');

INSERT INTO location (name) 
SELECT 'Dhanmondi' WHERE NOT EXISTS (SELECT 1 FROM location WHERE name = 'Dhanmondi');

INSERT INTO location (name) 
SELECT 'Savar' WHERE NOT EXISTS (SELECT 1 FROM location WHERE name = 'Savar');


