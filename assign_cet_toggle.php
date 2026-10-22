<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: login.html');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];
$id = intval($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

function back($status, $msg) {
    header('Location: teacher_dashboard.php?status=' . $status . '&msg=' . urlencode($msg) . '&tab=assign');
    exit;
}

if ($id <= 0 || !in_array($action, ['assign', 'unassign'], true)) {
    back('error', 'Invalid request.');
}

if ($action === 'assign') {
    // must have at least one question
    $qCheck = $conn->prepare("SELECT COUNT(*) AS c FROM questions WHERE cet_id = ?");
    $qCheck->bind_param("i", $id);
    $qCheck->execute();
    $count = $qCheck->get_result()->fetch_assoc()['c'];
    $qCheck->close();
    if ($count == 0) {
        back('error', 'Add at least one question before assigning this CET.');
    }

    $stmt = $conn->prepare("UPDATE cets SET status = 'assigned', assigned_at = NOW() WHERE id = ? AND teacher_id = ?");
    $stmt->bind_param("ii", $id, $teacher_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    back('success', 'CET assigned to students.');
} else {
    $stmt = $conn->prepare("UPDATE cets SET status = 'draft', assigned_at = NULL WHERE id = ? AND teacher_id = ?");
    $stmt->bind_param("ii", $id, $teacher_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    back('success', 'CET unassigned.');
}
