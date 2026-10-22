<?php
require_once 'db.php';

function redirect_with_message($page, $status, $message) {
    header('Location: ' . $page . '?status=' . $status . '&msg=' . urlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.html');
    exit;
}

// ---- collect + trim input ----
$college_name     = trim($_POST['college_name'] ?? '');
$college_code     = trim($_POST['college_code'] ?? '');
$admin_name       = trim($_POST['admin_name'] ?? '');
$email            = trim($_POST['email'] ?? '');
$phone            = trim($_POST['phone'] ?? '');
$password         = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

// ---- validation ----
if ($college_name === '' || $admin_name === '' || $email === '' || $phone === '' || $password === '' || $confirm_password === '') {
    redirect_with_message('register.html', 'error', 'Please fill in all required fields.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_with_message('register.html', 'error', 'Please enter a valid email address.');
}

if ($password !== $confirm_password) {
    redirect_with_message('register.html', 'error', 'Passwords do not match.');
}

if (strlen($password) < 6) {
    redirect_with_message('register.html', 'error', 'Password must be at least 6 characters.');
}

// ---- check duplicate admin email ----
$check = $conn->prepare("SELECT id FROM admins WHERE email = ?");
$check->bind_param("s", $email);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $check->close();
    redirect_with_message('register.html', 'error', 'An account with this email already exists. Please log in instead.');
}
$check->close();

// ---- insert college + admin together ----
$conn->begin_transaction();
try {
    $stmt1 = $conn->prepare("INSERT INTO colleges (college_name, college_code, phone) VALUES (?, ?, ?)");
    $stmt1->bind_param("sss", $college_name, $college_code, $phone);
    $stmt1->execute();
    $college_id = $conn->insert_id;
    $stmt1->close();

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $stmt2 = $conn->prepare("INSERT INTO admins (college_id, full_name, email, password) VALUES (?, ?, ?, ?)");
    $stmt2->bind_param("isss", $college_id, $admin_name, $email, $hashed_password);
    $stmt2->execute();
    $stmt2->close();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    redirect_with_message('register.html', 'error', 'Something went wrong while saving your details. Please try again.');
}

$conn->close();

redirect_with_message('login.html', 'success', 'College registered successfully! Please log in.');