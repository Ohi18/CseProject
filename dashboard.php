<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get session variables (NO default to 0)
$userId = $_SESSION['user_id'];
$userType = $_SESSION['user_type'] ?? '';
$userName = $_SESSION['name'] ?? '';
$userEmail = $_SESSION['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - GoGlam</title>
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
            padding: 20px;
        }

        .dashboard-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 4px 16px rgba(122, 28, 44, 0.12);
        }

        h1 {
            color: #7A1C2C;
            margin-bottom: 20px;
            font-family: 'Great Vibes', cursive;
            font-size: 42px;
        }

        .user-info {
            background: #F9F0F5;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            border-left: 4px solid #B87A9B;
        }

        .user-info p {
            color: #7A1C2C;
            margin-bottom: 8px;
            font-size: 16px;
        }

        .logout-btn {
            display: inline-block;
            padding: 12px 24px;
            background: #9B5A7B;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: background 0.3s ease;
        }

        .logout-btn:hover {
            background: #8B4A6B;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <h1>Welcome to GoGlam Dashboard</h1>
        
        <div class="user-info">
            <p><strong>User ID:</strong> <?php echo htmlspecialchars($userId); ?></p>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($userEmail); ?></p>
            <p><strong>User Type:</strong> <?php echo htmlspecialchars(ucfirst($userType)); ?></p>
        </div>

        <p style="color: #9B5A7B; margin-bottom: 20px;">You have successfully logged in!</p>
        
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</body>
</html>

