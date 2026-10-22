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
$id           = intval($_POST['subject_id'] ?? 0);
$subject_name = trim($_POST['subject_name'] ?? '');

if ($id <= 0 || $subject_name === '') {
    back('error', 'Enter a valid subject name.');
}

$stmt = $conn->prepare("UPDATE subjects SET subject_name = ? WHERE id = ? AND created_by_teacher_id = ?");
$stmt->bind_param("sii", $subject_name, $id, $teacher_id);
$stmt->execute();
$stmt->close();
$conn->close();

back('success', 'Subject updated successfully.');
