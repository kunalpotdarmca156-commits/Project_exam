<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: login.html');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];
$college_id = $_SESSION['college_id'];
$cet_id = intval($_GET['cet_id'] ?? 0);

$cetStmt = $conn->prepare("
    SELECT c.id, c.title, c.duration_minutes, c.status, c.course_id, c.branch_id,
           co.course_name, br.branch_name
    FROM cets c
    JOIN courses co ON c.course_id = co.id
    LEFT JOIN branches br ON c.branch_id = br.id
    WHERE c.id = ? AND c.teacher_id = ?
");
$cetStmt->bind_param("ii", $cet_id, $teacher_id);
$cetStmt->execute();
$cet = $cetStmt->get_result()->fetch_assoc();
$cetStmt->close();

if (!$cet) {
    header('Location: teacher_dashboard.php?status=error&msg=' . urlencode('CET not found.') . '&tab=cets');
    exit;
}

// subjects available for this CET's course+branch, so each question can be tagged
$subjStmt = $conn->prepare("
    SELECT id, subject_name FROM subjects
    WHERE college_id = ? AND course_id = ? AND (branch_id <=> ?)
    ORDER BY subject_name ASC
");
$subjStmt->bind_param("iii", $college_id, $cet['course_id'], $cet['branch_id']);
$subjStmt->execute();
$availableSubjects = $subjStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$qStmt = $conn->prepare("
    SELECT q.*, s.subject_name
    FROM questions q
    JOIN subjects s ON q.subject_id = s.id
    WHERE q.cet_id = ?
    ORDER BY s.subject_name ASC, q.id ASC
");
$qStmt->bind_param("i", $cet_id);
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
<title>Manage Questions — <?= h($cet['title']) ?> — CrustExams</title>
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
  a{text-decoration:none;color:inherit;}
  button{font-family:inherit;}

  .topbar{
    background:linear-gradient(120deg,var(--indigo),var(--indigo-2),var(--violet));
    background-size:200% 200%;animation:gradientShift 12s ease-in-out infinite;
    padding:26px 40px;color:#fff;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;
  }
  @keyframes gradientShift{0%,100%{background-position:0% 30%;}50%{background-position:100% 70%;}}
  .back-link{display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:rgba(255,255,255,.85);margin-bottom:10px;}
  .topbar h1{font-size:22px;font-weight:800;}
  .topbar .meta{font-size:12.5px;color:rgba(255,255,255,.8);margin-top:6px;}
  .topbar .meta span{margin-right:14px;}
  .status-badge{display:inline-flex;padding:5px 14px;border-radius:100px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;background:rgba(255,255,255,.2);}

  .wrap{max-width:900px;margin:0 auto;padding:34px 24px 60px;}
  .banner{display:none;align-items:center;gap:10px;padding:13px 18px;border-radius:14px;font-size:14px;font-weight:500;margin-bottom:22px;}

  .panel-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px;}
  .panel-head h2{font-size:17px;font-weight:700;}
  .panel-head p{font-size:12.5px;color:var(--muted);margin-top:2px;}
  .btn-add{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:100px;border:none;cursor:pointer;background:linear-gradient(120deg,var(--indigo-2),var(--violet));color:#fff;font-family:'Sora',sans-serif;font-weight:600;font-size:13.5px;transition:transform .2s ease, box-shadow .2s ease;}
  .btn-add:hover{transform:translateY(-2px);box-shadow:0 12px 22px -8px rgba(92,86,224,.55);}

  .subject-heading{
    font-family:'IBM Plex Mono',monospace;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
    color:var(--indigo-2);margin:26px 0 12px;padding-bottom:8px;border-bottom:2px solid var(--indigo-2);
  }
  .subject-heading:first-of-type{margin-top:0;}
  .q-card{background:#fff;border-radius:18px;box-shadow:0 16px 36px -26px rgba(24,26,42,.35);padding:22px 24px;margin-bottom:16px;display:flex;gap:18px;}
  .q-num{width:32px;height:32px;border-radius:9px;background:var(--cream);color:var(--indigo-2);display:flex;align-items:center;justify-content:center;font-family:'Sora',sans-serif;font-weight:700;font-size:13px;flex:none;}
  .q-body{flex:1;min-width:0;}
  .q-text{font-weight:600;font-size:14.5px;margin-bottom:10px;}
  .q-img{max-width:220px;border-radius:12px;margin-bottom:12px;display:block;border:1px solid var(--line);}
  .q-options{display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;margin-bottom:10px;}
  .q-opt{font-size:13px;color:var(--muted);padding:8px 12px;border-radius:10px;background:var(--cream);}
  .q-opt.correct{background:rgba(52,211,153,.14);color:#0f8a5f;font-weight:600;}
  .q-foot{display:flex;justify-content:space-between;align-items:center;}
  .q-marks{font-family:'IBM Plex Mono',monospace;font-size:12px;color:var(--muted);}
  .row-actions{display:flex;gap:8px;}
  .icon-btn{width:32px;height:32px;border-radius:9px;border:1.5px solid var(--line);background:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s ease;}
  .icon-btn.edit:hover{border-color:var(--indigo-2);background:var(--indigo-2);}
  .icon-btn.edit:hover svg path{stroke:#fff;}
  .icon-btn.del:hover{border-color:var(--coral);background:var(--coral);}
  .icon-btn.del:hover svg path{stroke:#fff;}
  .empty-state{background:#fff;border-radius:18px;box-shadow:0 16px 36px -26px rgba(24,26,42,.35);padding:50px 24px;text-align:center;color:var(--muted);font-size:14px;}

  .modal-overlay{display:none;position:fixed;inset:0;background:rgba(24,26,42,.55);backdrop-filter:blur(3px);align-items:center;justify-content:center;z-index:200;padding:20px;}
  .modal-overlay.show{display:flex;}
  .modal{background:#fff;border-radius:22px;padding:32px;width:100%;max-width:520px;box-shadow:0 40px 80px -30px rgba(0,0,0,.5);animation:modalIn .3s cubic-bezier(.2,.7,.3,1);max-height:88vh;overflow-y:auto;}
  @keyframes modalIn{from{opacity:0;transform:translateY(14px) scale(.98);}to{opacity:1;transform:translateY(0) scale(1);}}
  .modal h3{font-size:19px;font-weight:700;margin-bottom:6px;}
  .modal p.sub{color:var(--muted);font-size:13.5px;margin-bottom:22px;}
  .modal .field{margin-bottom:14px;}
  .modal label{display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;}
  .modal input, .modal select, .modal textarea{width:100%;padding:12px 15px;border-radius:12px;border:1.5px solid var(--line);background:var(--cream);font-size:14px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s ease, background .2s ease;}
  .modal textarea{resize:vertical;min-height:70px;}
  .modal input:focus, .modal select:focus, .modal textarea:focus{border-color:var(--indigo-2);background:#fff;}
  .modal-actions{display:flex;gap:10px;margin-top:22px;}
  .btn-cancel{flex:1;padding:12px;border-radius:100px;border:1.5px solid var(--line);background:#fff;font-weight:600;font-size:14px;cursor:pointer;font-family:'Sora',sans-serif;}
  .btn-confirm{flex:1;padding:12px;border-radius:100px;border:none;background:var(--ink);color:#fff;font-weight:600;font-size:14px;cursor:pointer;font-family:'Sora',sans-serif;}
  .field-row2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
  .option-row{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
  .option-row input[type=radio]{width:18px;height:18px;flex:none;accent-color:var(--indigo-2);}
  .option-row input[type=text]{flex:1;}
  .current-img-note{font-size:12px;color:var(--muted);margin-top:6px;}
</style>
</head>
<body>

<div class="topbar">
  <div>
    <a href="teacher_dashboard.php?tab=cets" class="back-link">← Back to your CETs</a>
    <h1><?= h($cet['title']) ?></h1>
    <div class="meta">
      <span><?= h($cet['course_name']) ?><?= $cet['branch_name'] ? ' · ' . h($cet['branch_name']) : '' ?></span>
      <span><?= (int)$cet['duration_minutes'] ?> min</span>
      <span><?= (int)$totalMarks ?> total marks</span>
      <span><?= count($questions) ?> questions</span>
    </div>
  </div>
  <span class="status-badge"><?= h($cet['status']) ?></span>
</div>

<div class="wrap">
  <div class="banner" id="banner"></div>

  <div class="panel-head">
    <div>
      <h2>Questions</h2>
      <p>Add MCQs one at a time — tag each with its subject, then four options, the correct answer, marks, and an optional image.</p>
    </div>
    <button class="btn-add" onclick="openModal('addQModal')" <?= empty($availableSubjects) ? 'disabled title="Create a subject for this course/branch first"' : '' ?>>
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></svg>
      Add Question
    </button>
  </div>

  <?php if (empty($availableSubjects)): ?>
    <div class="empty-state">No subjects exist yet for <?= h($cet['course_name']) ?><?= $cet['branch_name'] ? ' · ' . h($cet['branch_name']) : '' ?>. Go to "Create a Subject" and add at least one (e.g. Maths, English) before writing questions.</div>
  <?php elseif (empty($questions)): ?>
    <div class="empty-state">No questions yet — click "Add Question" to write your first one.</div>
  <?php else:
    $currentSubject = null; $i = 1;
    foreach ($questions as $q):
      if ($q['subject_name'] !== $currentSubject):
        $currentSubject = $q['subject_name'];
  ?>
    <div class="subject-heading"><?= h($currentSubject) ?></div>
  <?php endif; ?>
    <div class="q-card">
      <div class="q-num"><?= $i++ ?></div>
      <div class="q-body">
        <div class="q-text"><?= nl2br(h($q['question_text'])) ?></div>
        <?php if (!empty($q['image_path'])): ?>
          <img class="q-img" src="<?= h($q['image_path']) ?>" alt="Question image">
        <?php endif; ?>
        <div class="q-options">
          <?php foreach (['a','b','c','d'] as $opt): ?>
            <div class="q-opt<?= $q['correct_option'] === $opt ? ' correct' : '' ?>"><?= strtoupper($opt) ?>. <?= h($q['option_' . $opt]) ?></div>
          <?php endforeach; ?>
        </div>
        <div class="q-foot">
          <span class="q-marks"><?= (int)$q['marks'] ?> mark<?= (int)$q['marks'] !== 1 ? 's' : '' ?></span>
          <div class="row-actions">
            <button class="icon-btn edit" title="Edit" onclick='openEditQ(<?= json_encode($q) ?>)'>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 20h9" stroke="#5C56E0" stroke-width="1.8" stroke-linecap="round"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z" stroke="#5C56E0" stroke-width="1.8" stroke-linejoin="round"/></svg>
            </button>
            <a href="delete_question.php?id=<?= (int)$q['id'] ?>&cet_id=<?= $cet_id ?>" class="icon-btn del" title="Remove" onclick="return confirm('Remove this question?');">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M9 7V4h6v3M6 7l1 13a2 2 0 002 2h6a2 2 0 002-2l1-13" stroke="#FF6E5E" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>

<!-- ---------------- ADD QUESTION MODAL ---------------- -->
<div class="modal-overlay" id="addQModal">
  <div class="modal">
    <h3>Add a Question</h3>
    <p class="sub">Tag the subject this question belongs to, then select the radio button next to the correct option.</p>
    <form method="POST" action="add_question.php" enctype="multipart/form-data">
      <input type="hidden" name="cet_id" value="<?= $cet_id ?>">
      <div class="field">
        <label>Subject</label>
        <select name="subject_id" required>
          <?php foreach ($availableSubjects as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= h($s['subject_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Question</label><textarea name="question_text" placeholder="Type the question here..." required></textarea></div>

      <div class="option-row"><input type="radio" name="correct_option" value="a" required><input type="text" name="option_a" placeholder="Option A" required></div>
      <div class="option-row"><input type="radio" name="correct_option" value="b"><input type="text" name="option_b" placeholder="Option B" required></div>
      <div class="option-row"><input type="radio" name="correct_option" value="c"><input type="text" name="option_c" placeholder="Option C" required></div>
      <div class="option-row"><input type="radio" name="correct_option" value="d"><input type="text" name="option_d" placeholder="Option D" required></div>

      <div class="field-row2">
        <div class="field"><label>Marks</label><input type="number" name="marks" value="1" min="1" required></div>
        <div class="field"><label>Image (optional)</label><input type="file" name="question_image" accept="image/*"></div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('addQModal')">Cancel</button>
        <button type="submit" class="btn-confirm">Add Question</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------------- EDIT QUESTION MODAL ---------------- -->
<div class="modal-overlay" id="editQModal">
  <div class="modal">
    <h3>Edit Question</h3>
    <p class="sub">Upload a new image only if you want to replace the current one.</p>
    <form method="POST" action="update_question.php" enctype="multipart/form-data">
      <input type="hidden" name="cet_id" value="<?= $cet_id ?>">
      <input type="hidden" name="question_id" id="eqId">
      <div class="field">
        <label>Subject</label>
        <select name="subject_id" id="eqSubject" required>
          <?php foreach ($availableSubjects as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= h($s['subject_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Question</label><textarea name="question_text" id="eqText" required></textarea></div>

      <div class="option-row"><input type="radio" name="correct_option" id="eqOptA_radio" value="a" required><input type="text" name="option_a" id="eqOptA" required></div>
      <div class="option-row"><input type="radio" name="correct_option" id="eqOptB_radio" value="b"><input type="text" name="option_b" id="eqOptB" required></div>
      <div class="option-row"><input type="radio" name="correct_option" id="eqOptC_radio" value="c"><input type="text" name="option_c" id="eqOptC" required></div>
      <div class="option-row"><input type="radio" name="correct_option" id="eqOptD_radio" value="d"><input type="text" name="option_d" id="eqOptD" required></div>

      <div class="field-row2">
        <div class="field"><label>Marks</label><input type="number" name="marks" id="eqMarks" min="1" required></div>
        <div class="field">
          <label>Replace image (optional)</label>
          <input type="file" name="question_image" accept="image/*">
          <div class="current-img-note" id="eqImgNote"></div>
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('editQModal')">Cancel</button>
        <button type="submit" class="btn-confirm">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openModal(id){ document.getElementById(id).classList.add('show'); }
  function closeModal(id){ document.getElementById(id).classList.remove('show'); }
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.classList.remove('show'); });
  });

  function openEditQ(q){
    document.getElementById('eqId').value = q.id;
    document.getElementById('eqSubject').value = q.subject_id;
    document.getElementById('eqText').value = q.question_text;
    document.getElementById('eqOptA').value = q.option_a;
    document.getElementById('eqOptB').value = q.option_b;
    document.getElementById('eqOptC').value = q.option_c;
    document.getElementById('eqOptD').value = q.option_d;
    document.getElementById('eqMarks').value = q.marks;
    document.getElementById('eqOpt' + q.correct_option.toUpperCase() + '_radio').checked = true;
    document.getElementById('eqImgNote').textContent = q.image_path ? 'Current image is attached — leave blank to keep it.' : 'No image currently attached.';
    openModal('editQModal');
  }

  const params = new URLSearchParams(window.location.search);
  const status = params.get('status');
  const msg = params.get('msg');
  const banner = document.getElementById('banner');
  if (status && msg) {
    banner.style.display = 'flex';
    banner.textContent = decodeURIComponent(msg);
    if (status === 'success') { banner.style.background = 'rgba(34,197,94,.12)'; banner.style.color = '#15803d'; }
    else { banner.style.background = 'rgba(239,68,68,.1)'; banner.style.color = '#b91c1c'; }
  }
</script>
</body>
</html>
