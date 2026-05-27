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

$isLeagueManager = false;
$userInLeague = false;

$sqlCheck = "SELECT LeagueManager FROM Fantasy_Team WHERE UserID = ? AND LeagueID = ?";
if ($stmtCheck = $conn->prepare($sqlCheck)) {
    $stmtCheck->bind_param("ii", $_SESSION['userID'], $leagueID);
    $stmtCheck->execute();
    $resultCheck = $stmtCheck->get_result();
    if ($rowCheck = $resultCheck->fetch_assoc()) {
        $userInLeague = true;
        $isLeagueManager = $rowCheck['LeagueManager'] == 1;
    }
    $stmtCheck->close();
}

if (!$userInLeague) {
    header("Location: myleagues.php");
    exit;
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
}

/* ================================================
   NEW: Fetch all teams in the league for standings
   ================================================ */
$teams = [];
$sqlTeams = "SELECT ft.TeamID, ft.TeamName, ft.Wins, ft.Losses, u.UserName 
             FROM Fantasy_Team ft
             JOIN Users u ON ft.UserID = u.UserID
             WHERE ft.LeagueID = ?
             ORDER BY ft.Wins DESC, ft.Losses ASC, ft.TeamName ASC";

if ($stmtTeams = $conn->prepare($sqlTeams)) {
    $stmtTeams->bind_param("i", $leagueID);
    $stmtTeams->execute();
    $resultTeams = $stmtTeams->get_result();
    while ($row = $resultTeams->fetch_assoc()) {
        $teams[] = $row;
    }
    $stmtTeams->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($leagueName); ?> - Standings</title>
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
            width: 90%;
            max-width: 900px;
            margin-top: 20px;
            text-align: center;
        }
        .nav-tabs {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
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
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #1877f2;
            color: white;
        }
        tr:hover {
            background-color: #f5f5f5;
        }
        .team-link {
            color: #1877f2;
            text-decoration: none;
            font-weight: bold;
        }
        .team-link:hover {
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

        <?php if (empty($teams)): ?>
            <p>No teams in this league yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Team Name</th>
                        <th>Owner</th>
                        <th>Wins</th>
                        <th>Losses</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teams as $index => $team): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td>
                                <a href="viewteam.php?teamid=<?php echo $team['TeamID']; ?>&leagueid=<?php echo $leagueID; ?>" 
                                   class="team-link">
                                    <?php echo htmlspecialchars($team['TeamName']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($team['UserName']); ?></td>
                            <td><?php echo $team['Wins']; ?></td>
                            <td><?php echo $team['Losses']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
		<?php endif; ?>
		<?php if (!$isLeagueManager): ?>
			<a href="leagueDraft.php?leagueid=<?php echo htmlspecialchars($leagueID); ?>" class="back-link">Join Draft</a>
        <?php endif; ?>
        <a href="myleagues.php" style="display:block; margin:20px 0; text-decoration:none;">← Back to My Leagues</a>
    </div>
</body>
</html>