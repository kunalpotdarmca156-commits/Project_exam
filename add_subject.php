<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: login.html');
    exit;
}

function back($status, $msg) {
    header('Location: teacher_dashboard.php?status=' . $status . '&msg=' . urlencode($msg) . '&tab=subjects');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: teacher_dashboard.php');
    exit;
}

$teacher_id   = $_SESSION['teacher_id'];
$college_id   = $_SESSION['college_id'];
$subject_name = trim($_POST['subject_name'] ?? '');
$course_id    = intval($_POST['course_id'] ?? 0);
$branch_id    = intval($_POST['branch_id'] ?? 0);

if ($subject_name === '' || $course_id <= 0) {
    back('error', 'Enter a subject name and choose a course.');
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

$branchParam = $branch_id > 0 ? $branch_id : null;

// prevent duplicate subject on the same course+branch (NULL-safe check done in PHP)
$dupSql = "SELECT id FROM subjects WHERE college_id = ? AND course_id = ? AND subject_name = ? AND " .
          ($branchParam === null ? "branch_id IS NULL" : "branch_id = ?");
$dup = $conn->prepare($dupSql);
if ($branchParam === null) {
    $dup->bind_param("iis", $college_id, $course_id, $subject_name);
} else {
    $dup->bind_param("iisi", $college_id, $course_id, $subject_name, $branchParam);
}
$dup->execute();
$dup->store_result();
if ($dup->num_rows > 0) {
    $dup->close();
    back('error', 'This subject already exists for that course/branch.');
}
$dup->close();

$stmt = $conn->prepare("INSERT INTO subjects (college_id, course_id, branch_id, subject_name, created_by_teacher_id) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("iiisi", $college_id, $course_id, $branchParam, $subject_name, $teacher_id);
$stmt->execute();
$stmt->close();
$conn->close();

back('success', 'Subject added successfully.');
