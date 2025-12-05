<?php
// Script to populate location table with required locations
// Run this file once to add the locations to your database

// Database configuration
$host = "localhost";
$username = "root";
$password = "";
$database = "goglam";

// Connect to database
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>Populating Location Table</h2>";
echo "<p>Connected to database: $database</p>";

// List of locations to insert
$locations = [
    'Gulshan',
    'Uttara',
    'Mirpur',
    'Bashudhara R/A',
    'Khilgaon',
    'Bailey Road',
    'Dhanmondi',
    'Savar'
];

// Prepare statement to check if location exists
$check_stmt = $conn->prepare("SELECT location_id FROM location WHERE name = ?");
$insert_stmt = $conn->prepare("INSERT INTO location (name) VALUES (?)");

$inserted_count = 0;
$existing_count = 0;

foreach ($locations as $location_name) {
    // Check if location already exists
    $check_stmt->bind_param("s", $location_name);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo "<p style='color: orange;'>Location '$location_name' already exists - skipped</p>";
        $existing_count++;
    } else {
        // Insert new location
        $insert_stmt->bind_param("s", $location_name);
        if ($insert_stmt->execute()) {
            echo "<p style='color: green;'>✓ Location '$location_name' inserted successfully</p>";
            $inserted_count++;
        } else {
            echo "<p style='color: red;'>✗ Error inserting '$location_name': " . $insert_stmt->error . "</p>";
        }
    }
}

$check_stmt->close();
$insert_stmt->close();

// Display summary
echo "<hr>";
echo "<h3>Summary</h3>";
echo "<p><strong>New locations inserted:</strong> $inserted_count</p>";
echo "<p><strong>Existing locations skipped:</strong> $existing_count</p>";
echo "<p><strong>Total locations:</strong> " . count($locations) . "</p>";

// Display all locations in database
echo "<hr>";
echo "<h3>All Locations in Database:</h3>";
$result = $conn->query("SELECT location_id, name FROM location ORDER BY name");
if ($result && $result->num_rows > 0) {
    echo "<ul>";
    while ($row = $result->fetch_assoc()) {
        echo "<li>ID: {$row['location_id']} - {$row['name']}</li>";
    }
    echo "</ul>";
} else {
    echo "<p>No locations found in database.</p>";
}

$conn->close();

echo "<p style='color: green; font-weight: bold; margin-top: 20px;'>Location population complete!</p>";
echo "<p><a href='registration.php' style='color: #9B5A7B; text-decoration: none; border: 2px solid #D4B8C8; padding: 10px 20px; border-radius: 8px; display: inline-block;'>← Back to Registration</a></p>";
?>


