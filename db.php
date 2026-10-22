<?php
// ============================================
// Database connection — default XAMPP MySQL settings.
// Change these only if you changed your XAMPP MySQL config.
// ============================================
$host = "localhost";
$db_user = "root";
$db_pass = "";       // XAMPP's default MySQL password is empty
$dbname = "crustexams";

$conn = new mysqli($host, $db_user, $db_pass, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error .
        " — make sure XAMPP's MySQL is running and the 'crustexams' database has been imported.");
}

$conn->set_charset("utf8mb4");
?>