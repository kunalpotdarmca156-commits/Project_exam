<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.html');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_dashboard.php');
    exit;
}

$college_id = $_SESSION['college_id'];

$stmt = $conn->prepare("DELETE FROM colleges WHERE id = ?");
$stmt->bind_param("i", $college_id);
$stmt->execute();
$stmt->close();
$conn->close();

session_unset();
session_destroy();
header('Location: index.html');
exit;
