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

// Fetch leagues where user is not a member and CurrTeams < MaxTeams
$leagues = [];
$sql = "SELECT l.LeagueID, l.LeagueName, l.MaxTeams, l.CurrTeams, l.PrivOrPub
        FROM Leagues l
        WHERE l.CurrTeams < l.MaxTeams
        AND l.LeagueID NOT IN (
            SELECT ft.LeagueID FROM Fantasy_Team ft WHERE ft.UserID = ?
        )";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $_SESSION['userID']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $leagues[] = $row;
    }
    $stmt->close();
} else {
    $error = "Error preparing statement: " . $conn->error;
}

// Handle join league request
$message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['leagueID'])) {
    $leagueID = (int)$_POST['leagueID'];
    $isPrivate = (int)$_POST['isPrivate'];
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    // Verify password for private leagues
    if ($isPrivate === 1) {
        $passwordSql = "SELECT Password FROM Leagues WHERE LeagueID = ?";
        if ($stmt = $conn->prepare($passwordSql)) {
            $stmt->bind_param("i", $leagueID);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                if (!password_verify($password, $row['Password'])) {
                    $message = "Incorrect password for private league.";
                    $stmt->close();
                }
            } else {
                $message = "League not found.";
                $stmt->close();
            }
        } else {
            $message = "Error preparing statement: " . $conn->error;
        }
    }

    // Proceed with joining if no error
    if (empty($message)) {
        $conn->begin_transaction();
        try {
            // Insert team
            $teamName = "Team " . $_SESSION['username'];
            $wins = 0;
            $losses = 0;
            $leagueManager = 0; // Non-manager
            $userID = $_SESSION['userID'];
            $teamSql = "INSERT INTO Fantasy_Team (Wins, Losses, LeagueManager, TeamName, UserID, LeagueID) VALUES (?, ?, ?, ?, ?, ?)";
            if ($teamStmt = $conn->prepare($teamSql)) {
                $teamStmt->bind_param("iiisii", $wins, $losses, $leagueManager, $teamName, $userID, $leagueID);
                if (!$teamStmt->execute()) {
                    throw new Exception("Error creating team: " . $teamStmt->error);
                }
                $teamStmt->close();
            } else {
                throw new Exception("Error preparing team statement: " . $conn->error);
            }

            // Update CurrTeams
            $updateSql = "UPDATE Leagues SET CurrTeams = CurrTeams + 1 WHERE LeagueID = ?";
            if ($updateStmt = $conn->prepare($updateSql)) {
                $updateStmt->bind_param("i", $leagueID);
                if (!$updateStmt->execute()) {
                    throw new Exception("Error updating CurrTeams: " . $updateStmt->error);
                }
                $updateStmt->close();
            } else {
                throw new Exception("Error preparing update statement: " . $conn->error);
            }

            $conn->commit();
            header("Location: dashboard.php");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $message = $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-device-width, initial-scale=1.0">
    <title>Join League - Fantasy MMA</title>
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
        .leagues-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 80%;
            max-width: 800px;
            margin-top: 20px;
            text-align: center;
        }
        .leagues-container h1 {
            margin-bottom: 20px;
            color: #333;
        }
        .league {
            background-color: #f8f9fa;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 4px;
            text-align: left;
        }
        .league h3 {
            margin: 0 0 10px 0;
            color: #1877f2;
        }
        .league p {
            margin: 5px 0;
            color: #555;
        }
        .no-leagues {
            color: #555;
            font-size: 16px;
        }
        .error {
            color: red;
            font-size: 12px;
            margin-top: 10px;
            text-align: center;
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
        .join-button {
            background-color: #1877f2;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 10px;
            display: inline-block;
        }
        .join-button:hover {
            background-color: #165db7;
        }
        .password-form {
            display: none;
            margin-top: 10px;
        }
        .password-form input[type="password"] {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 200px;
            margin-right: 10px;
        }
        .password-form button {
            background-color: #1877f2;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .password-form button:hover {
            background-color: #165db7;
        }
    </style>
</head>
<body>
    <div class="leagues-container">
        <h1>Join a League</h1>
        <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php elseif (empty($leagues)): ?>
            <p class="no-leagues">No leagues available to join.</p>
        <?php else: ?>
            <?php foreach ($leagues as $league): ?>
                <div class="league">
                    <h3><?php echo htmlspecialchars($league['LeagueName']); ?></h3>
                    <p>Current Teams: <?php echo htmlspecialchars($league['CurrTeams']); ?></p>
                    <p>Maximum Teams: <?php echo htmlspecialchars($league['MaxTeams']); ?></p>
                    <p>Type: <?php echo $league['PrivOrPub'] ? 'Private' : 'Public'; ?></p>
                    <button class="join-button" data-league-id="<?php echo $league['LeagueID']; ?>" data-is-private="<?php echo $league['PrivOrPub']; ?>">Join</button>
                    <?php if ($league['PrivOrPub']): ?>
                        <form class="password-form" id="password-form-<?php echo $league['LeagueID']; ?>" method="post">
                            <input type="hidden" name="leagueID" value="<?php echo $league['LeagueID']; ?>">
                            <input type="hidden" name="isPrivate" value="<?php echo $league['PrivOrPub']; ?>">
                            <input type="password" name="password" placeholder="Enter league password" required>
                            <button type="submit">Submit</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="error"><?php echo htmlspecialchars($message); ?></div>
        <a href="dashboard.php" class="logout">Go Back</a>
    </div>

    <script>
        document.querySelectorAll('.join-button').forEach(button => {
            button.addEventListener('click', function() {
                const leagueID = this.getAttribute('data-league-id');
                const isPrivate = parseInt(this.getAttribute('data-is-private'));
                const passwordForm = document.getElementById(`password-form-${leagueID}`);

                if (isPrivate) {
                    // Show password form for private leagues
                    passwordForm.style.display = 'block';
                    this.style.display = 'none'; // Hide join button
                } else {
                    // Submit directly for public leagues
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    const leagueInput = document.createElement('input');
                    leagueInput.type = 'hidden';
                    leagueInput.name = 'leagueID';
                    leagueInput.value = leagueID;
                    const isPrivateInput = document.createElement('input');
                    isPrivateInput.type = 'hidden';
                    isPrivateInput.name = 'isPrivate';
                    isPrivateInput.value = isPrivate;
                    form.appendChild(leagueInput);
                    form.appendChild(isPrivateInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        });
    </script>
</body>
</html>