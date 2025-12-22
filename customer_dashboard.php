<?php
session_start();

// Check if user is logged in and is a customer
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'customer') {
    header("Location: login.php");
    exit();
}

// Database configuration
$host = "localhost";
$username = "root";
$password = "";
$database = "goglam";

// Connect to database with error handling
try {
    $conn = new mysqli($host, $username, $password, $database);
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
} catch (mysqli_sql_exception $e) {
    die("Database connection error: " . $e->getMessage() . ". Please make sure XAMPP MySQL is running and the database 'goglam' exists.");
} catch (Exception $e) {
    die("Database error: " . $e->getMessage() . ". Please check your database settings.");
}

$customer_id = $_SESSION['user_id'];
$error_message = "";
$success_message = "";

// Handle booking confirmation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'confirm_booking') {
    $saloon_id = isset($_POST['saloon_id']) ? (int)$_POST['saloon_id'] : 0;
    $service_id = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
    $slot_datetime = isset($_POST['slot_datetime']) ? trim($_POST['slot_datetime']) : '';
    
    // Validate inputs
    if ($saloon_id <= 0 || $service_id <= 0 || empty($slot_datetime)) {
        $error_message = "Invalid booking data. Please try again.";
    } else {
        // Get service price from range (extract numeric value)
        $service_stmt = $conn->prepare("SELECT `range` FROM services WHERE service_id = ? AND saloon_id = ?");
        $service_stmt->bind_param("ii", $service_id, $saloon_id);
        $service_stmt->execute();
        $service_result = $service_stmt->get_result();
        if ($service_result->num_rows > 0) {
            $service_row = $service_result->fetch_assoc();
            $service_range = $service_row['range'];
            // Extract price from range (simple extraction - assumes first number found)
            preg_match('/[\d.]+/', $service_range, $matches);
            $total_amount = isset($matches[0]) ? (float)$matches[0] : 0.00;
        } else {
            $total_amount = 0.00;
        }
        $service_stmt->close();
        
        // Check if service_id column exists in slots table
        $columns_result = $conn->query("SHOW COLUMNS FROM slots LIKE 'service_id'");
        $has_service_id = ($columns_result && $columns_result->num_rows > 0);
        
        // Check if slot is still available
        if ($has_service_id) {
            $check_stmt = $conn->prepare("SELECT s.slot_id FROM slots s INNER JOIN confirmation c ON s.confirmation_id = c.confirmation_id WHERE s.saloon_id = ? AND s.service_id = ? AND c.slot_time = ? AND s.customer_id IS NOT NULL");
            $check_stmt->bind_param("iis", $saloon_id, $service_id, $slot_datetime);
        } else {
            $check_stmt = $conn->prepare("SELECT s.slot_id FROM slots s INNER JOIN confirmation c ON s.confirmation_id = c.confirmation_id WHERE s.saloon_id = ? AND c.slot_time = ? AND s.customer_id IS NOT NULL");
            $check_stmt->bind_param("is", $saloon_id, $slot_datetime);
        }
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows > 0) {
            $error_message = "This time slot is no longer available. Please select another time.";
        } else {
            // Insert into confirmation table
            $confirmation_stmt = $conn->prepare("INSERT INTO confirmation (total_amount, slot_time, customer_id) VALUES (?, ?, ?)");
            $confirmation_stmt->bind_param("dsi", $total_amount, $slot_datetime, $customer_id);
            
            if ($confirmation_stmt->execute()) {
                $confirmation_id = $conn->insert_id;
                
                // Insert into slots table (check if service_id column exists)
                if ($has_service_id) {
                    $slots_stmt = $conn->prepare("INSERT INTO slots (saloon_id, customer_id, confirmation_id, service_id, status) VALUES (?, ?, ?, ?, 'confirmed')");
                    $slots_stmt->bind_param("iiii", $saloon_id, $customer_id, $confirmation_id, $service_id);
                } else {
                    $slots_stmt = $conn->prepare("INSERT INTO slots (saloon_id, customer_id, confirmation_id, status) VALUES (?, ?, ?, 'confirmed')");
                    $slots_stmt->bind_param("iii", $saloon_id, $customer_id, $confirmation_id);
                }
                
                if ($slots_stmt->execute()) {
                    $success_message = "Confirmed";
                } else {
                    $error_message = "Failed to create booking slot: " . $conn->error;
                    // Rollback confirmation
                    $conn->query("DELETE FROM confirmation WHERE confirmation_id = $confirmation_id");
                }
                $slots_stmt->close();
            } else {
                $error_message = "Failed to confirm booking: " . $conn->error;
            }
            $confirmation_stmt->close();
        }
        $check_stmt->close();
    }
    
    // Redirect to prevent form resubmission (moved outside else block to handle validation errors)
    if (!empty($success_message)) {
        header("Location: customer_dashboard.php?saloon_id=" . $saloon_id . "&booking_success=1");
        exit();
    } elseif (!empty($error_message)) {
        // Get saloon_id from POST if not already set (for validation errors)
        $redirect_saloon_id = $saloon_id > 0 ? $saloon_id : (isset($_POST['saloon_id']) ? (int)$_POST['saloon_id'] : (isset($_GET['saloon_id']) ? (int)$_GET['saloon_id'] : ''));
        header("Location: customer_dashboard.php" . ($redirect_saloon_id ? "?saloon_id=" . $redirect_saloon_id . "&" : "?") . "booking_error=" . urlencode($error_message));
        exit();
    }
}

// Handle review submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'submit_review') {
    $slot_id = isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : 0;
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $review_text = isset($_POST['review_text']) ? trim($_POST['review_text']) : '';
    
    // Debug: Log the review text to see if it's being received
    // Remove this after testing if needed
    
    // Validate inputs
    if ($slot_id <= 0) {
        $error_message = "Invalid booking reference. Please try again.";
    } elseif ($rating < 1 || $rating > 5) {
        $error_message = "Please select a rating between 1 and 5 stars.";
    } else {
        // Verify that this slot belongs to the current customer and is a past booking
        $verify_stmt = $conn->prepare("SELECT s.slot_id, s.saloon_id, s.customer_id, c.slot_time 
                                      FROM slots s 
                                      INNER JOIN confirmation c ON s.confirmation_id = c.confirmation_id 
                                      WHERE s.slot_id = ? AND s.customer_id = ? AND c.slot_time <= NOW()");
        $verify_stmt->bind_param("ii", $slot_id, $customer_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows > 0) {
            $slot_data = $verify_result->fetch_assoc();
            $saloon_id = $slot_data['saloon_id'];
            
            // Check if review already exists for this slot
            $check_review_stmt = $conn->prepare("SELECT review_id FROM reviews WHERE slot_id = ?");
            $check_review_stmt->bind_param("i", $slot_id);
            $check_review_stmt->execute();
            $check_review_result = $check_review_stmt->get_result();
            
            if ($check_review_result->num_rows > 0) {
                // Update existing review
                $review_row = $check_review_result->fetch_assoc();
                // Ensure review_text is properly handled (empty string becomes null)
                $review_text_for_update = (!empty(trim($review_text))) ? trim($review_text) : null;
                $update_stmt = $conn->prepare("UPDATE reviews SET rating = ?, review_text = ? WHERE review_id = ?");
                $update_stmt->bind_param("isi", $rating, $review_text_for_update, $review_row['review_id']);
                if ($update_stmt->execute()) {
                    $success_message = "Review updated successfully!";
                } else {
                    $error_message = "Failed to update review: " . $conn->error;
                }
                $update_stmt->close();
            } else {
                // Insert new review
                // Ensure review_text is properly handled (empty string becomes null)
                $review_text_for_insert = (!empty(trim($review_text))) ? trim($review_text) : null;
                $insert_stmt = $conn->prepare("INSERT INTO reviews (saloon_id, customer_id, slot_id, rating, review_text) VALUES (?, ?, ?, ?, ?)");
                $insert_stmt->bind_param("iiisi", $saloon_id, $customer_id, $slot_id, $rating, $review_text_for_insert);
                if ($insert_stmt->execute()) {
                    $success_message = "Thank you for your review!";
                } else {
                    $error_message = "Failed to submit review: " . $conn->error;
                }
                $insert_stmt->close();
            }
            $check_review_stmt->close();
        } else {
            $error_message = "Invalid booking or service not yet completed.";
        }
        $verify_stmt->close();
    }
    
    // Redirect to prevent form resubmission
    if (!empty($success_message)) {
        $redirect_saloon_id = isset($saloon_id) ? $saloon_id : (isset($_GET['saloon_id']) ? (int)$_GET['saloon_id'] : '');
        if (empty($redirect_saloon_id)) {
            // Try to get saloon_id from the slot
            $slot_id_for_redirect = isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : 0;
            if ($slot_id_for_redirect > 0) {
                $get_saloon_stmt = $conn->prepare("SELECT saloon_id FROM slots WHERE slot_id = ?");
                $get_saloon_stmt->bind_param("i", $slot_id_for_redirect);
                $get_saloon_stmt->execute();
                $get_saloon_result = $get_saloon_stmt->get_result();
                if ($get_saloon_result->num_rows > 0) {
                    $saloon_row = $get_saloon_result->fetch_assoc();
                    $redirect_saloon_id = $saloon_row['saloon_id'];
                }
                $get_saloon_stmt->close();
            }
        }
        header("Location: customer_dashboard.php" . ($redirect_saloon_id ? "?saloon_id=" . $redirect_saloon_id . "&" : "?") . "review_success=1");
        exit();
    } elseif (!empty($error_message)) {
        $redirect_saloon_id = isset($_GET['saloon_id']) ? (int)$_GET['saloon_id'] : '';
        if (empty($redirect_saloon_id)) {
            $slot_id_for_redirect = isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : 0;
            if ($slot_id_for_redirect > 0) {
                $get_saloon_stmt = $conn->prepare("SELECT saloon_id FROM slots WHERE slot_id = ?");
                $get_saloon_stmt->bind_param("i", $slot_id_for_redirect);
                $get_saloon_stmt->execute();
                $get_saloon_result = $get_saloon_stmt->get_result();
                if ($get_saloon_result->num_rows > 0) {
                    $saloon_row = $get_saloon_result->fetch_assoc();
                    $redirect_saloon_id = $saloon_row['saloon_id'];
                }
                $get_saloon_stmt->close();
            }
        }
        header("Location: customer_dashboard.php" . ($redirect_saloon_id ? "?saloon_id=" . $redirect_saloon_id . "&" : "?") . "review_error=" . urlencode($error_message));
        exit();
    }
}

