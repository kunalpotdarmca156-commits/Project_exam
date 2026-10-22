<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.html');
    exit;
}

function back($status, $msg) {
    header('Location: admin_dashboard.php?status=' . $status . '&msg=' . urlencode($msg) . '&tab=profile');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_dashboard.php');
    exit;
}

$college_id = $_SESSION['college_id'];
$admin_id   = $_SESSION['admin_id'];

$college_name = trim($_POST['college_name'] ?? '');
$college_code = trim($_POST['college_code'] ?? '');
$phone        = trim($_POST['phone'] ?? '');
$admin_name   = trim($_POST['admin_name'] ?? '');
$admin_email  = trim($_POST['admin_email'] ?? '');
$new_password = $_POST['new_password'] ?? '';

if ($college_name === '' || $phone === '' || $admin_name === '' || $admin_email === '') {
    back('error', 'Please fill in all required fields.');
}
if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
    back('error', 'Enter a valid admin email.');
}

// email must stay unique among admins
$check = $conn->prepare("SELECT id FROM admins WHERE email = ? AND id <> ?");
$check->bind_param("si", $admin_email, $admin_id);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $check->close();
    back('error', 'Another account already uses this email.');
}
$check->close();

$stmt1 = $conn->prepare("UPDATE colleges SET college_name = ?, college_code = ?, phone = ? WHERE id = ?");
$stmt1->bind_param("sssi", $college_name, $college_code, $phone, $college_id);
$stmt1->execute();
$stmt1->close();

if ($new_password !== '') {
    if (strlen($new_password) < 6) {
        back('error', 'New password must be at least 6 characters.');
    }
    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt2 = $conn->prepare("UPDATE admins SET full_name = ?, email = ?, password = ? WHERE id = ?");
    $stmt2->bind_param("sssi", $admin_name, $admin_email, $hashed, $admin_id);
} else {
    $stmt2 = $conn->prepare("UPDATE admins SET full_name = ?, email = ? WHERE id = ?");
    $stmt2->bind_param("ssi", $admin_name, $admin_email, $admin_id);
}
$stmt2->execute();
$stmt2->close();

$_SESSION['admin_name'] = $admin_name;
$conn->close();

back('success', 'Profile updated successfully.');
