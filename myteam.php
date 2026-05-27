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

// Handle drop fighter request 
$message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['drop_fighter'])) {
    $fighterID = intval($_POST['fighterID']);
    $teamID = null;

    // Get user's team ID
    $sqlTeam = "SELECT TeamID FROM Fantasy_Team WHERE UserID = ? AND LeagueID = ?";
    if ($stmtTeam = $conn->prepare($sqlTeam)) {
        $stmtTeam->bind_param("ii", $_SESSION['userID'], $leagueID);
        $stmtTeam->execute();
        $resultTeam = $stmtTeam->get_result();
        if ($rowTeam = $resultTeam->fetch_assoc()) {
            $teamID = $rowTeam['TeamID'];
        }
        $stmtTeam->close();
    }

    if ($teamID && $fighterID) {
        $conn->begin_transaction();
        try {
            // Verify fighter belongs to this team (security check)
            $checkSql = "SELECT COUNT(*) as count FROM Picks_belong WHERE TeamID = ? AND FighterID = ?";
            if ($stmtCheck = $conn->prepare($checkSql)) {
                $stmtCheck->bind_param("ii", $teamID, $fighterID);
                $stmtCheck->execute();
                $rowCheck = $stmtCheck->get_result()->fetch_assoc();
                if ($rowCheck['count'] == 0) {
                    throw new Exception("Fighter is not on your team.");
                }
                $stmtCheck->close();
            }

            // Drop the fighter
            $dropSql = "DELETE FROM Picks_belong WHERE TeamID = ? AND FighterID = ?";
            if ($stmtDrop = $conn->prepare($dropSql)) {
                $stmtDrop->bind_param("ii", $teamID, $fighterID);
                if ($stmtDrop->execute()) {
                    $conn->commit();
                    $message = "Fighter dropped successfully.";
                } else {
                    throw new Exception("Error dropping fighter.");
                }
                $stmtDrop->close();
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = $e->getMessage();
        }
    } else {
        $message = "Invalid request.";
    }
}
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
        .fighter-link {
            color: #1877f2;
            text-decoration: none;
            font-weight: bold;
        }
        .fighter-link:hover {
            text-decoration: underline;
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
		.drop-btn {
            top: 15px;
            right: 15px;
            background-color: #dc3545;
            color: white;
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .drop-btn:hover {
            background-color: #c82333;
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
		
		<?php if (!empty($message)): ?>
            <div class="<?php echo strpos($message, 'successfully') !== false ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
		
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
				<table>
                    <thead>
                        <tr>
                            <th>Fighter Name</th>
                            <th>Record</th>
                            <th>Weight Class</th>
                            <th>Avg Strikes</th>
                            <th>Avg Takedowns</th>
							<th>Drop Fighter</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($draftedFighters as $fighter): ?>
                            <tr>
                                <td>
                                    <a href="individual_fighters.php?leagueid=<?php echo htmlspecialchars($leagueID); ?>&fighterid=<?php echo htmlspecialchars($fighter['FighterID']); ?>" 
                                       class="fighter-link">
                                        <?php echo htmlspecialchars($fighter['FullName']); ?> 
                                        "<?php echo htmlspecialchars($fighter['NickName'] ?? ''); ?>"
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($fighter['Wins']) . '-' . htmlspecialchars($fighter['Losses']) . '-' . htmlspecialchars($fighter['NoContests']); ?></td>
                                <td><?php echo htmlspecialchars($fighter['WeightClass']); ?></td>
                                <td><?php echo htmlspecialchars($fighter['AvgStrikes']); ?></td>
                                <td><?php echo htmlspecialchars($fighter['AvgTakedowns']); ?></td>
								<td>
									<form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to drop this fighter?');">
                                        <input type="hidden" name="fighterID" value="<?php echo $fighter['FighterID']; ?>">
                                        <button type="submit" name="drop_fighter" class="drop-btn">Drop</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
        <a href="myleagues.php" class="back-link">Go Back to My Leagues</a>
    </div>
</body>
</html>