<?php
// Migration script to create reviews table and add description column
// Run this file once to set up the reviews system
// Access via browser: http://localhost/goglam/run_reviews_migration.php

$host = "localhost";
$username = "root";
$password = "";
$database = "goglam";

// Connect to database
$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

echo "<h2>Running reviews system migration...</h2><pre>";

// Check if reviews table exists
$table_check = $conn->query("SHOW TABLES LIKE 'reviews'");
if ($table_check && $table_check->num_rows > 0) {
    echo "Reviews table already exists.\n";
} else {
    // Create reviews table
    $create_table = "CREATE TABLE `reviews` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    if ($conn->query($create_table)) {
        echo "✓ Reviews table created successfully\n";
    } else {
        echo "✗ Error creating reviews table: " . $conn->error . "\n";
    }
}

// Check if description column exists
$column_check = $conn->query("SHOW COLUMNS FROM saloon LIKE 'description'");
if ($column_check && $column_check->num_rows > 0) {
    echo "Description column already exists in saloon table.\n";
} else {
    // Add description column
    $add_column = "ALTER TABLE `saloon` ADD COLUMN `description` TEXT DEFAULT NULL AFTER `phone_no`";
    if ($conn->query($add_column)) {
        echo "✓ Description column added to saloon table successfully\n";
    } else {
        echo "✗ Error adding description column: " . $conn->error . "\n";
    }
}

echo "\nMigration completed!</pre>";
$conn->close();
?>

