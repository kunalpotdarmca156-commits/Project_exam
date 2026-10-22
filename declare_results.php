<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: login.html');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];
$cet_id = intval($_GET['cet_id'] ?? 0);
$action = $_GET['action'] ?? 'declare';

// verify this CET belongs to the logged-in teacher
$chk = $conn->prepare("SELECT id FROM cets WHERE id = ? AND teacher_id = ?");
$chk->bind_param("ii", $cet_id, $teacher_id);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
    $chk->close();
    header('Location: teacher_dashboard.php?tab=results');
    exit;
}
$chk->close();

if ($action === 'undeclare') {
    $stmt = $conn->prepare("UPDATE attempts SET declared = 0, declared_at = NULL WHERE cet_id = ? AND status = 'submitted'");
    $stmt->bind_param("i", $cet_id);
    if (!$stmt->execute()) {
        header('Location: cet_results.php?cet_id=' . $cet_id . '&status=error&msg=' . urlencode('Database error: ' . $stmt->error));
        exit;
    }
    $stmt->close();
    $conn->close();
    header('Location: cet_results.php?cet_id=' . $cet_id . '&status=success&msg=' . urlencode('Results undeclared.'));
    exit;
}

$stmt = $conn->prepare("UPDATE attempts SET declared = 1, declared_at = NOW() WHERE cet_id = ? AND status = 'submitted'");
$stmt->bind_param("i", $cet_id);
if (!$stmt->execute()) {
    header('Location: cet_results.php?cet_id=' . $cet_id . '&status=error&msg=' . urlencode('Database error: ' . $stmt->error));
    exit;
}
$affected = $stmt->affected_rows;
$stmt->close();
$conn->close();

header('Location: cet_results.php?cet_id=' . $cet_id . '&status=success&msg=' . urlencode("Results declared for $affected student" . ($affected !== 1 ? 's' : '') . '.'));
exit;
