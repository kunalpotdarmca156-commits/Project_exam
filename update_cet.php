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
$id      = intval($_POST['cet_id'] ?? 0);
$title   = trim($_POST['title'] ?? '');
$hours   = max(0, intval($_POST['hours'] ?? 0));
$minutes = max(0, intval($_POST['minutes'] ?? 0));
$duration = ($hours * 60) + $minutes;

if ($id <= 0 || $title === '' || $duration <= 0) {
    back('error', 'Enter a valid title and duration.');
}

$stmt = $conn->prepare("UPDATE cets SET title = ?, duration_minutes = ? WHERE id = ? AND teacher_id = ?");
$stmt->bind_param("siii", $title, $duration, $id, $teacher_id);
$stmt->execute();
$stmt->close();
$conn->close();

back('success', 'CET updated successfully.');
