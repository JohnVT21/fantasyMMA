<?php
require_once 'DBconnect.php';

session_start();

//Prevent caching to ensure fresh data is loaded
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

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

// Fetch leagues where the user has a team
$leagues = [];
$sql = "SELECT l.LeagueID, l.LeagueName, l.MaxTeams, l.PrivOrPub, l.CurrTeams, ft.LeagueManager
        FROM Leagues l
        JOIN Fantasy_Team ft ON l.LeagueID = ft.LeagueID
        WHERE ft.UserID = ? FOR UPDATE";
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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Leagues - Fantasy MMA</title>
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
    </style>
</head>
<body>
    <div class="leagues-container">
        <h1>My Leagues</h1>
        <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php elseif (empty($leagues)): ?>
            <p class="no-leagues">You are not in any leagues.</p>
        <?php else: ?>
            <?php foreach ($leagues as $league): ?>
                <div class="league">
                    <h3><?php echo htmlspecialchars($league['LeagueName']); ?></h3>
					<p>Current Teams: <?php echo htmlspecialchars($league['CurrTeams']); ?></p>
                    <p>Maximum Teams: <?php echo htmlspecialchars($league['MaxTeams']); ?></p>
                    <p>Type: <?php echo $league['PrivOrPub'] ? 'Private' : 'Public'; ?></p>
                    <p>Role: <?php echo $league['LeagueManager'] ? 'Manager' : 'Member'; ?></p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <a href="dashboard.php" class="logout">Go Back</a>
    </div>
</body>
</html>