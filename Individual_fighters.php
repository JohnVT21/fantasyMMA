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

// Check if leagueid and fighterid are provided
if (!isset($_GET['leagueid']) || !is_numeric($_GET['leagueid']) || !isset($_GET['fighterid']) || !is_numeric($_GET['fighterid'])) {
    header("Location: myleagues.php");
    exit;
}

$leagueID = intval($_GET['leagueid']);
$fighterID = intval($_GET['fighterid']);

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

// Fetch fighter details
$fighter = null;
$sqlFighter = "SELECT * FROM Fighters WHERE FighterID = ?";
if ($stmtFighter = $conn->prepare($sqlFighter)) {
    $stmtFighter->bind_param("i", $fighterID);
    $stmtFighter->execute();
    $resultFighter = $stmtFighter->get_result();
    if ($rowFighter = $resultFighter->fetch_assoc()) {
        $fighter = $rowFighter;
    }
    $stmtFighter->close();
} else {
    $error = "Error preparing statement: " . $conn->error;
}

if (!$fighter) {
    $error = "Fighter not found.";
}

// Fetch fight history
$fights = [];
$sqlFights = "SELECT ind_fight.*, e.EventName 
              FROM Individual_fight ind_fight 
              JOIN events e ON ind_fight.EventID = e.EventID 
              WHERE ind_fight.Fighter1 = ? OR ind_fight.Fighter2 = ?";
if ($stmtFights = $conn->prepare($sqlFights)) {
    $stmtFights->bind_param("ii", $fighterID, $fighterID);
    $stmtFights->execute();
    $resultFights = $stmtFights->get_result();
    while ($rowFight = $resultFights->fetch_assoc()) {
        $fights[] = $rowFight;
    }
    $stmtFights->close();
} else {
    $error = "Error preparing statement: " . $conn->error;
}

