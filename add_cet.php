<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: login.html');
    exit;
}

function back($status, $msg) {
    header('Location: teacher_dashboard.php?status=' . $status . '&msg=' . urlencode($msg) . '&tab=cets');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: teacher_dashboard.php');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];
$college_id = $_SESSION['college_id'];

$title     = trim($_POST['title'] ?? '');
$course_id = intval($_POST['course_id'] ?? 0);
$branch_id = intval($_POST['branch_id'] ?? 0);
$hours     = max(0, intval($_POST['hours'] ?? 0));
$minutes   = max(0, intval($_POST['minutes'] ?? 0));
$duration  = ($hours * 60) + $minutes;

if ($title === '' || $course_id <= 0) {
    back('error', 'Enter a title and choose a course.');
}
if ($duration <= 0) {
    back('error', 'Duration must be more than 0 minutes.');
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
if ($branchParam !== null) {
    $chkB = $conn->prepare("SELECT id FROM branches WHERE id = ? AND course_id = ?");
    $chkB->bind_param("ii", $branchParam, $course_id);
    $chkB->execute();
    $chkB->store_result();
    if ($chkB->num_rows === 0) {
        $chkB->close();
        back('error', 'Branch not found for this course.');
    }
    $chkB->close();
}

$stmt = $conn->prepare("INSERT INTO cets (college_id, teacher_id, course_id, branch_id, title, duration_minutes) VALUES (?, ?, ?, ?, ?, ?)");
if (!$stmt) {
    back('error', 'Database error (prepare): ' . $conn->error);
}
$stmt->bind_param("iiiisi", $college_id, $teacher_id, $course_id, $branchParam, $title, $duration);
if (!$stmt->execute()) {
    back('error', 'Database error (insert): ' . $stmt->error);
}
$newId = $conn->insert_id;
$stmt->close();
$conn->close();

header('Location: manage_cet_questions.php?cet_id=' . $newId . '&status=success&msg=' . urlencode('CET created — now add its questions, tagging each with its subject.'));
exit;
