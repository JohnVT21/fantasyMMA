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

if (!$isLeagueManager) {
    header("Location: leaguehome.php?leagueid=$leagueID");
    exit;
}

// Fetch league name and current status
$leagueName = '';
$privOrPub = 0;
$sqlLeague = "SELECT LeagueName, PrivOrPub FROM Leagues WHERE LeagueID = ?";
if ($stmtLeague = $conn->prepare($sqlLeague)) {
    $stmtLeague->bind_param("i", $leagueID);
    $stmtLeague->execute();
    $resultLeague = $stmtLeague->get_result();
    if ($rowLeague = $resultLeague->fetch_assoc()) {
        $leagueName = $rowLeague['LeagueName'];
        $privOrPub = $rowLeague['PrivOrPub'];
    } else {
        header("Location: myleagues.php");
        exit;
    }
    $stmtLeague->close();
} else {
    $error = "Error preparing statement: " . $conn->error;
}

// Fetch members (excluding the manager) for removal
$members = [];
$sqlMembers = "SELECT u.UserID, u.UserName, ft.TeamName
               FROM Users u
               JOIN Fantasy_Team ft ON u.UserID = ft.UserID
               WHERE ft.LeagueID = ? AND ft.LeagueManager = 0";
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

// Handle form submissions
$message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['change_status'])) {
        $newPrivOrPub = (int)$_POST['privOrPub'];
        $password = isset($_POST['password']) ? trim($_POST['password']) : '';

        // Validate password for private leagues
        if ($newPrivOrPub === 1 && empty($password)) {
            $message = "Password is required for private leagues.";
        } else {
            $conn->begin_transaction();
            try {
                $passwordHash = $newPrivOrPub === 1 ? password_hash($password, PASSWORD_DEFAULT) : NULL;
                $updateSql = "UPDATE Leagues SET PrivOrPub = ?, Password = ? WHERE LeagueID = ?";
                if ($stmt = $conn->prepare($updateSql)) {
                    $stmt->bind_param("isi", $newPrivOrPub, $passwordHash, $leagueID);
                    if ($stmt->execute()) {
                        $conn->commit();
                        $message = "League status updated successfully.";
                        $privOrPub = $newPrivOrPub; // Update local variable for form
                    } else {
                        throw new Exception("Error updating status: " . $stmt->error);
                    }
                    $stmt->close();
                } else {
                    throw new Exception("Error preparing statement: " . $conn->error);
                }
            } catch (Exception $e) {
                $conn->rollback();
                $message = $e->getMessage();
            }
        }
    } elseif (isset($_POST['remove_user'])) {
        $userIDToRemove = (int)$_POST['userID'];
        $conn->begin_transaction();
        try {
            // Delete from Picks_belong for the user's team
            $teamSql = "SELECT TeamID FROM Fantasy_Team WHERE UserID = ? AND LeagueID = ?";
            if ($stmt = $conn->prepare($teamSql)) {
                $stmt->bind_param("ii", $userIDToRemove, $leagueID);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $teamID = $row['TeamID'];
                    $deletePicksSql = "DELETE FROM Picks_belong WHERE TeamID = ?";
                    if ($stmtPicks = $conn->prepare($deletePicksSql)) {
                        $stmtPicks->bind_param("i", $teamID);
                        $stmtPicks->execute();
                        $stmtPicks->close();
                    }
                }
                $stmt->close();
            }

            // Delete the user's team
            $deleteTeamSql = "DELETE FROM Fantasy_Team WHERE UserID = ? AND LeagueID = ?";
            if ($stmt = $conn->prepare($deleteTeamSql)) {
                $stmt->bind_param("ii", $userIDToRemove, $leagueID);
                if (!$stmt->execute()) {
                    throw new Exception("Error removing user: " . $stmt->error);
                }
                $stmt->close();
            } else {
                throw new Exception("Error preparing statement: " . $conn->error);
            }

            // Update CurrTeams
            $updateSql = "UPDATE Leagues SET CurrTeams = CurrTeams - 1 WHERE LeagueID = ?";
            if ($stmt = $conn->prepare($updateSql)) {
                $stmt->bind_param("i", $leagueID);
                if (!$stmt->execute()) {
                    throw new Exception("Error updating CurrTeams: " . $stmt->error);
                }
                $stmt->close();
            } else {
                throw new Exception("Error preparing statement: " . $conn->error);
            }

            $conn->commit();
            $message = "User removed successfully.";
            // Refresh members list
            $members = [];
            if ($stmtMembers = $conn->prepare($sqlMembers)) {
                $stmtMembers->bind_param("i", $leagueID);
                $stmtMembers->execute();
                $resultMembers = $stmtMembers->get_result();
                while ($rowMember = $resultMembers->fetch_assoc()) {
                    $members[] = $rowMember;
                }
                $stmtMembers->close();
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = $e->getMessage();
        }
    } elseif (isset($_POST['delete_league'])) {
        $conn->begin_transaction();
        try {
            // Delete from Picks_belong for all teams in the league
            $deletePicksSql = "DELETE FROM Picks_belong WHERE TeamID IN (SELECT TeamID FROM Fantasy_Team WHERE LeagueID = ?)";
            if ($stmt = $conn->prepare($deletePicksSql)) {
                $stmt->bind_param("i", $leagueID);
                $stmt->execute();
                $stmt->close();
            }

            // Delete all teams in the league
            $deleteTeamsSql = "DELETE FROM Fantasy_Team WHERE LeagueID = ?";
            if ($stmt = $conn->prepare($deleteTeamsSql)) {
                $stmt->bind_param("i", $leagueID);
                if (!$stmt->execute()) {
                    throw new Exception("Error deleting teams: " . $stmt->error);
                }
                $stmt->close();
            } else {
                throw new Exception("Error preparing statement: " . $conn->error);
            }

            // Delete the league
            $deleteLeagueSql = "DELETE FROM Leagues WHERE LeagueID = ?";
            if ($stmt = $conn->prepare($deleteLeagueSql)) {
                $stmt->bind_param("i", $leagueID);
                if (!$stmt->execute()) {
                    throw new Exception("Error deleting league: " . $stmt->error);
                }
                $stmt->close();
            } else {
                throw new Exception("Error preparing statement: " . $conn->error);
            }

            $conn->commit();
            header("Location: myleagues.php");
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($leagueName); ?> - Manager Tools</title>
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
            background-color: #f0f2f5;
        }
        .manager-tools-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 80%;
            max-width: 800px;
            margin-top: 20px;
            text-align: center;
        }
        .manager-tools-container h1 {
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
        .form-section {
            margin-bottom: 30px;
            text-align: left;
        }
        .form-section h2 {
            color: #333;
            margin-bottom: 15px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #555;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .radio-group {
            display: flex;
            gap: 20px;
        }
        .submit-button, .delete-button {
            background-color: #1877f2;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 10px;
        }
        .submit-button:hover {
            background-color: #165db7;
        }
        .delete-button {
            background-color: #dc3545;
        }
        .delete-button:hover {
            background-color: #c82333;
        }
        .member {
            background-color: #f8f9fa;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .member p {
            margin: 5px 0;
            color: #555;
        }
        .no-members {
            color: #555;
            font-size: 16px;
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
        #passwordField {
            display: <?php echo $privOrPub ? 'block' : 'none'; ?>;
        }
    </style>
</head>
<body>
    <div class="manager-tools-container">
        <h1><?php echo htmlspecialchars($leagueName); ?> - Manager Tools</h1>
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
        <!-- Change League Status -->
        <div class="form-section">
            <h2>Change League Status</h2>
            <form method="POST" action="">
                <div class="form-group">
                    <label>League Type</label>
                    <div class="radio-group">
                        <label><input type="radio" name="privOrPub" value="0" <?php echo $privOrPub == 0 ? 'checked' : ''; ?> required> Public</label>
                        <label><input type="radio" name="privOrPub" value="1" <?php echo $privOrPub == 1 ? 'checked' : ''; ?>> Private</label>
                    </div>
                </div>
                <div class="form-group" id="passwordField">
                    <label for="password">Password (required for private leagues)</label>
                    <input type="password" id="password" name="password">
                </div>
                <button type="submit" name="change_status" class="submit-button">Update Status</button>
            </form>
        </div>
        <!-- Remove Users -->
        <div class="form-section">
            <h2>Remove Users</h2>
            <?php if (empty($members)): ?>
                <p class="no-members">No members available to remove.</p>
            <?php else: ?>
                <?php foreach ($members as $member): ?>
                    <div class="member">
                        <div>
                            <p><strong>Username:</strong> <?php echo htmlspecialchars($member['UserName']); ?></p>
                            <p><strong>Team:</strong> <?php echo htmlspecialchars($member['TeamName']); ?></p>
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="userID" value="<?php echo $member['UserID']; ?>">
                            <button type="submit" name="remove_user" class="delete-button">Remove</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
		<!-- Start Draft Section -->
        <div class="form-section">
            <h2>Start Draft</h2>
            <form method="POST" action="leagueDraft.php?leagueid=<?php echo htmlspecialchars($leagueID); ?>">
                <p>Start the draft for all teams in the league.</p>
                <button type="submit" name="start_draft" class="submit-button">Start Draft</button>
            </form>
        </div>
        <!-- Delete League -->
        <div class="form-section">
            <h2>Delete League</h2>
            <form method="POST" action="">
                <p>Warning: This will permanently delete the league and all associated data.</p>
                <button type="submit" name="delete_league" class="delete-button">Delete League</button>
            </form>
        </div>
        <a href="leaguehome.php?leagueid=<?php echo htmlspecialchars($leagueID); ?>" class="back-link">Go Back to League Home</a>
    </div>
    <script>
        document.querySelectorAll('input[name="privOrPub"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const passwordField = document.getElementById('passwordField');
                if (this.value === '1') {
                    passwordField.style.display = 'block';
                    document.getElementById('password').required = true;
                } else {
                    passwordField.style.display = 'none';
                    document.getElementById('password').required = false;
                }
            });
        });
    </script>
</body>
</html>