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
$id         = intval($_POST['teacher_id'] ?? 0);
$full_name  = trim($_POST['full_name'] ?? '');
$email      = trim($_POST['email'] ?? '');
$password   = $_POST['password'] ?? '';

if ($id <= 0 || $full_name === '' || $email === '') {
    back('error', "Please fill in the teacher's name and email.");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back('error', 'Enter a valid teacher email.');
}

$check = $conn->prepare("SELECT id FROM teachers WHERE email = ? AND id <> ?");
$check->bind_param("si", $email, $id);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $check->close();
    back('error', 'Another teacher already uses this email.');
}
$check->close();

if ($password !== '') {
    if (strlen($password) < 6) {
        back('error', 'New password must be at least 6 characters.');
    }
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE teachers SET full_name = ?, email = ?, password = ? WHERE id = ? AND college_id = ?");
    $stmt->bind_param("sssii", $full_name, $email, $hashed, $id, $college_id);
} else {
    $stmt = $conn->prepare("UPDATE teachers SET full_name = ?, email = ? WHERE id = ? AND college_id = ?");
    $stmt->bind_param("ssii", $full_name, $email, $id, $college_id);
}
$stmt->execute();
$stmt->close();
$conn->close();

back('success', 'Teacher updated successfully.');
