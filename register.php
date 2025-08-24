<?php
require_once 'DBconnect.php';

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);

	//validate email
	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format";
    } else {
		// Check if username or email already exists
        $checkSql = "SELECT * FROM Users WHERE UserName = ? OR UserEmail = ?";
        if ($stmt = $conn->prepare($checkSql)) {
            $stmt->bind_param("ss", $username, $email);
            $stmt->execute();
            $result = $stmt->get_result();
			if ($result->num_rows > 0) {
                $message = "Username or Email already exists.";
            } else {
                // Hash the password
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                // Insert new user into the database
                $insertSql = "INSERT INTO Users (UserName, Password, UserEmail) VALUES (?, ?, ?)";
				if ($stmt = $conn->prepare($insertSql)) {
                    $stmt->bind_param("sss", $username, $passwordHash, $email);
                    if ($stmt->execute()) {
                        // Redirect to login.php on successful registration
                        header("Location: login.php");
                        exit;
					} else {
                        $message = "Error during signup: " . $stmt->error;
                    }
                } else {
                    $message = "Error preparing statement: " . $conn->error;
                }
            }
            $stmt->close();
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
    <title>Register Page</title>
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
		.login-button {
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
        .login-button:hover {
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
        <h2>Register</h2>
        <form id="loginForm" method = "post">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required>
            </div>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">Register</button>
        </form>
		<button class="login-button" onclick="window.location.href='login.php';">Already have an account? Log in</button>
		<button class="login-button" onclick="window.location.href='HomePreLoggin.html';">Back to Home</button>
		<div class="error"><?php echo $message; ?></div>
    </div>
</body>
</html>