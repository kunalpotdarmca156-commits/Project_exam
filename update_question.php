<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: login.html');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];
$cet_id = intval($_POST['cet_id'] ?? 0);
$question_id = intval($_POST['question_id'] ?? 0);

function back($cetId, $status, $msg) {
    header('Location: manage_cet_questions.php?cet_id=' . $cetId . '&status=' . $status . '&msg=' . urlencode($msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: teacher_dashboard.php');
    exit;
}

// verify ownership: this question's CET must belong to the logged-in teacher
$chk = $conn->prepare("SELECT q.id, q.image_path, c.course_id, c.branch_id FROM questions q INNER JOIN cets c ON q.cet_id = c.id WHERE q.id = ? AND c.id = ? AND c.teacher_id = ?");
$chk->bind_param("iii", $question_id, $cet_id, $teacher_id);
$chk->execute();
$existing = $chk->get_result()->fetch_assoc();
$chk->close();
if (!$existing) {
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

$chkSub = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND course_id = ? AND (branch_id <=> ?)");
$chkSub->bind_param("iii", $subject_id, $existing['course_id'], $existing['branch_id']);
$chkSub->execute();
$chkSub->store_result();
if ($chkSub->num_rows === 0) {
    $chkSub->close();
    back($cet_id, 'error', 'That subject does not belong to this CET\'s course/branch.');
}
$chkSub->close();

$imagePath = $existing['image_path'];
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
        // remove old image now that the new one is saved
        if (!empty($imagePath) && is_file(__DIR__ . '/' . $imagePath)) {
            @unlink(__DIR__ . '/' . $imagePath);
        }
        $imagePath = 'uploads/questions/' . $filename;
    }
}

$stmt = $conn->prepare("UPDATE questions SET subject_id = ?, question_text = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct_option = ?, marks = ?, image_path = ? WHERE id = ?");
if (!$stmt) {
    back($cet_id, 'error', 'Database error (prepare): ' . $conn->error);
}
$stmt->bind_param("issssssisi", $subject_id, $question_text, $option_a, $option_b, $option_c, $option_d, $correct_option, $marks, $imagePath, $question_id);
if (!$stmt->execute()) {
    back($cet_id, 'error', 'Database error (update): ' . $stmt->error);
}
$stmt->close();
$conn->close();

back($cet_id, 'success', 'Question updated successfully.');
