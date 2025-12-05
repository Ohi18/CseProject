<?php
// Setup script for GoGlam database and users table
// Run this file once to set up your database

// Connect to MySQL (XAMPP default: no password for root)
$conn = new mysqli('localhost', 'root', '');

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>Database Setup for GoGlam</h2>";
echo "<p>Connected to MySQL</p>";

// Create database if it doesn't exist
$db_name = "goglam";
$create_db_query = "CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";

if ($conn->query($create_db_query)) {
    echo "<p>Database '$db_name' created or already exists</p>";
} else {
    echo "<p style='color: red;'>Error creating database: " . $conn->error . "</p>";
    $conn->close();
    exit;
}

// Select the database
if (!$conn->select_db($db_name)) {
    echo "<p style='color: red;'>Error selecting database: " . $conn->error . "</p>";
    $conn->close();
    exit;
}

// Create users table if it doesn't exist
$create_table_query = "CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(255) UNIQUE NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `user_type` ENUM('customer','saloon') NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($create_table_query)) {
    echo "<p>Users table created or already exists</p>";
} else {
    echo "<p style='color: red;'>Error creating table: " . $conn->error . "</p>";
    $conn->close();
    exit;
}

// Insert test user if it doesn't exist
$test_email = "test@example.com";
$test_password = "123456";
$test_user_type = "customer";

// Hash the password
$password_hash = password_hash($test_password, PASSWORD_DEFAULT);

// Check if user already exists
$check_user = $conn->prepare("SELECT id FROM users WHERE email = ?");
$check_user->bind_param("s", $test_email);
$check_user->execute();
$result = $check_user->get_result();

if ($result->num_rows > 0) {
    echo "<p>Test user already exists</p>";
} else {
    // Insert test user
    $insert_user = $conn->prepare("INSERT INTO users (email, password_hash, user_type) VALUES (?, ?, ?)");
    $insert_user->bind_param("sss", $test_email, $password_hash, $test_user_type);
    
    if ($insert_user->execute()) {
        echo "<p>Test user inserted successfully</p>";
        echo "<p><strong>Test credentials:</strong></p>";
        echo "<ul>";
        echo "<li>Email: test@example.com</li>";
        echo "<li>Password: 123456</li>";
        echo "<li>User Type: customer</li>";
        echo "</ul>";
    } else {
        echo "<p style='color: red;'>Error inserting test user: " . $insert_user->error . "</p>";
    }
    
    $insert_user->close();
}

$check_user->close();

// Close connection
$conn->close();

echo "<p style='color: green; font-weight: bold; margin-top: 20px;'>Setup complete! You can now go back to the login page.</p>";
echo "<p><a href='goglam_login.html' style='color: #9B5A7B; text-decoration: none; border: 2px solid #D4B8C8; padding: 10px 20px; border-radius: 8px; display: inline-block;'>← Back to Login</a></p>";
?>