function decodeResult($result, $isFighter1) {
    if ($result == 40) return "Draw";
    if ($result == 30) return "No Contest";
    $tens = floor($result / 10);
    $ones = $result % 10;
    $method = '';
    switch ($ones) {
        case 1: $method = "Knockout"; break;
        case 2: $method = "Submission"; break;
        case 3: $method = "Decision"; break;
        default: return "Unknown";
    }
    $winner = ($tens == 1) ? 1 : (($tens == 2) ? 2 : 0);
    if ($winner == 0) return "Unknown";
    $win = (($winner == 1 && $isFighter1) || ($winner == 2 && !$isFighter1));
    return ($win ? "Win" : "Loss") . " by " . $method;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($leagueName); ?> - <?php echo htmlspecialchars($fighter['FullName']); ?></title>
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
            max-width: 1200px;
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
        .fighter-details {
            background-color: #f8f9fa;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            text-align: left;
        }
        .fighter-details h3 {
            margin: 0 0 10px 0;
            color: #1877f2;
        }
        .fighter-details p {
            margin: 5px 0;
            color: #555;
        }
        h2 {
            margin-bottom: 10px;
            color: #333;
        }
        .fight-history {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .fight-history th, .fight-history td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .fight-history th {
            background-color: #f2f2f2;
        }
        .no-fights {
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
        <h1><?php echo htmlspecialchars($leagueName); ?> - <?php echo htmlspecialchars($fighter['FullName']); ?></h1>
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
            <div class="fighter-details">
                <h3><?php echo htmlspecialchars($fighter['FullName']); ?> "<?php echo htmlspecialchars($fighter['NickName'] ?? ''); ?>"</h3>
                <p>Record: <?php echo htmlspecialchars($fighter['Wins']) . '-' . htmlspecialchars($fighter['Losses']) . '-' . htmlspecialchars($fighter['NoContests']); ?></p>
                <p>Weight Class: <?php echo htmlspecialchars($fighter['WeightClass']); ?></p>
                <p>Average Strikes: <?php echo htmlspecialchars($fighter['AvgStrikes']); ?></p>
                <p>Average Takedowns: <?php echo htmlspecialchars($fighter['AvgTakedowns']); ?></p>
            </div>
            <h2>Fight History</h2>
            <?php if (empty($fights)): ?>
                <p class="no-fights">No fight history available.</p>
            <?php else: ?>
                <table class="fight-history">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Opponent</th>
                            <th>Strikes</th>
                            <th>Significant Strikes</th>
                            <th>Takedowns</th>
							<th>Knockdowns</th>
                            <th>Control Time</th>
                            <th>Rounds Won</th>
                            <th>10-8 Rounds</th>
                            <th>Submission Attempts</th>
                            <th>Reversals</th>
                            <th>Outcome</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fights as $fight): ?>
                            <?php
                            $isFighter1 = ($fight['Fighter1'] == $fighterID);
                            $opponentID = $isFighter1 ? $fight['Fighter2'] : $fight['Fighter1'];
                            // Fetch opponent name
                            $oppSql = "SELECT FullName, NickName FROM Fighters WHERE FighterID = ?";
                            if ($oppStmt = $conn->prepare($oppSql)) {
                                $oppStmt->bind_param("i", $opponentID);
                                $oppStmt->execute();
                                $oppResult = $oppStmt->get_result();
                                if ($opponent = $oppResult->fetch_assoc()) {
                                    $oppName = htmlspecialchars($opponent['FullName']) . ($opponent['NickName'] ? ' "' . htmlspecialchars($opponent['NickName']) . '"' : '');
                                } else {
                                    $oppName = 'Unknown';
                                }
                                $oppStmt->close();
                            } else {
                                $oppName = 'Error';
                            }
                            // Get stats
                            $prefix = $isFighter1 ? 'Fighter1_' : 'Fighter2_';
                            $strikes = $fight[$prefix . 'Strikes'] ?? 'N/A';
                            $sigStrikes = $fight[$prefix . 'Significant_Strikes'] ?? 'N/A';
                            $takedowns = $fight[$prefix . 'Takedowns'] ?? 'N/A';
							$knockdowns = $fight[$prefix . 'Knockdowns'] ?? 'N/A';
                            $controlTime = $fight[$prefix . 'Control_Time'] ?? 'N/A';
                            $roundsWon = $fight[$prefix . 'Rounds_Won'] ?? 'N/A';
                            $tenEightRounds = $fight[$prefix . '10to8_Rounds'] ?? 'N/A';
                            $subAttempts = $fight[$prefix . 'Submission_Attempts'] ?? 'N/A';
                            $reversals = $fight[$prefix . 'Reversals'] ?? 'N/A';
                            // Outcome
                            $outcome = decodeResult($fight['Result'], $isFighter1);
                            // Color
                            if (strpos($outcome, 'Win') === 0) {
                                $color = 'green';
                            } elseif (strpos($outcome, 'Loss') === 0) {
                                $color = 'red';
                            } else {
                                $color = 'orange';
                            }
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($fight['EventName']); ?></td>
                                <td><?php echo $oppName; ?></td>
                                <td><?php echo htmlspecialchars($strikes); ?></td>
                                <td><?php echo htmlspecialchars($sigStrikes); ?></td>
                                <td><?php echo htmlspecialchars($takedowns); ?></td>
								<td><?php echo htmlspecialchars($knockdowns); ?></td>
                                <td><?php echo htmlspecialchars($controlTime); ?></td>
                                <td><?php echo htmlspecialchars($roundsWon); ?></td>
                                <td><?php echo htmlspecialchars($tenEightRounds); ?></td>
                                <td><?php echo htmlspecialchars($subAttempts); ?></td>
                                <td><?php echo htmlspecialchars($reversals); ?></td>
                                <td style="color: <?php echo $color; ?>;"><?php echo htmlspecialchars($outcome); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
        <a href="fighters.php?leagueid=<?php echo htmlspecialchars($leagueID); ?>" class="back-link">Go Back to Fighters</a>
        <a href="myleagues.php" class="back-link">Go Back to My Leagues</a>
    </div>
</body>
</html>