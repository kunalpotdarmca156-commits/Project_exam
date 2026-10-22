<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.html');
    exit;
}

function back($status, $msg) {
    header('Location: admin_dashboard.php?status=' . $status . '&msg=' . urlencode($msg) . '&tab=courses');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_dashboard.php');
    exit;
}

$college_id  = $_SESSION['college_id'];
$id          = intval($_POST['course_id'] ?? 0);
$course_name = trim($_POST['course_name'] ?? '');

if ($id <= 0 || $course_name === '') {
    back('error', 'Enter a valid course name.');
}

$check = $conn->prepare("SELECT id FROM courses WHERE college_id = ? AND course_name = ? AND id <> ?");
$check->bind_param("isi", $college_id, $course_name, $id);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $check->close();
    back('error', 'Another course already has this name.');
}
$check->close();

$stmt = $conn->prepare("UPDATE courses SET course_name = ? WHERE id = ? AND college_id = ?");
$stmt->bind_param("sii", $course_name, $id, $college_id);
$stmt->execute();
$stmt->close();
$conn->close();

back('success', 'Course updated successfully.');
