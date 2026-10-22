<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.html');
    exit;
}

$college_id = $_SESSION['college_id'];
$id = intval($_GET['id'] ?? 0);

if ($id > 0) {
    $stmt = $conn->prepare("DELETE FROM courses WHERE id = ? AND college_id = ?");
    $stmt->bind_param("ii", $id, $college_id);
    $stmt->execute();
    $stmt->close();
}
$conn->close();

header('Location: admin_dashboard.php?status=success&msg=' . urlencode('Course removed.') . '&tab=courses');
exit;
