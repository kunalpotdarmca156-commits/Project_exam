<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: login.html');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];
$id = intval($_GET['id'] ?? 0);

if ($id > 0) {
    $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ? AND created_by_teacher_id = ?");
    $stmt->bind_param("ii", $id, $teacher_id);
    $stmt->execute();
    $stmt->close();
}
$conn->close();

header('Location: teacher_dashboard.php?status=success&msg=' . urlencode('Subject removed.') . '&tab=subjects');
exit;
