<?php
// Database configuration
$servername = "localhost";
$dbUsername = "root";
$dbPassword = "";
$dbname = "fantasymma";

// Create a MySQLi connection
$conn = new mysqli($servername, $dbUsername, $dbPassword, $dbname);

// Check the connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>

