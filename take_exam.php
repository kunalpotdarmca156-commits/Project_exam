<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: login.html');
    exit;
}

$student_id = $_SESSION['student_id'];
$attempt_id = intval($_GET['attempt_id'] ?? 0);

$attemptStmt = $conn->prepare("
    SELECT a.id, a.cet_id, a.status, a.started_at,
           c.title, c.duration_minutes, c.course_id, c.branch_id,
           co.course_name, br.branch_name
    FROM attempts a
    JOIN cets c ON a.cet_id = c.id
    JOIN courses co ON c.course_id = co.id
    LEFT JOIN branches br ON c.branch_id = br.id
    WHERE a.id = ? AND a.student_id = ?
");
$attemptStmt->bind_param("ii", $attempt_id, $student_id);
$attemptStmt->execute();
$attempt = $attemptStmt->get_result()->fetch_assoc();
$attemptStmt->close();

if (!$attempt) {
    header('Location: student_dashboard.php?status=error&msg=' . urlencode('Attempt not found.'));
    exit;
}
if ($attempt['status'] !== 'in_progress') {
    header('Location: student_dashboard.php?status=error&msg=' . urlencode('This CET has already been submitted.') . '&tab=results');
    exit;
}

$elapsedSeconds = time() - strtotime($attempt['started_at']);
$remainingSeconds = max(0, ($attempt['duration_minutes'] * 60) - $elapsedSeconds);

// Never expose correct_option to the client — select everything else.
$qStmt = $conn->prepare("
    SELECT q.id, q.question_text, q.option_a, q.option_b, q.option_c, q.option_d, q.marks, q.image_path,
           s.subject_name
    FROM questions q
    JOIN subjects s ON q.subject_id = s.id
    WHERE q.cet_id = ?
    ORDER BY s.subject_name ASC, q.id ASC
");
$qStmt->bind_param("i", $attempt['cet_id']);
$qStmt->execute();
$questions = $qStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$totalMarks = array_sum(array_column($questions, 'marks'));

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($attempt['title']) ?> — CrustExams</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
  :root{
    --ink:#181A2A; --indigo:#3D3AA8; --indigo-2:#5C56E0; --violet:#8A63F3;
    --amber:#FFB648; --coral:#FF6E5E; --cream:#F7F6FB; --muted:#8A8AA3; --line:#E7E5F3;
    --page-bg:#EEF0F8;
  }
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Inter',sans-serif;background:var(--page-bg);color:var(--ink);}
  h1,h2,h3{font-family:'Sora',sans-serif;}
  button{font-family:inherit;}

  .topbar{
    position:sticky;top:0;z-index:50;
    background:linear-gradient(120deg,var(--indigo),var(--indigo-2),var(--violet));
    background-size:200% 200%;animation:gradientShift 12s ease-in-out infinite;
    padding:20px 40px;color:#fff;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;
    box-shadow:0 10px 30px -12px rgba(24,26,42,.5);
  }
  @keyframes gradientShift{0%,100%{background-position:0% 30%;}50%{background-position:100% 70%;}}
  .topbar h1{font-size:19px;font-weight:800;}
  .topbar .meta{font-size:12px;color:rgba(255,255,255,.8);margin-top:4px;}
  .topbar .meta span{margin-right:12px;}
  .timer{
    display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.35);
    padding:10px 18px;border-radius:100px;font-family:'IBM Plex Mono',monospace;font-weight:700;font-size:16px;
  }
  .timer.warning{background:rgba(255,110,94,.35);border-color:rgba(255,110,94,.6);animation:pulseWarn 1s infinite;}
  @keyframes pulseWarn{0%,100%{opacity:1;}50%{opacity:.6;}}

  .wrap{max-width:820px;margin:0 auto;padding:30px 24px 60px;}
  .subject-heading{
    font-family:'IBM Plex Mono',monospace;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
    color:var(--indigo-2);margin:28px 0 12px;padding-bottom:8px;border-bottom:2px solid var(--indigo-2);
  }
  .subject-heading:first-of-type{margin-top:0;}

  .q-card{background:#fff;border-radius:18px;box-shadow:0 16px 36px -26px rgba(24,26,42,.35);padding:22px 24px;margin-bottom:16px;}
  .q-head{display:flex;justify-content:space-between;gap:16px;margin-bottom:14px;}
  .q-text{font-weight:600;font-size:14.5px;}
  .q-marks{font-family:'IBM Plex Mono',monospace;font-size:12px;color:var(--muted);white-space:nowrap;flex:none;}
  .q-img{max-width:280px;border-radius:12px;margin-bottom:14px;display:block;border:1px solid var(--line);}
  .option-label{
    display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:12px;background:var(--cream);
    margin-bottom:8px;cursor:pointer;transition:background .2s ease, box-shadow .2s ease;border:1.5px solid transparent;
  }
  .option-label:hover{background:#EFEDFD;}
  .option-label input{width:17px;height:17px;accent-color:var(--indigo-2);flex:none;}
  .option-label.checked{background:rgba(92,86,224,.1);border-color:var(--indigo-2);}
  .option-text{font-size:13.5px;}

  .submit-bar{
    position:sticky;bottom:0;background:#fff;border-top:1px solid var(--line);padding:18px 24px;margin:0 -24px;
    display:flex;justify-content:space-between;align-items:center;box-shadow:0 -10px 30px -20px rgba(24,26,42,.3);
    border-radius:20px 20px 0 0;
  }
  .progress-text{font-size:13px;color:var(--muted);}
  .btn-submit{
    padding:14px 30px;border-radius:100px;border:none;cursor:pointer;
    background:linear-gradient(120deg,var(--indigo-2),var(--violet));color:#fff;font-family:'Sora',sans-serif;font-weight:700;font-size:14px;
    transition:transform .2s ease, box-shadow .2s ease;
  }
  .btn-submit:hover{transform:translateY(-2px);box-shadow:0 14px 26px -10px rgba(92,86,224,.6);}
</style>
</head>
<body>

<div class="topbar">
  <div>
    <h1><?= h($attempt['title']) ?></h1>
    <div class="meta">
      <span><?= h($attempt['course_name']) ?><?= $attempt['branch_name'] ? ' · ' . h($attempt['branch_name']) : '' ?></span>
      <span><?= count($questions) ?> questions</span>
      <span><?= (int)$totalMarks ?> total marks</span>
    </div>
  </div>
  <div class="timer" id="timer">--:--</div>
</div>

<form id="examForm" method="POST" action="submit_exam.php">
  <input type="hidden" name="attempt_id" value="<?= $attempt_id ?>">
  <div class="wrap">
    <?php if (empty($questions)): ?>
      <p>This CET has no questions.</p>
    <?php else:
      $currentSubject = null; $i = 1;
      foreach ($questions as $q):
        if ($q['subject_name'] !== $currentSubject):
          $currentSubject = $q['subject_name'];
    ?>
      <div class="subject-heading"><?= h($currentSubject) ?></div>
    <?php endif; ?>
      <div class="q-card">
        <div class="q-head">
          <div class="q-text"><?= $i++ ?>. <?= nl2br(h($q['question_text'])) ?></div>
          <div class="q-marks"><?= (int)$q['marks'] ?> mark<?= (int)$q['marks'] !== 1 ? 's' : '' ?></div>
        </div>
        <?php if (!empty($q['image_path'])): ?>
          <img class="q-img" src="<?= h($q['image_path']) ?>" alt="Question image">
        <?php endif; ?>
        <?php foreach (['a','b','c','d'] as $opt): ?>
          <label class="option-label">
            <input type="radio" name="answer[<?= (int)$q['id'] ?>]" value="<?= $opt ?>">
            <span class="option-text"><?= strtoupper($opt) ?>. <?= h($q['option_' . $opt]) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endforeach; endif; ?>

    <div class="submit-bar">
      <span class="progress-text" id="progressText">0 of <?= count($questions) ?> answered</span>
      <button type="submit" class="btn-submit" id="submitBtn" onclick="return confirmSubmit();">Submit Exam</button>
    </div>
  </div>
</form>

<script>
  let remaining = <?= (int)$remainingSeconds ?>;
  const totalQuestions = <?= count($questions) ?>;
  const timerEl = document.getElementById('timer');
  const progressEl = document.getElementById('progressText');
  let autoSubmitted = false;

  function formatTime(sec){
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    const pad = n => String(n).padStart(2, '0');
    return h > 0 ? `${pad(h)}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
  }

  function tick(){
    timerEl.textContent = formatTime(remaining);
    if (remaining <= 60) timerEl.classList.add('warning');
    if (remaining <= 0 && !autoSubmitted) {
      autoSubmitted = true;
      timerEl.textContent = "Time's up";
      document.getElementById('submitBtn').disabled = true;
      document.getElementById('examForm').submit();
      return;
    }
    remaining--;
  }
  tick();
  setInterval(tick, 1000);

  function updateProgress(){
    const answered = new Set();
    document.querySelectorAll('input[type=radio]:checked').forEach(r => {
      const name = r.name.match(/answer\[(\d+)\]/);
      if (name) answered.add(name[1]);
      r.closest('.option-label').classList.add('checked');
    });
    progressEl.textContent = answered.size + ' of ' + totalQuestions + ' answered';
  }

  document.querySelectorAll('input[type=radio]').forEach(radio => {
    radio.addEventListener('change', () => {
      const group = document.getElementsByName(radio.name);
      group.forEach(r => r.closest('.option-label').classList.remove('checked'));
      radio.closest('.option-label').classList.add('checked');
      updateProgress();
    });
  });

  function confirmSubmit(){
    if (autoSubmitted) return true;
    return confirm('Submit this exam now? You cannot change your answers after submitting.');
  }

  window.addEventListener('beforeunload', function (e) {
    if (!autoSubmitted) {
      e.preventDefault();
      e.returnValue = '';
    }
  });
</script>
</body>
</html>
