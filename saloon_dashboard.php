<?php
session_start();

// Check if user is logged in and is a saloon
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'saloon') {
    header("Location: login.php");
    exit();
}

// Database configuration
$host = "localhost";
$username = "root";
$password = "";
$database = "goglam";

// Connect to database
$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$saloon_id = $_SESSION['user_id'];
$error_message = "";
$success_message = "";

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Profile Update
    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone_no = trim($_POST['phone_no'] ?? '');
        $location_id = isset($_POST['location_id']) && $_POST['location_id'] !== '' ? (int)$_POST['location_id'] : null;
        
        if (empty($name)) {
            $error_message = "Saloon name is required.";
        } elseif (empty($address)) {
            $error_message = "Address is required.";
        } elseif (empty($phone_no)) {
            $error_message = "Phone number is required.";
        } else {
            // Handle location_id null case properly
            if ($location_id !== null) {
                $stmt = $conn->prepare("UPDATE saloon SET name=?, address=?, phone_no=?, location_id=? WHERE saloon_id=?");
                $stmt->bind_param("sssii", $name, $address, $phone_no, $location_id, $saloon_id);
            } else {
                $stmt = $conn->prepare("UPDATE saloon SET name=?, address=?, phone_no=?, location_id=NULL WHERE saloon_id=?");
                $stmt->bind_param("sssi", $name, $address, $phone_no, $saloon_id);
            }
            
            if ($stmt->execute()) {
                $success_message = "Profile updated successfully!";
                $_SESSION['name'] = $name; // Update session name
            } else {
                $error_message = "Failed to update profile: " . $conn->error;
            }
            $stmt->close();
        }
    }
    
    // Add Service
    if (isset($_POST['action']) && $_POST['action'] === 'add_service') {
        $service_name = trim($_POST['service_name'] ?? '');
        $service_range = trim($_POST['service_range'] ?? '');
        
        if (empty($service_name)) {
            $error_message = "Service name is required.";
        } elseif (empty($service_range)) {
            $error_message = "Service price/range is required.";
        } else {
            $stmt = $conn->prepare("INSERT INTO services (name, `range`, saloon_id) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $service_name, $service_range, $saloon_id);
            
            if ($stmt->execute()) {
                $success_message = "Service added successfully!";
            } else {
                $error_message = "Failed to add service: " . $conn->error;
            }
            $stmt->close();
        }
    }
    
    // Update Service
    if (isset($_POST['action']) && $_POST['action'] === 'update_service') {
        $service_id = (int)($_POST['service_id'] ?? 0);
        $service_name = trim($_POST['service_name'] ?? '');
        $service_range = trim($_POST['service_range'] ?? '');
        
        if (empty($service_name)) {
            $error_message = "Service name is required.";
        } elseif (empty($service_range)) {
            $error_message = "Service price/range is required.";
        } elseif ($service_id <= 0) {
            $error_message = "Invalid service ID.";
        } else {
            $stmt = $conn->prepare("UPDATE services SET name=?, `range`=? WHERE service_id=? AND saloon_id=?");
            $stmt->bind_param("ssii", $service_name, $service_range, $service_id, $saloon_id);
            
            if ($stmt->execute()) {
                $success_message = "Service updated successfully!";
            } else {
                $error_message = "Failed to update service: " . $conn->error;
            }
            $stmt->close();
        }
    }
    
    // Delete Service
    if (isset($_POST['action']) && $_POST['action'] === 'delete_service') {
        $service_id = (int)($_POST['service_id'] ?? 0);
        
        if ($service_id <= 0) {
            $error_message = "Invalid service ID.";
        } else {
            $stmt = $conn->prepare("DELETE FROM services WHERE service_id=? AND saloon_id=?");
            $stmt->bind_param("ii", $service_id, $saloon_id);
            
            if ($stmt->execute()) {
                $success_message = "Service deleted successfully!";
            } else {
                $error_message = "Failed to delete service: " . $conn->error;
            }
            $stmt->close();
        }
    }

    // Add / update saloon schedule (date-specific)
    if (isset($_POST['action']) && $_POST['action'] === 'add_schedule') {
        $schedule_date = trim($_POST['schedule_date'] ?? '');
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');
        $status = $_POST['status'] ?? 'available';

        // Basic validation
        if (empty($schedule_date) || empty($start_time) || empty($end_time)) {
            $error_message = "Date, start time and end time are required for schedule.";
        } else {
            // Validate date format (YYYY-MM-DD)
            $date_obj = DateTime::createFromFormat('Y-m-d', $schedule_date);
            $date_errors = DateTime::getLastErrors();

            if (!$date_obj || !empty($date_errors['warning_count']) || !empty($date_errors['error_count'])) {
                $error_message = "Invalid date format.";
            } else {
                // Prevent past dates
                $today = new DateTime('today');
                if ($date_obj < $today) {
                    $error_message = "You cannot set a schedule for a past date.";
                } else {
                    // Ensure start_time < end_time
                    if ($start_time >= $end_time) {
                        $error_message = "Start time must be earlier than end time.";
                    } else {
                        // Check if saloon_date_availability table exists
                        $table_check = $conn->query("SHOW TABLES LIKE 'saloon_date_availability'");
                        if ($table_check && $table_check->num_rows == 0) {
                            $error_message = "Schedule table 'saloon_date_availability' does not exist. Please create it in the database.";
                        } else {
                            $is_available = ($status === 'unavailable') ? 0 : 1;

                            // Optional: prevent overlapping available ranges for the same date
                            if ($is_available === 1) {
                                $overlap_stmt = $conn->prepare("
                                    SELECT id FROM saloon_date_availability
                                    WHERE saloon_id = ?
                                      AND date = ?
                                      AND is_available = 1
                                      AND NOT (end_time <= ? OR start_time >= ?)
                                ");
                                $overlap_stmt->bind_param("isss", $saloon_id, $schedule_date, $start_time, $end_time);
                                $overlap_stmt->execute();
                                $overlap_result = $overlap_stmt->get_result();

                                if ($overlap_result && $overlap_result->num_rows > 0) {
                                    $error_message = "This time range overlaps with an existing available schedule on this date.";
                                }
                                $overlap_stmt->close();
                            }

                            // Insert if no error so far
                            if (empty($error_message)) {
                                $stmt = $conn->prepare("
                                    INSERT INTO saloon_date_availability (saloon_id, date, start_time, end_time, is_available)
                                    VALUES (?, ?, ?, ?, ?)
                                ");
                                $stmt->bind_param("isssi", $saloon_id, $schedule_date, $start_time, $end_time, $is_available);

                                if ($stmt->execute()) {
                                    $success_message = "Schedule entry added successfully.";
                                } else {
                                    $error_message = "Failed to add schedule entry: " . $conn->error;
                                }
                                $stmt->close();
                            }
                        }
                    }
                }
            }
        }
    }

    // Delete schedule entry
    if (isset($_POST['action']) && $_POST['action'] === 'delete_schedule') {
        $schedule_id = (int)($_POST['schedule_id'] ?? 0);

        if ($schedule_id <= 0) {
            $error_message = "Invalid schedule ID.";
        } else {
            // Check table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'saloon_date_availability'");
            if ($table_check && $table_check->num_rows == 0) {
                $error_message = "Schedule table 'saloon_date_availability' does not exist.";
            } else {
                $stmt = $conn->prepare("DELETE FROM saloon_date_availability WHERE id = ? AND saloon_id = ?");
                $stmt->bind_param("ii", $schedule_id, $saloon_id);

                if ($stmt->execute()) {
                    $success_message = "Schedule entry deleted successfully.";
                } else {
                    $error_message = "Failed to delete schedule entry: " . $conn->error;
                }
                $stmt->close();
            }
        }
    }

    // Update booking (time, price, status)
    if (isset($_POST['action']) && $_POST['action'] === 'update_booking') {
        $slot_id = isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : 0;
        $slot_datetime = isset($_POST['slot_datetime']) ? trim($_POST['slot_datetime']) : '';
        $total_amount = isset($_POST['total_amount']) ? (float)$_POST['total_amount'] : 0;
        $status = isset($_POST['status']) ? trim($_POST['status']) : 'confirmed';

        // Convert HTML datetime-local (YYYY-MM-DDTHH:MM) to MySQL DATETIME (YYYY-MM-DD HH:MM:SS)
        if (!empty($slot_datetime)) {
            $slot_datetime = str_replace('T', ' ', $slot_datetime) . ':00';
        }

        $allowed_statuses = ['confirmed', 'completed', 'cancelled'];
        if (!in_array($status, $allowed_statuses, true)) {
            $status = 'confirmed';
        }

        if ($slot_id <= 0) {
            $error_message = "Invalid booking ID.";
        } elseif (empty($slot_datetime)) {
            $error_message = "Booking date and time are required.";
        } elseif ($total_amount < 0) {
            $error_message = "Service price cannot be negative.";
        } else {
            // Verify that this booking belongs to the current saloon and get confirmation_id
            $verify_stmt = $conn->prepare("SELECT slot_id, saloon_id, confirmation_id FROM slots WHERE slot_id = ? AND saloon_id = ?");
            $verify_stmt->bind_param("ii", $slot_id, $saloon_id);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();

            if ($verify_result && $verify_result->num_rows === 1) {
                $row = $verify_result->fetch_assoc();
                $confirmation_id = (int)$row['confirmation_id'];
                $verify_stmt->close();

                // Update confirmation (time and price)
                $update_conf_stmt = $conn->prepare("UPDATE confirmation SET slot_time = ?, total_amount = ? WHERE confirmation_id = ?");
                $update_conf_stmt->bind_param("sdi", $slot_datetime, $total_amount, $confirmation_id);

                // Update slot status
                $update_slot_stmt = $conn->prepare("UPDATE slots SET status = ? WHERE slot_id = ? AND saloon_id = ?");
                $update_slot_stmt->bind_param("sii", $status, $slot_id, $saloon_id);

                $conn->begin_transaction();
                try {
                    if ($update_conf_stmt->execute() && $update_slot_stmt->execute()) {
                        $conn->commit();
                        $success_message = "Booking updated successfully.";
                    } else {
                        $conn->rollback();
                        $error_message = "Failed to update booking: " . $conn->error;
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = "Failed to update booking: " . $e->getMessage();
                }

                $update_conf_stmt->close();
                $update_slot_stmt->close();
            } else {
                $error_message = "Booking not found for this saloon.";
                $verify_stmt->close();
            }
        }
    }

    // Cancel booking
    if (isset($_POST['action']) && $_POST['action'] === 'cancel_booking') {
        $slot_id = isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : 0;

        if ($slot_id <= 0) {
            $error_message = "Invalid booking ID.";
        } else {
            // Ensure the slot belongs to this saloon
            $verify_stmt = $conn->prepare("SELECT slot_id FROM slots WHERE slot_id = ? AND saloon_id = ?");
            $verify_stmt->bind_param("ii", $slot_id, $saloon_id);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();

            if ($verify_result && $verify_result->num_rows === 1) {
                $verify_stmt->close();

                $cancel_stmt = $conn->prepare("UPDATE slots SET status = 'cancelled' WHERE slot_id = ? AND saloon_id = ?");
                $cancel_stmt->bind_param("ii", $slot_id, $saloon_id);

                if ($cancel_stmt->execute()) {
                    $success_message = "Booking cancelled successfully.";
                } else {
                    $error_message = "Failed to cancel booking: " . $conn->error;
                }

                $cancel_stmt->close();
            } else {
                $error_message = "Booking not found for this saloon.";
                $verify_stmt->close();
            }
        }
    }
}

// Fetch saloon profile
$saloon = null;
$stmt = $conn->prepare("SELECT saloon_id, reg_id, name, email, address, phone_no, location_id FROM saloon WHERE saloon_id=?");
$stmt->bind_param("i", $saloon_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $saloon = $result->fetch_assoc();
}
$stmt->close();

// Fetch locations for dropdown
$locations = [];
$locations_result = $conn->query("SELECT location_id, name FROM location ORDER BY name");
if ($locations_result) {
    while ($row = $locations_result->fetch_assoc()) {
        $locations[] = $row;
    }
}

// Fetch services for this saloon
$services = [];
$services_stmt = $conn->prepare("SELECT service_id, name, `range` FROM services WHERE saloon_id=? ORDER BY name");
$services_stmt->bind_param("i", $saloon_id);
$services_stmt->execute();
$services_result = $services_stmt->get_result();
while ($row = $services_result->fetch_assoc()) {
    $services[] = $row;
}
$services_stmt->close();

// Get service to edit (if editing)
$edit_service = null;
if (isset($_GET['edit_service'])) {
    $edit_service_id = (int)$_GET['edit_service'];
    foreach ($services as $service) {
        if ($service['service_id'] == $edit_service_id) {
            $edit_service = $service;
            break;
        }
    }
}

// Fetch upcoming saloon schedule entries (next 30 days) if table exists
$saloon_schedule_entries = [];
$table_check = $conn->query("SHOW TABLES LIKE 'saloon_date_availability'");
if ($table_check && $table_check->num_rows > 0) {
    $today = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime('+30 days'));

    $schedule_stmt = $conn->prepare("
        SELECT id, date, start_time, end_time, is_available
        FROM saloon_date_availability
        WHERE saloon_id = ?
          AND date >= ?
          AND date <= ?
        ORDER BY date, start_time
    ");
    $schedule_stmt->bind_param("iss", $saloon_id, $today, $endDate);
    $schedule_stmt->execute();
    $schedule_result = $schedule_stmt->get_result();

    while ($row = $schedule_result->fetch_assoc()) {
        $saloon_schedule_entries[] = $row;
    }
    $schedule_stmt->close();
}

// Fetch all bookings for this saloon
$bookings = [];
// Check if service_id column exists in slots table
$columns_result = $conn->query("SHOW COLUMNS FROM slots LIKE 'service_id'");
$has_service_id = ($columns_result && $columns_result->num_rows > 0);

if ($has_service_id) {
    $bookings_stmt = $conn->prepare("SELECT s.slot_id, s.status, c.slot_time, c.total_amount,
                                            cu.name AS customer_name,
                                            sv.name AS service_name
                                     FROM slots s
                                     INNER JOIN confirmation c ON s.confirmation_id = c.confirmation_id
                                     INNER JOIN customer cu ON s.customer_id = cu.customer_id
                                     INNER JOIN services sv ON s.service_id = sv.service_id
                                     WHERE s.saloon_id = ?
                                     ORDER BY c.slot_time DESC");
} else {
    $bookings_stmt = $conn->prepare("SELECT s.slot_id, s.status, c.slot_time, c.total_amount,
                                            cu.name AS customer_name
                                     FROM slots s
                                     INNER JOIN confirmation c ON s.confirmation_id = c.confirmation_id
                                     INNER JOIN customer cu ON s.customer_id = cu.customer_id
                                     WHERE s.saloon_id = ?
                                     ORDER BY c.slot_time DESC");
}

if ($bookings_stmt) {
    $bookings_stmt->bind_param("i", $saloon_id);
    $bookings_stmt->execute();
    $bookings_result = $bookings_stmt->get_result();

    while ($row = $bookings_result->fetch_assoc()) {
        if (!$has_service_id) {
            // Old schema without service_id - use generic service name
            $row['service_name'] = 'Service';
        }
        $bookings[] = $row;
    }

    $bookings_stmt->close();
}

// Fetch reviews for this saloon
$reviews = [];
$table_check = $conn->query("SHOW TABLES LIKE 'reviews'");
if ($table_check && $table_check->num_rows > 0) {
    // Check if service_id column exists in slots table
    $columns_result = $conn->query("SHOW COLUMNS FROM slots LIKE 'service_id'");
    $has_service_id = ($columns_result && $columns_result->num_rows > 0);
    
    if ($has_service_id) {
        $reviews_stmt = $conn->prepare("SELECT r.review_id, r.rating, r.review_text, r.created_at, 
                                       c.name as customer_name, c.customer_id,
                                       conf.slot_time, sv.name as service_name
                                       FROM reviews r
                                       INNER JOIN customer c ON r.customer_id = c.customer_id
                                       INNER JOIN slots s ON r.slot_id = s.slot_id
                                       INNER JOIN confirmation conf ON s.confirmation_id = conf.confirmation_id
                                       LEFT JOIN services sv ON s.service_id = sv.service_id
                                       WHERE r.saloon_id = ?
                                       ORDER BY r.created_at DESC");
    } else {
        $reviews_stmt = $conn->prepare("SELECT r.review_id, r.rating, r.review_text, r.created_at, 
                                       c.name as customer_name, c.customer_id,
                                       conf.slot_time, 'Service' as service_name
                                       FROM reviews r
                                       INNER JOIN customer c ON r.customer_id = c.customer_id
                                       INNER JOIN slots s ON r.slot_id = s.slot_id
                                       INNER JOIN confirmation conf ON s.confirmation_id = conf.confirmation_id
                                       WHERE r.saloon_id = ?
                                       ORDER BY r.created_at DESC");
    }
    $reviews_stmt->bind_param("i", $saloon_id);
    $reviews_stmt->execute();
    $reviews_result = $reviews_stmt->get_result();
    while ($review_row = $reviews_result->fetch_assoc()) {
        $reviews[] = $review_row;
    }
    $reviews_stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saloon Dashboard - GoGlam</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #fafafa;
            color: #1a1a1a;
            line-height: 1.6;
        }

        /* Header */
        .header {
            background: #ffffff;
            border-bottom: 1px solid #e5e5e5;
            padding: 16px 24px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-content {
            max-width: 1280px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand {
            font-size: 24px;
            font-weight: 700;
            color: #7A1C2C;
            text-decoration: none;
        }

        .header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .header-btn {
            padding: 10px 20px;
            background: transparent;
            color: #1a1a1a;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
            border: 1px solid #d0d0d0;
        }

        .header-btn:hover {
            background: #f5f5f5;
            border-color: #7A1C2C;
            color: #7A1C2C;
        }

        .header-btn.primary {
            background: #7A1C2C;
            color: white;
            border-color: #7A1C2C;
        }

        .header-btn.primary:hover {
            background: #5a141f;
        }

        .header-btn.profile {
            background: transparent;
            color: #7A1C2C;
            border-color: #7A1C2C;
        }

        .header-btn.profile:hover {
            background: #7A1C2C;
            color: white;
        }

        /* Profile Modal */
        .profile-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .profile-modal-overlay.active {
            display: flex;
        }

        .profile-modal {
            background: white;
            border-radius: 8px;
            padding: 0;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            border: 1px solid #e5e5e5;
        }

        .profile-modal-header {
            padding: 24px;
            border-bottom: 1px solid #e5e5e5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .profile-modal-title {
            font-size: 20px;
            font-weight: 600;
            color: #1a1a1a;
            margin: 0;
        }

        .profile-modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: #666;
            cursor: pointer;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .profile-modal-close:hover {
            background: #f5f5f5;
            color: #1a1a1a;
        }

        .profile-modal-body {
            padding: 24px;
        }

        .profile-info-row {
            margin-bottom: 20px;
        }

        .profile-info-label {
            font-size: 12px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .profile-info-value {
            font-size: 16px;
            color: #1a1a1a;
            word-wrap: break-word;
        }

        .profile-info-value.empty {
            color: #999;
            font-style: italic;
        }

        /* Main Container */
        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 48px 24px;
        }

        .page-title {
            font-size: 32px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 8px;
        }

        .page-subtitle {
            color: #666;
            font-size: 16px;
            margin-bottom: 32px;
        }

        /* Messages */
        .message {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
        }

        .error-message {
            background: #F9F0F5;
            color: #7A1C2C;
            border-left: 4px solid #B87A9B;
        }

        .success-message {
            background: #F0F9F5;
            color: #1a7a2c;
            border-left: 4px solid #4CAF50;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 32px;
            border-bottom: 2px solid #e5e5e5;
        }

        .tab {
            padding: 12px 24px;
            background: transparent;
            color: #666;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-weight: 500;
            font-size: 16px;
            transition: all 0.2s;
            font-family: inherit;
        }

        .tab:hover {
            color: #7A1C2C;
        }

        .tab.active {
            color: #7A1C2C;
            border-bottom-color: #7A1C2C;
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 8px;
            padding: 32px;
            border: 1px solid #e5e5e5;
            margin-bottom: 24px;
        }

        .card-title {
            font-size: 20px;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 24px;
        }

        /* Form */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: #1a1a1a;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #d0d0d0;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #7A1C2C;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }

        .btn-primary {
            background: #7A1C2C;
            color: white;
        }

        .btn-primary:hover {
            background: #5a141f;
        }

        .btn-secondary {
            background: #f5f5f5;
            color: #1a1a1a;
            border: 1px solid #d0d0d0;
        }

        .btn-secondary:hover {
            background: #e5e5e5;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-small {
            padding: 8px 16px;
            font-size: 13px;
        }

        /* Services List */
        .services-list {
            display: grid;
            gap: 16px;
        }

        .service-item {
            background: #fafafa;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .service-info {
            flex: 1;
        }

        .service-name {
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 4px;
        }

        .service-range {
            color: #666;
            font-size: 14px;
        }

        .service-actions {
            display: flex;
            gap: 8px;
        }

        .service-view-mode {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }

        .service-edit-mode {
            width: 100%;
        }

        .service-edit-mode .service-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 12px;
        }

        .service-edit-input {
            padding: 8px 12px;
            border: 1px solid #d0d0d0;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            width: 100%;
            transition: border-color 0.2s;
        }

        .service-edit-input:focus {
            outline: none;
            border-color: #7A1C2C;
        }

        .service-item.editing {
            background: #fff;
            border-color: #7A1C2C;
            box-shadow: 0 2px 8px rgba(122, 28, 44, 0.1);
        }

        .empty-state {
            text-align: center;
            padding: 48px 20px;
            color: #666;
        }

        .empty-state p {
            margin-bottom: 16px;
        }

        /* Reviews Styles */
        .reviews-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .review-item {
            background: #fafafa;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            padding: 20px;
            transition: box-shadow 0.2s;
        }

        .review-item:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .review-customer-info {
            flex: 1;
        }

        .review-customer-name {
            font-weight: 600;
            color: #1a1a1a;
            font-size: 16px;
            margin-bottom: 4px;
        }

        .review-date {
            font-size: 12px;
            color: #666;
        }

        .review-rating {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .star {
            font-size: 20px;
            color: #d0d0d0;
            line-height: 1;
        }

        .star.filled {
            color: #FFD700;
        }

        .rating-number {
            font-size: 14px;
            font-weight: 600;
            color: #666;
            margin-left: 8px;
        }

        .review-text {
            color: #1a1a1a;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 12px;
            padding: 12px;
            background: white;
            border-radius: 6px;
            border-left: 3px solid #7A1C2C;
        }

        .review-service {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #666;
        }

        .review-service-label {
            font-weight: 600;
        }

        .review-service-name {
            color: #7A1C2C;
        }

        /* Chat Styles */
        .card.chat-container {
            padding: 0;
            display: flex;
            flex-direction: row;
            height: 600px;
            gap: 0;
        }

        .chat-customers-list {
            width: 300px;
            border-right: 1px solid #e5e5e5;
            background: #fafafa;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .chat-customers-header {
            padding: 16px;
            background: white;
            border-bottom: 1px solid #e5e5e5;
            font-weight: 600;
            color: #1a1a1a;
        }

        .chat-customer-item {
            padding: 16px;
            border-bottom: 1px solid #e5e5e5;
            cursor: pointer;
            transition: background 0.2s;
            background: white;
        }

        .chat-customer-item:hover {
            background: #f5f5f5;
        }

        .chat-customer-item.active {
            background: #F9F0F5;
            border-left: 3px solid #7A1C2C;
        }

        .chat-customer-name {
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 4px;
        }

        .chat-customer-last-message {
            font-size: 12px;
            color: #666;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .chat-customer-time {
            font-size: 11px;
            color: #999;
            margin-top: 4px;
        }

        .chat-messages-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        #chatMessagesContainer {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .chat-messages-header {
            padding: 16px;
            background: white;
            border-bottom: 1px solid #e5e5e5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chat-messages-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #1a1a1a;
        }

        .chat-connection-status {
            font-size: 12px;
            color: #999;
            padding: 4px 8px;
            border-radius: 4px;
            background: #f5f5f5;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #f5f5f5;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .chat-message {
            display: flex;
            flex-direction: column;
            max-width: 70%;
            animation: fadeIn 0.3s;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .chat-message.customer {
            align-self: flex-end;
        }

        .chat-message.saloon {
            align-self: flex-start;
        }

        .chat-bubble {
            padding: 12px 16px;
            border-radius: 18px;
            word-wrap: break-word;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .chat-message.customer .chat-bubble {
            background: #7A1C2C;
            color: white;
            border-bottom-right-radius: 4px;
        }

        .chat-message.saloon .chat-bubble {
            background: white;
            color: #1a1a1a;
            border-bottom-left-radius: 4px;
        }

        .chat-timestamp {
            font-size: 11px;
            color: #999;
            margin-top: 4px;
            padding: 0 4px;
        }

        .chat-message.customer .chat-timestamp {
            text-align: right;
        }

        .chat-message.saloon .chat-timestamp {
            text-align: left;
        }

        .chat-input-container {
            display: flex;
            gap: 12px;
            padding: 16px;
            background: white;
            border-top: 1px solid #e5e5e5;
        }

        .chat-input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #e5e5e5;
            border-radius: 24px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
            font-family: inherit;
        }

        .chat-input:focus {
            border-color: #7A1C2C;
        }

        .chat-input-container .btn {
            padding: 12px 24px;
            border-radius: 24px;
        }

        .chat-empty-state {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            text-align: center;
            padding: 40px;
        }

        /* Modal Styles for Edit Booking */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1100;
        }

        .modal {
            background: #ffffff;
            border-radius: 8px;
            padding: 24px 24px 20px;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            border: 1px solid #e5e5e5;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: #1a1a1a;
        }

        .modal-close {
            background: transparent;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #666;
            padding: 4px 8px;
            line-height: 1;
        }

        .modal-close:hover {
            color: #1a1a1a;
        }

        .modal-footer {
            margin-top: 16px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 24px 16px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .header-content {
                flex-wrap: wrap;
            }

            .tabs {
                overflow-x: auto;
            }

            .service-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .service-actions {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <a href="customer_dashboard.php" class="brand">GoGlam</a>
            <div class="header-actions">
                <button class="header-btn profile" onclick="openProfileModal()"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Saloon'); ?></button>
                <a href="logout.php" class="header-btn">Logout</a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="container">
        <h1 class="page-title">Saloon Dashboard</h1>
        <p class="page-subtitle">Manage your saloon profile and services</p>

        <!-- Messages -->
        <?php if (!empty($error_message)): ?>
            <div class="message error-message">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="message success-message">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>


        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('profile')">Profile</button>
            <button class="tab" onclick="switchTab('services')">Services</button>
            <button class="tab" onclick="switchTab('schedule')">Schedule</button>
            <button class="tab" onclick="switchTab('bookings')">All Bookings</button>
            <button class="tab" onclick="switchTab('chat')">Chat</button>
            <button class="tab" onclick="switchTab('feedback')">Feedback</button>
        </div>

        <!-- Profile Tab -->
        <div id="profile-tab" class="tab-content active">
            <div class="card">
                <h2 class="card-title">Saloon Profile</h2>
                <?php if ($saloon): ?>
                    <form method="POST" action="saloon_dashboard.php">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="form-group">
                            <label for="name">Saloon Name *</label>
                            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($saloon['name']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" value="<?php echo htmlspecialchars($saloon['email']); ?>" disabled>
                            <small style="color: #666; font-size: 12px;">Email cannot be changed</small>
                        </div>

                        <div class="form-group">
                            <label for="reg_id">Registration ID</label>
                            <input type="text" id="reg_id" value="<?php echo htmlspecialchars($saloon['reg_id'] ?? ''); ?>" disabled>
                            <small style="color: #666; font-size: 12px;">Registration ID cannot be changed</small>
                        </div>

                        <div class="form-group">
                            <label for="address">Address *</label>
                            <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($saloon['address'] ?? ''); ?>" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="phone_no">Phone Number *</label>
                                <input type="tel" id="phone_no" name="phone_no" value="<?php echo htmlspecialchars($saloon['phone_no'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="location_id">Location</label>
                                <select id="location_id" name="location_id">
                                    <option value="">Select Location</option>
                                    <?php foreach ($locations as $location): ?>
                                        <option value="<?php echo $location['location_id']; ?>" 
                                                <?php echo ($saloon['location_id'] == $location['location_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($location['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">Update Profile</button>
                    </form>
                <?php else: ?>
                    <p>Error loading saloon profile.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Services Tab -->
        <div id="services-tab" class="tab-content">
            <!-- Add/Edit Service Form -->
            <div class="card">
                <h2 class="card-title"><?php echo $edit_service ? 'Edit Service' : 'Add New Service'; ?></h2>
                <form method="POST" action="saloon_dashboard.php">
                    <input type="hidden" name="action" value="<?php echo $edit_service ? 'update_service' : 'add_service'; ?>">
                    <?php if ($edit_service): ?>
                        <input type="hidden" name="service_id" value="<?php echo $edit_service['service_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="service_name">Service Name *</label>
                            <input type="text" id="service_name" name="service_name" 
                                   value="<?php echo $edit_service ? htmlspecialchars($edit_service['name']) : ''; ?>" 
                                   required placeholder="e.g., Hair Cut, Facial, Manicure">
                        </div>

                        <div class="form-group">
                            <label for="service_range">Price/Range *</label>
                            <input type="text" id="service_range" name="service_range" 
                                   value="<?php echo $edit_service ? htmlspecialchars($edit_service['range']) : ''; ?>" 
                                   required placeholder="e.g., 500-1000, ৳500, $50-100">
                        </div>
                    </div>

                    <div style="display: flex; gap: 12px;">
                        <button type="submit" class="btn btn-primary">
                            <?php echo $edit_service ? 'Update Service' : 'Add Service'; ?>
                        </button>
                        <?php if ($edit_service): ?>
                            <a href="saloon_dashboard.php" class="btn btn-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Services List -->
            <div class="card">
                <h2 class="card-title">Your Services</h2>
                <?php if (empty($services)): ?>
                    <div class="empty-state">
                        <p>No services added yet.</p>
                        <p style="font-size: 14px; color: #999;">Add your first service above to get started.</p>
                    </div>
                <?php else: ?>
                    <div class="services-list">
                        <?php foreach ($services as $service): ?>
                            <div class="service-item" id="service-item-<?php echo $service['service_id']; ?>" data-service-id="<?php echo $service['service_id']; ?>">
                                <!-- View Mode -->
                                <div class="service-view-mode">
                                    <div class="service-info">
                                        <div class="service-name"><?php echo htmlspecialchars($service['name']); ?></div>
                                        <div class="service-range"><?php echo htmlspecialchars($service['range']); ?></div>
                                    </div>
                                    <div class="service-actions">
                                        <button type="button" class="btn btn-secondary btn-small" onclick="editServiceInline(<?php echo $service['service_id']; ?>)">Edit</button>
                                        <form method="POST" action="saloon_dashboard.php" style="display: inline;" 
                                              onsubmit="return confirm('Are you sure you want to delete this service?');">
                                            <input type="hidden" name="action" value="delete_service">
                                            <input type="hidden" name="service_id" value="<?php echo $service['service_id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-small">Delete</button>
                                        </form>
                                    </div>
                                </div>
                                <!-- Edit Mode -->
                                <div class="service-edit-mode" style="display: none;">
                                    <form method="POST" action="saloon_dashboard.php" class="service-edit-form">
                                        <input type="hidden" name="action" value="update_service">
                                        <input type="hidden" name="service_id" value="<?php echo $service['service_id']; ?>">
                                        <div class="service-info">
                                            <input type="text" name="service_name" class="service-edit-input" 
                                                   value="<?php echo htmlspecialchars($service['name']); ?>" 
                                                   required placeholder="Service Name">
                                            <input type="text" name="service_range" class="service-edit-input" 
                                                   value="<?php echo htmlspecialchars($service['range']); ?>" 
                                                   required placeholder="Price/Range">
                                        </div>
                                        <div class="service-actions">
                                            <button type="submit" class="btn btn-primary btn-small">Save</button>
                                            <button type="button" class="btn btn-secondary btn-small" onclick="cancelServiceEdit(<?php echo $service['service_id']; ?>)">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Schedule Tab -->
        <div id="schedule-tab" class="tab-content">
            <div class="card">
                <h2 class="card-title">Manage Schedule</h2>
                <p style="color: #666; font-size: 14px; margin-bottom: 20px;">
                    Define specific dates and times when your saloon is available or unavailable for bookings.
                </p>

                <form method="POST" action="saloon_dashboard.php" style="margin-bottom: 24px;">
                    <input type="hidden" name="action" value="add_schedule">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="schedule_date">Date *</label>
                            <input type="date" id="schedule_date" name="schedule_date" required>
                        </div>
                        <div class="form-group">
                            <label for="start_time">Start Time *</label>
                            <input type="time" id="start_time" name="start_time" required>
                        </div>
                        <div class="form-group">
                            <label for="end_time">End Time *</label>
                            <input type="time" id="end_time" name="end_time" required>
                        </div>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="available">Available</option>
                                <option value="unavailable">Not Available</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Add Schedule Entry</button>
                </form>

                <div>
                    <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 12px;">Upcoming Schedule (next 30 days)</h3>
                    <?php if (empty($saloon_schedule_entries)): ?>
                        <div class="empty-state">
                            <p>No schedule entries defined yet.</p>
                            <p style="font-size: 14px; color: #999;">Add your first schedule entry above to control when customers can book.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                                <thead>
                                    <tr style="background: #fafafa; border-bottom: 1px solid #e5e5e5;">
                                        <th style="text-align: left; padding: 8px;">Date</th>
                                        <th style="text-align: left; padding: 8px;">Time Range</th>
                                        <th style="text-align: left; padding: 8px;">Status</th>
                                        <th style="text-align: right; padding: 8px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($saloon_schedule_entries as $entry): ?>
                                        <tr style="border-bottom: 1px solid #f0f0f0;">
                                            <td style="padding: 8px;">
                                                <?php
                                                    $d = new DateTime($entry['date']);
                                                    echo $d->format('M d, Y (D)');
                                                ?>
                                            </td>
                                            <td style="padding: 8px;">
                                                <?php echo htmlspecialchars(substr($entry['start_time'], 0, 5)); ?> - 
                                                <?php echo htmlspecialchars(substr($entry['end_time'], 0, 5)); ?>
                                            </td>
                                            <td style="padding: 8px;">
                                                <?php if ((int)$entry['is_available'] === 1): ?>
                                                    <span style="display: inline-block; padding: 4px 8px; border-radius: 4px; background: #F0F9F5; color: #1a7a2c; font-size: 12px; font-weight: 600;">
                                                        Available
                                                    </span>
                                                <?php else: ?>
                                                    <span style="display: inline-block; padding: 4px 8px; border-radius: 4px; background: #F9F0F5; color: #7A1C2C; font-size: 12px; font-weight: 600;">
                                                        Not Available
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 8px; text-align: right;">
                                                <form method="POST" action="saloon_dashboard.php" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this schedule entry?');">
                                                    <input type="hidden" name="action" value="delete_schedule">
                                                    <input type="hidden" name="schedule_id" value="<?php echo (int)$entry['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-small">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Chat Tab -->
        <div id="chat-tab" class="tab-content">
            <div class="card chat-container">
                <!-- Customers List -->
                <div class="chat-customers-list">
                    <div class="chat-customers-header">Conversations</div>
                    <div id="chatCustomersList">
                        <!-- Customer list will be loaded here -->
                    </div>
                </div>

                <!-- Chat Messages Container -->
                <div class="chat-messages-container">
                    <div id="chatMessagesContainer" style="display: none;">
                        <div class="chat-messages-header">
                            <h3 id="chatCustomerName">Customer</h3>
                            <div id="chatConnectionStatus" class="chat-connection-status">
                                Connecting...
                            </div>
                        </div>
                        <div class="chat-messages" id="chatMessages">
                            <!-- Messages will be loaded here -->
                        </div>
                        <div class="chat-input-container">
                            <input type="text" id="chatMessageInput" class="chat-input" placeholder="Type your message..." />
                            <button id="chatSendBtn" class="btn btn-primary">Send</button>
                        </div>
                    </div>
                    <div id="chatEmptyState" class="chat-empty-state">
                        <div>
                            <p style="font-size: 18px; margin-bottom: 8px;">Select a customer to start chatting</p>
                            <p style="font-size: 14px; color: #999;">Choose a conversation from the list on the left</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Feedback Tab -->
        <div id="feedback-tab" class="tab-content">
            <div class="card">
                <h2 class="card-title">Customer Feedback & Reviews</h2>
                <p style="color: #666; font-size: 14px; margin-bottom: 20px;">
                    View all ratings and reviews from your customers.
                </p>

                <?php if (empty($reviews)): ?>
                    <div class="empty-state">
                        <p>No reviews yet.</p>
                        <p style="font-size: 14px; color: #999;">Customer reviews will appear here once they rate your services.</p>
                    </div>
                <?php else: ?>
                    <div class="reviews-list">
                        <?php foreach ($reviews as $review): ?>
                            <?php
                                $reviewDate = new DateTime($review['created_at']);
                                $formattedDate = $reviewDate->format('M d, Y');
                                $formattedTime = $reviewDate->format('g:i A');
                            ?>
                            <div class="review-item">
                                <div class="review-header">
                                    <div class="review-customer-info">
                                        <div class="review-customer-name"><?php echo htmlspecialchars($review['customer_name']); ?></div>
                                        <div class="review-date"><?php echo htmlspecialchars($formattedDate . ' at ' . $formattedTime); ?></div>
                                    </div>
                                    <div class="review-rating">
                                        <?php
                                            $rating = (int)$review['rating'];
                                            for ($i = 1; $i <= 5; $i++):
                                        ?>
                                            <span class="star <?php echo $i <= $rating ? 'filled' : ''; ?>">★</span>
                                        <?php endfor; ?>
                                        <span class="rating-number"><?php echo $rating; ?>/5</span>
                                    </div>
                                </div>
                                <div class="review-text">
                                    <?php 
                                        $reviewText = isset($review['review_text']) ? trim($review['review_text']) : '';
                                        // Treat "0" as empty (corrupted data from previous bug)
                                        if ($reviewText === '0') {
                                            $reviewText = '';
                                        }
                                        if (!empty($reviewText)) {
                                            echo nl2br(htmlspecialchars($reviewText));
                                        } else {
                                            echo '<span style="color: #999; font-style: italic;">No comment provided</span>';
                                        }
                                    ?>
                                </div>
                                <div class="review-service">
                                    <span class="review-service-label">Service:</span>
                                    <span class="review-service-name"><?php echo htmlspecialchars($review['service_name']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- All Bookings Tab -->
        <div id="bookings-tab" class="tab-content">
            <div class="card">
                <h2 class="card-title">All Bookings</h2>
                <p style="color: #666; font-size: 14px; margin-bottom: 20px;">
                    View and manage all bookings for your saloon. You can edit booking time, change service price, or cancel a booking.
                </p>

                <?php if (empty($bookings)): ?>
                    <div class="empty-state">
                        <p>No bookings found yet.</p>
                        <p style="font-size: 14px; color: #999;">Bookings will appear here once customers book your services.</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                            <thead>
                                <tr style="background: #fafafa; border-bottom: 1px solid #e5e5e5;">
                                    <th style="text-align: left; padding: 8px;">Customer</th>
                                    <th style="text-align: left; padding: 8px;">Service</th>
                                    <th style="text-align: left; padding: 8px;">Date &amp; Time</th>
                                    <th style="text-align: left; padding: 8px;">Price</th>
                                    <th style="text-align: left; padding: 8px;">Status</th>
                                    <th style="text-align: right; padding: 8px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookings as $booking): ?>
                                    <?php
                                        $slotDateTime = new DateTime($booking['slot_time']);
                                        $displayDateTime = $slotDateTime->format('M d, Y g:i A');
                                        $inputDateTime = $slotDateTime->format('Y-m-d\TH:i');

                                        $statusLabel = htmlspecialchars($booking['status']);
                                        $statusBg = '#f5f5f5';
                                        $statusColor = '#666';

                                        if ($booking['status'] === 'confirmed') {
                                            $statusBg = '#F0F9F5';
                                            $statusColor = '#1a7a2c';
                                        } elseif ($booking['status'] === 'completed') {
                                            $statusBg = '#E8F0FF';
                                            $statusColor = '#1a4a7a';
                                        } elseif ($booking['status'] === 'cancelled') {
                                            $statusBg = '#F9F0F5';
                                            $statusColor = '#7A1C2C';
                                        }
                                    ?>
                                    <tr style="border-bottom: 1px solid #f0f0f0;">
                                        <td style="padding: 8px;">
                                            <?php echo htmlspecialchars($booking['customer_name']); ?>
                                        </td>
                                        <td style="padding: 8px;">
                                            <?php echo htmlspecialchars($booking['service_name'] ?? 'Service'); ?>
                                        </td>
                                        <td style="padding: 8px;">
                                            <?php echo htmlspecialchars($displayDateTime); ?>
                                        </td>
                                        <td style="padding: 8px;">
                                            ৳<?php echo number_format((float)$booking['total_amount'], 2); ?>
                                        </td>
                                        <td style="padding: 8px;">
                                            <span style="display: inline-block; padding: 4px 8px; border-radius: 4px; background: <?php echo $statusBg; ?>; color: <?php echo $statusColor; ?>; font-size: 12px; font-weight: 600;">
                                                <?php echo $statusLabel; ?>
                                            </span>
                                        </td>
                                        <td style="padding: 8px; text-align: right; white-space: nowrap;">
                                            <button
                                                type="button"
                                                class="btn btn-secondary btn-small"
                                                onclick="openEditBookingModal(this)"
                                                data-slot-id="<?php echo (int)$booking['slot_id']; ?>"
                                                data-customer-name="<?php echo htmlspecialchars($booking['customer_name'], ENT_QUOTES); ?>"
                                                data-service-name="<?php echo htmlspecialchars($booking['service_name'] ?? 'Service', ENT_QUOTES); ?>"
                                                data-datetime="<?php echo htmlspecialchars($inputDateTime); ?>"
                                                data-price="<?php echo htmlspecialchars($booking['total_amount']); ?>"
                                                data-status="<?php echo htmlspecialchars($booking['status']); ?>"
                                            >
                                                Edit
                                            </button>

                                            <form method="POST" action="saloon_dashboard.php" style="display: inline;" onsubmit="return confirm('Are you sure you want to cancel this booking?');">
                                                <input type="hidden" name="action" value="cancel_booking">
                                                <input type="hidden" name="slot_id" value="<?php echo (int)$booking['slot_id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-small">Cancel</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Edit Booking Modal -->
    <div id="editBookingModalOverlay" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title" id="editBookingTitle">Edit Booking</div>
                <button type="button" class="modal-close" onclick="closeEditBookingModal()">&times;</button>
            </div>
            <form method="POST" action="saloon_dashboard.php">
                <input type="hidden" name="action" value="update_booking">
                <input type="hidden" name="slot_id" id="editBookingSlotId">

                <div class="form-group">
                    <label for="editBookingDateTime">Booking Date &amp; Time *</label>
                    <input type="datetime-local" id="editBookingDateTime" name="slot_datetime" required>
                </div>

                <div class="form-group">
                    <label for="editBookingPrice">Service Price *</label>
                    <input type="number" id="editBookingPrice" name="total_amount" min="0" step="0.01" required>
                </div>

                <div class="form-group">
                    <label for="editBookingStatus">Status</label>
                    <select id="editBookingStatus" name="status">
                        <option value="confirmed">Confirmed</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditBookingModal()">Close</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Profile Modal -->
    <div id="profileModalOverlay" class="profile-modal-overlay" onclick="closeProfileModal(event)">
        <div class="profile-modal" onclick="event.stopPropagation()">
            <div class="profile-modal-header">
                <h2 class="profile-modal-title">Saloon Profile</h2>
                <button type="button" class="profile-modal-close" onclick="closeProfileModal()">&times;</button>
            </div>
            <div class="profile-modal-body">
                <?php if ($saloon): ?>
                    <div class="profile-info-row">
                        <div class="profile-info-label">Saloon Name</div>
                        <div class="profile-info-value"><?php echo htmlspecialchars($saloon['name']); ?></div>
                    </div>

                    <div class="profile-info-row">
                        <div class="profile-info-label">Email</div>
                        <div class="profile-info-value"><?php echo htmlspecialchars($saloon['email']); ?></div>
                    </div>

                    <div class="profile-info-row">
                        <div class="profile-info-label">Registration ID</div>
                        <div class="profile-info-value"><?php echo htmlspecialchars($saloon['reg_id'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="profile-info-row">
                        <div class="profile-info-label">Phone Number</div>
                        <div class="profile-info-value"><?php echo htmlspecialchars($saloon['phone_no'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="profile-info-row">
                        <div class="profile-info-label">Address</div>
                        <div class="profile-info-value"><?php echo htmlspecialchars($saloon['address'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="profile-info-row">
                        <div class="profile-info-label">Location</div>
                        <div class="profile-info-value">
                            <?php
                            if ($saloon['location_id']) {
                                $location_name = 'N/A';
                                foreach ($locations as $loc) {
                                    if ($loc['location_id'] == $saloon['location_id']) {
                                        $location_name = htmlspecialchars($loc['name']);
                                        break;
                                    }
                                }
                                echo $location_name;
                            } else {
                                echo 'Not set';
                            }
                            ?>
                        </div>
                    </div>

                    <div class="profile-info-row">
                        <div class="profile-info-label">Saloon ID</div>
                        <div class="profile-info-value"><?php echo htmlspecialchars($saloon['saloon_id']); ?></div>
                    </div>

                <?php else: ?>
                    <div class="profile-info-value empty">Error loading saloon profile.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        // Inline Service Editing Functions
        function editServiceInline(serviceId) {
            // Cancel any other service being edited
            document.querySelectorAll('.service-item').forEach(item => {
                const otherServiceId = item.dataset.serviceId;
                if (otherServiceId && parseInt(otherServiceId) !== serviceId) {
                    cancelServiceEdit(parseInt(otherServiceId));
                }
            });

            const serviceItem = document.getElementById('service-item-' + serviceId);
            if (!serviceItem) return;

            const viewMode = serviceItem.querySelector('.service-view-mode');
            const editMode = serviceItem.querySelector('.service-edit-mode');

            if (viewMode && editMode) {
                viewMode.style.display = 'none';
                editMode.style.display = 'block';
                serviceItem.classList.add('editing');
                
                // Focus on first input
                const firstInput = editMode.querySelector('.service-edit-input');
                if (firstInput) {
                    firstInput.focus();
                }
            }
        }

        function cancelServiceEdit(serviceId) {
            const serviceItem = document.getElementById('service-item-' + serviceId);
            if (!serviceItem) return;

            const viewMode = serviceItem.querySelector('.service-view-mode');
            const editMode = serviceItem.querySelector('.service-edit-mode');

            if (viewMode && editMode) {
                viewMode.style.display = 'flex';
                editMode.style.display = 'none';
                serviceItem.classList.remove('editing');
            }
        }

        function saveServiceEdit(serviceId) {
            const serviceItem = document.getElementById('service-item-' + serviceId);
            if (!serviceItem) {
                console.error('Service item not found for ID:', serviceId);
                return false;
            }

            const form = serviceItem.querySelector('.service-edit-form');
            if (!form) {
                console.error('Edit form not found for service ID:', serviceId);
                return false;
            }

            // Validate inputs
            const nameInput = form.querySelector('input[name="service_name"]');
            const rangeInput = form.querySelector('input[name="service_range"]');

            if (!nameInput || !nameInput.value.trim()) {
                alert('Service name is required.');
                if (nameInput) nameInput.focus();
                return false;
            }

            if (!rangeInput || !rangeInput.value.trim()) {
                alert('Service price/range is required.');
                if (rangeInput) rangeInput.focus();
                return false;
            }

            // Validation passed - form will submit normally
            return true;
        }

        // Handle form submission for inline edits
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.service-edit-form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    const serviceIdInput = this.querySelector('input[name="service_id"]');
                    if (!serviceIdInput || !serviceIdInput.value) {
                        console.error('Service ID not found in form');
                        e.preventDefault();
                        return;
                    }
                    
                    const serviceId = parseInt(serviceIdInput.value);
                    if (isNaN(serviceId)) {
                        console.error('Invalid service ID:', serviceIdInput.value);
                        e.preventDefault();
                        return;
                    }
                    
                    if (!saveServiceEdit(serviceId)) {
                        e.preventDefault();
                    }
                    // If validation passes, form will submit normally
                });
            });
        });

        // Edit Booking modal helpers
        function openEditBookingModal(button) {
            var slotId = button.getAttribute('data-slot-id');
            var customerName = button.getAttribute('data-customer-name') || 'Customer';
            var serviceName = button.getAttribute('data-service-name') || 'Service';
            var datetime = button.getAttribute('data-datetime') || '';
            var price = button.getAttribute('data-price') || '';
            var status = button.getAttribute('data-status') || 'confirmed';

            var titleEl = document.getElementById('editBookingTitle');
            if (titleEl) {
                titleEl.textContent = 'Edit Booking - ' + customerName + ' (' + serviceName + ')';
            }

            document.getElementById('editBookingSlotId').value = slotId;
            document.getElementById('editBookingDateTime').value = datetime;
            document.getElementById('editBookingPrice').value = price;

            var statusSelect = document.getElementById('editBookingStatus');
            if (statusSelect) {
                statusSelect.value = status;
            }

            var overlay = document.getElementById('editBookingModalOverlay');
            if (overlay) {
                overlay.style.display = 'flex';
            }
        }

        function closeEditBookingModal() {
            var overlay = document.getElementById('editBookingModalOverlay');
            if (overlay) {
                overlay.style.display = 'none';
            }
        }

        // Profile Modal Functions
        function openProfileModal() {
            var overlay = document.getElementById('profileModalOverlay');
            if (overlay) {
                overlay.classList.add('active');
            }
        }

        function closeProfileModal(event) {
            // If event is provided and user clicked on overlay (not modal), close it
            if (event && event.target.id === 'profileModalOverlay') {
                var overlay = document.getElementById('profileModalOverlay');
                if (overlay) {
                    overlay.classList.remove('active');
                }
            } else {
                // Close button clicked or called directly
                var overlay = document.getElementById('profileModalOverlay');
                if (overlay) {
                    overlay.classList.remove('active');
                }
            }
        }

        // Close profile modal on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeProfileModal();
            }
        });

        // Auto-hide success messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const successMessage = document.querySelector('.success-message');
            if (successMessage) {
                setTimeout(function() {
                    successMessage.style.transition = 'opacity 0.5s';
                    successMessage.style.opacity = '0';
                    setTimeout(function() {
                        successMessage.remove();
                    }, 500);
                }, 5000);
            }
        });

        // Chat functionality
        let currentChatId = null;
        let currentCustomerId = null;
        let chatWebSocket = null;
        let pollingInterval = null;
        let lastMessageId = 0;
        const saloonId = <?php echo $saloon_id; ?>;
        const userType = 'saloon';

        // Load customer list
        function loadCustomerList() {
            fetch('chat_api.php?action=get_chat')
                .then(response => response.json())
                .then(data => {
                    if (data.chats && data.chats.length > 0) {
                        displayCustomerList(data.chats);
                    } else {
                        document.getElementById('chatCustomersList').innerHTML = 
                            '<div style="padding: 20px; text-align: center; color: #666;">No conversations yet</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading customer list:', error);
                    document.getElementById('chatCustomersList').innerHTML = 
                        '<div style="padding: 20px; text-align: center; color: #dc3545;">Error loading conversations</div>';
                });
        }

        function displayCustomerList(chats) {
            const listContainer = document.getElementById('chatCustomersList');
            listContainer.innerHTML = '';
            
            chats.forEach(chat => {
                const item = document.createElement('div');
                item.className = 'chat-customer-item';
                item.onclick = () => selectCustomer(chat.customer_id, chat.customer_name, item);
                
                const lastMessage = chat.last_message || 'No messages yet';
                const lastTime = chat.last_message_time ? formatTime(chat.last_message_time) : '';
                
                item.innerHTML = `
                    <div class="chat-customer-name">${escapeHtml(chat.customer_name)}</div>
                    <div class="chat-customer-last-message">${escapeHtml(lastMessage)}</div>
                    ${lastTime ? `<div class="chat-customer-time">${lastTime}</div>` : ''}
                `;
                
                listContainer.appendChild(item);
            });
        }

        function selectCustomer(customerId, customerName, element) {
            // Update active state
            document.querySelectorAll('.chat-customer-item').forEach(item => {
                item.classList.remove('active');
            });
            if (element) {
                element.classList.add('active');
            }
            
            currentCustomerId = customerId;
            document.getElementById('chatCustomerName').textContent = customerName;
            document.getElementById('chatMessagesContainer').style.display = 'flex';
            document.getElementById('chatEmptyState').style.display = 'none';
            
            // Clear previous chat
            currentChatId = null;
            lastMessageId = 0;
            document.getElementById('chatMessages').innerHTML = '';
            
            // Stop previous polling/websocket
            stopPolling();
            if (chatWebSocket) {
                chatWebSocket.close();
                chatWebSocket = null;
            }
            
            // Load chat
            initChat();
        }

        function initChat() {
            if (!currentCustomerId) return;

            showConnectionStatus('Loading...', 'warning');

            // Load chat history
            fetch(`chat_api.php?action=get_chat&customer_id=${currentCustomerId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.chat_id) {
                        currentChatId = data.chat_id;
                        displayMessages(data.messages || []);
                        // Start polling immediately, then try WebSocket
                        startPolling();
                        // Try WebSocket (polling will stop if it connects)
                        connectWebSocket();
                    } else {
                        showConnectionStatus('Failed to load chat', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error loading chat:', error);
                    showConnectionStatus('Failed to load chat', 'error');
                });
        }

        function startPolling() {
            if (pollingInterval) return;
            
            pollingInterval = setInterval(() => {
                if (!currentChatId) return;
                
                fetch(`chat_api.php?action=get_chat&customer_id=${currentCustomerId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.messages && data.messages.length > 0) {
                            const newMessages = data.messages.filter(msg => msg.message_id > lastMessageId);
                            if (newMessages.length > 0) {
                                newMessages.forEach(msg => addMessageToUI(msg));
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Polling error:', error);
                    });
            }, 2000);
        }

        function stopPolling() {
            if (pollingInterval) {
                clearInterval(pollingInterval);
                pollingInterval = null;
            }
        }

        function connectWebSocket() {
            if (!currentChatId) {
                startPolling();
                return;
            }

            const wsProtocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
            const wsHost = window.location.hostname;
            const wsPort = '8080';
            
            // Don't create new connection if one already exists
            if (chatWebSocket && (chatWebSocket.readyState === WebSocket.CONNECTING || chatWebSocket.readyState === WebSocket.OPEN)) {
                return;
            }
            
            chatWebSocket = new WebSocket(`${wsProtocol}//${wsHost}:${wsPort}`);

            chatWebSocket.onopen = () => {
                console.log('WebSocket connected');
                showConnectionStatus('Connected', 'success');
                stopPolling(); // Stop polling when WebSocket connects
                // Join chat
                chatWebSocket.send(JSON.stringify({
                    type: 'join',
                    chat_id: currentChatId,
                    user_id: saloonId,
                    user_type: userType
                }));
            };

            chatWebSocket.onmessage = (event) => {
                const data = JSON.parse(event.data);
                
                if (data.type === 'new_message') {
                    addMessageToUI(data);
                } else if (data.type === 'joined') {
                    console.log('Joined chat:', data.chat_id);
                } else if (data.type === 'error') {
                    console.error('WebSocket error:', data.message);
                    showConnectionStatus('Connection error', 'error');
                    startPolling(); // Fallback to polling
                }
            };

            chatWebSocket.onerror = (error) => {
                console.error('WebSocket error:', error);
                showConnectionStatus('Connection error', 'error');
                startPolling(); // Fallback to polling
            };

            chatWebSocket.onclose = () => {
                console.log('WebSocket disconnected');
                showConnectionStatus('Disconnected', 'warning');
                // Try to reconnect after a delay
                setTimeout(() => {
                    if (currentChatId) {
                        startPolling();
                        connectWebSocket();
                    }
                }, 3000);
            };
        }

        function displayMessages(messages) {
            const messagesContainer = document.getElementById('chatMessages');
            messagesContainer.innerHTML = '';
            
            messages.forEach(msg => {
                addMessageToUI(msg);
            });
            
            scrollChatToBottom();
        }

        function addMessageToUI(messageData) {
            const messagesContainer = document.getElementById('chatMessages');
            const messageDiv = document.createElement('div');
            messageDiv.className = `chat-message ${messageData.sender_type}`;
            
            const timestamp = new Date(messageData.time_stamp);
            const timeStr = formatTimestamp(timestamp);
            
            messageDiv.innerHTML = `
                <div class="chat-bubble">${escapeHtml(messageData.message_text)}</div>
                <div class="chat-timestamp">${timeStr}</div>
            `;
            
            messagesContainer.appendChild(messageDiv);
            
            // Update last message ID
            if (messageData.message_id > lastMessageId) {
                lastMessageId = messageData.message_id;
            }
            
            scrollChatToBottom();
        }

        function scrollChatToBottom() {
            const messagesContainer = document.getElementById('chatMessages');
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        function showConnectionStatus(message, type) {
            const statusEl = document.getElementById('chatConnectionStatus');
            if (statusEl) {
                statusEl.textContent = message;
                statusEl.style.background = type === 'success' ? '#d4edda' : 
                                           type === 'error' ? '#f8d7da' : '#fff3cd';
                statusEl.style.color = type === 'success' ? '#155724' : 
                                       type === 'error' ? '#721c24' : '#856404';
            }
        }

        function sendMessage() {
            const input = document.getElementById('chatMessageInput');
            const messageText = input.value.trim();
            
            if (!messageText || !currentChatId) {
                alert('Please wait for chat to initialize or select a customer.');
                return;
            }

            // Try WebSocket first, fallback to API
            if (chatWebSocket && chatWebSocket.readyState === WebSocket.OPEN) {
                // Send via WebSocket
                chatWebSocket.send(JSON.stringify({
                    type: 'message',
                    chat_id: currentChatId,
                    message_text: messageText,
                    sender_type: userType,
                    user_id: saloonId
                }));
                input.value = '';
            } else {
                // Fallback: Send via PHP API
                sendMessageViaAPI(messageText, input);
            }
        }

        function sendMessageViaAPI(messageText, inputElement) {
            const formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('chat_id', currentChatId);
            formData.append('message_text', messageText);
            
            fetch('chat_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    addMessageToUI(data);
                    inputElement.value = '';
                } else {
                    alert('Failed to send message: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error sending message:', error);
                alert('Failed to send message. Please try again.');
            });
        }

        function formatTimestamp(date) {
            const now = new Date();
            const diff = now - date;
            const minutes = Math.floor(diff / 60000);
            
            if (minutes < 1) return 'Just now';
            if (minutes < 60) return `${minutes}m ago`;
            
            const hours = Math.floor(minutes / 60);
            if (hours < 24) return `${hours}h ago`;
            
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        }

        function formatTime(timeString) {
            if (!timeString) return '';
            const date = new Date(timeString);
            return formatTimestamp(date);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Initialize chat when chat tab is shown
        document.addEventListener('DOMContentLoaded', function() {
            // Load customer list on page load
            loadCustomerList();
            
            const chatTab = document.getElementById('chat-tab');
            if (chatTab) {
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.target.classList.contains('active')) {
                            // Reload customer list when tab becomes active
                            loadCustomerList();
                        } else {
                            // Stop polling when tab is hidden
                            stopPolling();
                            if (chatWebSocket) {
                                chatWebSocket.close();
                                chatWebSocket = null;
                            }
                        }
                    });
                });
                
                observer.observe(chatTab, { attributes: true, attributeFilter: ['class'] });
            }

            // Chat input event listeners
            const sendBtn = document.getElementById('chatSendBtn');
            const messageInput = document.getElementById('chatMessageInput');
            
            if (sendBtn) {
                sendBtn.addEventListener('click', sendMessage);
            }
            
            if (messageInput) {
                messageInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        sendMessage();
                    }
                });
            }
        });
    </script>
</body>
</html>

