<?php
require_once 'DBconnect.php';

session_start();

// Prevent caching
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
} else {
    $message = "Error checking league manager status: " . $conn->error;
}

if (!$isLeagueManager) {
    header("Location: leaguehome.php?leagueid=$leagueID");
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
    } else {
        header("Location: myleagues.php");
        exit;
    }
    $stmtLeague->close();
} else {
    $message = "Error fetching league name: " . $conn->error;
}

// Fetch all teams in the league
$teams = [];
$sqlTeams = "SELECT TeamID, TeamName, UserID FROM Fantasy_Team WHERE LeagueID = ?";
if ($stmtTeams = $conn->prepare($sqlTeams)) {
    $stmtTeams->bind_param("i", $leagueID);
    $stmtTeams->execute();
    $resultTeams = $stmtTeams->get_result();
    while ($rowTeam = $resultTeams->fetch_assoc()) {
        $teams[] = $rowTeam;
    }
    $stmtTeams->close();
} else {
    $message = "Error fetching teams: " . $conn->error;
}

// Initialize draft state if not already set
if (!isset($_SESSION['draft_state'])) {
    // Randomize team order
    shuffle($teams);
    $_SESSION['draft_state'] = [
        'leagueID' => $leagueID,
        'teams' => $teams,
        'current_team_index' => 0,
        'picks_made' => 0,
        'total_teams' => count($teams),
        'draft_start_time' => time(),
        'current_team_start_time' => time()
    ];
} else {
    // Ensure draft state matches the current league
    if ($_SESSION['draft_state']['leagueID'] != $leagueID) {
        unset($_SESSION['draft_state']);
        header("Location: leagueDraft.php?leagueid=$leagueID");
        exit;
    }
}

// Fetch available fighters (not yet picked in this league)
$availableFighters = [];
$sqlFighters = "SELECT FighterID, FullName, WeightClass, Wins, Losses, NoContests
                FROM Fighters
                WHERE FighterID NOT IN (
                    SELECT FighterID FROM Picks_belong
                    WHERE TeamID IN (SELECT TeamID FROM Fantasy_Team WHERE LeagueID = ?)
                )";
if ($stmtFighters = $conn->prepare($sqlFighters)) {
    $stmtFighters->bind_param("i", $leagueID);
    $stmtFighters->execute();
    $resultFighters = $stmtFighters->get_result();
    while ($rowFighter = $resultFighters->fetch_assoc()) {
        $availableFighters[] = $rowFighter;
    }
    $stmtFighters->close();
} else {
    $message = "Error fetching fighters: " . $conn->error;
}

// Handle draft pick submission
$message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['select_fighter'])) {
    $fighterID = intval($_POST['fighterID']);
    $currentTeamIndex = $_SESSION['draft_state']['current_team_index'];
    $teamID = $_SESSION['draft_state']['teams'][$currentTeamIndex]['TeamID'];

    // Verify fighter is still available
    $sqlCheck = "SELECT COUNT(*) as count FROM Picks_belong WHERE FighterID = ? AND TeamID IN (SELECT TeamID FROM Fantasy_Team WHERE LeagueID = ?)";
    if ($stmtCheck = $conn->prepare($sqlCheck)) {
        $stmtCheck->bind_param("ii", $fighterID, $leagueID);
        $stmtCheck->execute();
        $resultCheck = $stmtCheck->get_result();
        $rowCheck = $resultCheck->fetch_assoc();
        if ($rowCheck['count'] > 0) {
            $message = "This fighter has already been selected.";
            $stmtCheck->close();
        } else {
            // Insert pick into Picks_belong, omitting picks2ID to allow auto-increment
            $conn->begin_transaction();
            try {
                // Fixed: Removed picks2ID from INSERT to let it auto-increment
                $sqlInsert = "INSERT INTO Picks_belong (TeamID, FighterID) VALUES (?, ?)";
                if ($stmtInsert = $conn->prepare($sqlInsert)) {
                    $stmtInsert->bind_param("ii", $teamID, $fighterID);
                    if ($stmtInsert->execute()) {
                        $_SESSION['draft_state']['picks_made']++;
                        $_SESSION['draft_state']['current_team_index']++;
                        $_SESSION['draft_state']['current_team_start_time'] = time();
                        $message = "Fighter selected successfully.";
                        $conn->commit();

                        // Check if draft is complete
                        if ($_SESSION['draft_state']['picks_made'] >= $_SESSION['draft_state']['total_teams']) {
                            unset($_SESSION['draft_state']);
                            header("Location: leaguehome.php?leagueid=$leagueID");
                            exit;
                        }
                    } else {
                        throw new Exception("Error saving pick: " . $stmtInsert->error);
                    }
                    $stmtInsert->close();
                } else {
                    throw new Exception("Error preparing insert statement: " . $conn->error);
                }
            } catch (Exception $e) {
                $conn->rollback();
                $message = "Failed to save pick: " . $e->getMessage();
            }
            $stmtCheck->close();
        }
    } else {
        $message = "Error preparing check statement: " . $conn->error;
    }
}

