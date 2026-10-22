<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: login.html');
    exit;
}

$student_id = $_SESSION['student_id'];
$college_id = $_SESSION['college_id'];
$cet_id = intval($_GET['cet_id'] ?? 0);

function back_to_dashboard($status, $msg) {
    header('Location: student_dashboard.php?status=' . $status . '&msg=' . urlencode($msg) . '&tab=dashboard');
    exit;
}

// get the student's own course/branch
$studentStmt = $conn->prepare("SELECT course_id, branch_id FROM students WHERE id = ?");
$studentStmt->bind_param("i", $student_id);
$studentStmt->execute();
$student = $studentStmt->get_result()->fetch_assoc();
$studentStmt->close();

if (!$student || !$student['course_id']) {
    back_to_dashboard('error', 'Your college hasn\'t assigned you a course yet.');
}

// verify this CET is actually assigned to this student's exact course + branch
$cetStmt = $conn->prepare("
    SELECT id, duration_minutes FROM cets
    WHERE id = ? AND college_id = ? AND status = 'assigned' AND course_id = ? AND (branch_id <=> ?)
");
$cetStmt->bind_param("iiii", $cet_id, $college_id, $student['course_id'], $student['branch_id']);
$cetStmt->execute();
$cet = $cetStmt->get_result()->fetch_assoc();
$cetStmt->close();

if (!$cet) {
    back_to_dashboard('error', 'That CET is not available to you.');
}

// does an attempt already exist?
$existStmt = $conn->prepare("SELECT id, status FROM attempts WHERE cet_id = ? AND student_id = ?");
$existStmt->bind_param("ii", $cet_id, $student_id);
$existStmt->execute();
$existing = $existStmt->get_result()->fetch_assoc();
$existStmt->close();

if ($existing) {
    if ($existing['status'] === 'submitted') {
        back_to_dashboard('error', 'You have already submitted this CET.');
    }
    // already in progress — resume it
    header('Location: take_exam.php?attempt_id=' . $existing['id']);
    exit;
}

// make sure the CET actually has questions before letting a student start
$qCountRow = $conn->query("SELECT COUNT(*) AS c FROM questions WHERE cet_id = " . intval($cet_id))->fetch_assoc();
if ((int)$qCountRow['c'] === 0) {
    back_to_dashboard('error', 'This CET has no questions yet. Please check back later.');
}

$stmt = $conn->prepare("INSERT INTO attempts (cet_id, student_id, status, started_at) VALUES (?, ?, 'in_progress', NOW())");
if (!$stmt) {
    back_to_dashboard('error', 'Database error (prepare): ' . $conn->error);
}
$stmt->bind_param("ii", $cet_id, $student_id);
if (!$stmt->execute()) {
    back_to_dashboard('error', 'Database error (start): ' . $stmt->error);
}
$attemptId = $conn->insert_id;
$stmt->close();
$conn->close();

header('Location: take_exam.php?attempt_id=' . $attemptId);
exit;
