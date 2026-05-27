<?php
require_once 'DBconnect.php';

session_start();

// 1. Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// 2. Check if leagueid is provided
if (!isset($_GET['leagueid']) || !is_numeric($_GET['leagueid'])) {
    header("Location: myleagues.php");
    exit;
}

$leagueID = intval($_GET['leagueid']);

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] == 1;

// 3. Check if user has a team in the league
$userTeamID = null;
$isLeagueManager = false;
$sqlUserTeam = "SELECT TeamID, LeagueManager FROM Fantasy_Team WHERE UserID = ? AND LeagueID = ?";
if ($stmtUserTeam = $conn->prepare($sqlUserTeam)) {
    $stmtUserTeam->bind_param("ii", $_SESSION['userID'], $leagueID);
    $stmtUserTeam->execute();
    $resultUserTeam = $stmtUserTeam->get_result();
    if ($rowUserTeam = $resultUserTeam->fetch_assoc()) {
        $userTeamID = $rowUserTeam['TeamID'];
        $isLeagueManager = $rowUserTeam['LeagueManager'] == 1;
    }
    $stmtUserTeam->close();
}

if ($userTeamID === null) {
    header("Location: myleagues.php");
    exit;
}

// 4. Fetch league name and draft state
$leagueName = '';
$draftActive = 0;
$draftOrderJson = '';
$currentTurn = 0;
$picksMade = 0;
$currentTurnStartTime = 0;
$sqlLeague = "SELECT LeagueName, DraftActive, DraftOrder, CurrentTurn, PicksMade, CurrentTurnStartTime FROM Leagues WHERE LeagueID = ?";
if ($stmtLeague = $conn->prepare($sqlLeague)) {
    $stmtLeague->bind_param("i", $leagueID);
    $stmtLeague->execute();
    $resultLeague = $stmtLeague->get_result();
    if ($rowLeague = $resultLeague->fetch_assoc()) {
        $leagueName = $rowLeague['LeagueName'];
        $draftActive = $rowLeague['DraftActive'];
        $draftOrderJson = $rowLeague['DraftOrder'];
        $currentTurn = $rowLeague['CurrentTurn'];
        $picksMade = $rowLeague['PicksMade'];
        $currentTurnStartTime = $rowLeague['CurrentTurnStartTime'];
    } else {
        header("Location: myleagues.php");
        exit;
    }
    $stmtLeague->close();
} else {
    $message = "Error fetching league name: " . $conn->error;
}

// 5. Fetch all teams in the league
$teams = [];
$sqlTeams = "SELECT TeamID, TeamName, UserID FROM Fantasy_Team WHERE LeagueID = ?";
if ($stmtTeams = $conn->prepare($sqlTeams)) {
    $stmtTeams->bind_param("i", $leagueID);
    $stmtTeams->execute();
    $resultTeams = $stmtTeams->get_result();
    while ($rowTeam = $resultTeams->fetch_assoc()) {
        $teams[$rowTeam['TeamID']] = $rowTeam;  // Key by TeamID for easy access
    }
    $stmtTeams->close();
} else {
    $message = "Error fetching teams: " . $conn->error;
}

$totalTeams = count($teams);

// 6. Initialize draft if not active and user is manager
if ($draftActive == 0 && $isLeagueManager) {
    // Randomize team order
    $teamIDs = array_keys($teams);
    shuffle($teamIDs);
    $draftOrderJson = json_encode($teamIDs);

    $updateSql = "UPDATE Leagues SET DraftActive = 1, DraftOrder = ?, CurrentTurn = 0, PicksMade = 0, CurrentTurnStartTime = ? WHERE LeagueID = ?";
    if ($stmtUpdate = $conn->prepare($updateSql)) {
        $now = time();
        $stmtUpdate->bind_param("sii", $draftOrderJson, $now, $leagueID);
        $stmtUpdate->execute();
        $stmtUpdate->close();

        // Refresh variables
        $draftActive = 1;
        $currentTurn = 0;
        $picksMade = 0;
        $currentTurnStartTime = $now;
    } else {
        $message = "Error initializing draft: " . $conn->error;
    }
}

// If draft not active, show message
if ($draftActive == 0) {
    $message = "Draft has not started yet. If you are the manager, refresh to start.";
}

// 7. Decode draft order
$draftOrder = json_decode($draftOrderJson, true) ?? [];

