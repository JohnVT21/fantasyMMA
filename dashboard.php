<?php
require_once 'DBconnect.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<title>Fantasy MMA</title>
	<meta charset="utf-8">
	<link rel="stylesheet" href="fantasy.css">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background-color: #f0f2f5;
        }
        .dashboard-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 80%;
            max-width: 800px;
            margin-top: 20px;
            text-align: center;
        }
        .dashboard-container h1 {
            margin-bottom: 20px;
            color: #333;
        }
        .features {
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 20px;
        }
        .feature {
            background-color: #1877f2;
            color: white;
            padding: 20px;
            border-radius: 8px;
            width: 200px;
            text-align: center;
            text-decoration: none;
            transition: background-color 0.3s;
        }
        .feature:hover {
            background-color: #165db7;
        }
        .feature i {
            font-size: 40px;
            margin-bottom: 10px;
        }
        .feature span {
            display: block;
            font-size: 18px;
        }
        .settings {
            background-color: #6c757d;
            color: white;
            padding: 10px;
            border-radius: 8px;
            width: 120px;
            text-align: center;
            text-decoration: none;
            transition: background-color 0.3s;
            font-size: 14px;
            margin: 10px auto;
        }
        .settings:hover {
            background-color: #5a6268;
        }
        .settings i {
            font-size: 20px;
            margin-bottom: 5px;
        }
        .settings span {
            display: block;
        }
        .logout {
            display: block;
            margin: 20px auto;
            color: #dc3545;
            text-decoration: none;
            font-size: 16px;
        }
        .logout:hover {
            text-decoration: underline;
        }
        .blurb {
            margin-top: 20px;
            font-size: 16px;
            color: #555;
        }
    </style>
</head>
<body>
	<div class="dashboard-container">
        <h1>Welcome to Fantasy MMA, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h1>
        <div class="features">
            <a href="myleagues.php" class="feature">
                <i class="fas fa-trophy"></i>
                <span>My Leagues</span>
            </a>
            <a href="createleague.php" class="feature">
                <i class="fas fa-plus-circle"></i>
                <span>Create League</span>
            </a>
            <a href="joinleague.php" class="feature">
                <i class="fas fa-users"></i>
                <span>Join League</span>
            </a>
        </div>
        <p class="blurb"> Join the ultimate MMA fan experience! Build your dream team in weekly drafts, compete against your friends or people around the world, and rise to the top!</p>
        <div class="actions">
            <a href="settings.php" class="action-link">Settings</a>
            <a href="?logout=true" class="action-link logout">Logout</a>
        </div>
    </div>
</body>
</html>