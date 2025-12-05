<?php
// Database connection parameters
$servername = "localhost";
$username = "root";
$password = "";
$database = "goglam"; // Database name from SQL file

// Create connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("❌ Connection failed: " . $conn->connect_error);
}

echo "✅ Connection successful!<br>";
echo "📊 Database: " . $database . "<br>";
echo "🖥️ Server: " . $servername . "<br>";

// Test query to verify database is accessible
$result = $conn->query("SHOW TABLES");
if ($result) {
    echo "📋 Tables in database:<br>";
    while ($row = $result->fetch_array()) {
        echo "  - " . $row[0] . "<br>";
    }
} else {
    echo "⚠️ Could not retrieve tables: " . $conn->error . "<br>";
}

// Close connection
$conn->close();
?>