// Get saloon_id from URL if viewing a specific saloon
$selected_saloon_id = isset($_GET['saloon_id']) ? (int)$_GET['saloon_id'] : null;
$view_mode = $selected_saloon_id ? 'detail' : 'list';

// Show success message if redirected after booking
if (isset($_GET['booking_success']) && $_GET['booking_success'] == '1') {
    $success_message = "Confirmed";
}

// Show error message if redirected after booking error
if (isset($_GET['booking_error'])) {
    $error_message = htmlspecialchars(urldecode($_GET['booking_error']));
}

// Show success message if redirected after review submission
if (isset($_GET['review_success']) && $_GET['review_success'] == '1') {
    $success_message = "Thank you for your review!";
}

// Show error message if redirected after review error
if (isset($_GET['review_error'])) {
    $error_message = htmlspecialchars(urldecode($_GET['review_error']));
}

// Fetch all saloons for listing with services and prices
$saloons = [];
$saloons_query = "SELECT saloon.saloon_id, saloon.name, saloon.address, saloon.phone_no, saloon.email, location.name as location_name 
                  FROM saloon 
                  LEFT JOIN location ON saloon.location_id = location.location_id 
                  ORDER BY saloon.name";
$saloons_result = $conn->query($saloons_query);
if ($saloons_result) {
    while ($row = $saloons_result->fetch_assoc()) {
        // Fetch services for this saloon
        $services_stmt = $conn->prepare("SELECT name, `range` FROM services WHERE saloon_id = ?");
        $services_stmt->bind_param("i", $row['saloon_id']);
        $services_stmt->execute();
        $services_result = $services_stmt->get_result();
        
        $service_names = [];
        $prices = [];
        
        while ($service_row = $services_result->fetch_assoc()) {
            $service_names[] = strtolower($service_row['name']);
            
            // Extract price from range field
            $range = $service_row['range'];
            // Remove currency symbols and extract numbers
            $range_clean = preg_replace('/[^0-9.-]/', '', $range);
            // Handle ranges like "5000-10000"
            if (strpos($range_clean, '-') !== false) {
                $price_parts = explode('-', $range_clean);
                $prices[] = (float)trim($price_parts[0]);
                if (isset($price_parts[1])) {
                    $prices[] = (float)trim($price_parts[1]);
                }
            } else {
                $price = (float)$range_clean;
                if ($price > 0) {
                    $prices[] = $price;
                }
            }
        }
        $services_stmt->close();
        
        // Calculate min and max prices
        $row['services_list'] = $service_names;
        $row['min_price'] = !empty($prices) ? min($prices) : 0;
        $row['max_price'] = !empty($prices) ? max($prices) : 0;
        
        $saloons[] = $row;
    }
}

// Fetch selected saloon details if viewing detail
$selected_saloon = null;
$selected_saloon_services = [];
$booked_slots = []; // Store booked slot times for availability checking
$saloon_reviews = [];
$average_rating = 0;
$total_reviews = 0;

