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

$college_id  = $_SESSION['college_id'];
$id          = intval($_POST['student_id'] ?? 0);
$full_name   = trim($_POST['full_name'] ?? '');
$roll_number = trim($_POST['roll_number'] ?? '');
$email       = trim($_POST['email'] ?? '');
$password    = $_POST['password'] ?? '';

if ($id <= 0 || $full_name === '' || $roll_number === '') {
    back('error', "Please fill in the student's name and roll number.");
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back('error', 'Enter a valid student email, or leave it blank.');
}

$check = $conn->prepare("SELECT id FROM students WHERE roll_number = ? AND college_id = ? AND id <> ?");
$check->bind_param("sii", $roll_number, $college_id, $id);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $check->close();
    back('error', 'Another student already uses this roll number.');
}
$check->close();

$emailValue = $email !== '' ? $email : null;

if ($password !== '') {
    if (strlen($password) < 6) {
        back('error', 'New password must be at least 6 characters.');
    }
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE students SET full_name = ?, roll_number = ?, email = ?, password = ? WHERE id = ? AND college_id = ?");
    $stmt->bind_param("ssssii", $full_name, $roll_number, $emailValue, $hashed, $id, $college_id);
} else {
    $stmt = $conn->prepare("UPDATE students SET full_name = ?, roll_number = ?, email = ? WHERE id = ? AND college_id = ?");
    $stmt->bind_param("sssii", $full_name, $roll_number, $emailValue, $id, $college_id);
}
$stmt->execute();
$stmt->close();
$conn->close();

back('success', 'Student updated successfully.');
