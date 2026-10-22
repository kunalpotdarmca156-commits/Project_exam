<?php
session_start();
require_once 'db.php';

function login_redirect($status, $message) {
    header('Location: login.html?status=' . $status . '&msg=' . urlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.html');
    exit;
}

$role       = $_POST['role'] ?? 'admin';
$identifier = trim($_POST['identifier'] ?? '');
$password   = $_POST['password'] ?? '';

if ($identifier === '' || $password === '') {
    login_redirect('error', 'Please enter your credentials.');
}

if ($role === 'admin') {
    $stmt = $conn->prepare("SELECT id, college_id, full_name, password FROM admins WHERE email = ?");
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        login_redirect('error', 'No admin account found with that email.');
    }

    $admin = $result->fetch_assoc();
    $stmt->close();

    if (!password_verify($password, $admin['password'])) {
        login_redirect('error', 'Incorrect password.');
    }

    $_SESSION['admin_id']    = $admin['id'];
    $_SESSION['college_id']  = $admin['college_id'];
    $_SESSION['admin_name']  = $admin['full_name'];

    $conn->close();
    header('Location: admin_dashboard.php');
    exit;

} elseif ($role === 'teacher') {
    $stmt = $conn->prepare("SELECT id, college_id, full_name, password FROM teachers WHERE email = ?");
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        login_redirect('error', 'No teacher account found with that email.');
    }

    $teacher = $result->fetch_assoc();
    $stmt->close();

    if (!password_verify($password, $teacher['password'])) {
        login_redirect('error', 'Incorrect password.');
    }

    $_SESSION['teacher_id']   = $teacher['id'];
    $_SESSION['college_id']   = $teacher['college_id'];
    $_SESSION['teacher_name'] = $teacher['full_name'];

    $conn->close();
    header('Location: teacher_dashboard.php');
    exit;

} elseif ($role === 'student') {
    $stmt = $conn->prepare("SELECT id, college_id, full_name, password FROM students WHERE roll_number = ? OR email = ? LIMIT 1");
    $stmt->bind_param("ss", $identifier, $identifier);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        login_redirect('error', 'No student account found with that roll number or email.');
    }

    $student = $result->fetch_assoc();
    $stmt->close();

    if (!password_verify($password, $student['password'])) {
        login_redirect('error', 'Incorrect password.');
    }

    $_SESSION['student_id']   = $student['id'];
    $_SESSION['college_id']   = $student['college_id'];
    $_SESSION['student_name'] = $student['full_name'];

    $conn->close();
    header('Location: student_dashboard.php');
    exit;

} else {
    login_redirect('error', 'Unknown login type.');
}
