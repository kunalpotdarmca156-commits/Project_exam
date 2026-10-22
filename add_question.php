<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: login.html');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];
$cet_id = intval($_POST['cet_id'] ?? 0);

function back($cetId, $status, $msg) {
    header('Location: manage_cet_questions.php?cet_id=' . $cetId . '&status=' . $status . '&msg=' . urlencode($msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: teacher_dashboard.php');
    exit;
}

// verify this CET belongs to the logged-in teacher, and get its course/branch
$chk = $conn->prepare("SELECT id, course_id, branch_id FROM cets WHERE id = ? AND teacher_id = ?");
$chk->bind_param("ii", $cet_id, $teacher_id);
$chk->execute();
$cet = $chk->get_result()->fetch_assoc();
$chk->close();
if (!$cet) {
    header('Location: teacher_dashboard.php');
    exit;
}

$subject_id     = intval($_POST['subject_id'] ?? 0);
$question_text  = trim($_POST['question_text'] ?? '');
$option_a       = trim($_POST['option_a'] ?? '');
$option_b       = trim($_POST['option_b'] ?? '');
$option_c       = trim($_POST['option_c'] ?? '');
$option_d       = trim($_POST['option_d'] ?? '');
$correct_option = $_POST['correct_option'] ?? '';
$marks          = max(1, intval($_POST['marks'] ?? 1));

if ($subject_id <= 0) {
    back($cet_id, 'error', 'Choose which subject this question belongs to.');
}
if ($question_text === '' || $option_a === '' || $option_b === '' || $option_c === '' || $option_d === '') {
    back($cet_id, 'error', 'Fill in the question and all four options.');
}
if (!in_array($correct_option, ['a', 'b', 'c', 'd'], true)) {
    back($cet_id, 'error', 'Select which option is correct.');
}

// the subject must belong to this CET's exact course + branch
$chkSub = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND course_id = ? AND (branch_id <=> ?)");
$chkSub->bind_param("iii", $subject_id, $cet['course_id'], $cet['branch_id']);
$chkSub->execute();
$chkSub->store_result();
if ($chkSub->num_rows === 0) {
    $chkSub->close();
    back($cet_id, 'error', 'That subject does not belong to this CET\'s course/branch.');
}
$chkSub->close();

// ---- optional image upload ----
$imagePath = null;
if (!empty($_FILES['question_image']['name']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
    $imgInfo = @getimagesize($_FILES['question_image']['tmp_name']);
    $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $ext = $imgInfo ? ($mimeToExt[$imgInfo['mime']] ?? null) : null;
    if (!$ext) {
        back($cet_id, 'error', 'Question image must be a JPG, PNG, WEBP, or GIF.');
    }
    $dir = __DIR__ . '/uploads/questions/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $filename = 'q_' . $cet_id . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    if (move_uploaded_file($_FILES['question_image']['tmp_name'], $dir . $filename)) {
        $imagePath = 'uploads/questions/' . $filename;
    }
}

$stmt = $conn->prepare("INSERT INTO questions (cet_id, subject_id, question_text, option_a, option_b, option_c, option_d, correct_option, marks, image_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
if (!$stmt) {
    back($cet_id, 'error', 'Database error (prepare): ' . $conn->error);
}
$stmt->bind_param("iissssssis", $cet_id, $subject_id, $question_text, $option_a, $option_b, $option_c, $option_d, $correct_option, $marks, $imagePath);
if (!$stmt->execute()) {
    back($cet_id, 'error', 'Database error (insert): ' . $stmt->error);
}
$stmt->close();
$conn->close();

back($cet_id, 'success', 'Question added successfully.');
