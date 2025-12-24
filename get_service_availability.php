<?php
// API endpoint to fetch saloon availability (date-specific, per saloon via service)
// Gets saloon_id from service_id and returns saloon-level availability keyed by date
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

// Check if saloon_date_availability table exists
$table_check = $conn->query("SHOW TABLES LIKE 'saloon_date_availability'");
if ($table_check && $table_check->num_rows == 0) {
    // Table doesn't exist, return empty availability (no schedule defined)
    echo json_encode([
        'success' => true,
        'service_id' => $service_id,
        'saloon_id' => $saloon_id,
        'has_schedule' => false,
        'availability_by_date' => [],
        'message' => 'No date-specific availability defined'
    ]);
    $conn->close();
    exit();
}

// Fetch date-specific availability for this saloon
// Only include dates from today up to the next 30 days
$today = date('Y-m-d');
$endDate = date('Y-m-d', strtotime('+30 days'));

$availabilityByDate = [];
$stmt = $conn->prepare("
    SELECT date, start_time, end_time, is_available 
    FROM saloon_date_availability 
    WHERE saloon_id = ? 
      AND date >= ? 
      AND date <= ?
    ORDER BY date, start_time
");
$stmt->bind_param("iss", $saloon_id, $today, $endDate);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $dateKey = $row['date'];
    if (!isset($availabilityByDate[$dateKey])) {
        $availabilityByDate[$dateKey] = [];
    }

    $availabilityByDate[$dateKey][] = [
        'start_time' => $row['start_time'],
        'end_time' => $row['end_time'],
        'is_available' => (int)$row['is_available']
    ];
}
$stmt->close();

// Determine if there is any availability defined
$hasSchedule = !empty($availabilityByDate);

// Return JSON response
echo json_encode([
    'success' => true,
    'service_id' => $service_id,
    'saloon_id' => $saloon_id,
    'has_schedule' => $hasSchedule,
    'availability_by_date' => $availabilityByDate
]);

$conn->close();
?>
