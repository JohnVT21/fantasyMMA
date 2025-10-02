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

// Check if leagueid is provided
if (!isset($_GET['leagueid']) || !is_numeric($_GET['leagueid'])) {
    header("Location: myleagues.php");
    exit;
}

$leagueID = intval($_GET['leagueid']);

// Check if user is league manager
$isLeagueManager = false;
$sqlManager = "SELECT LeagueManager FROM Fantasy_Team WHERE UserID = ? AND LeagueID = ?";
if ($stmtManager = $conn->prepare($sqlManager)) {
    $stmtManager->bind_param("ii", $_SESSION['userID'], $leagueID);
    $stmtManager->execute();
    $resultManager = $stmtManager->get_result();
    if ($rowManager = $resultManager->fetch_assoc()) {
        $isLeagueManager = $rowManager['LeagueManager'] == 1;
    }
    $stmtManager->close();
}

// Fetch league name
$leagueName = '';
$sqlLeague = "SELECT LeagueName FROM Leagues WHERE LeagueID = ?";
if ($stmtLeague = $conn->prepare($sqlLeague)) {
    $stmtLeague->bind_param("i", $leagueID);
    $stmtLeague->execute();
    $resultLeague = $stmtLeague->get_result();
    if ($rowLeague = $resultLeague->fetch_assoc()) {
        $leagueName = $rowLeague['LeagueName'];
    }
    $stmtLeague->close();
} else {
    $error = "Error preparing statement: " . $conn->error;
}

// Fetch members (users and their teams) in the league
$members = [];
$sqlMembers = "SELECT u.UserName, ft.TeamName, ft.LeagueManager
               FROM Users u
               JOIN Fantasy_Team ft ON u.UserID = ft.UserID
               WHERE ft.LeagueID = ?";
if ($stmtMembers = $conn->prepare($sqlMembers)) {
    $stmtMembers->bind_param("i", $leagueID);
    $stmtMembers->execute();
    $resultMembers = $stmtMembers->get_result();
    while ($rowMember = $resultMembers->fetch_assoc()) {
        $members[] = $rowMember;
    }
    $stmtMembers->close();
} else {
    $error = "Error preparing statement: " . $conn->error;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($leagueName); ?> - League Home</title>
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
        }
        .league-home-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 80%;
            max-width: 800px;
            margin-top: 20px;
            text-align: center;
        }
        .league-home-container h1 {
            margin-bottom: 20px;
            color: #333;
        }
		.nav-tabs {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        .nav-tabs a {
            padding: 10px 20px;
            background-color: #f8f9fa;
            color: #555;
            text-decoration: none;
            border-radius: 4px;
            font-size: 16px;
            transition: background-color 0.3s, color 0.3s;
        }
        .nav-tabs a:hover {
            background-color: #1877f2;
            color: white;
        }
        .nav-tabs a.active {
            background-color: #1877f2;
            color: white;
        }
        .member {
            background-color: #f8f9fa;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 4px;
            text-align: left;
        }
        .member h3 {
            margin: 0 0 10px 0;
            color: #1877f2;
        }
        .member p {
            margin: 5px 0;
            color: #555;
        }
        .no-members {
            color: #555;
            font-size: 16px;
        }
        .error {
            color: red;
            font-size: 12px;
            margin-top: 10px;
            text-align: center;
        }
        .back-link {
            display: block;
            margin: 20px 0;
            text-decoration: none;
            font-size: 16px;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="league-home-container">
        <h1><?php echo htmlspecialchars($leagueName); ?> - Standings</h1>
		<div class="nav-tabs">
            <a href="myteam.php?leagueid=<?php echo htmlspecialchars($leagueID); ?>" class="nav-tab">My Team</a>
            <a href="leaguehome.php?leagueid=<?php echo htmlspecialchars($leagueID); ?>" class="nav-tab active">Standings</a>
            <a href="#" class="nav-tab">Matchup</a>
            <a href="fighters.php?leagueid=<?php echo htmlspecialchars($leagueID); ?>" class="nav-tab">Fighters</a>
            <a href="#" class="nav-tab">League History</a>
            <?php if ($isLeagueManager): ?>
                <a href="ManagerTools.php?leagueid=<?php echo htmlspecialchars($leagueID); ?>" class="nav-tab">League Manager Tools</a>
            <?php endif; ?>
        </div>
        <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php elseif (empty($members)): ?>
            <p class="no-members">No members in this league.</p>
        <?php else: ?>
            <?php foreach ($members as $member): ?>
                <div class="member">
                    <h3><?php echo htmlspecialchars($member['UserName']); ?></h3>
                    <p>Team: <?php echo htmlspecialchars($member['TeamName']); ?></p>
                    <p>Role: <?php echo $member['LeagueManager'] ? 'Manager' : 'Member'; ?></p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <a href="myleagues.php" class="back-link">Go Back to My Leagues</a>
    </div>
</body>
</html>