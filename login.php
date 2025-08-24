<?php
require_once 'DBconnect.php';

session_start();
$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
	
	// Query to find user by username or email
    $sql = "SELECT UserID, UserName, Password FROM Users WHERE UserName = ?";
    if ($stmt = $conn->prepare($sql)) {
		$stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($user = $result->fetch_assoc()) {
			// Verify password
            if (password_verify($password, $user['Password'])) {
                // Set session variables
                $_SESSION['loggedin'] = true;
                $_SESSION['username'] = $user['UserName'];
                $_SESSION['userID'] = $user['UserID'];
                // Redirect to dashboard
                header("Location: dashboard.php");
                exit;
			} else {
                $message = "Incorrect username or password.";
            }
        } else {
            $message = "Incorrect username or password.";
        }
        $stmt->close();
    } else {
        $message = "Error preparing statement: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Page</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background-color: #f0f2f5;
        }
        .login-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 300px;
        }
        .login-container h2 {
            text-align: center;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        .form-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .form-group input:focus {
            outline: none;
            border-color: #1877f2;
        }
        button {
            width: 100%;
            padding: 10px;
            background-color: #1877f2;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background-color: #165db7;
        }
		.register-button {
            width: 100%;
            padding: 10px;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 10px;
        }
        .register-button:hover {
            background-color: #218838;
        }
        .error {
            color: red;
            font-size: 12px;
            margin-top: 5px;
			display: block;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Login</h2>
        <form id="loginForm" method="post">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">Login</button>
        </form>
		<button class="register-button" onclick="window.location.href='register.php';">No Account? Register</button>
		<button class="register-button" onclick="window.location.href='HomePreLoggin.html';">Back to Home</button>
		<div class="error"><?php echo $message; ?></div>
    </div>
</body>
</html>