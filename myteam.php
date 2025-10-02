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

// Fetch user's team info
$team = null;
$sqlTeam = "SELECT TeamID, TeamName, Wins, Losses FROM Fantasy_Team WHERE UserID = ? AND LeagueID = ?";
if ($stmtTeam = $conn->prepare($sqlTeam)) {
    $stmtTeam->bind_param("ii", $_SESSION['userID'], $leagueID);
    $stmtTeam->execute();
    $resultTeam = $stmtTeam->get_result();
    if ($rowTeam = $resultTeam->fetch_assoc()) {
        $team = $rowTeam;
    }
    $stmtTeam->close();
} else {
    $error = "Error preparing statement: " . $conn->error;
}

if (!$team) {
    $error = "Team not found for this league.";
}

// Fetch drafted fighters for the team
$draftedFighters = [];
if ($team) {
    $sqlFighters = "SELECT f.FighterID, f.FullName, f.NickName, f.Wins, f.Losses, f.NoContests, f.WeightClass, f.AvgStrikes, f.AvgTakedowns 
                    FROM Fighters f 
                    JOIN Picks_belong pb ON f.FighterID = pb.FighterID 
                    WHERE pb.TeamID = ?";
    if ($stmtFighters = $conn->prepare($sqlFighters)) {
        $stmtFighters->bind_param("i", $team['TeamID']);
        $stmtFighters->execute();
        $resultFighters = $stmtFighters->get_result();
        while ($rowFighter = $resultFighters->fetch_assoc()) {
            $draftedFighters[] = $rowFighter;
        }
        $stmtFighters->close();
    } else {
        $error = "Error preparing statement: " . $conn->error;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($leagueName); ?> - My Team</title>
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
        .nav-tab {
            padding: 10px 20px;
            background-color: #f8f9fa;
            color: #555;
            text-decoration: none;
            border-radius: 4px;
            font-size: 16px;
            transition: background-color 0.3s, color 0.3s;
        }
        .nav-tab:hover {
            background-color: #1877f2;
            color: white;
        }
        .nav-tab.active {
            background-color: #1877f2;
            color: white;
        }
        .team-info {
            background-color: #f8f9fa;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            text-align: left;
        }
        .team-info h3 {
            margin: 0 0 10px 0;
            color: #1877f2;
        }
        .team-info p {
            margin: 5px 0;
            color: #555;
        }
        .fighter {
            background-color: #f8f9fa;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 4px;
            text-align: left;
        }
        .fighter h3 {
            margin: 0 0 10px 0;
            color: #1877f2;
        }
        .fighter h3 a {
            color: #1877f2;
            text-decoration: none;
        }
        .fighter h3 a:hover {
            text-decoration: underline;
        }
        .fighter p {
            margin: 5px 0;
            color: #555;
        }
        .no-fighters {
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
        <h1><?php echo htmlspecialchars($leagueName); ?> - My Team</h1>
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
        <?php else: ?>
            <div class="team-info">
                <h3><?php echo htmlspecialchars($team['TeamName']); ?></h3>
                <p>Record: <?php echo htmlspecialchars($team['Wins']) . ' - ' . htmlspecialchars($team['Losses']); ?></p>
            </div>
            <h2>Drafted Fighters</h2>
            <?php if (empty($draftedFighters)): ?>
                <p class="no-fighters">No fighters drafted yet.</p>
            <?php else: ?>
                <?php foreach ($draftedFighters as $fighter): ?>
                    <div class="fighter">
                        <h3><a href="individual_fighters.php?leagueid=<?php echo htmlspecialchars($leagueID); ?>&fighterid=<?php echo htmlspecialchars($fighter['FighterID']); ?>"><?php echo htmlspecialchars($fighter['FullName']); ?> "<?php echo htmlspecialchars($fighter['NickName'] ?? ''); ?>"</a></h3>
                        <p>Record: <?php echo htmlspecialchars($fighter['Wins']) . '-' . htmlspecialchars($fighter['Losses']) . '-' . htmlspecialchars($fighter['NoContests']); ?></p>
                        <p>Weight Class: <?php echo htmlspecialchars($fighter['WeightClass']); ?></p>
                        <p>Average Strikes: <?php echo htmlspecialchars($fighter['AvgStrikes']); ?></p>
                        <p>Average Takedowns: <?php echo htmlspecialchars($fighter['AvgTakedowns']); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
        <a href="myleagues.php" class="back-link">Go Back to My Leagues</a>
    </div>
</body>
</html>