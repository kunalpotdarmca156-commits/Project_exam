<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: login.html');
    exit;
}

$student_id = $_SESSION['student_id'];
$college_id = $_SESSION['college_id'];

$studentStmt = $conn->prepare("
    SELECT s.full_name, s.email, s.roll_number, s.course_id, s.branch_id, s.profile_pic, s.banner_image,
           co.course_name, br.branch_name
    FROM students s
    LEFT JOIN courses co ON s.course_id = co.id
    LEFT JOIN branches br ON s.branch_id = br.id
    WHERE s.id = ?
");
$studentStmt->bind_param("i", $student_id);
$studentStmt->execute();
$student = $studentStmt->get_result()->fetch_assoc();
$studentStmt->close();
$student_name = $student['full_name'] ?? '';

$collegeStmt = $conn->prepare("SELECT college_name FROM colleges WHERE id = ?");
$collegeStmt->bind_param("i", $college_id);
$collegeStmt->execute();
$college = $collegeStmt->get_result()->fetch_assoc();
$collegeStmt->close();

// ---- CETs assigned to this student's exact course + branch ----
$cetStmt = $conn->prepare("
    SELECT c.id, c.title, c.duration_minutes,
           (SELECT COUNT(*) FROM questions q WHERE q.cet_id = c.id) AS question_count,
           (SELECT COALESCE(SUM(marks),0) FROM questions q WHERE q.cet_id = c.id) AS total_marks,
           (SELECT GROUP_CONCAT(DISTINCT sub.subject_name ORDER BY sub.subject_name SEPARATOR ', ')
              FROM questions q2 JOIN subjects sub ON q2.subject_id = sub.id WHERE q2.cet_id = c.id) AS subjects_covered,
           a.id AS attempt_id, a.status AS attempt_status, a.declared
    FROM cets c
    LEFT JOIN attempts a ON a.cet_id = c.id AND a.student_id = ?
    WHERE c.status = 'assigned' AND c.course_id = ? AND (c.branch_id <=> ?)
    ORDER BY c.created_at DESC
");
$cetStmt->bind_param("iii", $student_id, $student['course_id'], $student['branch_id']);
$cetStmt->execute();
$cets = $cetStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pendingCount = count(array_filter($cets, fn($c) => !$c['attempt_id']));
$submittedCount = count(array_filter($cets, fn($c) => $c['attempt_status'] === 'submitted'));

// ---- results (submitted attempts only) ----
$resultStmt = $conn->prepare("
    SELECT a.id, a.score, a.total_marks, a.submitted_at, a.declared, a.declared_at,
           c.title,
           (SELECT GROUP_CONCAT(DISTINCT sub.subject_name ORDER BY sub.subject_name SEPARATOR ', ')
              FROM questions q2 JOIN subjects sub ON q2.subject_id = sub.id WHERE q2.cet_id = c.id) AS subjects_covered
    FROM attempts a
    JOIN cets c ON a.cet_id = c.id
    WHERE a.student_id = ? AND a.status = 'submitted'
    ORDER BY a.submitted_at DESC
");
$resultStmt->bind_param("i", $student_id);
$resultStmt->execute();
$results = $resultStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$declaredCount = count(array_filter($results, fn($r) => (int)$r['declared'] === 1));

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Dashboard — CrustExams</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
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

  .sidebar{position:fixed;top:18px;left:18px;bottom:18px;width:250px;background:#fff;border-radius:22px;box-shadow:0 20px 45px -25px rgba(24,26,42,.25);padding:24px 18px;display:flex;flex-direction:column;z-index:50;}
  .brand{display:flex;align-items:center;gap:10px;font-family:'Sora',sans-serif;font-weight:800;font-size:17.5px;padding:0 6px 18px;border-bottom:1px solid var(--line);margin-bottom:18px;}
  .brand-mark{width:32px;height:32px;border-radius:9px;background:var(--cream);display:flex;align-items:center;justify-content:center;overflow:hidden;flex:none;}
  .brand-mark img{width:100%;height:100%;object-fit:contain;}
  .brand span{color:var(--indigo-2);}
  .nav-link{position:relative;display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:14px;font-size:13.5px;font-weight:600;color:var(--muted);margin-bottom:6px;cursor:pointer;background:transparent;border:none;width:100%;text-align:left;transition:color .25s ease;}
  .nav-link .ic{width:30px;height:30px;border-radius:9px;background:var(--cream);display:flex;align-items:center;justify-content:center;flex:none;transition:background .25s ease, box-shadow .25s ease;}
  .nav-link.active{color:var(--ink);}
  .nav-link.active .ic{background:linear-gradient(120deg,var(--indigo-2),var(--violet));box-shadow:0 10px 20px -8px rgba(92,86,224,.6);}
  .nav-link.active .ic svg path, .nav-link.active .ic svg circle{stroke:#fff;}
  .nav-link:not(.active):hover{color:var(--ink);}
  .nav-link:not(.active):hover .ic{background:#EFEDFD;}
  .side-help{margin-top:auto;background:linear-gradient(150deg,var(--indigo-2),var(--violet));border-radius:18px;padding:20px;color:#fff;position:relative;overflow:hidden;}
  .side-help::before{content:"";position:absolute;top:-30px;right:-30px;width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.15);}
  .side-help p{font-size:12.5px;font-weight:700;margin-bottom:2px;position:relative;}
  .side-help span{font-size:11.5px;color:rgba(255,255,255,.8);display:block;margin-bottom:12px;position:relative;}
  .admin-chip{display:flex;align-items:center;gap:9px;position:relative;}
  .admin-avatar{width:30px;height:30px;border-radius:50%;background:var(--amber);color:var(--ink);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex:none;overflow:hidden;}
  .admin-avatar img{width:100%;height:100%;object-fit:cover;}
  .side-help small{font-size:11px;opacity:.85;display:block;}
  .side-help b{font-size:12.5px;}
  .logout-btn{margin-top:14px;width:100%;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.4);color:#fff;padding:9px;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;transition:background .2s ease;}
  .logout-btn:hover{background:rgba(255,255,255,.3);}

  .main{margin-left:290px;padding:26px 32px 50px;}
  .topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;flex-wrap:wrap;gap:16px;}
  .crumb{font-size:12.5px;color:var(--muted);margin-bottom:4px;}
  .crumb b{color:var(--ink);}
  .topbar h1{font-size:22px;font-weight:800;}
  .top-admin{display:flex;align-items:center;gap:9px;background:#fff;padding:6px 14px 6px 6px;border-radius:100px;box-shadow:0 6px 16px -12px rgba(24,26,42,.2);}
  .top-admin span{font-size:13px;font-weight:600;}
  .banner{display:none;align-items:center;gap:10px;padding:13px 18px;border-radius:14px;font-size:14px;font-weight:500;margin-bottom:22px;}

  /* ---------------- PROFILE TAB ---------------- */
  .profile-banner-wrap{position:relative;margin-bottom:60px;}
  .profile-banner{position:relative;height:200px;border-radius:20px;overflow:hidden;background:linear-gradient(120deg,var(--indigo),var(--indigo-2),var(--violet),var(--coral));background-size:220% 220%;animation:profileGradientShift 12s ease-in-out infinite;}
  @keyframes profileGradientShift{0%,100%{background-position:0% 30%;}50%{background-position:100% 70%;}}
  .profile-banner img{width:100%;height:100%;object-fit:cover;}
  .banner-edit{position:absolute;top:14px;right:14px;background:rgba(24,26,42,.55);color:#fff;border:1px solid rgba(255,255,255,.4);padding:9px 16px;border-radius:100px;font-size:12.5px;font-weight:700;cursor:pointer;backdrop-filter:blur(4px);display:flex;align-items:center;gap:7px;z-index:2;}
  .profile-head{position:absolute;bottom:-46px;left:30px;display:flex;align-items:flex-end;gap:16px;z-index:3;}
  .profile-avatar-wrap{position:relative;width:96px;height:96px;flex:none;}
  .profile-avatar{width:96px;height:96px;border-radius:50%;border:4px solid #fff;background:var(--cream);object-fit:cover;box-shadow:0 10px 24px -10px rgba(24,26,42,.4);}
  .avatar-edit{position:absolute;bottom:2px;right:2px;width:28px;height:28px;border-radius:50%;background:var(--ink);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;border:2px solid #fff;}
  .profile-head-txt{padding-bottom:6px;}
  .profile-head-txt h2{font-size:19px;background:#fff;padding:4px 10px;border-radius:8px;display:inline-block;}
  .profile-head-txt span{display:block;font-size:12px;color:var(--muted);background:#fff;padding:2px 10px;border-radius:8px;margin-top:4px;display:inline-block;}

  .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;padding:24px 26px;}
  .form-field{margin-bottom:16px;}
  .form-field label{display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--ink);}
  .form-field input{width:100%;padding:12px 15px;border-radius:12px;border:1.5px solid var(--line);background:var(--cream);font-size:14px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s ease, background .2s ease;}
  .form-field input:focus{border-color:var(--indigo-2);background:#fff;}
  .form-field input:disabled{color:var(--muted);cursor:not-allowed;}
  .form-field .hint{font-size:11.5px;color:var(--muted);margin-top:5px;display:block;}
  .form-actions{padding:4px 26px 26px;display:flex;justify-content:flex-end;}
  .btn-save{padding:13px 28px;border-radius:100px;border:none;background:linear-gradient(120deg,var(--indigo-2),var(--violet));color:#fff;font-weight:700;font-size:13.5px;cursor:pointer;transition:transform .2s ease, box-shadow .2s ease;}
  .btn-save:hover{transform:translateY(-2px);box-shadow:0 14px 26px -10px rgba(92,86,224,.6);}

  /* ---------------- MODAL ---------------- */
  .modal-overlay{display:none;position:fixed;inset:0;background:rgba(24,26,42,.55);backdrop-filter:blur(3px);align-items:center;justify-content:center;z-index:200;padding:20px;}
  .modal-overlay.show{display:flex;}
  .modal{background:#fff;border-radius:22px;padding:32px;width:100%;max-width:440px;box-shadow:0 40px 80px -30px rgba(0,0,0,.5);animation:modalIn .3s cubic-bezier(.2,.7,.3,1);max-height:88vh;overflow-y:auto;}
  @keyframes modalIn{from{opacity:0;transform:translateY(14px) scale(.98);}to{opacity:1;transform:translateY(0) scale(1);}}
  .modal h3{font-size:19px;font-weight:700;margin-bottom:6px;}
  .modal p.sub{color:var(--muted);font-size:13.5px;margin-bottom:22px;}
  .modal-actions{display:flex;gap:10px;margin-top:22px;}
  .btn-cancel{flex:1;padding:12px;border-radius:100px;border:1.5px solid var(--line);background:#fff;font-weight:600;font-size:14px;cursor:pointer;font-family:'Sora',sans-serif;}
  .btn-confirm{flex:1;padding:12px;border-radius:100px;border:none;background:var(--ink);color:#fff;font-weight:600;font-size:14px;cursor:pointer;font-family:'Sora',sans-serif;}

  /* ---------------- CROP MODAL ---------------- */
  .crop-stage{max-height:380px;overflow:hidden;background:#111;border-radius:14px;}
  .crop-stage.avatar .cropper-view-box,
  .crop-stage.avatar .cropper-face{border-radius:50%;}
  .crop-stage.avatar .cropper-view-box{box-shadow:0 0 0 1px #fff;outline:0;}

  .tab-panel{display:none;}
  .tab-panel.active{display:block;animation:panelIn .4s cubic-bezier(.2,.7,.3,1);}
  @keyframes panelIn{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);}}

  .stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:24px;}
  .stat-card{background:#fff;border-radius:18px;padding:22px;display:flex;justify-content:space-between;align-items:flex-start;box-shadow:0 16px 36px -26px rgba(24,26,42,.35);}
  .stat-card span{font-size:12.5px;color:var(--muted);display:block;margin-bottom:6px;}
  .stat-card b{font-family:'Sora',sans-serif;font-size:24px;font-weight:800;}
  .stat-icon{width:46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center;flex:none;}
  .stat-card:nth-child(1) .stat-icon{background:linear-gradient(135deg,var(--indigo-2),var(--violet));}
  .stat-card:nth-child(2) .stat-icon{background:linear-gradient(135deg,var(--amber),#ff9d47);}
  .stat-card:nth-child(3) .stat-icon{background:linear-gradient(135deg,var(--coral),#ff9080);}

  .welcome-card{
    border-radius:20px;padding:28px 30px;margin-bottom:24px;color:#fff;position:relative;overflow:hidden;
    background:linear-gradient(120deg,var(--indigo),var(--indigo-2),var(--violet));background-size:200% 200%;
    animation:gradientShift 12s ease-in-out infinite;
  }
  @keyframes gradientShift{0%,100%{background-position:0% 30%;}50%{background-position:100% 70%;}}
  .welcome-card h2{font-size:21px;margin-bottom:6px;}
  .welcome-card p{font-size:13.5px;color:rgba(255,255,255,.85);}

  .panel{background:#fff;border-radius:20px;box-shadow:0 16px 36px -26px rgba(24,26,42,.35);overflow:hidden;margin-bottom:24px;}
  .panel-head{padding:22px 26px;border-bottom:1px solid var(--line);}
  .panel-head h2{font-size:17px;font-weight:700;}
  .panel-head p{font-size:12.5px;color:var(--muted);margin-top:2px;}

  .cet-list{padding:6px 26px 20px;}
  .cet-row{display:flex;align-items:center;gap:16px;padding:18px 0;border-bottom:1px solid var(--line);flex-wrap:wrap;}
  .cet-row:last-child{border-bottom:none;}
  .cet-icon{width:42px;height:42px;border-radius:12px;background:var(--cream);color:var(--indigo-2);display:flex;align-items:center;justify-content:center;flex:none;}
  .cet-info{flex:1;min-width:200px;}
  .cet-info p{font-size:14.5px;font-weight:700;}
  .cet-info span{font-size:12.5px;color:var(--muted);}
  .status-badge{display:inline-flex;padding:5px 13px;border-radius:100px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;}
  .status-badge.pending{background:rgba(92,86,224,.1);color:var(--indigo-2);}
  .status-badge.submitted{background:rgba(255,182,72,.18);color:#b8790e;}
  .status-badge.declared{background:rgba(52,211,153,.16);color:#0f8a5f;}
  .btn-start{
    padding:11px 22px;border-radius:100px;border:none;cursor:pointer;
    background:linear-gradient(120deg,var(--indigo-2),var(--violet));color:#fff;font-family:'Sora',sans-serif;font-weight:700;font-size:13px;
    transition:transform .2s ease, box-shadow .2s ease;
  }
  .btn-start:hover{transform:translateY(-2px);box-shadow:0 12px 22px -8px rgba(92,86,224,.55);}
  .empty-row{padding:40px 26px;text-align:center;color:var(--muted);font-size:14px;}

  .result-row{padding:20px 0;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:18px;flex-wrap:wrap;}
  .result-row:last-child{border-bottom:none;}
  .score-ring{
    width:58px;height:58px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;
    font-family:'Sora',sans-serif;font-weight:800;font-size:13px;color:#fff;
    background:conic-gradient(var(--indigo-2) var(--pct,0%), var(--cream) 0);
  }
  .score-ring span{width:46px;height:46px;border-radius:50%;background:#fff;color:var(--ink);display:flex;align-items:center;justify-content:center;font-size:12px;}
  .result-info{flex:1;min-width:200px;}
  .result-info p{font-size:14.5px;font-weight:700;}
  .result-info span{font-size:12.5px;color:var(--muted);}
  .btn-mini{padding:8px 16px;border-radius:100px;border:1.5px solid var(--line);background:#fff;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s ease;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;}
  .btn-mini.primary{border-color:var(--indigo-2);color:var(--indigo-2);}
  .btn-mini.primary:hover{background:var(--indigo-2);color:#fff;}

  @media(max-width:980px){
    .sidebar{position:relative;top:0;left:0;bottom:auto;width:auto;margin:14px;height:auto;}
    .main{margin-left:0;padding:0 16px 40px;}
    .stats-row{grid-template-columns:1fr;}
  }
</style>
</head>
<body>

<aside class="sidebar">
  <div class="brand">
    <div class="brand-mark"><img src="logo_image/logo.png" alt="CrustExams logo"></div>
    Crust<span>Exams</span>
  </div>

  <button class="nav-link active" data-tab="dashboard">
    <span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="8" height="8" rx="2" stroke="#8A8AA3" stroke-width="1.8"/><rect x="13" y="3" width="8" height="8" rx="2" stroke="#8A8AA3" stroke-width="1.8"/><rect x="3" y="13" width="8" height="8" rx="2" stroke="#8A8AA3" stroke-width="1.8"/><rect x="13" y="13" width="8" height="8" rx="2" stroke="#8A8AA3" stroke-width="1.8"/></svg></span>
    Dashboard
  </button>
  <button class="nav-link" data-tab="profile">
    <span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4 20a8 8 0 0116 0" stroke="#8A8AA3" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="8" r="4" stroke="#8A8AA3" stroke-width="1.8"/></svg></span>
    My Profile
  </button>
  <button class="nav-link" data-tab="results">
    <span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 11l3 3L22 4" stroke="#8A8AA3" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" stroke="#8A8AA3" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
    My Results
  </button>

  <div class="side-help">
    <p>Student</p>
    <span><?= h($college['college_name'] ?? '') ?></span>
    <div class="admin-chip">
      <div class="admin-avatar">
        <?php if (!empty($student['profile_pic'])): ?>
          <img src="<?= h($student['profile_pic']) ?>" alt="">
        <?php else: ?>
          <?= h(strtoupper(substr($student_name ?: 'S', 0, 1))) ?>
        <?php endif; ?>
      </div>
      <div>
        <b><?= h($student_name) ?></b>
        <small><?= h($student['roll_number'] ?? '') ?></small>
      </div>
    </div>
    <form action="logout.php" method="GET">
      <button type="submit" class="logout-btn">Log out</button>
    </form>
  </div>
</aside>

<main class="main">
  <div class="banner" id="banner"></div>

  <div class="topbar">
    <div>
      <div class="crumb" id="crumb">Pages / <b>Dashboard</b></div>
      <h1 id="pageTitle">Dashboard</h1>
    </div>
    <div class="top-admin">
      <div class="admin-avatar">
        <?php if (!empty($student['profile_pic'])): ?>
          <img src="<?= h($student['profile_pic']) ?>" alt="">
        <?php else: ?>
          <?= h(strtoupper(substr($student_name ?: 'S', 0, 1))) ?>
        <?php endif; ?>
      </div>
      <span><?= h(explode(' ', $student_name)[0] ?? 'Student') ?></span>
    </div>
  </div>

  <!-- ================= DASHBOARD TAB ================= -->
  <section class="tab-panel active" id="tab-dashboard">
    <div class="welcome-card">
      <h2>Hi <?= h(explode(' ', $student_name)[0] ?? '') ?>, ready for your next CET?</h2>
      <p><?= h($student['course_name'] ?? '') ?><?= !empty($student['branch_name']) ? ' · ' . h($student['branch_name']) : '' ?> · <?= h($college['college_name'] ?? '') ?></p>
    </div>

    <div class="stats-row">
      <div class="stat-card">
        <div><span>To Attempt</span><b><?= (int)$pendingCount ?></b></div>
        <div class="stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M9 11l3 3L22 4" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
      </div>
      <div class="stat-card">
        <div><span>Submitted</span><b><?= (int)$submittedCount ?></b></div>
        <div class="stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
      </div>
      <div class="stat-card">
        <div><span>Results Declared</span><b><?= (int)$declaredCount ?></b></div>
        <div class="stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2l2.5 6.5L21 9l-5 4.5L17.5 21 12 17l-5.5 4L8 13.5 3 9l6.5-.5L12 2z" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"/></svg></div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <h2>Assigned CETs</h2>
        <p>Only tests assigned to your course &amp; branch appear here.</p>
      </div>
      <div class="cet-list">
        <?php if (empty($cets)): ?>
          <div class="empty-row">No CETs assigned to you yet. Check back once your teacher assigns one.</div>
        <?php else: foreach ($cets as $c): ?>
          <div class="cet-row">
            <div class="cet-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M9 11l3 3L22 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
            <div class="cet-info">
              <p><?= h($c['title']) ?></p>
              <span><?= h($c['subjects_covered'] ?: '—') ?> · <?= (int)$c['duration_minutes'] ?> min · <?= (int)$c['question_count'] ?> questions · <?= (int)$c['total_marks'] ?> marks</span>
            </div>
            <?php if (!$c['attempt_id']): ?>
              <a href="start_exam.php?cet_id=<?= (int)$c['id'] ?>" class="btn-start" onclick="return confirm('Start this exam now? Once started, the timer cannot be paused.');">Start Exam</a>
            <?php elseif ($c['attempt_status'] === 'in_progress'): ?>
              <a href="take_exam.php?attempt_id=<?= (int)$c['attempt_id'] ?>" class="btn-start">Resume Exam</a>
            <?php else: ?>
              <span class="status-badge <?= $c['declared'] ? 'declared' : 'submitted' ?>"><?= $c['declared'] ? 'Result out' : 'Submitted' ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </section>

  <!-- ================= PROFILE TAB ================= -->
  <section class="tab-panel" id="tab-profile">
    <div class="panel" style="overflow:visible;">
      <div style="padding:26px 26px 0;">
        <div class="profile-banner-wrap">
          <div class="profile-banner">
            <?php if (!empty($student['banner_image'])): ?><img src="<?= h($student['banner_image']) ?>" alt=""><?php endif; ?>
            <button type="button" class="banner-edit" onclick="document.getElementById('bannerFileInput').click()">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M12 20h9" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/></svg>
              Change banner
            </button>
          </div>
          <div class="profile-head">
            <div class="profile-avatar-wrap">
              <img class="profile-avatar" src="<?= !empty($student['profile_pic']) ? h($student['profile_pic']) : 'https://api.dicebear.com/7.x/initials/svg?seed=' . urlencode($student_name) ?>" alt="">
              <div class="avatar-edit" onclick="document.getElementById('avatarFileInput').click()">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M12 20h9" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/></svg>
              </div>
            </div>
            <div class="profile-head-txt">
              <h2><?= h($student_name) ?></h2>
              <span>Student · <?= h($college['college_name'] ?? '') ?></span>
            </div>
          </div>
        </div>
      </div>

      <form action="update_student_profile.php" method="POST" enctype="multipart/form-data" id="profileForm">
        <input type="file" name="avatar" id="avatarFileInput" accept="image/*" style="display:none;" onchange="handleFileSelect(this,'avatar')">
        <input type="file" name="banner" id="bannerFileInput" accept="image/*" style="display:none;" onchange="handleFileSelect(this,'banner')">

        <div class="form-grid" style="padding-top:36px;">
          <div class="form-field">
            <label>Full name</label>
            <input type="text" name="full_name" value="<?= h($student_name) ?>" required>
          </div>
          <div class="form-field">
            <label>Roll number</label>
            <input type="text" value="<?= h($student['roll_number'] ?? '') ?>" disabled>
            <span class="hint">Set by your College Admin — contact them to change it.</span>
          </div>
          <div class="form-field">
            <label>Email (optional)</label>
            <input type="email" name="email" value="<?= h($student['email'] ?? '') ?>" placeholder="you@example.com">
          </div>
          <div class="form-field">
            <label>Course · Branch</label>
            <input type="text" value="<?= h($student['course_name'] ?? 'Not assigned yet') ?><?= !empty($student['branch_name']) ? ' · ' . h($student['branch_name']) : '' ?>" disabled>
          </div>
          <div class="form-field">
            <label>New password (optional)</label>
            <input type="password" name="new_password" placeholder="Leave blank to keep current password" minlength="6">
          </div>
          <div class="form-field">
            <label>Confirm new password</label>
            <input type="password" name="confirm_password" placeholder="Re-enter new password" minlength="6">
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn-save">Save Changes</button>
        </div>
      </form>
    </div>
  </section>

  <!-- ================= RESULTS TAB ================= -->
  <section class="tab-panel" id="tab-results">
    <div class="panel">
      <div class="panel-head">
        <h2>My Results</h2>
        <p>Your score only appears here once your teacher declares it for that CET.</p>
      </div>
      <div class="cet-list">
        <?php if (empty($results)): ?>
          <div class="empty-row">You haven't submitted any CETs yet.</div>
        <?php else: foreach ($results as $r):
          $pct = (!empty($r['declared']) && $r['total_marks'] > 0) ? round(($r['score'] / $r['total_marks']) * 100) : 0;
        ?>
          <div class="result-row">
            <?php if ($r['declared']): ?>
              <div class="score-ring" style="--pct:<?= $pct ?>%;"><span><?= $pct ?>%</span></div>
            <?php else: ?>
              <div class="cet-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 7v5l3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></div>
            <?php endif; ?>
            <div class="result-info">
              <p><?= h($r['title']) ?></p>
              <span><?= h($r['subjects_covered'] ?: '—') ?> · Submitted <?= h(date('d M Y, h:i A', strtotime($r['submitted_at']))) ?></span>
            </div>
            <?php if ($r['declared']): ?>
              <span class="status-badge declared"><?= (int)$r['score'] ?> / <?= (int)$r['total_marks'] ?></span>
              <a href="download_result.php?attempt_id=<?= (int)$r['id'] ?>" target="_blank" class="btn-mini primary">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" style="vertical-align:-2px;margin-right:4px;"><path d="M12 3v12m0 0l-4-4m4 4l4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                Download PDF
              </a>
            <?php else: ?>
              <span class="status-badge submitted">Pending declaration</span>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </section>
</main>

<!-- ---------------- CROP MODAL ---------------- -->
<div class="modal-overlay" id="cropModal">
  <div class="modal" style="max-width:520px;">
    <h3 id="cropModalTitle">Crop Image</h3>
    <p class="sub">Drag to reposition, drag a corner to zoom, then save.</p>
    <div class="crop-stage" id="cropStage">
      <img id="cropperImage" style="max-width:100%;display:block;">
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-cancel" onclick="cancelCrop()">Cancel</button>
      <button type="button" class="btn-confirm" onclick="confirmCrop()">Save &amp; Upload</button>
    </div>
  </div>
</div>

<script>
  function openModal(id){ document.getElementById(id).classList.add('show'); }
  function closeModal(id){ document.getElementById(id).classList.remove('show'); }

  let cropper = null;
  let cropTarget = null;

  function handleFileSelect(input, target){
    const file = input.files[0];
    if (!file) return;
    cropTarget = target;
    const reader = new FileReader();
    reader.onload = function(e){
      document.getElementById('cropperImage').src = e.target.result;
      document.getElementById('cropModalTitle').textContent = target === 'avatar' ? 'Crop your photo' : 'Crop your banner';
      document.getElementById('cropStage').className = 'crop-stage ' + target;
      openModal('cropModal');
      if (cropper) cropper.destroy();
      cropper = new Cropper(document.getElementById('cropperImage'), {
        aspectRatio: target === 'avatar' ? 1 : 4.2,
        viewMode: 1,
        dragMode: 'move',
        autoCropArea: 1,
        background: false,
        guides: false
      });
    };
    reader.readAsDataURL(file);
  }

  function cancelCrop(){
    if (cropper) { cropper.destroy(); cropper = null; }
    document.getElementById('avatarFileInput').value = '';
    document.getElementById('bannerFileInput').value = '';
    closeModal('cropModal');
  }

  function confirmCrop(){
    if (!cropper) return;
    const size = cropTarget === 'avatar' ? { width: 400, height: 400 } : { width: 1200, height: 285 };
    cropper.getCroppedCanvas(size).toBlob(function(blob){
      const filename = cropTarget + '.png';
      const dt = new DataTransfer();
      dt.items.add(new File([blob], filename, { type: 'image/png' }));
      const targetInput = cropTarget === 'avatar' ? document.getElementById('avatarFileInput') : document.getElementById('bannerFileInput');
      targetInput.files = dt.files;
      cropper.destroy();
      cropper = null;
      closeModal('cropModal');
      document.getElementById('profileForm').submit();
    }, 'image/png');
  }
</script>

<script>
  const tabLabels = { dashboard: 'Dashboard', profile: 'My Profile', results: 'My Results' };
  function switchTab(tab){
    document.querySelectorAll('.nav-link').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    document.getElementById('pageTitle').textContent = tabLabels[tab];
    document.getElementById('crumb').innerHTML = 'Pages / <b>' + tabLabels[tab] + '</b>';
  }
  document.querySelectorAll('.nav-link').forEach(btn => btn.addEventListener('click', () => switchTab(btn.dataset.tab)));

  const params = new URLSearchParams(window.location.search);
  const initialTab = params.get('tab');
  if (initialTab && tabLabels[initialTab]) switchTab(initialTab);

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
