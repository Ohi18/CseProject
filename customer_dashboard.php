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
        // Validate that slot time is not in the past
        try {
            $slot_datetime_obj = new DateTime($slot_datetime);
            $now = new DateTime();
            if ($slot_datetime_obj < $now) {
                $error_message = "Cannot book appointments in the past. Please select a future time slot.";
            }
        } catch (Exception $e) {
            $error_message = "Invalid date/time format. Please try again.";
        }
        
        // Continue with booking logic if no validation errors
        if (empty($error_message)) {
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
                // #region agent log
                file_put_contents('c:\\xampp\\htdocs\\goglam\\.cursor\\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'save-review','hypothesisId'=>'L','location'=>'customer_dashboard.php:171','message'=>'Preparing to update review','data'=>['review_id'=>$review_row['review_id'],'rating'=>$rating,'review_text_original'=>$review_text,'review_text_for_update'=>$review_text_for_update],'timestamp'=>time()*1000])."\n", FILE_APPEND);
                // #endregion
                $update_stmt = $conn->prepare("UPDATE reviews SET rating = ?, review_text = ? WHERE review_id = ?");
                $update_stmt->bind_param("isi", $rating, $review_text_for_update, $review_row['review_id']);
                // #region agent log
                file_put_contents('c:\\xampp\\htdocs\\goglam\\.cursor\\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'save-review','hypothesisId'=>'M','location'=>'customer_dashboard.php:174','message'=>'Executing update with bind_param','data'=>['bind_types'=>'iss','review_text_value'=>$review_text_for_update],'timestamp'=>time()*1000])."\n", FILE_APPEND);
                // #endregion
                if ($update_stmt->execute()) {
                    // #region agent log
                    file_put_contents('c:\\xampp\\htdocs\\goglam\\.cursor\\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'save-review','hypothesisId'=>'N','location'=>'customer_dashboard.php:177','message'=>'Review updated successfully','data'=>['review_id'=>$review_row['review_id']],'timestamp'=>time()*1000])."\n", FILE_APPEND);
                    // #endregion
                    $success_message = "Review updated successfully!";
                } else {
                    // #region agent log
                    file_put_contents('c:\\xampp\\htdocs\\goglam\\.cursor\\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'save-review','hypothesisId'=>'O','location'=>'customer_dashboard.php:180','message'=>'Review update failed','data'=>['error'=>$conn->error],'timestamp'=>time()*1000])."\n", FILE_APPEND);
                    // #endregion
                    $error_message = "Failed to update review: " . $conn->error;
                }
                $update_stmt->close();
            } else {
                // Insert new review
                // Ensure review_text is properly handled (empty string becomes null)
                $review_text_for_insert = (!empty(trim($review_text))) ? trim($review_text) : null;
                // #region agent log
                file_put_contents('c:\\xampp\\htdocs\\goglam\\.cursor\\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'save-review','hypothesisId'=>'H','location'=>'customer_dashboard.php:183','message'=>'Preparing to insert review','data'=>['saloon_id'=>$saloon_id,'customer_id'=>$customer_id,'slot_id'=>$slot_id,'rating'=>$rating,'review_text_original'=>$review_text,'review_text_for_insert'=>$review_text_for_insert,'review_text_type'=>gettype($review_text_for_insert)],'timestamp'=>time()*1000])."\n", FILE_APPEND);
                // #endregion
                $insert_stmt = $conn->prepare("INSERT INTO reviews (saloon_id, customer_id, slot_id, rating, review_text) VALUES (?, ?, ?, ?, ?)");
                $insert_stmt->bind_param("iiiss", $saloon_id, $customer_id, $slot_id, $rating, $review_text_for_insert);
                // #region agent log
                file_put_contents('c:\\xampp\\htdocs\\goglam\\.cursor\\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'save-review','hypothesisId'=>'I','location'=>'customer_dashboard.php:186','message'=>'Executing insert with bind_param','data'=>['bind_types'=>'iiiss','review_text_value'=>$review_text_for_insert],'timestamp'=>time()*1000])."\n", FILE_APPEND);
                // #endregion
                if ($insert_stmt->execute()) {
                    // #region agent log
                    file_put_contents('c:\\xampp\\htdocs\\goglam\\.cursor\\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'save-review','hypothesisId'=>'J','location'=>'customer_dashboard.php:189','message'=>'Review inserted successfully','data'=>['insert_id'=>$conn->insert_id],'timestamp'=>time()*1000])."\n", FILE_APPEND);
                    // #endregion
                    $success_message = "Thank you for your review!";
                } else {
                    // #region agent log
                    file_put_contents('c:\\xampp\\htdocs\\goglam\\.cursor\\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'save-review','hypothesisId'=>'K','location'=>'customer_dashboard.php:192','message'=>'Review insert failed','data'=>['error'=>$conn->error],'timestamp'=>time()*1000])."\n", FILE_APPEND);
                    // #endregion
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

// Handle profile update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone_no = trim($_POST['phone_no'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    
    // Validate inputs
    if (empty($name)) {
        $error_message = "Name is required.";
    } elseif (empty($email)) {
        $error_message = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format.";
    } elseif (empty($phone_no)) {
        $error_message = "Phone number is required.";
    } elseif (empty($gender)) {
        $error_message = "Gender is required.";
    } else {
        // Check if email already exists for another customer
        $check_stmt = $conn->prepare("SELECT customer_id FROM customer WHERE email = ? AND customer_id != ?");
        $check_stmt->bind_param("si", $email, $customer_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error_message = "This email is already registered by another user.";
            $check_stmt->close();
        } else {
            $check_stmt->close();
            
            // Update customer profile
            $update_stmt = $conn->prepare("UPDATE customer SET name=?, email=?, phone_no=?, gender=? WHERE customer_id=?");
            $update_stmt->bind_param("ssssi", $name, $email, $phone_no, $gender, $customer_id);
            
            if ($update_stmt->execute()) {
                $success_message = "Profile updated successfully!";
                $_SESSION['name'] = $name; // Update session name
            } else {
                $error_message = "Failed to update profile: " . $conn->error;
            }
            $update_stmt->close();
        }
    }
    
    // Redirect to prevent form resubmission
    if (!empty($success_message)) {
        header("Location: customer_dashboard.php?profile_updated=1");
        exit();
    } elseif (!empty($error_message)) {
        header("Location: customer_dashboard.php?profile_error=" . urlencode($error_message));
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

// Show success message if redirected after profile update
if (isset($_GET['profile_updated']) && $_GET['profile_updated'] == '1') {
    $success_message = "Profile updated successfully!";
}

// Show error message if redirected after profile update error
if (isset($_GET['profile_error'])) {
    $error_message = htmlspecialchars(urldecode($_GET['profile_error']));
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

        /* Make search input wider for full addresses */
        .form-group:first-child {
            max-width: 800px;
        }

        #search {
            padding-right: 50px;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #7A1C2C;
        }

        .gps-btn-dashboard {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 6px;
            background: transparent;
            color: #7A1C2C;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .gps-btn-dashboard:hover {
            background: rgba(122, 28, 44, 0.1);
        }

        .gps-btn-dashboard:active {
            background: rgba(122, 28, 44, 0.2);
        }

        .gps-btn-dashboard.loading {
            animation: pulse 1.5s ease-in-out infinite;
        }

        .gps-btn-dashboard.active {
            background: rgba(122, 28, 44, 0.15);
            color: #5a141f;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .saloon-distance {
            margin-left: 8px;
            font-size: 12px;
            color: #666;
            font-weight: 400;
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
                <button onclick="openUpdateProfileModal()" class="header-btn" style="margin-right: 10px;">Update Profile</button>
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

            <!-- Messages (shown in list view) -->
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

            <!-- Search and Filter -->
            <div class="search-filter-container">
                <div class="search-filter-row">
                    <div class="form-group" style="position: relative;">
                        <label for="search">Search Saloons</label>
                        <div style="position: relative;">
                            <input type="text" id="search" placeholder="Search by name, address, or location..." onkeyup="filterSaloons()">
                            <button type="button" class="gps-btn-dashboard" id="gpsBtnDashboard" aria-label="Find nearby saloons using GPS" title="Find nearby saloons" style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 32px; height: 32px; border: none; border-radius: 6px; background: transparent; color: #7A1C2C; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" fill="currentColor"/>
                                </svg>
                            </button>
                        </div>
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
                            <option value="manicure">Manicure</option>
                            <option value="pedicure">Pedicure</option>
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
                             data-address="<?php echo htmlspecialchars($saloon['address'] ?? ''); ?>"
                             data-location="<?php echo htmlspecialchars(strtolower($saloon['location_name'] ?? '')); ?>"
                             data-services="<?php echo htmlspecialchars(implode(',', $saloon['services_list'] ?? [])); ?>"
                             data-min-price="<?php echo $saloon['min_price'] ?? 0; ?>"
                             data-max-price="<?php echo $saloon['max_price'] ?? 0; ?>"
                             data-distance="">
                            <div class="saloon-card-name"><?php echo htmlspecialchars($saloon['name']); ?></div>
                            <?php if (!empty($saloon['location_name'])): ?>
                                <div class="saloon-card-info"><strong>Location:</strong> <?php echo htmlspecialchars($saloon['location_name']); ?> <span class="saloon-distance" style="display: none; margin-left: 8px; font-size: 12px; color: #666;"></span></div>
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

    <!-- Update Profile Modal -->
    <div id="updateProfileModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Update Profile</h2>
                <span class="close" onclick="closeUpdateProfileModal()">&times;</span>
            </div>
            <form method="POST" action="customer_dashboard.php" id="updateProfileForm">
                <input type="hidden" name="action" value="update_profile">
                <?php if (isset($_GET['saloon_id'])): ?>
                    <input type="hidden" name="saloon_id" value="<?php echo (int)$_GET['saloon_id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="profileName">Full Name *</label>
                    <input type="text" id="profileName" name="name" value="<?php echo htmlspecialchars($customer_info['name'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="profileEmail">Email *</label>
                    <input type="email" id="profileEmail" name="email" value="<?php echo htmlspecialchars($customer_info['email'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="profilePhone">Phone Number *</label>
                    <input type="text" id="profilePhone" name="phone_no" value="<?php echo htmlspecialchars($customer_info['phone_no'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="profileGender">Gender *</label>
                    <select id="profileGender" name="gender" required>
                        <option value="">Select Gender</option>
                        <option value="Male" <?php echo (isset($customer_info['gender']) && $customer_info['gender'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo (isset($customer_info['gender']) && $customer_info['gender'] === 'Female') ? 'selected' : ''; ?>>Female</option>
                        <option value="Other" <?php echo (isset($customer_info['gender']) && $customer_info['gender'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeUpdateProfileModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Profile</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // #region agent log
        fetch('http://127.0.0.1:7242/ingest/19dbfd65-af3c-4960-a38f-4b58e38246f6',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'customer_dashboard.php:2313',message:'Script initialization',data:{profileUpdated:<?php echo isset($_GET['profile_updated']) ? 'true' : 'false'; ?>,profileError:<?php echo isset($_GET['profile_error']) ? 'true' : 'false'; ?>,successMessage:'<?php echo isset($success_message) ? addslashes($success_message) : ''; ?>',errorMessage:'<?php echo isset($error_message) ? addslashes($error_message) : ''; ?>'},timestamp:Date.now(),sessionId:'debug-session',runId:'run1',hypothesisId:'A'})}).catch(()=>{});
        // #endregion agent log

        // Global variables for booking
        let selectedSaloonId = null;
        let selectedServiceId = null;
        // Date-specific availability keyed by 'YYYY-MM-DD' -> [{ start_time, end_time, is_available }]
        let availabilityByDate = null;

        // Helper function to check if a time slot is available based on date-specific availability
        function isSlotAvailableForService(date, hour) {
            // If no availability has been defined, no slots are available
            if (!availabilityByDate || Object.keys(availabilityByDate).length === 0) {
                return false;
            }

            const dateKey = date.toISOString().split('T')[0];
            const timeStr = String(hour).padStart(2, '0') + ':00:00';

            const entries = availabilityByDate[dateKey] || [];
            if (entries.length === 0) {
                return false;
            }

            // Check if the time slot falls within any available time range for that date
            for (let entry of entries) {
                if (parseInt(entry.is_available) !== 1) {
                    continue;
                }
                const startTime = entry.start_time;
                const endTime = entry.end_time;

                if (timeStr >= startTime && timeStr < endTime) {
                    return true;
                }
            }

            return false; // Time slot not in any available range for the selected date
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
            const maxDistanceKm = 30; // Maximum distance in kilometers

            // Service category names for partial matching
            const categoryNames = {
                'hair': 'hair',
                'face': 'face',
                'nail': 'nail',
                'manicure': 'manicure',
                'pedicure': 'pedicure',
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
                
                // If GPS is active, filter by distance first
                if (window.isGpsActiveDashboard && window.userLocationDashboard) {
                    const distance = parseFloat(card.getAttribute('data-distance')) || Infinity;
                    if (distance > maxDistanceKm) {
                        card.style.display = 'none';
                        return false;
                    }
                }
                
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

            const grid = document.getElementById('saloons-grid');
            
            // Distance sorting (if GPS is active, prioritize distance)
            if (window.isGpsActiveDashboard && visibleCards.length > 0) {
                visibleCards.sort((a, b) => {
                    const distA = parseFloat(a.getAttribute('data-distance')) || Infinity;
                    const distB = parseFloat(b.getAttribute('data-distance')) || Infinity;
                    return distA - distB;
                });
                visibleCards.forEach(card => grid.appendChild(card));
            }
            // Price sorting (only if GPS is not active)
            else if (priceSort !== '' && visibleCards.length > 0) {
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
            
            // Clear GPS if active
            if (window.isGpsActiveDashboard) {
                const gpsBtn = document.getElementById('gpsBtnDashboard');
                if (gpsBtn) {
                    gpsBtn.classList.remove('active');
                }
                window.isGpsActiveDashboard = false;
                window.userLocationDashboard = null;
                
                // Hide distances
                const cards = document.querySelectorAll('.saloon-card');
                cards.forEach(card => {
                    const distanceSpan = card.querySelector('.saloon-distance');
                    if (distanceSpan) {
                        distanceSpan.style.display = 'none';
                    }
                    card.setAttribute('data-distance', '');
                });
            }
            
            filterSaloons();
        }

        // GPS Location Search Functionality for Dashboard
        console.log('Initializing GPS functionality for dashboard...');
        const gpsBtnDashboard = document.getElementById('gpsBtnDashboard');
        console.log('GPS button lookup result:', gpsBtnDashboard);
        console.log('GPS button exists:', !!gpsBtnDashboard);
        
        if (!gpsBtnDashboard) {
            console.error('CRITICAL: GPS button not found! Make sure the button has id="gpsBtnDashboard"');
        }
        
        window.userLocationDashboard = null;
        window.isGpsActiveDashboard = false;
        window.gpsWatchIdDashboard = null; // Store watchPosition ID for cleanup
        window.isRequestingLocationDashboard = false; // Prevent multiple simultaneous requests
        window.locationRequestTimeoutDashboard = null; // Timeout to reset stuck state
        window.locationRefreshIntervalDashboard = null; // Interval for periodic location updates
        const geocodeCacheDashboard = JSON.parse(sessionStorage.getItem('geocodeCache') || '{}');

        // Haversine formula to calculate distance between two coordinates
        function calculateDistanceDashboard(lat1, lon1, lat2, lon2) {
            const R = 6371; // Earth's radius in km
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = 
                Math.sin(dLat/2) * Math.sin(dLat/2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLon/2) * Math.sin(dLon/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            return R * c; // Distance in km
        }

        // Format distance for display
        function formatDistanceDashboard(km) {
            if (km < 1) {
                return Math.round(km * 1000) + ' m away';
            }
            return km.toFixed(1) + ' km away';
        }

        // Helper function to construct address from address components
        function constructAddressFromComponentsDashboard(addressObj) {
            if (!addressObj) return null;
            
            const parts = [];
            
            // Try to build a readable address in order of preference
            if (addressObj.road || addressObj.street) {
                parts.push(addressObj.road || addressObj.street);
            }
            if (addressObj.house_number) {
                parts.unshift(addressObj.house_number); // Put house number first
            }
            if (addressObj.suburb || addressObj.neighbourhood) {
                parts.push(addressObj.suburb || addressObj.neighbourhood);
            }
            if (addressObj.city || addressObj.town || addressObj.village) {
                parts.push(addressObj.city || addressObj.town || addressObj.village);
            }
            if (addressObj.state || addressObj.region) {
                parts.push(addressObj.state || addressObj.region);
            }
            if (addressObj.country) {
                parts.push(addressObj.country);
            }
            
            return parts.length > 0 ? parts.join(', ') : null;
        }

        // Reverse geocode coordinates to get address using Photon (Komoot) API
        async function reverseGeocodeDashboard(lat, lon, retryCount = 0) {
            const maxRetries = 2;
            
            console.log('Reverse geocoding coordinates (dashboard):', lat, lon, 'attempt:', retryCount + 1);
            
            // Create cache key for coordinates
            const cacheKey = `${lat.toFixed(4)},${lon.toFixed(4)}`;
            const reverseGeocodeCache = JSON.parse(sessionStorage.getItem('reverseGeocodeCache') || '{}');
            
            // Check cache first - but only if it's a valid address (not null/empty)
            if (reverseGeocodeCache[cacheKey] && reverseGeocodeCache[cacheKey].trim() !== '') {
                console.log('Using cached address for coordinates:', cacheKey, reverseGeocodeCache[cacheKey]);
                return reverseGeocodeCache[cacheKey];
            } else if (reverseGeocodeCache[cacheKey] === null || reverseGeocodeCache[cacheKey] === '') {
                // Clear invalid cache entry
                delete reverseGeocodeCache[cacheKey];
                sessionStorage.setItem('reverseGeocodeCache', JSON.stringify(reverseGeocodeCache));
                console.log('Cleared invalid cache entry for:', cacheKey);
            }

            // Small delay for retries (Photon is more lenient with rate limits)
            if (retryCount > 0) {
                const delay = 1000 * retryCount; // 1 second per retry
                console.log(`Waiting ${delay}ms before retry...`);
                await new Promise(resolve => setTimeout(resolve, delay));
            }

            try {
                const url = `https://photon.komoot.io/reverse?lat=${lat}&lon=${lon}`;
                console.log('Fetching reverse geocode from:', url);
                
                // Create AbortController for timeout handling
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
                
                const response = await fetch(url, {
                    signal: controller.signal
                });
                
                clearTimeout(timeoutId);
                
                if (!response.ok) {
                    console.error('Reverse geocoding API response not OK:', {
                        status: response.status,
                        statusText: response.statusText,
                        url: url
                    });
                    
                    // Retry if we have retries left
                    if (retryCount < maxRetries) {
                        console.log(`Retrying reverse geocoding (${retryCount + 1}/${maxRetries})...`);
                        return await reverseGeocodeDashboard(lat, lon, retryCount + 1);
                    }
                    
                    throw new Error(`Reverse geocoding failed: ${response.status} ${response.statusText}`);
                }
                
                const data = await response.json();
                console.log('Reverse geocoding response (dashboard):', JSON.stringify(data, null, 2));
                
                // Photon returns GeoJSON format
                if (data && data.features && data.features.length > 0) {
                    const feature = data.features[0];
                    const properties = feature.properties || {};
                    
                    // Construct address from Photon properties
                    const addressParts = [];
                    
                    // Photon uses different property names
                    if (properties.housenumber) addressParts.push(properties.housenumber);
                    if (properties.street) addressParts.push(properties.street);
                    if (properties.city || properties.locality) addressParts.push(properties.city || properties.locality);
                    if (properties.state || properties.region) addressParts.push(properties.state || properties.region);
                    if (properties.country) addressParts.push(properties.country);
                    
                    // Fallback: use name if available
                    if (addressParts.length === 0 && properties.name) {
                        addressParts.push(properties.name);
                    }
                    
                    const address = addressParts.length > 0 ? addressParts.join(', ') : null;
                    
                    if (address && address.trim() !== '') {
                        // Cache the result only if it's valid
                        reverseGeocodeCache[cacheKey] = address;
                        sessionStorage.setItem('reverseGeocodeCache', JSON.stringify(reverseGeocodeCache));
                        console.log('Address cached successfully (dashboard):', address);
                        return address;
                    } else {
                        console.warn('No valid address found in reverse geocoding results (dashboard). Full response:', JSON.stringify(data, null, 2));
                        // Retry if we have retries left
                        if (retryCount < maxRetries) {
                            console.log('Retrying (attempt', retryCount + 2, 'of', maxRetries + 1, ')...');
                            return await reverseGeocodeDashboard(lat, lon, retryCount + 1);
                        } else {
                            console.error('All retry attempts exhausted (dashboard). Could not get address.');
                        }
                    }
                } else {
                    console.warn('No features in reverse geocoding response (dashboard). Full response:', JSON.stringify(data, null, 2));
                    // Retry if we have retries left
                    if (retryCount < maxRetries) {
                        console.log('Retrying (attempt', retryCount + 2, 'of', maxRetries + 1, ')...');
                        return await reverseGeocodeDashboard(lat, lon, retryCount + 1);
                    } else {
                        console.error('All retry attempts exhausted (dashboard). Could not get address.');
                    }
                }
            } catch (error) {
                console.error('Reverse geocoding error for coordinates', lat, lon, ':', {
                    error: error.message,
                    name: error.name,
                    stack: error.stack
                });
                
                // Retry on network errors if we have retries left
                if (retryCount < maxRetries && (error.name === 'TypeError' || error.name === 'AbortError')) {
                    console.log(`Retrying reverse geocoding after error (${retryCount + 1}/${maxRetries})...`);
                    return await reverseGeocodeDashboard(lat, lon, retryCount + 1);
                }
            }
            return null;
        }

        // Geocode address using Photon (Komoot) API
        async function geocodeAddressDashboard(address) {
            console.log('Geocoding address (dashboard):', address);
            // Check cache first
            if (geocodeCacheDashboard[address]) {
                console.log('Using cached coordinates for:', address, geocodeCacheDashboard[address]);
                return geocodeCacheDashboard[address];
            }

            try {
                const encodedAddress = encodeURIComponent(address);
                const url = `https://photon.komoot.io/api/?q=${encodedAddress}&limit=1`;
                console.log('Fetching geocode from:', url);
                
                const response = await fetch(url);
                
                if (!response.ok) {
                    console.error('Geocoding API response not OK:', response.status, response.statusText);
                    throw new Error('Geocoding failed');
                }
                
                const data = await response.json();
                console.log('Geocoding response:', data);
                
                // Photon returns GeoJSON format with features array
                if (data && data.features && data.features.length > 0) {
                    const feature = data.features[0];
                    const coords = {
                        lat: parseFloat(feature.geometry.coordinates[1]), // Photon uses [lon, lat] format
                        lon: parseFloat(feature.geometry.coordinates[0])
                    };
                    console.log('Geocoded coordinates:', coords);
                    // Cache the result
                    geocodeCacheDashboard[address] = coords;
                    sessionStorage.setItem('geocodeCache', JSON.stringify(geocodeCacheDashboard));
                    return coords;
                } else {
                    console.warn('No geocoding results for address:', address);
                }
            } catch (error) {
                console.error('Geocoding error for address', address, ':', error);
            }
            return null;
        }

        // Stop watching location for dashboard
        function stopWatchingLocationDashboard() {
            try {
                if (window.gpsWatchIdDashboard !== null && navigator.geolocation) {
                    navigator.geolocation.clearWatch(window.gpsWatchIdDashboard);
                    window.gpsWatchIdDashboard = null;
                    console.log('Stopped watching location (dashboard)');
                }
                // Clear refresh interval
                if (window.locationRefreshIntervalDashboard) {
                    clearInterval(window.locationRefreshIntervalDashboard);
                    window.locationRefreshIntervalDashboard = null;
                    console.log('Cleared location refresh interval (dashboard)');
                }
                // Clear safety timeout
                if (window.locationRequestTimeoutDashboard) {
                    clearTimeout(window.locationRequestTimeoutDashboard);
                    window.locationRequestTimeoutDashboard = null;
                }
            } catch (error) {
                console.error('Error stopping location watch (dashboard):', error);
                window.gpsWatchIdDashboard = null; // Reset even if clearWatch fails
                if (window.locationRefreshIntervalDashboard) {
                    clearInterval(window.locationRefreshIntervalDashboard);
                    window.locationRefreshIntervalDashboard = null;
                }
                if (window.locationRequestTimeoutDashboard) {
                    clearTimeout(window.locationRequestTimeoutDashboard);
                    window.locationRequestTimeoutDashboard = null;
                }
            }
        }

        // Get user location with continuous watching
        function getUserLocationDashboard() {
            try {
                console.log('getUserLocationDashboard() called');
                console.log('navigator.geolocation exists:', !!navigator.geolocation);
                console.log('Current GPS state:', { 
                    isGpsActive: window.isGpsActiveDashboard, 
                    isRequestingLocation: window.isRequestingLocationDashboard, 
                    watchId: window.gpsWatchIdDashboard 
                });
                
                // If GPS is already active, don't start a new request
                if (window.isGpsActiveDashboard && window.userLocationDashboard) {
                    console.log('GPS is already active, skipping new request');
                    return;
                }
                
                // Prevent multiple simultaneous requests
                if (window.isRequestingLocationDashboard) {
                    console.log('Location request already in progress, skipping...');
                    return;
                }
                
                if (!navigator.geolocation) {
                    const errorMsg = 'Geolocation is not supported by your browser. Please use a modern browser like Chrome, Firefox, or Edge.';
                    console.error(errorMsg);
                    alert(errorMsg);
                    return;
                }

                // Stop any existing watch and reset state
                stopWatchingLocationDashboard();
                window.isGpsActiveDashboard = false;
                window.userLocationDashboard = null;

                console.log('Requesting location with options:', {
                    enableHighAccuracy: true,
                    timeout: 30000,
                    maximumAge: 0
                });

                if (gpsBtnDashboard) {
                    gpsBtnDashboard.classList.add('loading');
                    gpsBtnDashboard.disabled = true;
                    console.log('GPS button set to loading state');
                }

                window.isRequestingLocationDashboard = true;

                // Safety timeout: reset flag if request takes too long (35 seconds)
                if (window.locationRequestTimeoutDashboard) {
                    clearTimeout(window.locationRequestTimeoutDashboard);
                }
                window.locationRequestTimeoutDashboard = setTimeout(() => {
                    console.warn('Location request timeout safety - resetting state (dashboard)');
                    window.isRequestingLocationDashboard = false;
                    if (gpsBtnDashboard) {
                        gpsBtnDashboard.classList.remove('loading');
                        gpsBtnDashboard.disabled = false;
                    }
                }, 35000); // 35 seconds (5 seconds after geolocation timeout)

                navigator.geolocation.getCurrentPosition(
                async (position) => {
                    // Clear safety timeout
                    if (window.locationRequestTimeoutDashboard) {
                        clearTimeout(window.locationRequestTimeoutDashboard);
                        window.locationRequestTimeoutDashboard = null;
                    }
                    
                    window.isRequestingLocationDashboard = false;
                    console.log('Location obtained successfully!', {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy: position.coords.accuracy
                    });
                    
                    window.userLocationDashboard = {
                        lat: position.coords.latitude,
                        lon: position.coords.longitude
                    };
                    window.isGpsActiveDashboard = true;
                    if (gpsBtnDashboard) {
                        gpsBtnDashboard.classList.remove('loading');
                        gpsBtnDashboard.classList.add('active');
                        gpsBtnDashboard.disabled = false;
                    }

                    // Don't populate search bar - just calculate distances and filter
                    console.log('Location obtained, calculating distances (dashboard)...');

                    console.log('Processing salons with distance...');
                    await processSalonsWithDistanceDashboard();
                    console.log('Distance processing completed');

                    // Use periodic refresh instead of watchPosition for better reliability
                    // Clear any existing interval
                    if (window.locationRefreshIntervalDashboard) {
                        clearInterval(window.locationRefreshIntervalDashboard);
                    }
                    
                    // Refresh location every 2 minutes if GPS is still active
                    window.locationRefreshIntervalDashboard = setInterval(() => {
                        if (window.isGpsActiveDashboard && !window.isRequestingLocationDashboard && navigator.geolocation) {
                            console.log('Refreshing location (periodic update, dashboard)...');
                            navigator.geolocation.getCurrentPosition(
                                (updatedPosition) => {
                                    if (window.isGpsActiveDashboard && window.userLocationDashboard) {
                                        const distance = calculateDistanceDashboard(
                                            window.userLocationDashboard.lat, window.userLocationDashboard.lon,
                                            updatedPosition.coords.latitude, updatedPosition.coords.longitude
                                        );
                                        
                                        if (distance > 0.1) { // More than 100 meters
                                            console.log('Location changed significantly (dashboard):', distance, 'km');
                                            window.userLocationDashboard = {
                                                lat: updatedPosition.coords.latitude,
                                                lon: updatedPosition.coords.longitude
                                            };
                                            processSalonsWithDistanceDashboard();
                                        }
                                    }
                                },
                                (error) => {
                                    console.warn('Periodic location refresh failed (dashboard):', error.message);
                                    // Don't stop GPS on periodic refresh failures
                                },
                                {
                                    enableHighAccuracy: false,
                                    timeout: 10000,
                                    maximumAge: 120000 // Accept cached location up to 2 minutes old
                                }
                            );
                        } else {
                            // GPS deactivated, clear interval
                            if (window.locationRefreshIntervalDashboard) {
                                clearInterval(window.locationRefreshIntervalDashboard);
                                window.locationRefreshIntervalDashboard = null;
                            }
                        }
                    }, 120000); // Refresh every 2 minutes

                    // Also use watchPosition for immediate updates (but with better error handling)
                    if (window.isGpsActiveDashboard) {
                        window.gpsWatchIdDashboard = navigator.geolocation.watchPosition(
                            async (updatedPosition) => {
                                try {
                                    // Check if GPS is still active before processing update
                                    if (!window.isGpsActiveDashboard) {
                                        console.log('GPS deactivated, ignoring location update (dashboard)');
                                        return;
                                    }
                                    
                                    console.log('Location updated (dashboard):', {
                                        latitude: updatedPosition.coords.latitude,
                                        longitude: updatedPosition.coords.longitude,
                                        accuracy: updatedPosition.coords.accuracy
                                    });
                                    
                                    // Only update if position changed significantly (more than 100 meters)
                                    if (window.userLocationDashboard) {
                                        const distance = calculateDistanceDashboard(
                                            window.userLocationDashboard.lat, window.userLocationDashboard.lon,
                                            updatedPosition.coords.latitude, updatedPosition.coords.longitude
                                        );
                                        
                                        if (distance > 0.1) { // More than 100 meters
                                            console.log('Significant location change detected (dashboard):', distance, 'km');
                                            window.userLocationDashboard = {
                                                lat: updatedPosition.coords.latitude,
                                                lon: updatedPosition.coords.longitude
                                            };
                                            
                                            // Recalculate distances
                                            await processSalonsWithDistanceDashboard();
                                        }
                                    } else {
                                        window.userLocationDashboard = {
                                            lat: updatedPosition.coords.latitude,
                                            lon: updatedPosition.coords.longitude
                                        };
                                        await processSalonsWithDistanceDashboard();
                                    }
                                } catch (error) {
                                    console.error('Error processing location update (dashboard):', error);
                                }
                            },
                            (error) => {
                                console.warn('Location watch error (dashboard):', error.message, error.code);
                                // If watch fails critically, stop watching and reset state
                                if (error.code === error.PERMISSION_DENIED || error.code === error.POSITION_UNAVAILABLE) {
                                    console.log('Critical watch error, stopping GPS (dashboard)');
                                    stopWatchingLocationDashboard();
                                    window.isGpsActiveDashboard = false;
                                    window.userLocationDashboard = null;
                                    window.isRequestingLocationDashboard = false;
                                    if (gpsBtnDashboard) {
                                        gpsBtnDashboard.classList.remove('active');
                                        gpsBtnDashboard.classList.remove('loading');
                                        gpsBtnDashboard.disabled = false;
                                    }
                                }
                            },
                            {
                                enableHighAccuracy: true,
                                timeout: 30000,
                                maximumAge: 60000
                            }
                        );
                        console.log('Started watching position (dashboard), watchId:', window.gpsWatchIdDashboard);
                    }
                },
                (error) => {
                    try {
                        // Clear safety timeout
                        if (window.locationRequestTimeoutDashboard) {
                            clearTimeout(window.locationRequestTimeoutDashboard);
                            window.locationRequestTimeoutDashboard = null;
                        }
                        
                        window.isRequestingLocationDashboard = false;
                        console.error('Geolocation error:', {
                            code: error.code,
                            message: error.message,
                            PERMISSION_DENIED: error.PERMISSION_DENIED,
                            POSITION_UNAVAILABLE: error.POSITION_UNAVAILABLE,
                            TIMEOUT: error.TIMEOUT
                        });
                        
                        if (gpsBtnDashboard) {
                            gpsBtnDashboard.classList.remove('loading');
                            gpsBtnDashboard.classList.remove('active');
                            gpsBtnDashboard.disabled = false;
                        }
                        
                        // Reset state on error
                        window.isGpsActiveDashboard = false;
                        window.userLocationDashboard = null;
                        stopWatchingLocationDashboard();
                    } catch (err) {
                        console.error('Error in geolocation error handler (dashboard):', err);
                        // Force reset on handler error
                        window.isRequestingLocationDashboard = false;
                        if (window.locationRequestTimeoutDashboard) {
                            clearTimeout(window.locationRequestTimeoutDashboard);
                            window.locationRequestTimeoutDashboard = null;
                        }
                    }
                    let errorMsg = 'Unable to get your location. ';
                    let canRetry = false;
                    let troubleshooting = '';
                    
                    switch(error.code) {
                        case error.PERMISSION_DENIED:
                            errorMsg += 'Location permission denied.';
                            troubleshooting = '\n\nTroubleshooting:\n1. Click the lock/info icon in your browser address bar\n2. Allow location access\n3. Refresh the page and try again';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            errorMsg += 'Location information unavailable.';
                            troubleshooting = '\n\nTroubleshooting:\n1. Check if location services are enabled on your device\n2. Make sure you have internet connection\n3. Try refreshing the page';
                            canRetry = true;
                            break;
                        case error.TIMEOUT:
                            errorMsg += 'Location request timed out.';
                            troubleshooting = '\n\nTroubleshooting:\n1. Check your internet connection\n2. Make sure location services are enabled\n3. Try again in a few seconds';
                            canRetry = true;
                            break;
                        default:
                            errorMsg += 'An unknown error occurred.';
                            troubleshooting = '\n\nTroubleshooting:\n1. Refresh the page\n2. Check browser console for errors\n3. Try a different browser';
                            canRetry = true;
                            break;
                    }
                    
                    const fullMessage = errorMsg + troubleshooting;
                    console.error('Full error message:', fullMessage);
                    
                    if (canRetry && confirm(fullMessage + '\n\nWould you like to try again?')) {
                        console.log('User chose to retry');
                        getUserLocationDashboard();
                    } else if (!canRetry) {
                        alert(fullMessage);
                    } else {
                        console.log('User cancelled retry');
                    }
                },
                {
                    enableHighAccuracy: false,
                    timeout: 20000,
                    maximumAge: 60000
                }
            );
            } catch (error) {
                console.error('Error in getUserLocationDashboard function:', error);
                window.isRequestingLocationDashboard = false;
                if (gpsBtnDashboard) {
                    gpsBtnDashboard.classList.remove('loading');
                    gpsBtnDashboard.classList.remove('active');
                    gpsBtnDashboard.disabled = false;
                }
                alert('An error occurred with GPS functionality. Please try again.');
            }
        }

        // Process all salons and calculate distances
        async function processSalonsWithDistanceDashboard() {
            console.log('processSalonsWithDistanceDashboard() called, userLocation:', window.userLocationDashboard);
            if (!window.userLocationDashboard) {
                console.error('No user location available');
                return;
            }
            const grid = document.getElementById('saloons-grid');
            if (!grid) {
                console.error('Salons grid not found');
                return;
            }

            const cards = Array.from(grid.getElementsByClassName('saloon-card'));
            console.log('Found', cards.length, 'salon cards to process');
            
            if (cards.length === 0) {
                console.warn('No salon cards found to process');
                return;
            }
            
            const distancePromises = [];

            for (const card of cards) {
                const address = card.getAttribute('data-address');
                if (!address || address.trim() === '') {
                    console.warn('Card has no address attribute or address is empty:', card);
                    // Set distance to Infinity so it won't show
                    card.setAttribute('data-distance', 'Infinity');
                    continue;
                }

                distancePromises.push(
                    geocodeAddressDashboard(address).then(coords => {
                        if (coords) {
                            const distance = calculateDistanceDashboard(
                                window.userLocationDashboard.lat,
                                window.userLocationDashboard.lon,
                                coords.lat,
                                coords.lon
                            );
                            console.log('Distance calculated for', address, ':', distance, 'km');
                            card.setAttribute('data-distance', distance.toString());
                            
                            // Show distance in UI
                            const distanceSpan = card.querySelector('.saloon-distance');
                            if (distanceSpan) {
                                distanceSpan.textContent = formatDistanceDashboard(distance);
                                distanceSpan.style.display = 'inline';
                            } else {
                                console.warn('Distance span not found for card:', card);
                            }
                        } else {
                            console.warn('Failed to geocode address:', address);
                            // Set distance to Infinity so it won't show
                            card.setAttribute('data-distance', 'Infinity');
                        }
                    }).catch(error => {
                        console.error('Error calculating distance for address', address, ':', error);
                        card.setAttribute('data-distance', 'Infinity');
                    })
                );
            }

            console.log('Waiting for all geocoding to complete...', distancePromises.length, 'promises');
            try {
                await Promise.all(distancePromises);
                console.log('All geocoding completed, filtering and sorting by distance');
            } catch (error) {
                console.error('Error during distance calculation:', error);
            }
            
            // Filter by 30km distance - trigger filterSaloons
            if (typeof filterSaloons === 'function') {
                filterSaloons();
            } else {
                console.warn('filterSaloons function not found, using sortByDistanceDashboard');
                sortByDistanceDashboard();
            }
        }

        // Sort cards by distance
        function sortByDistanceDashboard() {
            const grid = document.getElementById('saloons-grid');
            if (!grid) return;
            
            const cards = Array.from(grid.getElementsByClassName('saloon-card'));
            const visibleCards = cards.filter(card => card.style.display !== 'none');
            
            visibleCards.sort((a, b) => {
                const distA = parseFloat(a.getAttribute('data-distance')) || Infinity;
                const distB = parseFloat(b.getAttribute('data-distance')) || Infinity;
                return distA - distB;
            });

            // Reorder in DOM
            visibleCards.forEach(card => grid.appendChild(card));
        }

        // Toggle GPS search
        console.log('Checking for GPS button and attaching event listener...');
        if (gpsBtnDashboard) {
            console.log('GPS button found, attaching event listener');
            gpsBtnDashboard.addEventListener('click', () => {
                console.log('GPS button clicked, isGpsActive:', window.isGpsActiveDashboard);
                if (window.isGpsActiveDashboard) {
                    // Deactivate GPS search
                    window.isGpsActiveDashboard = false;
                    window.userLocationDashboard = null;
                    window.isRequestingLocationDashboard = false;
                    stopWatchingLocationDashboard(); // Stop continuous tracking and intervals
                    gpsBtnDashboard.classList.remove('active');
                    
                    // Hide distances
                    const grid = document.getElementById('saloons-grid');
                    if (grid) {
                        const cards = Array.from(grid.getElementsByClassName('saloon-card'));
                        cards.forEach(card => {
                            const distanceSpan = card.querySelector('.saloon-distance');
                            if (distanceSpan) {
                                distanceSpan.style.display = 'none';
                            }
                            card.setAttribute('data-distance', '');
                        });
                    }
                    
                    // Reapply current filter
                    filterSaloons();
                } else {
                    // Only activate if not already requesting
                    if (!window.isRequestingLocationDashboard && !window.isGpsActiveDashboard) {
                        console.log('Activating GPS search, calling getUserLocationDashboard()');
                        getUserLocationDashboard();
                    } else {
                        console.log('GPS already active or request in progress, skipping (dashboard)');
                    }
                }
            });
        } else {
            console.error('GPS button not found! Button ID: gpsBtnDashboard');
        }


        function openSlotModal(saloonId, serviceId, serviceName, servicePrice) {
            selectedSaloonId = saloonId;
            selectedServiceId = serviceId;
            selectedServiceName = serviceName;
            selectedServicePrice = servicePrice;
            selectedDate = null;
            selectedTime = null;
            availabilityByDate = null; // Reset availability

            document.getElementById('modalServiceName').textContent = serviceName;
            
            // Fetch service availability first, then generate UI
            fetchServiceAvailability(serviceId).then(() => {
                generateSlotSelectionUI();
            });
            
            document.getElementById('slotModal').style.display = 'block';
        }

        // Fetch service availability from API (date-specific)
        function fetchServiceAvailability(serviceId) {
            return fetch(`get_service_availability.php?service_id=${serviceId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        availabilityByDate = data.availability_by_date || {};
                    } else {
                        availabilityByDate = {}; // No availability if fetch fails
                    }
                })
                .catch(error => {
                    console.error('Error fetching availability:', error);
                    availabilityByDate = {}; // Default to no availability
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

            // Generate time slots (7 AM to 11 PM, hourly)
            const timeSlots = [];
            for (let hour = 7; hour <= 23; hour++) {
                const hour12 = (hour % 12) === 0 ? 12 : (hour % 12);
                const ampm = hour >= 12 ? 'PM' : 'AM';
                const timeStr = `${hour12}:00 ${ampm}`;
                timeSlots.push({
                    hour: hour,
                    display: timeStr,
                    value: String(hour).padStart(2, '0') + ':00:00'
                });
            }

            // Build schedule summary from availabilityByDate
            let scheduleSummaryHtml = '';
            if (availabilityByDate && Object.keys(availabilityByDate).length > 0) {
                const dateKeys = Object.keys(availabilityByDate).sort();
                const maxEntriesToShow = 5;
                const summaryParts = [];

                for (let i = 0; i < dateKeys.length && summaryParts.length < maxEntriesToShow; i++) {
                    const key = dateKeys[i];
                    const dateObj = new Date(key);
                    const friendlyDate = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', weekday: 'short' });
                    const ranges = (availabilityByDate[key] || [])
                        .filter(entry => parseInt(entry.is_available) === 1)
                        .map(entry => {
                            const start = entry.start_time.substring(0, 5);
                            const end = entry.end_time.substring(0, 5);
                            return `${start}-${end}`;
                        });

                    if (ranges.length > 0) {
                        summaryParts.push(`${friendlyDate}: ${ranges.join(', ')}`);
                    }
                }

                if (summaryParts.length > 0) {
                    scheduleSummaryHtml = `
                        <div style="margin-bottom: 24px; padding: 12px 16px; background: #f9f9f9; border-left: 4px solid #7A1C2C; border-radius: 4px;">
                            <p style="margin: 0 0 4px; font-size: 13px; color: #333; font-weight: 600;">Saloon schedule (upcoming):</p>
                            <p style="margin: 0; font-size: 13px; color: #555;">${summaryParts.join(' • ')}</p>
                            <p style="margin: 8px 0 0; font-size: 12px; color: #999;">Only showing times when the saloon is marked as available.</p>
                        </div>
                    `;
                }
            }

            let html = `
                <div style="margin-bottom: 24px;">
                    <p style="color: #666; margin-bottom: 16px;"><strong>Service:</strong> ${selectedServiceName}</p>
                    <p style="color: #666; margin-bottom: 24px;"><strong>Price:</strong> ${selectedServicePrice}</p>
                </div>

                ${scheduleSummaryHtml}

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

                // Check if this specific date has any available slots
                const dateKey = date.toISOString().split('T')[0];
                let hasAvailability = false;
                if (availabilityByDate && availabilityByDate[dateKey] && availabilityByDate[dateKey].length > 0) {
                    hasAvailability = availabilityByDate[dateKey].some(entry => parseInt(entry.is_available) === 1);
                }
                
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
                document.querySelectorAll('.time-slot-btn').forEach(btn => {
                    const hour = parseInt(btn.textContent.match(/\d+/)[0]);
                    let actualHour = hour;
                    
                    // Convert to 24-hour format
                    if (btn.textContent.includes('PM') && hour !== 12) {
                        actualHour = hour + 12;
                    } else if (btn.textContent.includes('AM') && hour === 12) {
                        actualHour = 0;
                    }
                    
                    // Create datetime for this slot
                    const slotDateTime = new Date(selectedDateObj);
                    slotDateTime.setHours(actualHour, 0, 0, 0);
                    
                    // Disable if slot time is in the past (before current time)
                    if (slotDateTime < now) {
                        btn.classList.add('disabled');
                        btn.disabled = true;
                        btn.title = 'Past time - cannot book';
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

        function openUpdateProfileModal() {
            // #region agent log
            fetch('http://127.0.0.1:7242/ingest/19dbfd65-af3c-4960-a38f-4b58e38246f6',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'customer_dashboard.php:openUpdateProfileModal',message:'Opening update profile modal',data:{},timestamp:Date.now(),sessionId:'debug-session',runId:'run1',hypothesisId:'B'})}).catch(()=>{});
            // #endregion agent log
            document.getElementById('updateProfileModal').style.display = 'block';
        }

        function closeUpdateProfileModal() {
            document.getElementById('updateProfileModal').style.display = 'none';
        }

        // Close update profile modal when clicking outside

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
            const updateProfileModal = document.getElementById('updateProfileModal');
            if (event.target == reviewModal) {
                closeReviewModal();
            }
            if (event.target == slotModal) {
                closeSlotModal();
            }
            if (event.target == updateProfileModal) {
                closeUpdateProfileModal();
            }
        }
    </script>
</body>
</html>

