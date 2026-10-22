<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.html');
    exit;
}

function back($status, $msg) {
    header('Location: admin_dashboard.php?status=' . $status . '&msg=' . urlencode($msg) . '&tab=assign');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_dashboard.php');
    exit;
}

$college_id = $_SESSION['college_id'];
$student_id = intval($_POST['student_id'] ?? 0);
$course_id  = intval($_POST['course_id'] ?? 0);
$branch_id  = intval($_POST['branch_id'] ?? 0);

if ($student_id <= 0 || $course_id <= 0) {
    back('error', 'Choose a course to assign.');
}

$chkS = $conn->prepare("SELECT id FROM students WHERE id = ? AND college_id = ?");
$chkS->bind_param("ii", $student_id, $college_id);
$chkS->execute();
$chkS->store_result();
if ($chkS->num_rows === 0) {
    $chkS->close();
    back('error', 'Student not found.');
}
$chkS->close();

$chkC = $conn->prepare("SELECT id FROM courses WHERE id = ? AND college_id = ?");
$chkC->bind_param("ii", $course_id, $college_id);
$chkC->execute();
$chkC->store_result();
if ($chkC->num_rows === 0) {
    $chkC->close();
    back('error', 'Course not found.');
}
$chkC->close();

$branchParam = $branch_id > 0 ? $branch_id : null;

$stmt = $conn->prepare("UPDATE students SET course_id = ?, branch_id = ? WHERE id = ? AND college_id = ?");
$stmt->bind_param("iiii", $course_id, $branchParam, $student_id, $college_id);
$stmt->execute();
$stmt->close();
$conn->close();

back('success', 'Course assigned successfully.');
