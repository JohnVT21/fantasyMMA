<?php
require_once 'DBconnect.php';

session_start();

// Prevent caching to ensure fresh data is loaded
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

$message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];

    // Validate inputs
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $message = "All fields are required.";
    } elseif ($newPassword !== $confirmPassword) {
        $message = "New passwords do not match.";
    } else {
        // Verify current password
        $userID = $_SESSION['userID'];
        $sql = "SELECT Password FROM users WHERE UserID = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("i", $userID);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                if (password_verify($currentPassword, $row['Password'])) {
                    // Update password
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $updateSql = "UPDATE users SET Password = ? WHERE UserID = ?";
                    if ($updateStmt = $conn->prepare($updateSql)) {
                        $updateStmt->bind_param("si", $hashedPassword, $userID);
                        if ($updateStmt->execute()) {
                            $message = "Password updated successfully.";
                        } else {
                            $message = "Error updating password: " . $conn->error;
                        }
                        $updateStmt->close();
                    } else {
                        $message = "Error preparing update statement: " . $conn->error;
                    }
                } else {
                    $message = "Current password is incorrect.";
                }
                $stmt->close();
            } else {
                $message = "User not found.";
            }
        } else {
            $message = "Error preparing statement: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Fantasy MMA</title>
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
        .settings-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 80%;
            max-width: 400px;
            margin-top: 20px;
            text-align: center;
        }
        .settings-container h1 {
            margin-bottom: 20px;
            color: #333;
        }
        .form-group {
            margin-bottom: 15px;
            text-align: left;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #555;
        }
        .form-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .submit-button {
            background-color: #1877f2;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .submit-button:hover {
            background-color: #165db7;
        }
        .message {
            margin-top: 10px;
            color: #dc3545;
        }
        .success {
            color: #28a745;
        }
        .logout {
            display: block;
            margin: 20px 0;
            text-decoration: none;
            font-size: 16px;
        }
        .logout:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="settings-container">
        <h1>Settings</h1>
        <?php if (!empty($message)): ?>
            <div class="message <?php echo (strpos($message, 'successfully') !== false) ? 'success' : ''; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="form-group">
                <label for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password" required>
            </div>
            <div class="form-group">
                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <button type="submit" name="change_password" class="submit-button">Change Password</button>
        </form>
        <a href="dashboard.php" class="logout">Go Back</a>
    </div>
</body>
</html>