if ($selected_saloon_id) {
    // Fetch saloon details including description
    $stmt = $conn->prepare("SELECT saloon.saloon_id, saloon.name, saloon.address, saloon.phone_no, saloon.email, saloon.reg_id, saloon.description, location.name as location_name 
                            FROM saloon 
                            LEFT JOIN location ON saloon.location_id = location.location_id 
                            WHERE saloon.saloon_id = ?");
    $stmt->bind_param("i", $selected_saloon_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $selected_saloon = $result->fetch_assoc();
        
        // Check if reviews table exists, if not create it
        $table_check = $conn->query("SHOW TABLES LIKE 'reviews'");
        if (!$table_check || $table_check->num_rows == 0) {
            // Create reviews table
            $create_reviews = "CREATE TABLE IF NOT EXISTS `reviews` (
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
            $conn->query($create_reviews);
            
            // Check if description column exists in saloon table
            $desc_check = $conn->query("SHOW COLUMNS FROM saloon LIKE 'description'");
            if (!$desc_check || $desc_check->num_rows == 0) {
                $conn->query("ALTER TABLE `saloon` ADD COLUMN `description` TEXT DEFAULT NULL AFTER `phone_no`");
            }
        }
        
        // Fetch reviews for this saloon
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
                                           ORDER BY r.created_at DESC
                                           LIMIT 20");
        } else {
            $reviews_stmt = $conn->prepare("SELECT r.review_id, r.rating, r.review_text, r.created_at, 
                                           c.name as customer_name, c.customer_id,
                                           conf.slot_time, 'Service' as service_name
                                           FROM reviews r
                                           INNER JOIN customer c ON r.customer_id = c.customer_id
                                           INNER JOIN slots s ON r.slot_id = s.slot_id
                                           INNER JOIN confirmation conf ON s.confirmation_id = conf.confirmation_id
                                           WHERE r.saloon_id = ?
                                           ORDER BY r.created_at DESC
                                           LIMIT 20");
        }
        $reviews_stmt->bind_param("i", $selected_saloon_id);
        $reviews_stmt->execute();
        $reviews_result = $reviews_stmt->get_result();
        while ($review_row = $reviews_result->fetch_assoc()) {
            $saloon_reviews[] = $review_row;
        }
        $reviews_stmt->close();
        
        // Calculate average rating
        if (!empty($saloon_reviews)) {
            $total_reviews = count($saloon_reviews);
            $sum_ratings = 0;
            foreach ($saloon_reviews as $review) {
                $sum_ratings += (int)$review['rating'];
            }
            $average_rating = $total_reviews > 0 ? round($sum_ratings / $total_reviews, 1) : 0;
        }
        
        // Fetch services for this saloon
        $services_stmt = $conn->prepare("SELECT service_id, name, `range` FROM services WHERE saloon_id = ? ORDER BY name");
        $services_stmt->bind_param("i", $selected_saloon_id);
        $services_stmt->execute();
        $services_result = $services_stmt->get_result();
        while ($row = $services_result->fetch_assoc()) {
            $selected_saloon_services[] = $row;
        }
        $services_stmt->close();
        
        // Fetch booked slots for this saloon (to exclude from available slots)
        // Check if service_id column exists in slots table
        $columns_result = $conn->query("SHOW COLUMNS FROM slots LIKE 'service_id'");
        if ($columns_result && $columns_result->num_rows > 0) {
            // New schema with service_id - get all booked slots
            $booked_stmt = $conn->prepare("SELECT c.slot_time, s.service_id FROM slots s INNER JOIN confirmation c ON s.confirmation_id = c.confirmation_id WHERE s.saloon_id = ? AND s.customer_id IS NOT NULL AND c.slot_time > NOW()");
            $booked_stmt->bind_param("i", $selected_saloon_id);
            $booked_stmt->execute();
            $booked_result = $booked_stmt->get_result();
            while ($row = $booked_result->fetch_assoc()) {
                $booked_slots[] = $row['slot_time'];
            }
            $booked_stmt->close();
        } else {
            // Old schema without service_id - still get booked slots
            $booked_stmt = $conn->prepare("SELECT c.slot_time FROM slots s INNER JOIN confirmation c ON s.confirmation_id = c.confirmation_id WHERE s.saloon_id = ? AND s.customer_id IS NOT NULL AND c.slot_time > NOW()");
            $booked_stmt->bind_param("i", $selected_saloon_id);
            $booked_stmt->execute();
            $booked_result = $booked_stmt->get_result();
            while ($row = $booked_result->fetch_assoc()) {
                $booked_slots[] = $row['slot_time'];
            }
            $booked_stmt->close();
        }
    }
    $stmt->close();
}

// Fetch locations for filtering (if needed)
$locations = [];
$locations_result = $conn->query("SELECT location_id, name FROM location ORDER BY name");
if ($locations_result) {
    while ($row = $locations_result->fetch_assoc()) {
        $locations[] = $row;
    }
}

// Fetch customer information
$customer_info = null;
$customer_stmt = $conn->prepare("SELECT name, email, phone_no, gender, created_at FROM customer WHERE customer_id = ?");
$customer_stmt->bind_param("i", $customer_id);
$customer_stmt->execute();
$customer_result = $customer_stmt->get_result();
if ($customer_result->num_rows > 0) {
    $customer_info = $customer_result->fetch_assoc();
}
$customer_stmt->close();

// Check if service_id column exists in slots table
$columns_result = $conn->query("SHOW COLUMNS FROM slots LIKE 'service_id'");
$has_service_id = ($columns_result && $columns_result->num_rows > 0);

// Fetch upcoming bookings
$upcoming_bookings = [];
$total_upcoming_price = 0.00;
if ($has_service_id) {
    $upcoming_stmt = $conn->prepare("SELECT s.slot_id, s.status, c.slot_time, c.total_amount, 
                                     sv.name as service_name, sal.name as saloon_name, sal.address
                                     FROM slots s
                                     INNER JOIN confirmation c ON s.confirmation_id = c.confirmation_id
                                     INNER JOIN services sv ON s.service_id = sv.service_id
                                     INNER JOIN saloon sal ON s.saloon_id = sal.saloon_id
                                     WHERE s.customer_id = ? AND c.slot_time > NOW()
                                     ORDER BY c.slot_time ASC");
} else {
    // Fallback if service_id doesn't exist - join through confirmation only
    $upcoming_stmt = $conn->prepare("SELECT s.slot_id, s.status, c.slot_time, c.total_amount,
                                     s.saloon_id, sal.name as saloon_name, sal.address
                                     FROM slots s
                                     INNER JOIN confirmation c ON s.confirmation_id = c.confirmation_id
                                     INNER JOIN saloon sal ON s.saloon_id = sal.saloon_id
                                     WHERE s.customer_id = ? AND c.slot_time > NOW()
                                     ORDER BY c.slot_time ASC");
}
$upcoming_stmt->bind_param("i", $customer_id);
$upcoming_stmt->execute();
$upcoming_result = $upcoming_stmt->get_result();
while ($row = $upcoming_result->fetch_assoc()) {
    // Try to get service name for this booking
    if (!isset($row['service_name'])) {
        // Try to get first service name from this saloon as fallback
        $service_fallback_stmt = $conn->prepare("SELECT name FROM services WHERE saloon_id = ? ORDER BY name LIMIT 1");
        $service_fallback_stmt->bind_param("i", $row['saloon_id']);
        $service_fallback_stmt->execute();
        $service_fallback_result = $service_fallback_stmt->get_result();
        if ($service_fallback_result->num_rows > 0) {
            $service_row = $service_fallback_result->fetch_assoc();
            $row['service_name'] = $service_row['name'];
        } else {
            $row['service_name'] = 'Service';
        }
        $service_fallback_stmt->close();
    }
    $upcoming_bookings[] = $row;
    $total_upcoming_price += (float)$row['total_amount'];
}
$upcoming_stmt->close();

// Fetch booking history (past bookings) with review status
$booking_history = [];
if ($has_service_id) {
    $history_stmt = $conn->prepare("SELECT s.slot_id, s.status, c.slot_time, c.total_amount,
                                    sv.name as service_name, sal.name as saloon_name, sal.saloon_id
                                    FROM slots s
                                    INNER JOIN confirmation c ON s.confirmation_id = c.confirmation_id
                                    INNER JOIN services sv ON s.service_id = sv.service_id
                                    INNER JOIN saloon sal ON s.saloon_id = sal.saloon_id
                                    WHERE s.customer_id = ? AND c.slot_time <= NOW()
                                    ORDER BY c.slot_time DESC");
} else {
    // Fallback if service_id doesn't exist
    $history_stmt = $conn->prepare("SELECT s.slot_id, s.status, c.slot_time, c.total_amount,
                                    s.saloon_id, sal.name as saloon_name
                                    FROM slots s
                                    INNER JOIN confirmation c ON s.confirmation_id = c.confirmation_id
                                    INNER JOIN saloon sal ON s.saloon_id = sal.saloon_id
                                    WHERE s.customer_id = ? AND c.slot_time <= NOW()
                                    ORDER BY c.slot_time DESC");
}
$history_stmt->bind_param("i", $customer_id);
$history_stmt->execute();
$history_result = $history_stmt->get_result();
while ($row = $history_result->fetch_assoc()) {
    // Try to get service name for this booking
    if (!isset($row['service_name'])) {
        // Try to get first service name from this saloon as fallback
        $service_fallback_stmt = $conn->prepare("SELECT name FROM services WHERE saloon_id = ? ORDER BY name LIMIT 1");
        $service_fallback_stmt->bind_param("i", $row['saloon_id']);
        $service_fallback_stmt->execute();
        $service_fallback_result = $service_fallback_stmt->get_result();
        if ($service_fallback_result->num_rows > 0) {
            $service_row = $service_fallback_result->fetch_assoc();
            $row['service_name'] = $service_row['name'];
        } else {
            $row['service_name'] = 'Service';
        }
        $service_fallback_stmt->close();
    }
    
    // Check if review exists for this booking (only if reviews table exists)
    $table_check = $conn->query("SHOW TABLES LIKE 'reviews'");
    if ($table_check && $table_check->num_rows > 0) {
        $review_check_stmt = $conn->prepare("SELECT review_id, rating, review_text FROM reviews WHERE slot_id = ?");
        $review_check_stmt->bind_param("i", $row['slot_id']);
        $review_check_stmt->execute();
        $review_check_result = $review_check_stmt->get_result();
        if ($review_check_result->num_rows > 0) {
            $review_data = $review_check_result->fetch_assoc();
            $row['has_review'] = true;
            $row['review_id'] = $review_data['review_id'];
            $row['review_rating'] = $review_data['rating'];
            $row['review_text'] = $review_data['review_text'];
        } else {
            $row['has_review'] = false;
        }
        $review_check_stmt->close();
    } else {
        $row['has_review'] = false;
    }
    
    $booking_history[] = $row;
}
$history_stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard - GoGlam</title>
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
            position: relative;
        }

        /* Profile Dropdown */
        .profile-container {
            position: relative;
        }

        .profile-trigger {
            padding: 10px 20px;
            background: transparent;
            color: #1a1a1a;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
            border: 1px solid #d0d0d0;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: inherit;
        }

        .profile-trigger:hover {
            background: #f5f5f5;
            border-color: #7A1C2C;
            color: #7A1C2C;
        }

        .profile-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            background: white;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            min-width: 400px;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
            z-index: 2000;
        }

        .profile-dropdown.active {
            display: block;
        }

        .profile-section {
            padding: 20px;
            border-bottom: 1px solid #e5e5e5;
        }

        .profile-section:last-child {
            border-bottom: none;
        }

        .profile-section-title {
            font-size: 16px;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #7A1C2C;
        }

        .profile-info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .profile-info-item:last-child {
            margin-bottom: 0;
        }

        .profile-info-label {
            color: #666;
            font-weight: 500;
        }

        .profile-info-value {
            color: #1a1a1a;
            text-align: right;
        }

        .booking-item {
            padding: 12px;
            background: #fafafa;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            margin-bottom: 12px;
        }

        .booking-item:last-child {
            margin-bottom: 0;
        }

        .booking-item-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 8px;
        }

        .booking-service-name {
            font-weight: 600;
            color: #1a1a1a;
            font-size: 14px;
        }

        .booking-price {
            color: #7A1C2C;
            font-weight: 600;
            font-size: 14px;
        }

        .booking-saloon-name {
            color: #666;
            font-size: 13px;
            margin-bottom: 4px;
        }

        .booking-datetime {
            color: #666;
            font-size: 12px;
        }

        .booking-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 8px;
        }

        .booking-status.confirmed {
            background: #F0F9F5;
            color: #1a7a2c;
        }

        .total-price-section {
            background: #fafafa;
            padding: 16px;
            border-radius: 6px;
            margin-top: 12px;
        }

        .total-price-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 4px;
        }

        .total-price-value {
            font-size: 20px;
            font-weight: 700;
            color: #7A1C2C;
        }

        .empty-booking-message {
            text-align: center;
            color: #666;
            font-size: 13px;
            padding: 20px;
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

        /* Search and Filter */
        .search-filter-container {
            background: white;
            border-radius: 8px;
            padding: 24px;
            border: 1px solid #e5e5e5;
            margin-bottom: 24px;
        }

        .search-filter-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
            gap: 16px;
            align-items: end;
        }

        @media (max-width: 1200px) {
            .search-filter-row {
                grid-template-columns: 1fr 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            .search-filter-row {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            margin-bottom: 0;
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

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
            text-decoration: none;
            display: inline-block;
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

        /* Saloon Cards Grid */
        .saloons-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }

        .saloon-card {
            background: white;
            border-radius: 8px;
            padding: 24px;
            border: 1px solid #e5e5e5;
            transition: all 0.2s;
            cursor: pointer;
        }

        .saloon-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-color: #7A1C2C;
        }

        .saloon-card-name {
            font-size: 20px;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 12px;
        }

        .saloon-card-info {
            color: #666;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .saloon-card-info strong {
            color: #1a1a1a;
            font-weight: 600;
        }

        .saloon-card-actions {
            margin-top: 16px;
            display: flex;
            gap: 8px;
        }

        .btn-small {
            padding: 8px 16px;
            font-size: 13px;
        }

        /* Detail View */
        .back-button {
            margin-bottom: 24px;
        }

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

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

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

        .detail-field {
            margin-bottom: 20px;
        }

        .detail-field label {
            display: block;
            color: #666;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .detail-field-value {
            color: #1a1a1a;
            font-size: 16px;
            padding: 12px;
            background: #fafafa;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
        }

        /* Saloon Description */
        .saloon-description {
            margin-bottom: 24px;
            padding: 20px;
            background: #f9f9f9;
            border-left: 4px solid #7A1C2C;
            border-radius: 6px;
        }

        .saloon-description p {
            color: #1a1a1a;
            font-size: 16px;
            line-height: 1.6;
            margin: 0;
        }

        /* Contact Info */
        .saloon-contact-info {
            margin-top: 24px;
        }

        .contact-info-row {
            display: flex;
            gap: 24px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .contact-info-item {
            flex: 1;
            min-width: 200px;
            color: #666;
            font-size: 14px;
        }

        .contact-info-item strong {
            color: #1a1a1a;
            font-weight: 600;
        }

        /* Reviews Section */
        .reviews-summary {
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e5e5e5;
        }

        .average-rating {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .rating-value {
            font-size: 32px;
            font-weight: 700;
            color: #7A1C2C;
        }

        .rating-stars {
            font-size: 24px;
            color: #FFD700;
        }

        .rating-count {
            color: #666;
            font-size: 14px;
        }

        .no-reviews-message {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 14px;
        }

        .reviews-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .review-item {
            padding: 20px;
            background: #fafafa;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .review-item:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .review-customer {
            flex: 1;
        }

        .review-customer strong {
            color: #1a1a1a;
            font-size: 16px;
        }

        .review-service {
            color: #666;
            font-size: 14px;
        }

        .review-rating {
            font-size: 18px;
            color: #FFD700;
        }

        .review-text {
            color: #1a1a1a;
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 12px;
        }

        .review-date {
            color: #999;
            font-size: 12px;
        }

        .empty-reviews {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        /* Review Modal */
        .review-modal {
            display: none;
            position: fixed;
            z-index: 3000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .review-modal-content {
            background-color: #ffffff;
            margin: 5% auto;
            padding: 32px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
        }

        .star-rating {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            justify-content: center;
        }

        .star {
            font-size: 32px;
            color: #ddd;
            cursor: pointer;
            transition: all 0.2s;
        }

        .star:hover,
        .star.active {
            color: #FFD700;
        }

        .star.half {
            background: linear-gradient(90deg, #FFD700 50%, #ddd 50%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .review-form textarea {
            width: 100%;
            min-height: 120px;
            padding: 12px;
            border: 1px solid #d0d0d0;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
        }

        .review-form textarea:focus {
            outline: none;
            border-color: #7A1C2C;
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
            gap: 16px;
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

        .empty-state {
            text-align: center;
            padding: 48px 20px;
            color: #666;
        }

        .empty-state p {
            margin-bottom: 16px;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: #ffffff;
            margin: 5% auto;
            padding: 32px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .modal-title {
            font-size: 24px;
            font-weight: 600;
            color: #1a1a1a;
        }

        .close {
            color: #666;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }

        .close:hover {
            color: #1a1a1a;
        }

        /* Date Selection */
        .date-selector {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
            margin-bottom: 24px;
        }

        .date-btn {
            padding: 12px 8px;
            border: 1px solid #d0d0d0;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            text-align: center;
            font-size: 13px;
            transition: all 0.2s;
        }

        .date-btn:hover {
            border-color: #7A1C2C;
            background: #f9f9f9;
        }

        .date-btn.selected {
            background: #7A1C2C;
            color: white;
            border-color: #7A1C2C;
        }

        .date-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Time Slots Grid */
        .time-slots-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }

        .time-slot-btn {
            padding: 12px;
            border: 1px solid #d0d0d0;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            text-align: center;
            font-size: 14px;
            transition: all 0.2s;
        }

        .time-slot-btn:hover:not(.disabled) {
            border-color: #7A1C2C;
            background: #f9f9f9;
        }

        .time-slot-btn.selected {
            background: #7A1C2C;
            color: white;
            border-color: #7A1C2C;
        }

        .time-slot-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: #f5f5f5;
        }

        /* Booking Confirmation */
        .booking-summary {
            background: #fafafa;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .booking-summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .booking-summary-item:last-child {
            margin-bottom: 0;
            padding-top: 12px;
            border-top: 1px solid #e5e5e5;
            font-weight: 600;
        }

        .booking-summary-label {
            color: #666;
        }

        .booking-summary-value {
            color: #1a1a1a;
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

        /* Chat Styles */
        .chat-container {
            display: flex;
            flex-direction: column;
            height: 600px;
            padding: 0;
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
        }

        .chat-input:focus {
            border-color: #7A1C2C;
        }

        .chat-input-container .btn {
            padding: 12px 24px;
            border-radius: 24px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 24px 16px;
            }

            .search-filter-row {
                grid-template-columns: 1fr;
            }

            .saloons-grid {
                grid-template-columns: 1fr;
            }

            .header-content {
                flex-wrap: wrap;
            }

            .tabs {
                overflow-x: auto;
            }

            .profile-dropdown {
                min-width: 320px;
                max-width: calc(100vw - 48px);
                right: -12px;
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
                <div class="profile-container">
                    <div class="profile-trigger" onclick="toggleProfileDropdown()">
                        <span><?php echo htmlspecialchars($_SESSION['name'] ?? 'Customer'); ?></span>
                        <span style="font-size: 10px;">▼</span>
                    </div>
                    <div class="profile-dropdown" id="profileDropdown">
                        <!-- Customer Information Section -->
                        <?php if ($customer_info): ?>
                        <div class="profile-section">
                            <h3 class="profile-section-title">Customer Information</h3>
                            <div class="profile-info-item">
                                <span class="profile-info-label">Name:</span>
                                <span class="profile-info-value"><?php echo htmlspecialchars($customer_info['name']); ?></span>
                            </div>
                            <div class="profile-info-item">
                                <span class="profile-info-label">Email:</span>
                                <span class="profile-info-value"><?php echo htmlspecialchars($customer_info['email']); ?></span>
                            </div>
                            <?php if (!empty($customer_info['phone_no'])): ?>
                            <div class="profile-info-item">
                                <span class="profile-info-label">Phone:</span>
                                <span class="profile-info-value"><?php echo htmlspecialchars($customer_info['phone_no']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($customer_info['gender'])): ?>
                            <div class="profile-info-item">
                                <span class="profile-info-label">Gender:</span>
                                <span class="profile-info-value"><?php echo htmlspecialchars($customer_info['gender']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Upcoming Bookings Section -->
                        <div class="profile-section">
                            <h3 class="profile-section-title">Upcoming Bookings</h3>
                            <?php if (empty($upcoming_bookings)): ?>
                                <div class="empty-booking-message">No upcoming bookings</div>
                            <?php else: ?>
                                <?php foreach ($upcoming_bookings as $booking): ?>
                                    <div class="booking-item">
                                        <div class="booking-item-header">
                                            <div>
                                                <div class="booking-service-name"><?php echo htmlspecialchars($booking['service_name']); ?></div>
                                                <div class="booking-saloon-name"><?php echo htmlspecialchars($booking['saloon_name']); ?></div>
                                            </div>
                                            <div class="booking-price"><?php echo number_format((float)$booking['total_amount'], 2); ?></div>
                                        </div>
                                        <div class="booking-datetime">
                                            <?php 
                                            $slot_time = new DateTime($booking['slot_time']);
                                            echo $slot_time->format('M d, Y') . ' at ' . $slot_time->format('g:i A');
                                            ?>
                                        </div>
                                        <?php if (!empty($booking['status'])): ?>
                                            <span class="booking-status <?php echo strtolower($booking['status']); ?>"><?php echo htmlspecialchars($booking['status']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <div class="total-price-section">
                                    <div class="total-price-label">Total Upcoming Services Price</div>
                                    <div class="total-price-value"><?php echo number_format($total_upcoming_price, 2); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Booking History Section -->
                        <div class="profile-section">
                            <h3 class="profile-section-title">Booking History</h3>
                            <?php if (empty($booking_history)): ?>
                                <div class="empty-booking-message">No booking history</div>
                            <?php else: ?>
                                <?php foreach ($booking_history as $booking): ?>
                                    <div class="booking-item">
                                        <div class="booking-item-header">
                                            <div>
                                                <div class="booking-service-name"><?php echo htmlspecialchars($booking['service_name']); ?></div>
                                                <div class="booking-saloon-name"><?php echo htmlspecialchars($booking['saloon_name']); ?></div>
                                            </div>
                                            <div class="booking-price"><?php echo number_format((float)$booking['total_amount'], 2); ?></div>
                                        </div>
                                        <div class="booking-datetime">
                                            <?php 
                                            $slot_time = new DateTime($booking['slot_time']);
                                            echo $slot_time->format('M d, Y') . ' at ' . $slot_time->format('g:i A');
                                            ?>
                                        </div>
                                        <?php if (!empty($booking['status'])): ?>
                                            <span class="booking-status <?php echo strtolower($booking['status']); ?>"><?php echo htmlspecialchars($booking['status']); ?></span>
                                        <?php endif; ?>
                                        <div style="margin-top: 8px;">
                                            <?php if (isset($booking['has_review']) && $booking['has_review']): ?>
                                                <button class="btn btn-secondary btn-small" onclick="openReviewModal(<?php echo $booking['slot_id']; ?>, <?php echo $booking['review_rating']; ?>, '<?php echo htmlspecialchars(addslashes($booking['review_text'])); ?>')" style="font-size: 12px; padding: 6px 12px;">
                                                    Edit Review
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-primary btn-small" onclick="openReviewModal(<?php echo $booking['slot_id']; ?>, 0, '')" style="font-size: 12px; padding: 6px 12px;">
                                                    Rate & Review
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <a href="logout.php" class="header-btn">Logout</a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="container">
        <?php if ($view_mode === 'detail' && $selected_saloon): ?>
            <!-- Detail View -->
            <div class="back-button">
                <a href="customer_dashboard.php" class="btn btn-secondary">← Back to Saloon List</a>
            </div>
            <h1 class="page-title"><?php echo htmlspecialchars($selected_saloon['name']); ?></h1>
            <p class="page-subtitle">View saloon profile and services</p>

            <!-- Messages (shown before tabs) -->
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
                <button class="tab <?php echo empty($success_message) ? 'active' : ''; ?>" onclick="switchTab('profile')">Profile</button>
                <button class="tab <?php echo !empty($success_message) ? 'active' : ''; ?>" onclick="switchTab('services')">Services</button>
                <button class="tab" onclick="switchTab('chat')">Chat</button>
            </div>

            <!-- Profile Tab -->
            <div id="profile-tab" class="tab-content <?php echo empty($success_message) ? 'active' : ''; ?>">
                <!-- Saloon Information Box -->
                <div class="card">
                    <h2 class="card-title"><?php echo htmlspecialchars($selected_saloon['name']); ?></h2>
                    
                    <?php if (!empty($selected_saloon['description'])): ?>
                    <div class="saloon-description">
                        <p><?php echo nl2br(htmlspecialchars($selected_saloon['description'])); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <div class="saloon-contact-info">
                        <div class="contact-info-row">
                            <div class="contact-info-item">
                                <strong>Email:</strong> <?php echo htmlspecialchars($selected_saloon['email']); ?>
                            </div>
                            <?php if (!empty($selected_saloon['phone_no'])): ?>
                            <div class="contact-info-item">
                                <strong>Phone:</strong> <?php echo htmlspecialchars($selected_saloon['phone_no']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="contact-info-row">
                            <?php if (!empty($selected_saloon['address'])): ?>
                            <div class="contact-info-item">
                                <strong>Address:</strong> <?php echo htmlspecialchars($selected_saloon['address']); ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($selected_saloon['location_name'])): ?>
                            <div class="contact-info-item">
                                <strong>Location:</strong> <?php echo htmlspecialchars($selected_saloon['location_name']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($selected_saloon['reg_id'])): ?>
                        <div class="contact-info-row">
                            <div class="contact-info-item">
                                <strong>Registration ID:</strong> <?php echo htmlspecialchars($selected_saloon['reg_id']); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Reviews Section -->
                <div class="card">
                    <h2 class="card-title">Reviews & Ratings</h2>
                    
                    <?php if ($total_reviews > 0): ?>
                        <div class="reviews-summary">
                            <div class="average-rating">
                                <span class="rating-value"><?php echo $average_rating; ?></span>
                                <span class="rating-stars"><?php 
                                    $full_stars = floor($average_rating);
                                    $half_star = ($average_rating - $full_stars) >= 0.5;
                                    for ($i = 0; $i < $full_stars; $i++) {
                                        echo '⭐';
                                    }
                                    if ($half_star) {
                                        echo '½';
                                    }
                                ?></span>
                                <span class="rating-count">based on <?php echo $total_reviews; ?> review<?php echo $total_reviews != 1 ? 's' : ''; ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="no-reviews-message">
                            <p>No reviews yet. Be the first to review this saloon!</p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="reviews-list">
                        <?php if (empty($saloon_reviews)): ?>
                            <div class="empty-reviews">
                                <p>No reviews available.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($saloon_reviews as $review): ?>
                                <div class="review-item">
                                    <div class="review-header">
                                        <div class="review-customer">
                                            <strong><?php echo htmlspecialchars($review['customer_name']); ?></strong>
                                            <?php if (!empty($review['service_name'])): ?>
                                                <span class="review-service"> - <?php echo htmlspecialchars($review['service_name']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="review-rating">
                                            <?php 
                                            for ($i = 1; $i <= 5; $i++) {
                                                if ($i <= $review['rating']) {
                                                    echo '⭐';
                                                } else {
                                                    echo '☆';
                                                }
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    <div class="review-text">
                                        <?php 
                                        $review_text_display = isset($review['review_text']) ? trim($review['review_text']) : '';
                                        if (!empty($review_text_display)): 
                                        ?>
                                            <?php echo nl2br(htmlspecialchars($review_text_display)); ?>
                                        <?php else: ?>
                                            <em style="color: #999;">No review text provided.</em>
                                        <?php endif; ?>
                                    </div>
                                    <div class="review-date">
                                        <?php 
                                        $review_date = new DateTime($review['created_at']);
                                        echo $review_date->format('M d, Y');
                                        ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Services Tab -->
            <div id="services-tab" class="tab-content <?php echo !empty($success_message) ? 'active' : ''; ?>">
                <div class="card">
                    <h2 class="card-title">Services Offered</h2>
                    <?php if (empty($selected_saloon_services)): ?>
                        <div class="empty-state">
                            <p>No services available at this saloon.</p>
                        </div>
                    <?php else: ?>
                        <div class="services-list">
                            <?php foreach ($selected_saloon_services as $service): ?>
                                <div class="service-item">
                                    <div class="service-info">
                                        <div class="service-name"><?php echo htmlspecialchars($service['name']); ?></div>
                                        <div class="service-range"><?php echo htmlspecialchars($service['range']); ?></div>
                                    </div>
                                    <div class="service-actions">
                                        <button class="btn btn-primary btn-small" onclick="openSlotModal(<?php echo $selected_saloon_id; ?>, <?php echo $service['service_id']; ?>, '<?php echo htmlspecialchars($service['name']); ?>', '<?php echo htmlspecialchars($service['range']); ?>')">
                                            Select
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Chat Tab -->
            <div id="chat-tab" class="tab-content">
                <div class="card chat-container">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px; border-bottom: 1px solid #e5e5e5;">
                        <h2 class="card-title" style="margin: 0;">Chat with <?php echo htmlspecialchars($selected_saloon['name']); ?></h2>
                        <div id="chatConnectionStatus" style="font-size: 12px; color: #999; padding: 4px 8px; border-radius: 4px; background: #f5f5f5;">
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
            </div>

        <?php else: ?>
            <!-- List View -->
            <h1 class="page-title">Browse Saloons</h1>
            <p class="page-subtitle">Find and explore saloons near you</p>

            <!-- Search and Filter -->
            <div class="search-filter-container">
                <div class="search-filter-row">
                    <div class="form-group">
                        <label for="search">Search Saloons</label>
                        <input type="text" id="search" placeholder="Search by name, address, or location..." onkeyup="filterSaloons()">
                    </div>
                    <div class="form-group">
                        <label for="location-filter">Filter by Location</label>
                        <select id="location-filter" onchange="filterSaloons()">
                            <option value="">All Locations</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo htmlspecialchars($location['name']); ?>"><?php echo htmlspecialchars($location['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="service-category-filter">Service Category</label>
                        <select id="service-category-filter" onchange="handleServiceCategoryChange()">
                            <option value="">All Categories</option>
                            <option value="hair">Hair</option>
                            <option value="face">Face</option>
                            <option value="nail">Nail</option>
                            <option value="body">Body</option>
                            <option value="consultation">Consultation</option>
                            <option value="makeup">Makeup</option>
                        </select>
                    </div>
                    <div class="form-group" id="body-subcategory-group" style="display: none;">
                        <label for="body-subcategory-filter">Body Type</label>
                        <select id="body-subcategory-filter" onchange="filterSaloons()">
                            <option value="">All Body Types</option>
                            <option value="full body">Full Body</option>
                            <option value="leg">Leg</option>
                            <option value="hand">Hand</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="price-sort">Sort by Price</label>
                        <select id="price-sort" onchange="filterSaloons()">
                            <option value="">None</option>
                            <option value="high-to-low">High to Low</option>
                            <option value="low-to-high">Low to High</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 16px;">
                    <button type="button" class="btn btn-secondary" onclick="clearFilters()">Clear Filters</button>
                </div>
            </div>

            <!-- Saloons Grid -->
            <?php if (empty($saloons)): ?>
                <div class="card">
                    <div class="empty-state">
                        <p>No saloons available at the moment.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="saloons-grid" id="saloons-grid">
                    <?php foreach ($saloons as $saloon): ?>
                        <div class="saloon-card" data-name="<?php echo htmlspecialchars(strtolower($saloon['name'])); ?>" 
                             data-address="<?php echo htmlspecialchars(strtolower($saloon['address'] ?? '')); ?>"
                             data-location="<?php echo htmlspecialchars(strtolower($saloon['location_name'] ?? '')); ?>"
                             data-services="<?php echo htmlspecialchars(implode(',', $saloon['services_list'] ?? [])); ?>"
                             data-min-price="<?php echo $saloon['min_price'] ?? 0; ?>"
                             data-max-price="<?php echo $saloon['max_price'] ?? 0; ?>">
                            <div class="saloon-card-name"><?php echo htmlspecialchars($saloon['name']); ?></div>
                            <?php if (!empty($saloon['location_name'])): ?>
                                <div class="saloon-card-info"><strong>Location:</strong> <?php echo htmlspecialchars($saloon['location_name']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($saloon['address'])): ?>
                                <div class="saloon-card-info"><strong>Address:</strong> <?php echo htmlspecialchars($saloon['address']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($saloon['phone_no'])): ?>
                                <div class="saloon-card-info"><strong>Phone:</strong> <?php echo htmlspecialchars($saloon['phone_no']); ?></div>
                            <?php endif; ?>
                            <div class="saloon-card-actions">
                                <a href="customer_dashboard.php?saloon_id=<?php echo $saloon['saloon_id']; ?>" class="btn btn-primary btn-small">View Details</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Slot Selection Modal -->
    <div id="slotModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalServiceName">Select Time Slot</h2>
                <span class="close" onclick="closeSlotModal()">&times;</span>
            </div>
            <div id="slotModalBody">
                <!-- Content will be populated by JavaScript -->
            </div>
        </div>
    </div>

    <!-- Review Submission Modal -->
    <div id="reviewModal" class="review-modal">
        <div class="review-modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Rate & Review</h2>
                <span class="close" onclick="closeReviewModal()">&times;</span>
            </div>
            <form method="POST" action="customer_dashboard.php" class="review-form" id="reviewForm" onsubmit="return validateReviewForm()">
                <input type="hidden" name="action" value="submit_review">
                <input type="hidden" name="slot_id" id="reviewSlotId">
                <input type="hidden" name="rating" id="reviewRating" value="0">
                <?php if (isset($_GET['saloon_id'])): ?>
                    <input type="hidden" name="saloon_id" value="<?php echo (int)$_GET['saloon_id']; ?>">
                <?php endif; ?>
                
                <div class="star-rating" id="starRating">
                    <span class="star" data-rating="1">☆</span>
                    <span class="star" data-rating="2">☆</span>
                    <span class="star" data-rating="3">☆</span>
                    <span class="star" data-rating="4">☆</span>
                    <span class="star" data-rating="5">☆</span>
                </div>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="reviewText">Your Review (optional)</label>
                    <textarea id="reviewText" name="review_text" placeholder="Share your experience..."></textarea>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeReviewModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitReviewBtn" disabled>Submit Review</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Global variables for booking
        let selectedSaloonId = null;
        let selectedServiceId = null;
        let serviceAvailability = null; // Store availability data for current service

        // Helper function to check if a time slot is available based on service availability
        function isSlotAvailableForService(date, hour) {
            // If no availability restrictions, all slots are available
            if (!serviceAvailability || serviceAvailability.length === 0) {
                return true;
            }

            const dayOfWeek = date.getDay(); // 0=Sunday, 1=Monday, etc.
            const timeStr = String(hour).padStart(2, '0') + ':00:00';
            
            // Check if there's availability for this day
            const dayAvailability = serviceAvailability.filter(avail => avail.day_of_week === dayOfWeek);
            
            if (dayAvailability.length === 0) {
                return false; // No availability for this day
            }

            // Check if the time slot falls within any available time range
            for (let avail of dayAvailability) {
                const startTime = avail.start_time;
                const endTime = avail.end_time;
                
                // Compare times (HH:MM:SS format)
                if (timeStr >= startTime && timeStr < endTime) {
                    return true;
                }
            }

            return false; // Time slot not in any available range
        }
        let selectedServiceName = '';
        let selectedServicePrice = '';
        let selectedDate = null;
        let selectedTime = null;
        let bookedSlots = <?php echo json_encode($booked_slots); ?>;

        // Profile dropdown functionality
        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('active');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const profileContainer = document.querySelector('.profile-container');
            const dropdown = document.getElementById('profileDropdown');
            
            if (profileContainer && !profileContainer.contains(event.target)) {
                dropdown.classList.remove('active');
            }
        });

        // Close dropdown when clicking logout
        document.addEventListener('DOMContentLoaded', function() {
            const logoutBtn = document.querySelector('.header-btn[href="logout.php"]');
            if (logoutBtn) {
                logoutBtn.addEventListener('click', function() {
                    document.getElementById('profileDropdown').classList.remove('active');
                });
            }
        });

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

        function handleServiceCategoryChange() {
            const serviceCategory = document.getElementById('service-category-filter').value;
            const bodySubcategoryGroup = document.getElementById('body-subcategory-group');
            
            if (serviceCategory === 'body') {
                bodySubcategoryGroup.style.display = 'block';
            } else {
                bodySubcategoryGroup.style.display = 'none';
                document.getElementById('body-subcategory-filter').value = '';
            }
            filterSaloons();
        }

        function filterSaloons() {
            const searchTerm = document.getElementById('search').value.toLowerCase();
            const locationFilter = document.getElementById('location-filter').value.toLowerCase();
            const serviceCategory = document.getElementById('service-category-filter').value;
            const bodySubcategory = document.getElementById('body-subcategory-filter').value;
            const priceSort = document.getElementById('price-sort').value;
            const cards = Array.from(document.querySelectorAll('.saloon-card'));

            // Service category names for partial matching
            const categoryNames = {
                'hair': 'hair',
                'face': 'face',
                'nail': 'nail',
                'body': 'body',
                'consultation': 'consultation',
                'makeup': 'makeup'
            };

            // Body subcategory names for partial matching
            const bodySubcategoryNames = {
                'full body': 'full body',
                'leg': 'leg',
                'hand': 'hand'
            };

            const visibleCards = cards.filter(card => {
                const name = card.getAttribute('data-name');
                const address = card.getAttribute('data-address');
                const location = card.getAttribute('data-location');
                const services = card.getAttribute('data-services') || '';
                
                // Search filter
                const matchesSearch = searchTerm === '' || 
                    name.includes(searchTerm) || 
                    address.includes(searchTerm) || 
                    location.includes(searchTerm);
                
                // Location filter
                const matchesLocation = locationFilter === '' || location === locationFilter;
                
                // Service category filter - check if any service name contains the category term
                let matchesServiceCategory = true;
                if (serviceCategory !== '') {
                    const categoryTerm = categoryNames[serviceCategory] || '';
                    if (categoryTerm !== '') {
                        // Split services by comma and check if any service contains the category term
                        const serviceList = services.split(',').map(s => s.trim().toLowerCase());
                        matchesServiceCategory = serviceList.some(service => 
                            service.includes(categoryTerm.toLowerCase())
                        );
                    }
                }
                
                // Body subcategory filter - check if any service name contains the subcategory term
                let matchesBodySubcategory = true;
                if (serviceCategory === 'body' && bodySubcategory !== '') {
                    const subcategoryTerm = bodySubcategoryNames[bodySubcategory] || '';
                    if (subcategoryTerm !== '') {
                        // Split services by comma and check if any service contains the subcategory term
                        const serviceList = services.split(',').map(s => s.trim().toLowerCase());
                        matchesBodySubcategory = serviceList.some(service => 
                            service.includes(subcategoryTerm.toLowerCase())
                        );
                    }
                }
                
                const isVisible = matchesSearch && matchesLocation && matchesServiceCategory && matchesBodySubcategory;
                card.style.display = isVisible ? 'block' : 'none';
                
                return isVisible;
            });

            // Price sorting
            if (priceSort !== '' && visibleCards.length > 0) {
                const grid = document.getElementById('saloons-grid');
                const sortedCards = visibleCards.sort((a, b) => {
                    const priceA = parseFloat(a.getAttribute('data-max-price')) || 0;
                    const priceB = parseFloat(b.getAttribute('data-max-price')) || 0;
                    
                    if (priceSort === 'high-to-low') {
                        return priceB - priceA;
                    } else if (priceSort === 'low-to-high') {
                        return priceA - priceB;
                    }
                    return 0;
                });
                
                // Reorder cards in DOM
                sortedCards.forEach(card => grid.appendChild(card));
            }
        }

        function clearFilters() {
            document.getElementById('search').value = '';
            document.getElementById('location-filter').value = '';
            document.getElementById('service-category-filter').value = '';
            document.getElementById('body-subcategory-filter').value = '';
            document.getElementById('price-sort').value = '';
            document.getElementById('body-subcategory-group').style.display = 'none';
            filterSaloons();
        }

        function openSlotModal(saloonId, serviceId, serviceName, servicePrice) {
            selectedSaloonId = saloonId;
            selectedServiceId = serviceId;
            selectedServiceName = serviceName;
            selectedServicePrice = servicePrice;
            selectedDate = null;
            selectedTime = null;
            serviceAvailability = null; // Reset availability

            document.getElementById('modalServiceName').textContent = serviceName;
            
            // Fetch service availability first, then generate UI
            fetchServiceAvailability(serviceId).then(() => {
                generateSlotSelectionUI();
            });
            
            document.getElementById('slotModal').style.display = 'block';
        }

        // Fetch service availability from API
        function fetchServiceAvailability(serviceId) {
            return fetch(`get_service_availability.php?service_id=${serviceId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        serviceAvailability = data.availability || [];
                    } else {
                        serviceAvailability = []; // No restrictions if fetch fails
                    }
                })
                .catch(error => {
                    console.error('Error fetching availability:', error);
                    serviceAvailability = []; // Default to all available
                });
        }

        function closeSlotModal() {
            document.getElementById('slotModal').style.display = 'none';
            selectedDate = null;
            selectedTime = null;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('slotModal');
            if (event.target == modal) {
                closeSlotModal();
            }
        }

        function generateSlotSelectionUI() {
            const modalBody = document.getElementById('slotModalBody');
            
            // Generate dates for next 7 days
            const dates = [];
            const today = new Date();
            for (let i = 0; i < 7; i++) {
                const date = new Date(today);
                date.setDate(today.getDate() + i);
                dates.push(date);
            }

            // Generate time slots (9 AM to 6 PM, hourly)
            const timeSlots = [];
            for (let hour = 9; hour < 18; hour++) {
                const timeStr = hour < 12 ? `${hour}:00 AM` : `${hour === 12 ? 12 : hour - 12}:00 PM`;
                timeSlots.push({
                    hour: hour,
                    display: timeStr,
                    value: String(hour).padStart(2, '0') + ':00:00'
                });
            }

            let html = `
                <div style="margin-bottom: 24px;">
                    <p style="color: #666; margin-bottom: 16px;"><strong>Service:</strong> ${selectedServiceName}</p>
                    <p style="color: #666; margin-bottom: 24px;"><strong>Price:</strong> ${selectedServicePrice}</p>
                </div>

                <div style="margin-bottom: 24px;">
                    <label style="display: block; color: #1a1a1a; font-size: 14px; font-weight: 600; margin-bottom: 12px;">Select Date</label>
                    <div class="date-selector">
            `;

            dates.forEach((date, index) => {
                const dateStr = date.toISOString().split('T')[0];
                const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
                const dayNum = date.getDate();
                const monthName = date.toLocaleDateString('en-US', { month: 'short' });
                const isPast = index === 0 && new Date().getHours() >= 18;
                
                // Check if this day has any available slots
                const dayOfWeek = date.getDay();
                const hasAvailability = !serviceAvailability || serviceAvailability.length === 0 || 
                    serviceAvailability.some(avail => avail.day_of_week === dayOfWeek);
                
                const isDisabled = isPast || !hasAvailability;
                
                html += `
                    <button class="date-btn ${isDisabled ? 'disabled' : ''}" 
                            onclick="selectDate('${dateStr}')" 
                            ${isDisabled ? 'disabled' : ''}
                            id="date-${dateStr}"
                            title="${!hasAvailability ? 'No availability for this day' : ''}">
                        <div style="font-size: 11px; color: #666;">${dayName}</div>
                        <div style="font-weight: 600;">${dayNum}</div>
                        <div style="font-size: 11px; color: #666;">${monthName}</div>
                    </button>
                `;
            });

            html += `
                    </div>
                </div>

                <div id="timeSlotsContainer" style="display: none;">
                    <label style="display: block; color: #1a1a1a; font-size: 14px; font-weight: 600; margin-bottom: 12px;">Select Time</label>
                    <div class="time-slots-grid">
            `;

            timeSlots.forEach(slot => {
                html += `
                    <button class="time-slot-btn" 
                            onclick="selectTime('${slot.value}', ${slot.hour})" 
                            id="time-${slot.value}">
                        ${slot.display}
                    </button>
                `;
            });

            html += `
                    </div>
                </div>

                <div id="bookingConfirmation" style="display: none;">
                    <div class="booking-summary">
                        <div class="booking-summary-item">
                            <span class="booking-summary-label">Service:</span>
                            <span class="booking-summary-value" id="confirmServiceName">${selectedServiceName}</span>
                        </div>
                        <div class="booking-summary-item">
                            <span class="booking-summary-label">Date:</span>
                            <span class="booking-summary-value" id="confirmDate"></span>
                        </div>
                        <div class="booking-summary-item">
                            <span class="booking-summary-label">Time:</span>
                            <span class="booking-summary-value" id="confirmTime"></span>
                        </div>
                        <div class="booking-summary-item">
                            <span class="booking-summary-label">Price:</span>
                            <span class="booking-summary-value">${selectedServicePrice}</span>
                        </div>
                    </div>
                    <form method="POST" action="customer_dashboard.php?saloon_id=${selectedSaloonId}">
                        <input type="hidden" name="action" value="confirm_booking">
                        <input type="hidden" name="saloon_id" value="${selectedSaloonId}">
                        <input type="hidden" name="service_id" value="${selectedServiceId}">
                        <input type="hidden" name="slot_datetime" id="slotDateTime">
                        <div style="display: flex; gap: 12px;">
                            <button type="submit" class="btn btn-primary">Confirm Booking</button>
                            <button type="button" class="btn btn-secondary" onclick="resetBookingSelection()">Change Selection</button>
                        </div>
                    </form>
                </div>
            `;

            modalBody.innerHTML = html;
        }

        function selectDate(dateStr) {
            selectedDate = dateStr;
            selectedTime = null;

            // Update UI
            document.querySelectorAll('.date-btn').forEach(btn => {
                btn.classList.remove('selected');
            });
            document.getElementById('date-' + dateStr).classList.add('selected');

            // Show time slots
            document.getElementById('timeSlotsContainer').style.display = 'block';
            document.getElementById('bookingConfirmation').style.display = 'none';

            // Reset time selections
            document.querySelectorAll('.time-slot-btn').forEach(btn => {
                btn.classList.remove('selected', 'disabled');
                btn.disabled = false;
            });

            const selectedDateObj = new Date(dateStr);

            // Disable time slots based on service availability
            document.querySelectorAll('.time-slot-btn').forEach(btn => {
                const hour = parseInt(btn.textContent.match(/\d+/)[0]);
                let actualHour = hour;
                
                // Convert to 24-hour format
                if (btn.textContent.includes('PM') && hour !== 12) {
                    actualHour = hour + 12;
                } else if (btn.textContent.includes('AM') && hour === 12) {
                    actualHour = 0;
                }

                // Check availability
                if (!isSlotAvailableForService(selectedDateObj, actualHour)) {
                    btn.classList.add('disabled');
                    btn.disabled = true;
                    btn.title = 'Not available for this service';
                }
            });

            // Disable booked slots for this date
            bookedSlots.forEach(bookedSlot => {
                const bookedDate = new Date(bookedSlot);
                if (bookedDate.toDateString() === selectedDateObj.toDateString()) {
                    const bookedHour = bookedDate.getHours();
                    const timeBtn = document.getElementById(`time-${String(bookedHour).padStart(2, '0')}:00:00`);
                    if (timeBtn) {
                        timeBtn.classList.add('disabled');
                        timeBtn.disabled = true;
                        timeBtn.title = 'Already booked';
                    }
                }
            });

            // Disable past times if today
            if (dateStr === new Date().toISOString().split('T')[0]) {
                const now = new Date();
                const currentHour = now.getHours();
                document.querySelectorAll('.time-slot-btn').forEach(btn => {
                    const hour = parseInt(btn.textContent.match(/\d+/)[0]);
                    if (btn.textContent.includes('PM') && hour !== 12) {
                        const actualHour = hour + 12;
                        if (actualHour <= currentHour) {
                            btn.classList.add('disabled');
                            btn.disabled = true;
                        }
                    } else if (btn.textContent.includes('AM') && hour < 12 && hour <= currentHour) {
                        btn.classList.add('disabled');
                        btn.disabled = true;
                    }
                });
            }
        }

        function selectTime(timeValue, hour) {
            const timeBtn = document.getElementById('time-' + timeValue);
            if (timeBtn.classList.contains('disabled')) return;

            selectedTime = timeValue;

            // Update UI
            document.querySelectorAll('.time-slot-btn').forEach(btn => {
                btn.classList.remove('selected');
            });
            timeBtn.classList.add('selected');

            // Show confirmation
            showBookingConfirmation(hour);
        }

        function showBookingConfirmation(hour) {
            if (!selectedDate || !selectedTime) return;

            const dateObj = new Date(selectedDate);
            const dateStr = dateObj.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            const timeDisplay = hour < 12 ? `${hour}:00 AM` : `${hour === 12 ? 12 : hour - 12}:00 PM`;

            document.getElementById('confirmDate').textContent = dateStr;
            document.getElementById('confirmTime').textContent = timeDisplay;

            // Create datetime string for form submission
            const datetimeStr = selectedDate + ' ' + String(hour).padStart(2, '0') + ':00:00';
            document.getElementById('slotDateTime').value = datetimeStr;

            document.getElementById('bookingConfirmation').style.display = 'block';
        }

        function resetBookingSelection() {
            selectedTime = null;
            document.getElementById('bookingConfirmation').style.display = 'none';
            document.querySelectorAll('.time-slot-btn').forEach(btn => {
                btn.classList.remove('selected');
            });
        }

        // Chat functionality
        let chatWebSocket = null;
        let currentChatId = null;
        let pollingInterval = null;
        let lastMessageId = 0;
        const saloonId = <?php echo $selected_saloon_id ? $selected_saloon_id : 'null'; ?>;
        const customerId = <?php echo $customer_id; ?>;
        const userType = 'customer';

        function initChat() {
            if (!saloonId) return;

            showConnectionStatus('Loading...', 'warning');

            // Load chat history
            fetch(`chat_api.php?action=get_chat&saloon_id=${saloonId}`)
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
            // Stop existing polling if any
            stopPolling();
            
            if (!currentChatId || !saloonId) {
                return;
            }
            
            // Only poll if WebSocket is not connected
            if (chatWebSocket && chatWebSocket.readyState === WebSocket.OPEN) {
                return;
            }
            
            showConnectionStatus('Polling for messages...', 'warning');
            
            pollingInterval = setInterval(() => {
                if (!currentChatId || !saloonId) {
                    stopPolling();
                    return;
                }
                
                // Fetch new messages
                fetch(`chat_api.php?action=get_chat&saloon_id=${saloonId}`)
                    .then(response => response.json())
                    .then(data => {
                        console.log('Polling response:', data);
                        if (data.messages && data.messages.length > 0) {
                            console.log('Total messages:', data.messages.length, 'Last message ID:', lastMessageId);
                            let hasNewMessages = false;
                            // Check all messages and add any that are new
                            data.messages.forEach(msg => {
                                // Check if message already exists in UI
                                const existingMsg = document.querySelector(`[data-message-id="${msg.message_id}"]`);
                                if (!existingMsg) {
                                    console.log('Adding new message:', msg.message_id, 'sender:', msg.sender_type);
                                    addMessageToUI(msg);
                                    hasNewMessages = true;
                                    // Update lastMessageId
                                    if (msg.message_id > lastMessageId) {
                                        lastMessageId = msg.message_id;
                                    }
                                }
                            });
                            if (hasNewMessages) {
                                console.log('New messages added via polling');
                            } else {
                                console.log('No new messages to add');
                            }
                        } else {
                            console.log('No messages in response');
                        }
                    })
                    .catch(error => {
                        console.error('Error polling messages:', error);
                    });
            }, 1500); // Poll every 1.5 seconds for faster updates
            
            console.log('Polling started');
        }

        function stopPolling() {
            if (pollingInterval) {
                clearInterval(pollingInterval);
                pollingInterval = null;
            }
        }

        function connectWebSocket() {
            if (!currentChatId) {
                // If no chat ID, ensure polling is running
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
                    user_id: customerId,
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
                }
            };

            chatWebSocket.onerror = (error) => {
                console.error('WebSocket error:', error);
                showConnectionStatus('Disconnected - using polling', 'error');
                // Start polling immediately on error
                setTimeout(() => {
                    if (!chatWebSocket || chatWebSocket.readyState !== WebSocket.OPEN) {
                        startPolling();
                    }
                }, 500);
            };

            chatWebSocket.onclose = () => {
                console.log('WebSocket disconnected, reconnecting...');
                showConnectionStatus('Reconnecting...', 'warning');
                // Start polling immediately
                startPolling();
                // Try to reconnect after delay
                setTimeout(() => {
                    if (!chatWebSocket || chatWebSocket.readyState !== WebSocket.OPEN) {
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
                // Track last message ID for polling
                if (msg.message_id > lastMessageId) {
                    lastMessageId = msg.message_id;
                }
            });
            
            scrollChatToBottom();
        }

        function addMessageToUI(messageData) {
            // Check if message already exists to avoid duplicates
            const existingMessage = document.querySelector(`[data-message-id="${messageData.message_id}"]`);
            if (existingMessage) {
                return;
            }
            
            // Debug: Log sender type
            console.log('Adding message:', messageData.message_id, 'sender_type:', messageData.sender_type);
            
            const messagesContainer = document.getElementById('chatMessages');
            const messageDiv = document.createElement('div');
            messageDiv.className = `chat-message ${messageData.sender_type}`;
            messageDiv.setAttribute('data-message-id', messageData.message_id);
            
            // Fix timestamp parsing - handle both datetime and timestamp formats
            let timestamp;
            if (messageData.time_stamp) {
                // Parse the timestamp string properly
                timestamp = new Date(messageData.time_stamp.replace(' ', 'T'));
                // If still invalid, try alternative parsing
                if (isNaN(timestamp.getTime())) {
                    timestamp = new Date(messageData.time_stamp);
                }
            } else {
                timestamp = new Date();
            }
            
            // Format time correctly
            const timeStr = timestamp.toLocaleTimeString('en-US', { 
                hour: 'numeric', 
                minute: '2-digit',
                hour12: true
            });
            
            messageDiv.innerHTML = `
                <div class="chat-bubble">${messageData.message_text}</div>
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
                alert('Please wait for chat to initialize or refresh the page.');
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
                    user_id: customerId
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
                    // Add message to UI immediately
                    addMessageToUI(data);
                    inputElement.value = '';
                    
                    // Try to reconnect WebSocket
                    if (!chatWebSocket || chatWebSocket.readyState !== WebSocket.OPEN) {
                        connectWebSocket();
                    }
                } else {
                    alert('Failed to send message: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error sending message:', error);
                alert('Failed to send message. Please check your connection and try again.');
            });
        }

        // Event listeners
        document.addEventListener('DOMContentLoaded', function() {
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

            // Initialize chat when chat tab is shown
            const chatTab = document.getElementById('chat-tab');
            if (chatTab) {
                // Check if tab is already active on page load
                if (chatTab.classList.contains('active')) {
                    setTimeout(() => {
                        if (!currentChatId) {
                            initChat();
                        }
                    }, 100);
                }
                
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.target.classList.contains('active')) {
                            if (!currentChatId) {
                                initChat();
                            } else {
                                // Resume polling if chat is already initialized
                                if (!chatWebSocket || chatWebSocket.readyState !== WebSocket.OPEN) {
                                    startPolling();
                                }
                            }
                        } else {
                            // Stop polling when tab is hidden
                            stopPolling();
                        }
                    });
                });
                
                observer.observe(chatTab, { attributes: true, attributeFilter: ['class'] });
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

            // Initialize star rating interaction
            const stars = document.querySelectorAll('.star');
            stars.forEach(star => {
                star.addEventListener('click', function() {
                    const rating = parseInt(this.getAttribute('data-rating'));
                    setStarRating(rating);
                });
                star.addEventListener('mouseenter', function() {
                    const rating = parseInt(this.getAttribute('data-rating'));
                    highlightStars(rating);
                });
            });

            const starContainer = document.getElementById('starRating');
            if (starContainer) {
                starContainer.addEventListener('mouseleave', function() {
                    const currentRating = parseInt(document.getElementById('reviewRating').value) || 0;
                    highlightStars(currentRating);
                });
            }
        });

        // Review Modal Functions
        function openReviewModal(slotId, existingRating, existingReview) {
            document.getElementById('reviewSlotId').value = slotId;
            document.getElementById('reviewText').value = existingReview || '';
            
            if (existingRating > 0) {
                setStarRating(existingRating);
            } else {
                setStarRating(0);
            }
            
            document.getElementById('reviewModal').style.display = 'block';
        }

        function closeReviewModal() {
            document.getElementById('reviewModal').style.display = 'none';
            // Don't reset the form immediately - let the redirect handle it
            setTimeout(function() {
                document.getElementById('reviewForm').reset();
                document.getElementById('reviewRating').value = '0';
                setStarRating(0);
            }, 100);
        }

        function setStarRating(rating) {
            document.getElementById('reviewRating').value = rating;
            highlightStars(rating);
            
            const submitBtn = document.getElementById('submitReviewBtn');
            if (rating > 0) {
                submitBtn.disabled = false;
            } else {
                submitBtn.disabled = true;
            }
        }

        function highlightStars(rating) {
            const stars = document.querySelectorAll('#starRating .star');
            stars.forEach((star, index) => {
                const starRating = index + 1;
                if (starRating <= rating) {
                    star.textContent = '⭐';
                    star.classList.add('active');
                } else {
                    star.textContent = '☆';
                    star.classList.remove('active');
                }
            });
        }

        // Validate review form before submission
        function validateReviewForm() {
            const rating = parseInt(document.getElementById('reviewRating').value) || 0;
            if (rating < 1 || rating > 5) {
                alert('Please select a rating before submitting.');
                return false;
            }
            // Ensure textarea value is properly captured
            const reviewText = document.getElementById('reviewText').value;
            const form = document.getElementById('reviewForm');
            // Make sure the textarea value is included in form submission
            if (reviewText.trim() !== '') {
                // Force update the textarea value to ensure it's submitted
                document.getElementById('reviewText').value = reviewText.trim();
            }
            return true;
        }

        // Close review modal when clicking outside
        window.onclick = function(event) {
            const reviewModal = document.getElementById('reviewModal');
            const slotModal = document.getElementById('slotModal');
            if (event.target == reviewModal) {
                closeReviewModal();
            }
            if (event.target == slotModal) {
                closeSlotModal();
            }
        }
    </script>
</body>
</html>

