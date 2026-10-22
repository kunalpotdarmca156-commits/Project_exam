<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: login.html');
    exit;
}

$student_id = $_SESSION['student_id'];
$attempt_id = intval($_GET['attempt_id'] ?? 0);

$stmt = $conn->prepare("
    SELECT a.id, a.score, a.total_marks, a.submitted_at, a.declared,
           c.title AS cet_title,
           co.course_name, br.branch_name,
           st.full_name, st.roll_number,
           cl.college_name, cl.college_code
    FROM attempts a
    JOIN cets c ON a.cet_id = c.id
    JOIN courses co ON c.course_id = co.id
    LEFT JOIN branches br ON c.branch_id = br.id
    JOIN students st ON a.student_id = st.id
    JOIN colleges cl ON st.college_id = cl.id
    WHERE a.id = ? AND a.student_id = ?
");
$stmt->bind_param("ii", $attempt_id, $student_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$result) {
    header('Location: student_dashboard.php?status=error&msg=' . urlencode('Result not found.') . '&tab=results');
    exit;
}
if (!$result['declared']) {
    header('Location: student_dashboard.php?status=error&msg=' . urlencode('This result has not been declared yet.') . '&tab=results');
    exit;
}

// subject-wise breakdown: marks obtained vs max marks per subject
$subStmt = $conn->prepare("
    SELECT s.subject_name,
           SUM(q.marks) AS max_marks,
           SUM(CASE WHEN aa.is_correct = 1 THEN q.marks ELSE 0 END) AS obtained_marks,
           COUNT(q.id) AS question_count,
           SUM(CASE WHEN aa.is_correct = 1 THEN 1 ELSE 0 END) AS correct_count
    FROM attempt_answers aa
    JOIN questions q ON aa.question_id = q.id
    JOIN subjects s ON q.subject_id = s.id
    WHERE aa.attempt_id = ?
    GROUP BY s.id, s.subject_name
    ORDER BY s.subject_name ASC
");
$subStmt->bind_param("i", $attempt_id);
$subStmt->execute();
$subjects = $subStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$conn->close();

$overallPct = $result['total_marks'] > 0 ? round(($result['score'] / $result['total_marks']) * 100, 2) : 0;

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }

function gradeFor($pct) {
    if ($pct >= 90) return 'A+';
    if ($pct >= 80) return 'A';
    if ($pct >= 70) return 'B+';
    if ($pct >= 60) return 'B';
    if ($pct >= 50) return 'C';
    if ($pct >= 40) return 'D';
    return 'F';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Result — <?= h($result['full_name']) ?> — <?= h($result['cet_title']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
  :root{
    --ink:#181A2A; --indigo:#3D3AA8; --indigo-2:#5C56E0; --violet:#8A63F3;
    --amber:#FFB648; --coral:#FF6E5E; --cream:#F7F6FB; --muted:#8A8AA3; --line:#E0E0EA;
  }
  *{margin:0;padding:0;box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  body{font-family:'Inter',sans-serif;background:#DCDFEE;color:var(--ink);padding:30px 16px;}
  h1,h2,h3{font-family:'Sora',sans-serif;}

  .toolbar{max-width:800px;margin:0 auto 18px;display:flex;justify-content:space-between;align-items:center;}
  .btn-back{font-size:13.5px;font-weight:600;color:var(--indigo-2);text-decoration:none;}
  .btn-print{
    padding:12px 24px;border-radius:100px;border:none;cursor:pointer;
    background:linear-gradient(120deg,var(--indigo-2),var(--violet));color:#fff;font-family:'Sora',sans-serif;font-weight:700;font-size:13.5px;
    display:inline-flex;align-items:center;gap:8px;
  }

  .sheet{
    max-width:800px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;
    box-shadow:0 30px 60px -30px rgba(24,26,42,.4);border:1px solid var(--line);
  }
  .head{
    background:linear-gradient(120deg,var(--indigo),var(--indigo-2),var(--violet));color:#fff;
    padding:30px 36px;display:flex;justify-content:space-between;align-items:center;gap:20px;
  }
  .head .college-name{font-size:22px;font-weight:800;}
  .head .college-meta{font-size:12px;color:rgba(255,255,255,.8);margin-top:4px;}
  .head .doc-title{text-align:right;}
  .head .doc-title b{display:block;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.75);}
  .head .doc-title span{font-size:17px;font-weight:700;}

  .info-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:0;border-bottom:2px solid var(--ink);}
  .info-cell{padding:16px 20px;border-right:1px solid var(--line);border-bottom:1px solid var(--line);}
  .info-cell:nth-child(4n){border-right:none;}
  .info-cell label{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:4px;}
  .info-cell b{font-size:14px;}

  .section-title{
    background:var(--cream);padding:10px 20px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink);
    border-bottom:1px solid var(--line);
  }

  table{width:100%;border-collapse:collapse;}
  thead th{
    background:var(--ink);color:#fff;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;
    padding:12px 20px;font-weight:600;
  }
  thead th.center, tbody td.center{text-align:center;}
  tbody td{padding:13px 20px;font-size:13.5px;border-bottom:1px solid var(--line);}
  tbody tr:last-child td{border-bottom:none;}
  tbody tr:nth-child(even){background:#FAFAFD;}
  .grade-pill{
    display:inline-block;padding:3px 12px;border-radius:100px;font-size:11.5px;font-weight:700;
    background:rgba(92,86,224,.12);color:var(--indigo-2);
  }

  .total-row td{background:var(--ink);color:#fff;font-weight:700;font-size:14px;padding:16px 20px;}

  .summary-row{display:grid;grid-template-columns:1fr 1fr 1fr;border-top:2px solid var(--ink);}
  .summary-box{padding:22px 20px;text-align:center;border-right:1px solid var(--line);}
  .summary-box:last-child{border-right:none;}
  .summary-box label{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:6px;}
  .summary-box .big{font-family:'Sora',sans-serif;font-size:26px;font-weight:800;}
  .summary-box.highlight{background:linear-gradient(120deg,var(--indigo-2),var(--violet));}
  .summary-box.highlight label{color:rgba(255,255,255,.8);}
  .summary-box.highlight .big{color:#fff;}

  .legend{display:flex;justify-content:center;gap:20px;flex-wrap:wrap;padding:16px 20px;background:var(--cream);font-size:11.5px;color:var(--muted);border-top:1px solid var(--line);}
  .legend b{color:var(--ink);}

  .footer{display:flex;justify-content:space-between;padding:26px 36px 34px;font-size:12px;color:var(--muted);}
  .footer .sign{text-align:center;}
  .footer .sign .line{width:150px;border-top:1px solid var(--muted);margin-bottom:6px;}

  @media print{
    body{background:#fff;padding:0;}
    .toolbar{display:none;}
    .sheet{box-shadow:none;border:none;max-width:100%;}
  }
</style>
</head>
<body>

<div class="toolbar">
  <a href="student_dashboard.php?tab=results" class="btn-back">← Back to My Results</a>
  <button class="btn-print" onclick="window.print()">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M6 9V2h12v7" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/><rect x="6" y="14" width="12" height="8" stroke="#fff" stroke-width="1.8"/><path d="M6 18H4a2 2 0 01-2-2v-4a2 2 0 012-2h16a2 2 0 012 2v4a2 2 0 01-2 2h-2" stroke="#fff" stroke-width="1.8"/></svg>
    Print / Save as PDF
  </button>
</div>

<div class="sheet">
  <div class="head">
    <div>
      <div class="college-name"><?= h($result['college_name']) ?></div>
      <div class="college-meta"><?= $result['college_code'] ? 'College Code: ' . h($result['college_code']) : 'CrustExams CET Platform' ?></div>
    </div>
    <div class="doc-title">
      <b>CET Result</b>
      <span><?= h($result['cet_title']) ?></span>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-cell"><label>Student Name</label><b><?= h($result['full_name']) ?></b></div>
    <div class="info-cell"><label>College Roll No.</label><b><?= h($result['roll_number']) ?></b></div>
    <div class="info-cell"><label>Course · Branch</label><b><?= h($result['course_name']) ?><?= $result['branch_name'] ? ' · ' . h($result['branch_name']) : '' ?></b></div>
    <div class="info-cell"><label>Submitted On</label><b><?= h(date('d M Y, h:i A', strtotime($result['submitted_at']))) ?></b></div>
  </div>

  <div class="section-title">Subject-wise Performance</div>
  <table>
    <thead>
      <tr>
        <th>Subject</th>
        <th class="center">Questions</th>
        <th class="center">Correct</th>
        <th class="center">Marks Obtained</th>
        <th class="center">Max Marks</th>
        <th class="center">Percentage</th>
        <th class="center">Grade</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($subjects as $s):
        $pct = $s['max_marks'] > 0 ? round(($s['obtained_marks'] / $s['max_marks']) * 100, 2) : 0;
      ?>
        <tr>
          <td><?= h($s['subject_name']) ?></td>
          <td class="center"><?= (int)$s['question_count'] ?></td>
          <td class="center"><?= (int)$s['correct_count'] ?></td>
          <td class="center"><?= (int)$s['obtained_marks'] ?></td>
          <td class="center"><?= (int)$s['max_marks'] ?></td>
          <td class="center"><?= $pct ?>%</td>
          <td class="center"><span class="grade-pill"><?= gradeFor($pct) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <tr class="total-row">
        <td colspan="3">Overall Total</td>
        <td class="center"><?= (int)$result['score'] ?></td>
        <td class="center"><?= (int)$result['total_marks'] ?></td>
        <td class="center"><?= $overallPct ?>%</td>
        <td class="center"><span class="grade-pill" style="background:rgba(255,255,255,.2);color:#fff;"><?= gradeFor($overallPct) ?></span></td>
      </tr>
    </tbody>
  </table>

  <div class="summary-row">
    <div class="summary-box">
      <label>Marks Obtained</label>
      <div class="big"><?= (int)$result['score'] ?> / <?= (int)$result['total_marks'] ?></div>
    </div>
    <div class="summary-box highlight">
      <label>Overall Percentage</label>
      <div class="big"><?= $overallPct ?>%</div>
    </div>
    <div class="summary-box">
      <label>Overall Grade</label>
      <div class="big"><?= gradeFor($overallPct) ?></div>
    </div>
  </div>

  <div class="legend">
    <span><b>A+</b> 90–100</span>
    <span><b>A</b> 80–89</span>
    <span><b>B+</b> 70–79</span>
    <span><b>B</b> 60–69</span>
    <span><b>C</b> 50–59</span>
    <span><b>D</b> 40–49</span>
    <span><b>F</b> Below 40</span>
  </div>

  <div class="footer">
    <div class="sign"><div class="line"></div>Student Signature</div>
    <div class="sign"><div class="line"></div>Class Teacher's Sign</div>
    <div class="sign"><div class="line"></div>College Admin Stamp</div>
  </div>
</div>

</body>
</html>