// 8. Handle timer expiration on page load auto skip
$currentTime = time();
$timeElapsed = $currentTime - $currentTurnStartTime;
if ($draftActive && $timeElapsed >= 45 && $picksMade < $totalTeams) {  // Assuming one round for now
    // Skip to next team
    $conn->begin_transaction();
    try {
        $newCurrentTurn = ($currentTurn + 1) % $totalTeams;
        $newStartTime = time();
        $updateSql = "UPDATE Leagues SET CurrentTurn = ?, CurrentTurnStartTime = ? WHERE LeagueID = ? AND CurrentTurn = ?";  // Prevent race
        if ($stmt = $conn->prepare($updateSql)) {
            $stmt->bind_param("iiii", $newCurrentTurn, $newStartTime, $leagueID, $currentTurn);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $conn->commit();
                // Update local variables
                $currentTurn = $newCurrentTurn;
                $currentTurnStartTime = $newStartTime;
                $timeElapsed = 0;
            } else {
                $conn->rollback();
            }
            $stmt->close();
        } else {
            throw new Exception("Error preparing update: " . $conn->error);
        }
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Error skipping turn: " . $e->getMessage();
    }
}

// 9. Get current team
$currentTeamID = isset($draftOrder[$currentTurn]) ? $draftOrder[$currentTurn] : null;
$currentTeam = $currentTeamID ? ($teams[$currentTeamID] ?? null) : null;
$isMyTurn = $currentTeam && $currentTeam['UserID'] == $_SESSION['userID'];

// 10. Fetch available fighters
$availableFighters = [];
if ($draftActive) {
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
}
// 11. Ajax 
if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'draftActive' => $draftActive,
        'currentTurn' => $currentTurn,
        'picksMade' => $picksMade,
        'timeElapsed' => time() - $currentTurnStartTime,
        'currentTeam' => $currentTeam ? $currentTeam['TeamName'] : null,
        'isMyTurn' => $isMyTurn,
        'availableFighters' => $availableFighters,
        'draftComplete' => $picksMade >= $totalTeams
    ]);
    exit;
}
// 12. Handle draft pick submission
$message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['select_fighter']) && $isMyTurn && $draftActive) {
    $fighterID = intval($_POST['fighterID']);
    $teamID = $currentTeamID;

    // Verify fighter is still available and it's still my turn
    $conn->begin_transaction();
    try {
        // Check current turn still the same
        $checkSql = "SELECT CurrentTurn FROM Leagues WHERE LeagueID = ?";
        if ($stmtCheck = $conn->prepare($checkSql)) {
            $stmtCheck->bind_param("i", $leagueID);
            $stmtCheck->execute();
            $resultCheck = $stmtCheck->get_result();
            $rowCheck = $resultCheck->fetch_assoc();
            if ($rowCheck['CurrentTurn'] != $currentTurn) {
                throw new Exception("It's no longer your turn.");
            }
            $stmtCheck->close();
        }

        // Check time not expired
        $currentTime = time();
        if ($currentTime - $currentTurnStartTime >= 45) {
            throw new Exception("Time expired.");
        }

        // Check fighter available
        $sqlCheckAvail = "SELECT COUNT(*) as count FROM Picks_belong WHERE FighterID = ? AND TeamID IN (SELECT TeamID FROM Fantasy_Team WHERE LeagueID = ?)";
        if ($stmtAvail = $conn->prepare($sqlCheckAvail)) {
            $stmtAvail->bind_param("ii", $fighterID, $leagueID);
            $stmtAvail->execute();
            $resultAvail = $stmtAvail->get_result();
            $rowAvail = $resultAvail->fetch_assoc();
            if ($rowAvail['count'] > 0) {
                throw new Exception("This fighter has already been selected.");
            }
            $stmtAvail->close();
        }

        // Insert pick
        $sqlInsert = "INSERT INTO Picks_belong (TeamID, FighterID) VALUES (?, ?)";
        if ($stmtInsert = $conn->prepare($sqlInsert)) {
            $stmtInsert->bind_param("ii", $teamID, $fighterID);
            if ($stmtInsert->execute()) {
                // Update league state
                $newPicksMade = $picksMade + 1;
                $newCurrentTurn = ($currentTurn + 1) % $totalTeams;
                $newStartTime = time();
                $updateSql = "UPDATE Leagues SET PicksMade = ?, CurrentTurn = ?, CurrentTurnStartTime = ? WHERE LeagueID = ? AND PicksMade = ?";
                if ($stmtUpdate = $conn->prepare($updateSql)) {
                    $stmtUpdate->bind_param("iiiii", $newPicksMade, $newCurrentTurn, $newStartTime, $leagueID, $picksMade);
                    if ($stmtUpdate->execute() && $stmtUpdate->affected_rows > 0) {
                        $conn->commit();
                        $message = "Fighter selected successfully.";
                        // Update local
                        $picksMade = $newPicksMade;
                        $currentTurn = $newCurrentTurn;
                        $currentTurnStartTime = $newStartTime;
                        $timeElapsed = 0;

                        // Check if draft complete (assuming one round)
                        if ($picksMade >= $totalTeams) {
                            // End draft
                            $endSql = "UPDATE Leagues SET DraftActive = 0 WHERE LeagueID = ?";
                            if ($stmtEnd = $conn->prepare($endSql)) {
                                $stmtEnd->bind_param("i", $leagueID);
                                $stmtEnd->execute();
                                $stmtEnd->close();
                            }
                            header("Location: leaguehome.php?leagueid=$leagueID");
                            exit;
                        }
                    } else {
                        throw new Exception("Error updating league state.");
                    }
                    $stmtUpdate->close();
                }
            } else {
                throw new Exception("Error saving pick: " . $stmtInsert->error);
            }
            $stmtInsert->close();
        }
    } catch (Exception $e) {
        $conn->rollback();
        $message = $e->getMessage();
    }
}

