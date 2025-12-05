<?php
session_start();

// Database configuration
$host = "localhost";
$username = "root";
$password = "";
$database = "goglam";

// Initialize variables
$email = "";
$password = "";
$user_type = "";
$error_message = "";

// Check if form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get and sanitize input
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $user_type = trim($_POST["user_type"] ?? "");

    // Validate input
    if (empty($email)) {
        $error_message = "Email is required.";
    } elseif (empty($password)) {
        $error_message = "Password is required.";
    } elseif (empty($user_type) || !in_array($user_type, ["customer", "saloon"])) {
        $error_message = "Please select a user type.";
    } else {
        // Connect to database (XAMPP default: no password for root)
        $conn = new mysqli('localhost', 'root', '', 'goglam');

        // Check connection
        if ($conn->connect_error) {
            $error_message = "Database connection failed: " . $conn->connect_error . ". Please check your database settings.";
        } else {
            // Prepare query based on user type
            if ($user_type === "customer") {
                // Query customer table
                $stmt = $conn->prepare("SELECT customer_id, name, email, password FROM customer WHERE email=?");
            } elseif ($user_type === "saloon") {
                // Query saloon table
                $stmt = $conn->prepare("SELECT saloon_id, name, email, password FROM saloon WHERE email=?");
            } else {
                $error_message = "Invalid user type.";
                $conn->close();
                $stmt = null;
            }
            
            if (isset($stmt) && $stmt !== false) {
                // Bind parameters
                $stmt->bind_param("s", $email);
                
                // Execute query
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows === 1) {
                        // User found, verify password using password_verify()
                        $row = $result->fetch_assoc();
                        
                        // Get the appropriate ID field based on user type
                        if ($user_type === "customer") {
                            $user_id = $row['customer_id'];
                        } else {
                            $user_id = $row['saloon_id'];
                        }
                        $stored_password_hash = $row['password'];
                        $name = $row['name'] ?? '';
                        
                        // Verify password using password_verify()
                        if (password_verify($password, $stored_password_hash)) {
                            // Password is correct - set session variables with real ID from database
                            $_SESSION['user_id'] = $user_id;
                            $_SESSION['email'] = $row['email'];
                            $_SESSION['user_type'] = $user_type;
                            $_SESSION['name'] = $name;
                            
                            // Close statement and connection
                            $stmt->close();
                            $conn->close();
                            
                            // Redirect to dashboard
                            header("Location: dashboard.php");
                            exit();
                        } else {
                            $error_message = "Login Failed: wrong email or password.";
                        }
                    } else {
                        $error_message = "Login Failed: wrong email or password.";
                    }
                } else {
                    $error_message = "Database error. Please try again later.";
                }
                
                // Close statement
                $stmt->close();
            } elseif (!isset($error_message)) {
                $error_message = "Database error: " . $conn->error . ". Please try again later.";
            }
            
            // Close connection
            $conn->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - GoGlam</title>
    <link rel="icon" type="image/png" href="goglam-logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Great+Vibes&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #F7E8ED;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 4px 16px rgba(122, 28, 44, 0.12);
            max-width: 480px;
            width: 100%;
        }

        .logo-container {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo-container h1 {
            font-family: 'Great Vibes', cursive;
            font-size: 42px;
            color: #7A1C2C;
            margin-bottom: 10px;
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

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: #7A1C2C;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #D4B8C8;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #9B5A7B;
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
            background: #934f7b;
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

        .submit-btn {
            width: 100%;
            padding: 14px;
            background: #9B5A7B;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .submit-btn:hover {
            background: #8B4A6B;
        }

        .register-link {
            text-align: center;
            margin-top: 20px;
            color: #9B5A7B;
            font-size: 14px;
        }

        .register-link a {
            color: #9B5A7B;
            text-decoration: none;
            font-weight: 600;
        }

        .register-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-container">
            <h1>GoGlam</h1>
            <p style="color: #9B5A7B; font-size: 18px;">Welcome Back!</p>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="user-type-selector">
                <input type="radio" id="user_type_customer" name="user_type" value="customer" <?php echo ($user_type === 'customer') ? 'checked' : ''; ?> required>
                <label for="user_type_customer" class="user-type-card user-type-card-customer <?php echo ($user_type === 'customer') ? 'active' : ''; ?>">
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

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="submit-btn">Login</button>
        </form>

        <div class="register-link">
            Don't have an account? <a href="registration.php">Register here</a>
        </div>
    </div>
</body>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const customerRadio = document.getElementById('user_type_customer');
        const saloonRadio = document.getElementById('user_type_saloon');
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

        customerRadio.addEventListener('change', setActiveCards);
        saloonRadio.addEventListener('change', setActiveCards);
        customerCard.addEventListener('click', () => { customerRadio.checked = true; customerRadio.dispatchEvent(new Event('change')); });
        saloonCard.addEventListener('click', () => { saloonRadio.checked = true; saloonRadio.dispatchEvent(new Event('change')); });

        setActiveCards();
    });
</script>
</html>

