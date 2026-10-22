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
$course_id   = intval($_POST['course_id'] ?? 0);
$branch_name = trim($_POST['branch_name'] ?? '');

if ($course_id <= 0 || $branch_name === '') {
    back('error', 'Choose a course and enter a branch name.');
}

$chk = $conn->prepare("SELECT id FROM courses WHERE id = ? AND college_id = ?");
$chk->bind_param("ii", $course_id, $college_id);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
    $chk->close();
    back('error', 'Course not found.');
}
$chk->close();

$stmt = $conn->prepare("INSERT INTO branches (course_id, branch_name) VALUES (?, ?)");
$stmt->bind_param("is", $course_id, $branch_name);
$stmt->execute();
$stmt->close();
$conn->close();

back('success', 'Branch added successfully.');
