<?php
header('Content-Type: application/json');

// Connect to database with error handling
try {
    $conn = new mysqli('localhost', 'root', '', 'goglam');
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
} catch (mysqli_sql_exception $e) {
    echo json_encode(['error' => 'Database connection error: ' . $e->getMessage() . '. Please make sure XAMPP MySQL is running.']);
    exit;
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

$result = $conn->query("SELECT location_id, name FROM location ORDER BY name");
$locations = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $locations[] = [
            'id' => $row['location_id'],
            'name' => $row['name']
        ];
    }
}

$conn->close();
echo json_encode($locations);
?>


