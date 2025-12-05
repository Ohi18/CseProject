<?php
header('Content-Type: application/json');

$conn = new mysqli('localhost', 'root', '', 'goglam');

if ($conn->connect_error) {
    echo json_encode(['error' => 'Database connection failed']);
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


