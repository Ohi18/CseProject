<?php
// API endpoint to fetch saloon availability (Option B - per saloon, not per service)
// Gets saloon_id from service_id and returns saloon-level availability
header('Content-Type: application/json');

// Database configuration
$host = "localhost";
$username = "root";
$password = "";
$database = "goglam";

// Connect to database
try {
    $conn = new mysqli($host, $username, $password, $database);
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

// Get service_id from request
$service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;

if ($service_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid service ID']);
    $conn->close();
    exit();
}

// Get saloon_id from service_id
$saloon_id = null;
$stmt = $conn->prepare("SELECT saloon_id FROM services WHERE service_id = ?");
$stmt->bind_param("i", $service_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $saloon_id = (int)$row['saloon_id'];
}
$stmt->close();

if ($saloon_id === null) {
    echo json_encode(['success' => false, 'error' => 'Service not found']);
    $conn->close();
    exit();
}

// Check if saloon_availability table exists
$table_check = $conn->query("SHOW TABLES LIKE 'saloon_availability'");
if ($table_check && $table_check->num_rows == 0) {
    // Table doesn't exist, return empty availability (all slots available)
    echo json_encode([
        'success' => true,
        'service_id' => $service_id,
        'saloon_id' => $saloon_id,
        'availability' => [],
        'message' => 'No availability restrictions (all slots available)'
    ]);
    $conn->close();
    exit();
}

// Fetch availability for this saloon (Option B - saloon-level availability)
$availability = [];
$stmt = $conn->prepare("SELECT day_of_week, start_time, end_time, is_available FROM saloon_availability WHERE saloon_id = ? AND is_available = 1 ORDER BY day_of_week");
$stmt->bind_param("i", $saloon_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $availability[] = [
        'day_of_week' => (int)$row['day_of_week'],
        'start_time' => $row['start_time'],
        'end_time' => $row['end_time']
    ];
}
$stmt->close();

// Return JSON response
echo json_encode([
    'success' => true,
    'service_id' => $service_id,
    'saloon_id' => $saloon_id,
    'availability' => $availability
]);

$conn->close();
?>

