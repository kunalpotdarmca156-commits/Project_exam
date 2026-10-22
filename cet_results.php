<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: login.html');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];
$cet_id = intval($_GET['cet_id'] ?? 0);

$cetStmt = $conn->prepare("
    SELECT c.id, c.title, c.duration_minutes, co.course_name, br.branch_name,
           (SELECT COALESCE(SUM(marks),0) FROM questions q WHERE q.cet_id = c.id) AS total_marks
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
    header('Location: teacher_dashboard.php?status=error&msg=' . urlencode('CET not found.') . '&tab=results');
    exit;
}

$attemptStmt = $conn->prepare("
    SELECT a.id, a.score, a.total_marks, a.submitted_at, a.declared,
           s.full_name, s.roll_number
    FROM attempts a
    JOIN students s ON a.student_id = s.id
    WHERE a.cet_id = ? AND a.status = 'submitted'
    ORDER BY a.score DESC, a.submitted_at ASC
");
$attemptStmt->bind_param("i", $cet_id);
$attemptStmt->execute();
$attempts = $attemptStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$attemptCount = count($attempts);
$declaredCount = count(array_filter($attempts, fn($a) => (int)$a['declared'] === 1));
$avgScore = $attemptCount > 0 ? round(array_sum(array_column($attempts, 'score')) / $attemptCount, 1) : 0;
$highScore = $attemptCount > 0 ? max(array_column($attempts, 'score')) : 0;

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Results — <?= h($cet['title']) ?> — CrustExams</title>
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

  .wrap{max-width:960px;margin:0 auto;padding:34px 24px 60px;}
  .banner{display:none;align-items:center;gap:10px;padding:13px 18px;border-radius:14px;font-size:14px;font-weight:500;margin-bottom:22px;}

  .stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:24px;}
  .stat-card{background:#fff;border-radius:18px;padding:20px;box-shadow:0 16px 36px -26px rgba(24,26,42,.35);}
  .stat-card span{font-size:12.5px;color:var(--muted);display:block;margin-bottom:6px;}
  .stat-card b{font-family:'Sora',sans-serif;font-size:24px;font-weight:800;}

  .panel{background:#fff;border-radius:20px;box-shadow:0 16px 36px -26px rgba(24,26,42,.35);overflow:hidden;margin-bottom:24px;}
  .panel-head{display:flex;justify-content:space-between;align-items:center;padding:22px 26px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:12px;}
  .panel-head h2{font-size:17px;font-weight:700;}
  .panel-head p{font-size:12.5px;color:var(--muted);margin-top:2px;}

  .btn-declare{
    padding:12px 24px;border-radius:100px;border:none;cursor:pointer;
    background:linear-gradient(120deg,#0f8a5f,#34D399);color:#fff;font-family:'Sora',sans-serif;font-weight:700;font-size:13.5px;
    transition:transform .2s ease, box-shadow .2s ease;
  }
  .btn-declare:hover{transform:translateY(-2px);box-shadow:0 12px 22px -8px rgba(52,211,153,.55);}
  .btn-declare:disabled{opacity:.45;cursor:not-allowed;transform:none;box-shadow:none;}
  .btn-undeclare{
    padding:10px 18px;border-radius:100px;border:1.5px solid var(--coral);background:#fff;color:var(--coral);
    font-family:'Sora',sans-serif;font-weight:700;font-size:12.5px;cursor:pointer;transition:all .2s ease;
  }
  .btn-undeclare:hover{background:var(--coral);color:#fff;}

  table{width:100%;border-collapse:collapse;}
  thead th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);padding:14px 26px;border-bottom:1px solid var(--line);font-weight:600;}
  tbody td{padding:15px 26px;font-size:13.5px;border-bottom:1px solid var(--line);vertical-align:middle;}
  tbody tr:last-child td{border-bottom:none;}
  tbody tr:hover{background:var(--cream);}
  .name-cell{display:flex;align-items:center;gap:10px;font-weight:600;}
  .mini-avatar{width:32px;height:32px;border-radius:50%;background:var(--cream);color:var(--indigo-2);display:flex;align-items:center;justify-content:center;font-family:'Sora',sans-serif;font-weight:700;font-size:12.5px;flex:none;}
  .muted-cell{color:var(--muted);font-family:'IBM Plex Mono',monospace;font-size:12px;}
  .score-cell{font-family:'IBM Plex Mono',monospace;font-weight:700;}
  .status-badge{display:inline-flex;padding:4px 12px;border-radius:100px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;}
  .status-badge.pending{background:rgba(255,182,72,.18);color:#b8790e;}
  .status-badge.declared{background:rgba(52,211,153,.16);color:#0f8a5f;}
  .empty-state{background:#fff;border-radius:18px;box-shadow:0 16px 36px -26px rgba(24,26,42,.35);padding:50px 24px;text-align:center;color:var(--muted);font-size:14px;}
  .rank{font-family:'IBM Plex Mono',monospace;font-size:12px;color:var(--muted);}
</style>
</head>
<body>

<div class="topbar">
  <div>
    <a href="teacher_dashboard.php?tab=results" class="back-link">← Back to Results</a>
    <h1><?= h($cet['title']) ?></h1>
    <div class="meta">
      <span><?= h($cet['course_name']) ?><?= $cet['branch_name'] ? ' · ' . h($cet['branch_name']) : '' ?></span>
      <span><?= (int)$cet['duration_minutes'] ?> min</span>
      <span><?= (int)$cet['total_marks'] ?> total marks</span>
    </div>
  </div>
</div>

<div class="wrap">
  <div class="banner" id="banner"></div>

  <div class="stats-row">
    <div class="stat-card"><span>Attempted</span><b><?= $attemptCount ?></b></div>
    <div class="stat-card"><span>Average score</span><b><?= $avgScore ?></b></div>
    <div class="stat-card"><span>Highest score</span><b><?= $highScore ?></b></div>
  </div>

  <?php if (empty($attempts)): ?>
    <div class="empty-state">No students have attempted this CET yet.</div>
  <?php else: ?>
    <div class="panel">
      <div class="panel-head">
        <div>
          <h2>Student Results</h2>
          <p><?= $declaredCount ?> of <?= $attemptCount ?> declared. Declaring releases the result to every student below at once.</p>
        </div>
        <?php if ($declaredCount < $attemptCount): ?>
          <a href="declare_results.php?cet_id=<?= (int)$cet_id ?>&action=declare" class="btn-declare" onclick="return confirm('Declare results for all <?= $attemptCount ?> students? They will immediately see their score.');">Declare All Results</a>
        <?php else: ?>
          <a href="declare_results.php?cet_id=<?= (int)$cet_id ?>&action=undeclare" class="btn-undeclare" onclick="return confirm('Undeclare results? Students will no longer be able to see their scores.');">Undeclare</a>
        <?php endif; ?>
      </div>
      <table>
        <thead><tr><th>#</th><th>Student</th><th>Roll No.</th><th>Score</th><th>Submitted</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($attempts as $i => $a): ?>
            <tr>
              <td class="rank">#<?= $i + 1 ?></td>
              <td>
                <div class="name-cell">
                  <div class="mini-avatar"><?= h(strtoupper(substr($a['full_name'], 0, 1))) ?></div>
                  <?= h($a['full_name']) ?>
                </div>
              </td>
              <td class="muted-cell"><?= h($a['roll_number']) ?></td>
              <td class="score-cell"><?= (int)$a['score'] ?> / <?= (int)$a['total_marks'] ?></td>
              <td class="muted-cell"><?= h(date('d M Y, h:i A', strtotime($a['submitted_at']))) ?></td>
              <td><span class="status-badge <?= $a['declared'] ? 'declared' : 'pending' ?>"><?= $a['declared'] ? 'Declared' : 'Pending' ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
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
