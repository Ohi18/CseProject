<?php
/*
 * SQL to run in phpMyAdmin to fix table structures and remove bad ID=0 rows:
 * 
 * -- Ensure auto-increment on primary keys (using exact column names from schema)
 * ALTER TABLE customer MODIFY Customer_id INT NOT NULL AUTO_INCREMENT;
 * ALTER TABLE saloon   MODIFY Saloon_id   INT NOT NULL AUTO_INCREMENT;
 * 
 * -- Set starting values
 * ALTER TABLE customer AUTO_INCREMENT = 1001;
 * ALTER TABLE saloon   AUTO_INCREMENT = 101;
 * 
 * -- Remove any old rows with ID 0
 * DELETE FROM customer WHERE Customer_id = 0;
 * DELETE FROM saloon   WHERE Saloon_id   = 0;
 */

// Database configuration


$host = "localhost";
$username = "root";
$password = "";
$database = "goglam";

// Initialize variables
$error_message = "";
$success_message = "";
$user_type = "";
$full_name = "";
$email = "";
$phone_no = "";
$gender = "";
$reg_id = "";
$address = "";
$location_id = "";
$location_name = "";

// Check if form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get user type
    $user_type = trim($_POST["user_type"] ?? "");
    
    // Validate user type
    if (empty($user_type) || !in_array($user_type, ["customer", "saloon"])) {
        $error_message = "Please select a user type.";
        $user_type = ""; // Reset for form re-display
    } else {
        // Connect to database with error handling
        try {
            $conn = new mysqli('localhost', 'root', '', 'goglam');
            
            // Check connection
            if ($conn->connect_error) {
                $error_message = "Database connection failed: " . $conn->connect_error . ". Please make sure XAMPP MySQL is running.";
            } else {
            if ($user_type === "customer") {
                // Get customer form data - ensure we're getting values, not empty strings
                $name = isset($_POST["full_name"]) ? trim($_POST["full_name"]) : "";
                $email = isset($_POST["email"]) ? trim($_POST["email"]) : "";
                $password = isset($_POST["password"]) ? $_POST["password"] : "";
                $phone_no = isset($_POST["phone_no"]) ? trim($_POST["phone_no"]) : "";
                $gender = isset($_POST["gender"]) ? trim($_POST["gender"]) : "";
                
                // Preserve form values for re-display
                $full_name = $name;
                $email = $email;
                $phone_no = $phone_no;
                $gender = $gender;
                
                // Validate required fields with specific error messages
                if (empty($name)) {
                    $error_message = "Full name is required.";
                } elseif (empty($email)) {
                    $error_message = "Email is required.";
                } elseif (empty($password)) {
                    $error_message = "Password is required.";
                } elseif (empty($phone_no)) {
                    $error_message = "Phone number is required.";
                } elseif (empty($gender)) {
                    $error_message = "Gender is required.";
                } else {
                    // Check if email already exists
                    $check_stmt = $conn->prepare("SELECT email FROM customer WHERE email = ?");
                    $check_stmt->bind_param("s", $email);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $error_message = "This email is already registered. Please use a different email or sign in.";
                        $check_stmt->close();
                    } else {
                        $check_stmt->close();
                        
                        // Hash the password before inserting
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        
                        // Insert customer (customer_id is AUTO_INCREMENT, so we don't include it)
                        // Using exact column names from database schema: name, email, password, phone_no, gender
                        $stmt = $conn->prepare("INSERT INTO customer (name, email, password, phone_no, gender) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("sssss", $name, $email, $hashedPassword, $phone_no, $gender);
                        
                        if ($stmt->execute()) {
                            // Get the auto-generated customer_id (if needed)
                            $newCustomerId = $conn->insert_id;
                            $success_message = "Registration complete! You can now log in.";
                            // Redirect after 2 seconds with user type
                            header("refresh:2;url=login.php?user_type=customer");
                        } else {
                            $error_message = "Registration failed: " . $conn->error;
                        }
                        
                        $stmt->close();
                    }
                }
            } elseif ($user_type === "saloon") {
                // Get saloon form data - ensure we're getting values, not empty strings
                $reg_id = isset($_POST["reg_id"]) ? trim($_POST["reg_id"]) : "";
                $name = isset($_POST["full_name"]) ? trim($_POST["full_name"]) : "";
                $email = isset($_POST["email"]) ? trim($_POST["email"]) : "";
                $password = isset($_POST["password"]) ? $_POST["password"] : "";
                $address = isset($_POST["address"]) ? trim($_POST["address"]) : "";
                $phone_no = isset($_POST["phone_no"]) ? trim($_POST["phone_no"]) : "";
                $location_name = isset($_POST["location_name"]) ? trim($_POST["location_name"]) : "";
                
                // Preserve form values for re-display
                $reg_id = $reg_id;
                $full_name = $name;
                $email = $email;
                $address = $address;
                $phone_no = $phone_no;
                $location_name = $location_name;
                
                // Validate required fields with specific error messages
                if (empty($reg_id)) {
                    $error_message = "Registration ID is required.";
                } elseif (empty($name)) {
                    $error_message = "Saloon name is required.";
                } elseif (empty($email)) {
                    $error_message = "Email is required.";
                } elseif (empty($password)) {
                    $error_message = "Password is required.";
                } elseif (empty($address)) {
                    $error_message = "Address is required.";
                } elseif (empty($phone_no)) {
                    $error_message = "Phone number is required.";
                } else {
                    // Check if email already exists
                    $check_stmt = $conn->prepare("SELECT email FROM saloon WHERE email = ?");
                    $check_stmt->bind_param("s", $email);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $error_message = "This email is already registered. Please use a different email or sign in.";
                        $check_stmt->close();
                    } else {
                        $check_stmt->close();
                        
                        // Handle location - find existing or create new
                        $location_id = null;
                        if (!empty($location_name)) {
                            // Check if location already exists
                            $location_check = $conn->prepare("SELECT location_id FROM location WHERE name = ?");
                            $location_check->bind_param("s", $location_name);
                            $location_check->execute();
                            $location_result = $location_check->get_result();
                            
                            if ($location_result->num_rows > 0) {
                                // Location exists, use its ID
                                $location_row = $location_result->fetch_assoc();
                                $location_id = $location_row['location_id'];
                            } else {
                                // Location doesn't exist, create it
                                $location_insert = $conn->prepare("INSERT INTO location (name) VALUES (?)");
                                $location_insert->bind_param("s", $location_name);
                                if ($location_insert->execute()) {
                                    $location_id = $conn->insert_id;
                                }
                                $location_insert->close();
                            }
                            $location_check->close();
                        }
                        
                        // Hash the password before inserting
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        
                        // Insert saloon (saloon_id is AUTO_INCREMENT, so we don't include it)
                        // Using exact column names from database schema: reg_id, name, email, password, address, phone_no, location_id
                        if ($location_id !== null) {
                            $stmt = $conn->prepare("INSERT INTO saloon (reg_id, name, email, password, address, phone_no, location_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $reg_id_int = (int)$reg_id;
                            $stmt->bind_param("isssssi", $reg_id_int, $name, $email, $hashedPassword, $address, $phone_no, $location_id);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO saloon (reg_id, name, email, password, address, phone_no, location_id) VALUES (?, ?, ?, ?, ?, ?, NULL)");
                            $reg_id_int = (int)$reg_id;
                            $stmt->bind_param("isssss", $reg_id_int, $name, $email, $hashedPassword, $address, $phone_no);
                        }
                        
                        if ($stmt->execute()) {
                            // Get the auto-generated saloon_id (if needed)
                            $newSaloonId = $conn->insert_id;
                            $success_message = "Registration complete! You can now log in.";
                            // Redirect after 2 seconds with user type
                            header("refresh:2;url=login.php?user_type=saloon");
                        } else {
                            $error_message = "Registration failed: " . $conn->error;
                        }
                        
                        $stmt->close();
                    }
                }
            }
            
            // Close connection
            if (isset($conn) && $conn) {
                $conn->close();
            }
            } // End of else block (connection successful)
        } catch (mysqli_sql_exception $e) {
            $error_message = "Database connection error: " . $e->getMessage() . ". Please make sure XAMPP MySQL is running and the database 'goglam' exists.";
        } catch (Exception $e) {
            $error_message = "An error occurred: " . $e->getMessage() . ". Please check your database settings.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration - GoGlam</title>
    <link rel="icon" type="image/png" href="goglam-logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Great+Vibes&display=swap" rel="stylesheet">
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
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            line-height: 1.6;
        }

        .registration-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 4px 16px rgba(122, 28, 44, 0.12);
            max-width: 600px;
            width: 100%;
        }

        .logo-container {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo-container h1 {
            font-size: 24px;
            font-weight: 700;
            color: #7A1C2C;
            margin-bottom: 10px;
        }

        .success-message {
            color: #7A1C2C;
            font-size: 16px;
            margin-bottom: 24px;
            padding: 12px;
            background: #F9F0F5;
            border-radius: 8px;
            border-left: 4px solid #9B5A7B;
            text-align: center;
        }

        .error-message {
            color: #7A1C2C;
            font-size: 14px;
            margin-bottom: 20px;
            padding: 12px;
            background: #F9F0F5;
            border-radius: 8px;
            border-left: 4px solid #B87A9B;
        }

        .user-type-selector {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .user-type-selector input[type="radio"] {
            display: none;
        }

        .user-type-card {
            flex: 1;
            display: flex;
            align-items: center;
            padding: 12px 16px;
            border-radius: 999px;
            background: #ffffff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
            cursor: pointer;
            transition: background 0.2s ease, transform 0.1s ease, box-shadow 0.2s ease;
            color: #7A1C2C;
            border: 2px solid transparent;
        }

        .user-type-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .user-type-card.active {
            background: #7A1C2C;
            color: #ffffff;
        }

        .user-type-avatar {
            height: 40px;
            width: 40px;
            border-radius: 50%;
            margin-right: 12px;
            object-fit: cover;
            flex-shrink: 0;
        }

        .user-type-text-title {
            font-weight: 600;
            font-size: 16px;
            margin-bottom: 2px;
        }

        .user-type-text-subtitle {
            font-size: 12px;
            opacity: 0.8;
        }

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
            gap: 15px;
        }

        .customer-fields,
        .saloon-fields {
            display: none;
        }

        .customer-fields.active,
        .saloon-fields.active {
            display: block;
        }

        .customer-fields input:disabled,
        .customer-fields select:disabled,
        .saloon-fields input:disabled,
        .saloon-fields select:disabled {
            display: none;
        }

        .submit-btn {
            width: 100%;
            padding: 12px 24px;
            background: #7A1C2C;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 10px;
        }

        .submit-btn:hover {
            background: #5a141f;
        }

        .login-link {
            text-align: center;
            margin-top: 20px;
            color: #666;
            font-size: 14px;
        }

        .login-link a {
            color: #7A1C2C;
            text-decoration: none;
            font-weight: 600;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        .message-container {
            background: white;
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 4px 16px rgba(122, 28, 44, 0.12);
            max-width: 480px;
            width: 100%;
            text-align: center;
        }

        .message-container h1 {
            font-family: 'Great Vibes', cursive;
            font-size: 36px;
            color: #7A1C2C;
            margin-bottom: 24px;
            font-weight: 400;
        }

        .login-link-btn {
            display: inline-block;
            color: white;
            text-decoration: none;
            font-size: 14px;
            padding: 10px 20px;
            background: #7A1C2C;
            border-radius: 6px;
            transition: all 0.2s;
            margin-top: 10px;
            font-family: inherit;
        }

        .login-link-btn:hover {
            background: #5a141f;
        }
    </style>
</head>
<body>
    <?php if (!empty($success_message)): ?>
    <div class="message-container">
            <h1>Registration Successful!</h1>
            <div class="success-message">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
            <p style="color: #9B5A7B; margin-bottom: 20px;">Redirecting to login page...</p>
            <a href="login.php" class="login-link-btn">Go to Login</a>
        </div>
        <?php else: ?>
        <div class="registration-container">
            <div class="logo-container">
                <h1>GoGlam</h1>
                <p style="color: #666; font-size: 16px;">Create Your Account</p>
            </div>

            <?php if (!empty($error_message)): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="registration.php" id="registrationForm">
                <div class="user-type-selector">
                    <input type="radio" id="user_type_customer" name="user_type" value="customer" <?php echo ($user_type === 'customer' || empty($user_type)) ? 'checked' : ''; ?> required>
                    <label for="user_type_customer" class="user-type-card user-type-card-customer <?php echo ($user_type === 'customer' || empty($user_type)) ? 'active' : ''; ?>">
                        <img src="customer-icon.png" alt="Customer" class="user-type-avatar">
                        <div>
                            <div class="user-type-text-title">Customer</div>
                            <div class="user-type-text-subtitle">Select if you’re booking services</div>
                        </div>
                    </label>

                    <input type="radio" id="user_type_saloon" name="user_type" value="saloon" <?php echo ($user_type === 'saloon') ? 'checked' : ''; ?> required>
                    <label for="user_type_saloon" class="user-type-card user-type-card-saloon <?php echo ($user_type === 'saloon') ? 'active' : ''; ?>">
                        <img src="saloon-icon.png" alt="Saloon" class="user-type-avatar">
                        <div>
                            <div class="user-type-text-title">Saloon</div>
                            <div class="user-type-text-subtitle">Select if you’re a saloon owner</div>
                        </div>
                    </label>
                </div>

                <!-- Customer Fields -->
                <div class="customer-fields <?php echo ($user_type === 'customer' || empty($user_type)) ? 'active' : ''; ?>" id="customerFields">
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>" <?php echo ($user_type === 'customer' || empty($user_type)) ? 'required' : ''; ?>>
                    </div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" <?php echo ($user_type === 'customer' || empty($user_type)) ? 'required' : ''; ?>>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" <?php echo ($user_type === 'customer' || empty($user_type)) ? 'required' : ''; ?>>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="phone_no">Phone Number</label>
                            <input type="tel" id="phone_no" name="phone_no" value="<?php echo htmlspecialchars($phone_no); ?>" <?php echo ($user_type === 'customer' || empty($user_type)) ? 'required' : ''; ?>>
                        </div>

                        <div class="form-group">
                            <label for="gender">Gender</label>
                            <select id="gender" name="gender" <?php echo ($user_type === 'customer' || empty($user_type)) ? 'required' : ''; ?>>
                                <option value="">Select Gender</option>
                                <option value="Male" <?php echo ($gender === 'Male') ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($gender === 'Female') ? 'selected' : ''; ?>>Female</option>
                                <option value="Other" <?php echo ($gender === 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Saloon Fields -->
                <div class="saloon-fields <?php echo ($user_type === 'saloon') ? 'active' : ''; ?>" id="saloonFields">
                    <div class="form-group">
                        <label for="reg_id">Registration ID</label>
                        <input type="text" id="reg_id" name="reg_id" value="<?php echo htmlspecialchars($reg_id); ?>" <?php echo ($user_type === 'saloon') ? 'required' : ''; ?>>
                    </div>

                    <div class="form-group">
                        <label for="saloon_full_name">Saloon Name</label>
                        <input type="text" id="saloon_full_name" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>" <?php echo ($user_type === 'saloon') ? 'required' : ''; ?>>
                    </div>

                    <div class="form-group">
                        <label for="saloon_email">Email</label>
                        <input type="email" id="saloon_email" name="email" value="<?php echo htmlspecialchars($email); ?>" <?php echo ($user_type === 'saloon') ? 'required' : ''; ?>>
                    </div>

                    <div class="form-group">
                        <label for="saloon_password">Password</label>
                        <input type="password" id="saloon_password" name="password" <?php echo ($user_type === 'saloon') ? 'required' : ''; ?>>
                    </div>

                    <div class="form-group">
                        <label for="address">Address</label>
                        <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($address); ?>" <?php echo ($user_type === 'saloon') ? 'required' : ''; ?>>
                    </div>

                    <div class="form-group">
                        <label for="saloon_phone_no">Phone Number</label>
                        <input type="tel" id="saloon_phone_no" name="phone_no" value="<?php echo htmlspecialchars($phone_no); ?>" <?php echo ($user_type === 'saloon') ? 'required' : ''; ?>>
                    </div>

                    <div class="form-group">
                        <label for="location_name">Location</label>
                        <input type="text" id="location_name" name="location_name" value="<?php echo htmlspecialchars($location_name); ?>" placeholder="Enter location">
                    </div>
                </div>

                <button type="submit" class="submit-btn">Register</button>
            </form>

            <div class="login-link">
                Already have an account? <a href="login.php">Login here</a>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const customerRadio = document.getElementById('user_type_customer');
                const saloonRadio = document.getElementById('user_type_saloon');
                const customerFields = document.getElementById('customerFields');
                const saloonFields = document.getElementById('saloonFields');
                const registrationForm = document.getElementById('registrationForm');
                const customerCard = document.querySelector('.user-type-card-customer');
                const saloonCard = document.querySelector('.user-type-card-saloon');

                function setActiveCards() {
                    if (customerRadio.checked) {
                        customerCard.classList.add('active');
                        saloonCard.classList.remove('active');
                    } else if (saloonRadio.checked) {
                        saloonCard.classList.add('active');
                        customerCard.classList.remove('active');
                    }
                }

                function toggleFields() {
                    if (customerRadio.checked) {
                        customerFields.classList.add('active');
                        saloonFields.classList.remove('active');
                        
                        // Enable and make customer fields required
                        document.querySelectorAll('#customerFields input, #customerFields select').forEach(el => {
                            if (el.name !== 'user_type') {
                                el.disabled = false;
                                el.removeAttribute('readonly');
                                if (el.type !== 'hidden') {
                                    el.required = true;
                                }
                            }
                        });
                        // Disable and remove required from saloon fields
                        document.querySelectorAll('#saloonFields input, #saloonFields select').forEach(el => {
                            if (el.name !== 'user_type' && el.type !== 'button') {
                                el.disabled = true;
                                el.required = false;
                                el.removeAttribute('required');
                                if (el.type !== 'password') {
                                    el.value = ''; // Clear values (don't clear password fields)
                                }
                            }
                        });
                    } else if (saloonRadio.checked) {
                        customerFields.classList.remove('active');
                        saloonFields.classList.add('active');
                        
                        // Enable and make saloon fields required
                        document.querySelectorAll('#saloonFields input, #saloonFields select').forEach(el => {
                            if (el.name !== 'user_type' && el.type !== 'button') {
                                el.disabled = false;
                                el.removeAttribute('readonly');
                                if (el.type !== 'hidden') {
                                    el.required = true;
                                }
                            }
                        });
                        // Disable and remove required from customer fields
                        document.querySelectorAll('#customerFields input, #customerFields select').forEach(el => {
                            if (el.name !== 'user_type') {
                                el.disabled = true;
                                el.required = false;
                                el.removeAttribute('required');
                                if (el.type !== 'password') {
                                    el.value = ''; // Clear values (don't clear password fields)
                                }
                            }
                        });
                    }
                }

                customerRadio.addEventListener('change', () => { toggleFields(); setActiveCards(); });
                saloonRadio.addEventListener('change', () => { toggleFields(); setActiveCards(); });
                customerCard.addEventListener('click', () => { customerRadio.checked = true; customerRadio.dispatchEvent(new Event('change')); });
                saloonCard.addEventListener('click', () => { saloonRadio.checked = true; saloonRadio.dispatchEvent(new Event('change')); });
                
                // Before form submission, ensure only active fields are enabled
                registrationForm.addEventListener('submit', function(e) {
                    // Disable all hidden fields before submission
                    if (customerRadio.checked) {
                        // Disable all saloon fields
                        document.querySelectorAll('#saloonFields input, #saloonFields select').forEach(el => {
                            if (el.name !== 'user_type') {
                                el.disabled = true;
                            }
                        });
                    } else if (saloonRadio.checked) {
                        // Disable all customer fields only - keep saloon fields enabled so they get submitted
                        document.querySelectorAll('#customerFields input, #customerFields select').forEach(el => {
                            if (el.name !== 'user_type') {
                                el.disabled = true;
                            }
                        });
                        // DO NOT disable saloon fields - they need to be submitted with the form
                    }
                    // Allow form to submit
                });
                
                // Initialize on page load
                toggleFields();
                setActiveCards();
            });
        </script>
        <?php endif; ?>
</body>
</html>

