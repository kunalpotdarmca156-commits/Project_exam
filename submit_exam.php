<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: login.html');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: student_dashboard.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$attempt_id = intval($_POST['attempt_id'] ?? 0);
$submittedAnswers = $_POST['answer'] ?? []; // [question_id => 'a'|'b'|'c'|'d']

function back_with_error($msg) {
    header('Location: student_dashboard.php?status=error&msg=' . urlencode($msg));
    exit;
}

// verify this attempt belongs to this student and is still in progress
$attemptStmt = $conn->prepare("SELECT id, cet_id, status FROM attempts WHERE id = ? AND student_id = ?");
$attemptStmt->bind_param("ii", $attempt_id, $student_id);
$attemptStmt->execute();
$attempt = $attemptStmt->get_result()->fetch_assoc();
$attemptStmt->close();

if (!$attempt) {
    back_with_error('Attempt not found.');
}
if ($attempt['status'] !== 'in_progress') {
    // already submitted (e.g. double-submit) — just send them back quietly
    header('Location: student_dashboard.php?tab=results');
    exit;
}

// pull the real questions + correct answers server-side (never trust the client for this)
$qStmt = $conn->prepare("SELECT id, correct_option, marks FROM questions WHERE cet_id = ?");
$qStmt->bind_param("i", $attempt['cet_id']);
$qStmt->execute();
$questions = $qStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$score = 0;
$totalMarks = 0;

$ansStmt = $conn->prepare("INSERT INTO attempt_answers (attempt_id, question_id, selected_option, is_correct) VALUES (?, ?, ?, ?)");
if (!$ansStmt) {
    back_with_error('Database error (prepare answers): ' . $conn->error);
}

foreach ($questions as $q) {
    $totalMarks += (int)$q['marks'];
    $selected = $submittedAnswers[$q['id']] ?? null;
    $selected = in_array($selected, ['a', 'b', 'c', 'd'], true) ? $selected : null;
    $isCorrect = ($selected !== null && $selected === $q['correct_option']) ? 1 : 0;
    if ($isCorrect) {
        $score += (int)$q['marks'];
    }
    $ansStmt->bind_param("iisi", $attempt_id, $q['id'], $selected, $isCorrect);
    if (!$ansStmt->execute()) {
        back_with_error('Database error (saving answer): ' . $ansStmt->error);
    }
}
$ansStmt->close();

$updateStmt = $conn->prepare("UPDATE attempts SET score = ?, total_marks = ?, submitted_at = NOW(), status = 'submitted' WHERE id = ? AND student_id = ?");
if (!$updateStmt) {
    back_with_error('Database error (prepare finalize): ' . $conn->error);
}
$updateStmt->bind_param("iiii", $score, $totalMarks, $attempt_id, $student_id);
if (!$updateStmt->execute()) {
    back_with_error('Database error (finalize): ' . $updateStmt->error);
}
$updateStmt->close();
$conn->close();

header('Location: student_dashboard.php?status=success&msg=' . urlencode('Exam submitted! Your teacher will declare the result soon.') . '&tab=results');
exit;
