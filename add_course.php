<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.html');
    exit;
}

function back($status, $msg) {
    header('Location: admin_dashboard.php?status=' . $status . '&msg=' . urlencode($msg) . '&tab=courses');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_dashboard.php');
    exit;
}

$college_id  = $_SESSION['college_id'];
$course_name = trim($_POST['course_name'] ?? '');

if ($course_name === '') {
    back('error', 'Enter a course name.');
}

$check = $conn->prepare("SELECT id FROM courses WHERE college_id = ? AND course_name = ?");
$check->bind_param("is", $college_id, $course_name);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $check->close();
    back('error', 'This course already exists.');
}
$check->close();

$stmt = $conn->prepare("INSERT INTO courses (college_id, course_name) VALUES (?, ?)");
$stmt->bind_param("is", $college_id, $course_name);
$stmt->execute();
$stmt->close();
$conn->close();

back('success', 'Course added successfully.');
