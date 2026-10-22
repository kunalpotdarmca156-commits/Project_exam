<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: login.html');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];
$id = intval($_GET['id'] ?? 0);
$cet_id = intval($_GET['cet_id'] ?? 0);

if ($id > 0) {
    $chk = $conn->prepare("SELECT q.image_path FROM questions q INNER JOIN cets c ON q.cet_id = c.id WHERE q.id = ? AND c.teacher_id = ?");
    $chk->bind_param("ii", $id, $teacher_id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($row) {
        if (!empty($row['image_path']) && is_file(__DIR__ . '/' . $row['image_path'])) {
            @unlink(__DIR__ . '/' . $row['image_path']);
        }
        $stmt = $conn->prepare("DELETE q FROM questions q INNER JOIN cets c ON q.cet_id = c.id WHERE q.id = ? AND c.teacher_id = ?");
        $stmt->bind_param("ii", $id, $teacher_id);
        $stmt->execute();
        $stmt->close();
    }
}
$conn->close();

header('Location: manage_cet_questions.php?cet_id=' . $cet_id . '&status=success&msg=' . urlencode('Question removed.'));
exit;
