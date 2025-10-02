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

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $leagueName = trim($_POST['leagueName']);
    $maxTeams = (int)$_POST['maxTeams'];
    $privOrPub = (int)$_POST['privOrPub'];
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    // Server-side validation
    if (empty($leagueName)) {
        $message = "League name is required.";
    } elseif (strlen($leagueName) > 40) {
        $message = "League name must be 40 characters or less.";
    } elseif ($maxTeams < 2 || $maxTeams > 12) {
        $message = "Maximum teams must be between 2 and 12.";
    } elseif (!isset($_POST['privOrPub'])) {
        $message = "Please select public or private.";
    } elseif ($privOrPub === 1 && empty($password)) {
        $message = "Password is required for private leagues.";
    } else {
        
		// Check if league name already exists
        $checkSql = "SELECT LeagueID FROM Leagues WHERE LeagueName = ?";
        if ($stmt = $conn->prepare($checkSql)) {
            $stmt->bind_param("s", $leagueName);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $message = "League name already exists.";
            } else {
               
			   // Start transaction for league and team insertion
                $conn->begin_transaction();
                try {
                   
				   // Prepare password (hash for private, NULL for public)
                    $passwordHash = $privOrPub === 1 ? password_hash($password, PASSWORD_DEFAULT) : NULL;
                    
					// Insert into Leagues table
                    $insertSql = "INSERT INTO Leagues (LeagueName, MaxTeams, PrivOrPub, CurrTeams, Password) VALUES (?, ?, ?, ?, ?)";
                    if ($stmt = $conn->prepare($insertSql)) {
						$currTeams = 1;
                        $stmt->bind_param("siiis", $leagueName, $maxTeams, $privOrPub, $currTeams, $passwordHash);
                        if ($stmt->execute()) {
                            // Get the new LeagueID
                            $leagueID = $conn->insert_id;
                            // Create team for the active user
                            $teamName = "Team " . $_SESSION['username'];
                            $wins = 0;
                            $losses = 0;
                            $leagueManager = 1;
                            $userID = $_SESSION['userID'];
                            $teamSql = "INSERT INTO Fantasy_Team (Wins, Losses, LeagueManager, TeamName, UserID, LeagueID) VALUES (?, ?, ?, ?, ?, ?)";
                            if ($teamStmt = $conn->prepare($teamSql)) {
                                $teamStmt->bind_param("iiisii", $wins, $losses, $leagueManager, $teamName, $userID, $leagueID);
                                if ($teamStmt->execute()) {
                                    $conn->commit();
                                    header("Location: myleagues.php");
                                    exit;
                                } else {
                                    throw new Exception("Error creating team: " . $teamStmt->error);
                                }
                                $teamStmt->close();
                            } else {
                                throw new Exception("Error preparing team statement: " . $conn->error);
                            }
                        } else {
                            throw new Exception("Error creating league: " . $stmt->error);
                        }
                        $stmt->close();
                    } else {
                        throw new Exception("Error preparing league statement: " . $conn->error);
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = $e->getMessage();
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
    <title>Create League - Fantasy MMA</title>
    <style>
        body {
			background-color: #EAEAEA;
			background-image: linear-gradient(to bottom, #FFFFFF, #B22222);
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background-color: #f0f2f5;
        }
        .form-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 300px;
            text-align: center;
        }
        .form-container h2 {
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
        }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="password"] {
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
        .form-group .radio-group {
            display: flex;
            gap: 20px;
        }
        .form-group input[type="radio"] {
            margin-right: 5px;
        }
        #passwordField {
            display: none; /* Hidden by default, shown via JavaScript for private leagues */
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
        .error {
            color: red;
            font-size: 12px;
            margin-top: 10px;
            text-align: center;
            display: block;
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
    <div class="form-container">
        <h2>Create League</h2>
        <form id="createLeagueForm" method="post">
            <div class="form-group">
                <label for="leagueName">League Name (max 40 characters)</label>
                <input type="text" id="leagueName" name="leagueName" maxlength="40" required>
            </div>
            <div class="form-group">
                <label for="maxTeams">Maximum Teams (2-12)</label>
                <input type="number" id="maxTeams" name="maxTeams" min="2" max="12" required>
            </div>
            <div class="form-group">
                <label>League Type</label>
                <div class="radio-group">
                    <label><input type="radio" name="privOrPub" value="0" required> Public</label>
                    <label><input type="radio" name="privOrPub" value="1"> Private</label>
                }
            </div>
            <div class="form-group" id="passwordField">
                <label for="password">Password (required for private leagues)</label>
                <input type="password" id="password" name="password">
            </div>
            <button type="submit">Create League</button>
        </form>
        <div class="error"><?php echo $message; ?></div>
        <a href="dashboard.php" class="logout">Go Back</a>
    </div>

    <script>
        // Show/hide password field based on private/public selection
        document.querySelectorAll('input[name="privOrPub"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const passwordField = document.getElementById('passwordField');
                if (this.value === '1') {
                    passwordField.style.display = 'block';
                    document.getElementById('password').required = true;
                } else {
                    passwordField.style.display = 'none';
                    document.getElementById('password').required = false;
                }
            });
        });
    </script>
</body>
</html>