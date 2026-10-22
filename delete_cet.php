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
    // clean up any uploaded question images belonging to this CET
    $imgStmt = $conn->prepare("SELECT q.image_path FROM questions q INNER JOIN cets c ON q.cet_id = c.id WHERE c.id = ? AND c.teacher_id = ? AND q.image_path IS NOT NULL");
    $imgStmt->bind_param("ii", $id, $teacher_id);
    $imgStmt->execute();
    $images = $imgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $imgStmt->close();
    foreach ($images as $img) {
        $path = __DIR__ . '/' . $img['image_path'];
        if (is_file($path)) { @unlink($path); }
    }

    $stmt = $conn->prepare("DELETE FROM cets WHERE id = ? AND teacher_id = ?");
    $stmt->bind_param("ii", $id, $teacher_id);
    $stmt->execute();
    $stmt->close();
}
$conn->close();

header('Location: teacher_dashboard.php?status=success&msg=' . urlencode('CET removed.') . '&tab=cets');
exit;
