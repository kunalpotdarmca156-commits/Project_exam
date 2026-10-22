<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.html');
    exit;
}

function back($status, $msg) {
    header('Location: admin_dashboard.php?status=' . $status . '&msg=' . urlencode($msg) . '&tab=students');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_dashboard.php');
    exit;
}

$college_id = $_SESSION['college_id'];
$admin_id   = $_SESSION['admin_id'];

$full_name   = trim($_POST['full_name'] ?? '');
$roll_number = trim($_POST['roll_number'] ?? '');
$email       = trim($_POST['email'] ?? '');
$password    = $_POST['password'] ?? '';

if ($full_name === '' || $roll_number === '' || $password === '') {
    back('error', 'Please fill in the student\'s name, roll number and password.');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back('error', 'Enter a valid student email, or leave it blank.');
}
if (strlen($password) < 6) {
    back('error', 'Student password must be at least 6 characters.');
}

$check = $conn->prepare("SELECT id FROM students WHERE roll_number = ? AND college_id = ?");
$check->bind_param("si", $roll_number, $college_id);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $check->close();
    back('error', 'A student with this roll number already exists in your college.');
}
$check->close();

$hashed = password_hash($password, PASSWORD_DEFAULT);
$emailValue = $email !== '' ? $email : null;

$stmt = $conn->prepare("INSERT INTO students (college_id, added_by_admin_id, full_name, roll_number, email, password) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("iissss", $college_id, $admin_id, $full_name, $roll_number, $emailValue, $hashed);
$stmt->execute();
$stmt->close();
$conn->close();

back('success', 'Student added successfully.');
