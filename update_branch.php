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
$id          = intval($_POST['branch_id'] ?? 0);
$branch_name = trim($_POST['branch_name'] ?? '');

if ($id <= 0 || $branch_name === '') {
    back('error', 'Enter a valid branch name.');
}

// make sure this branch's course belongs to this college
$chk = $conn->prepare("SELECT b.id FROM branches b INNER JOIN courses c ON b.course_id = c.id WHERE b.id = ? AND c.college_id = ?");
$chk->bind_param("ii", $id, $college_id);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
    $chk->close();
    back('error', 'Branch not found.');
}
$chk->close();

$stmt = $conn->prepare("UPDATE branches SET branch_name = ? WHERE id = ?");
$stmt->bind_param("si", $branch_name, $id);
$stmt->execute();
$stmt->close();
$conn->close();

back('success', 'Branch updated successfully.');