// Refresh time elapsed
$timeElapsed = time() - $currentTurnStartTime;

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
        <h1><?= htmlspecialchars($leagueName) ?> - Draft</h1>
        
        <div id="message" class="message"></div>

        <div id="draft-content">
            <?php if ($draftActive && $currentTeam): ?>
                <div class="current-team">
                    <p>Current Team: <strong id="current-team-name"><?= htmlspecialchars($currentTeam['TeamName']) ?></strong></p>
                </div>
                <div class="timer">
                    Time Remaining: <span id="timer"><?= max(45 - $timeElapsed, 0) ?></span> seconds
                </div>

                <?php if ($isMyTurn): ?>
                    <div class="fighter-list" id="fighter-list">
                        <h2>Available Fighters</h2>
                        <?= renderFighters($availableFighters, $leagueID) ?>
                    </div>
                <?php else: ?>
                    <p id="not-my-turn">It's not your turn. Please wait...</p>
                <?php endif; ?>
            <?php elseif (!$draftActive): ?>
                <p>Draft has not started or is completed.</p>
            <?php endif; ?>
        </div>

        <a href="leaguehome.php?leagueid=<?= htmlspecialchars($leagueID) ?>" class="back-link">Go Back to League Home</a>
    </div>

    <script>
        let timeLeft = <?= max(45 - $timeElapsed, 0) ?>;
        let pollInterval;

        function renderFighters(fighters) {
            let html = '<h2>Available Fighters</h2>';
            if (fighters.length === 0) {
                html += '<p>No fighters available to draft.</p>';
            } else {
                fighters.forEach(f => {
                    html += `
                        <div class="fighter">
                            <div>
                                <p><strong>${f.FullName}</strong></p>
                                <p>${f.WeightClass} • ${f.Wins}-${f.Losses}-${f.NoContests}</p>
                            </div>
                            <form method="POST" action="">
                                <input type="hidden" name="fighterID" value="${f.FighterID}">
                                <button type="submit" name="select_fighter" class="submit-button">Select Fighter</button>
                            </form>
                        </div>`;
                });
            }
            return html;
        }

        function updateDraft() {
            fetch(`leagueDraft.php?leagueid=${<?= $leagueID ?>}&ajax=1`)
                .then(response => response.json())
                .then(data => {
                    if (data.draftComplete) {
                        window.location.href = `leaguehome.php?leagueid=<?= $leagueID ?>`;
                        return;
                    }

                    // Update current team
                    document.getElementById('current-team-name').textContent = data.currentTeam || 'Unknown';

                    // Update timer
                    timeLeft = Math.max(45 - data.timeElapsed, 0);
                    document.getElementById('timer').textContent = timeLeft;

                    // Update fighters if it's my turn
                    if (data.isMyTurn) {
                        document.getElementById('fighter-list').innerHTML = renderFighters(data.availableFighters);
                    } else {
                        document.getElementById('not-my-turn').style.display = 'block';
                    }

                    // Refresh page if turn changed significantly
                    if (timeLeft <= 0) {
                        window.location.reload();
                    }
                })
                .catch(err => console.error('Poll error:', err));
        }

        // Start polling
        pollInterval = setInterval(updateDraft, 3000);

        // Initial timer countdown
        const timerElement = document.getElementById('timer');
        if (timerElement) {
            setInterval(() => {
                if (timeLeft > 0) timeLeft--;
                timerElement.textContent = timeLeft;
            }, 1000);
        }
    </script>
</body>
</html>