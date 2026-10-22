<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.html');
    exit;
}

function back($status, $msg) {
    header('Location: admin_dashboard.php?status=' . $status . '&msg=' . urlencode($msg) . '&tab=teachers');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_dashboard.php');
    exit;
}

$college_id = $_SESSION['college_id'];
$admin_id   = $_SESSION['admin_id'];

$full_name = trim($_POST['full_name'] ?? '');
$email     = trim($_POST['email'] ?? '');
$password  = $_POST['password'] ?? '';

if ($full_name === '' || $email === '' || $password === '') {
    back('error', 'Please fill in all teacher fields.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back('error', 'Enter a valid teacher email.');
}
if (strlen($password) < 6) {
    back('error', 'Teacher password must be at least 6 characters.');
}

$check = $conn->prepare("SELECT id FROM teachers WHERE email = ?");
$check->bind_param("s", $email);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $check->close();
    back('error', 'A teacher with this email already exists.');
}
$check->close();

$hashed = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO teachers (college_id, added_by_admin_id, full_name, email, password) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("iisss", $college_id, $admin_id, $full_name, $email, $hashed);
$stmt->execute();
$stmt->close();
$conn->close();

back('success', 'Teacher added successfully.');
