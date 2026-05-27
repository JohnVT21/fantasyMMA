<?php
require_once 'DBconnect.php';

session_start();

/* ================================================
   NEW: View Team Roster Page
   ================================================ */

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['teamid']) || !isset($_GET['leagueid'])) {
    header("Location: myleagues.php");
    exit;
}

$teamID = intval($_GET['teamid']);
$leagueID = intval($_GET['leagueid']);

// Fetch team information
$teamInfo = null;
$sqlTeam = "SELECT ft.TeamName, ft.Wins, ft.Losses, u.UserName 
            FROM Fantasy_Team ft 
            JOIN Users u ON ft.UserID = u.UserID 
            WHERE ft.TeamID = ? AND ft.LeagueID = ?";

if ($stmt = $conn->prepare($sqlTeam)) {
    $stmt->bind_param("ii", $teamID, $leagueID);
    $stmt->execute();
    $result = $stmt->get_result();
    $teamInfo = $result->fetch_assoc();
    $stmt->close();
}

if (!$teamInfo) {
    die("Team not found or you don't have access.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($teamInfo['TeamName']); ?> - Roster</title>
    <style>
        body {
            background-color: #EAEAEA;
            background-image: linear-gradient(to bottom, #FFFFFF, #B22222);
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 { color: #1877f2; margin-bottom: 5px; }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #1877f2;
            text-decoration: none;
        }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h1><?php echo htmlspecialchars($teamInfo['TeamName']); ?></h1>
        <p><strong>Owner:</strong> <?php echo htmlspecialchars($teamInfo['UserName']); ?></p>
        <p><strong>Record:</strong> <?php echo $teamInfo['Wins']; ?> - <?php echo $teamInfo['Losses']; ?></p>

        <hr>
        <h2>Roster</h2>
        <p><em>Roster display will be added once the Fantasy_Roster / team-fighter linking is implemented.</em></p>

        <a href="leaguehome.php?leagueid=<?php echo $leagueID; ?>" class="back-link">← Back to Standings</a>
    </div>
</body>
</html>