// Handle timer expiration (via AJAX or page refresh)
$currentTime = time();
$timeElapsed = $currentTime - $_SESSION['draft_state']['current_team_start_time'];
if ($timeElapsed >= 45 && $_SESSION['draft_state']['picks_made'] < $_SESSION['draft_state']['total_teams']) {
    // Move to next team without a pick
    $_SESSION['draft_state']['current_team_index']++;
    $_SESSION['draft_state']['current_team_start_time'] = time();
    if ($_SESSION['draft_state']['current_team_index'] >= $_SESSION['draft_state']['total_teams']) {
        unset($_SESSION['draft_state']);
        header("Location: leaguehome.php?leagueid=$leagueID");
        exit;
    }
    header("Location: leagueDraft.php?leagueid=$leagueID");
    exit;
}

$currentTeamIndex = $_SESSION['draft_state']['current_team_index'];
$currentTeam = isset($_SESSION['draft_state']['teams'][$currentTeamIndex]) ? $_SESSION['draft_state']['teams'][$currentTeamIndex] : null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($leagueName); ?> - Draft</title>
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
        .draft-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 80%;
            max-width: 800px;
            margin-top: 20px;
            text-align: center;
        }
        .draft-container h1 {
            margin-bottom: 20px;
            color: #333;
        }
        .timer {
            font-size: 24px;
            color: #dc3545;
            margin-bottom: 20px;
        }
        .current-team {
            font-size: 18px;
            margin-bottom: 20px;
            color: #555;
        }
        .fighter-list {
            margin-bottom: 30px;
            text-align: left;
        }
        .fighter {
            background-color: #f8f9fa;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .fighter p {
            margin: 5px 0;
            color: #555;
        }
        .submit-button {
            background-color: #1877f2;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .submit-button:hover {
            background-color: #165db7;
        }
        .error, .success {
            font-size: 14px;
            margin: 10px 0;
        }
        .error {
            color: #dc3545;
        }
        .success {
            color: #28a745;
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
    <div class="draft-container">
        <h1><?php echo htmlspecialchars($leagueName); ?> - Draft</h1>
        <?php if (!empty($message)): ?>
            <div class="<?php echo strpos($message, 'successfully') !== false ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <?php if ($currentTeam): ?>
            <div class="current-team">
                <p>Current Team: <?php echo htmlspecialchars($currentTeam['TeamName']); ?></p>
            </div>
            <div class="timer">
                Time Remaining: <span id="timer">45</span> seconds
            </div>
            <div class="fighter-list">
                <h2>Available Fighters</h2>
                <?php if (empty($availableFighters)): ?>
                    <p>No fighters available to draft.</p>
                <?php else: ?>
                    <?php foreach ($availableFighters as $fighter): ?>
                        <div class="fighter">
                            <div>
                                <p><strong>Name:</strong> <?php echo htmlspecialchars($fighter['FullName']); ?></p>
                                <p><strong>Weight Class:</strong> <?php echo htmlspecialchars($fighter['WeightClass']); ?></p>
                                <p><strong>Record:</strong> <?php echo $fighter['Wins'] . '-' . $fighter['Losses'] . '-' . $fighter['NoContests']; ?></p>
                            </div>
                            <form method="POST" action="">
                                <input type="hidden" name="fighterID" value="<?php echo $fighter['FighterID']; ?>">
                                <button type="submit" name="select_fighter" class="submit-button">Select Fighter</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p>Draft completed or no teams available.</p>
        <?php endif; ?>
        <a href="leaguehome.php?leagueid=<?php echo htmlspecialchars($leagueID); ?>" class="back-link">Go Back to League Home</a>
    </div>
    <script>
        let timeLeft = <?php echo 45 - $timeElapsed; ?>;
        const timerElement = document.getElementById('timer');
        if (timerElement) {
            const timerInterval = setInterval(() => {
                timeLeft--;
                timerElement.textContent = timeLeft;
                if (timeLeft <= 0) {
                    clearInterval(timerInterval);
                    window.location.reload(); // Refresh to move to next team
                }
            }, 1000);
        }
    </script>
</body>
</html>