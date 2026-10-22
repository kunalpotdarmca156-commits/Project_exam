<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: login.html');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];
$college_id = $_SESSION['college_id'];

$teacherStmt = $conn->prepare("SELECT full_name, email, profile_pic, banner_image FROM teachers WHERE id = ?");
$teacherStmt->bind_param("i", $teacher_id);
$teacherStmt->execute();
$teacher = $teacherStmt->get_result()->fetch_assoc();
$teacherStmt->close();
$teacher_name = $teacher['full_name'] ?? '';

$collegeStmt = $conn->prepare("SELECT college_name FROM colleges WHERE id = ?");
$collegeStmt->bind_param("i", $college_id);
$collegeStmt->execute();
$college = $collegeStmt->get_result()->fetch_assoc();
$collegeStmt->close();

// ---- courses & branches (for Subject creation) ----
$courseStmt = $conn->prepare("SELECT id, course_name FROM courses WHERE college_id = ? ORDER BY course_name ASC");
$courseStmt->bind_param("i", $college_id);
$courseStmt->execute();
$courses = $courseStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$branchStmt = $conn->prepare("SELECT b.id, b.branch_name, b.course_id FROM branches b INNER JOIN courses c ON b.course_id = c.id WHERE c.college_id = ? ORDER BY b.branch_name ASC");
$branchStmt->bind_param("i", $college_id);
$branchStmt->execute();
$branchesFlat = $branchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$branchesByCourse = [];
foreach ($branchesFlat as $b) { $branchesByCourse[$b['course_id']][] = ['id' => $b['id'], 'name' => $b['branch_name']]; }

// ---- subjects (whole college, so any teacher can reuse an existing one) ----
$subjectStmt = $conn->prepare("
    SELECT sub.id, sub.subject_name, sub.course_id, sub.branch_id, sub.created_by_teacher_id,
           co.course_name, br.branch_name, t.full_name AS teacher_name
    FROM subjects sub
    JOIN courses co ON sub.course_id = co.id
    LEFT JOIN branches br ON sub.branch_id = br.id
    LEFT JOIN teachers t ON sub.created_by_teacher_id = t.id
    WHERE sub.college_id = ?
    ORDER BY sub.created_at DESC
");
$subjectStmt->bind_param("i", $college_id);
$subjectStmt->execute();
$subjects = $subjectStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ---- this teacher's CETs (now tied to course+branch directly; subjects live on questions) ----
$cetStmt = $conn->prepare("
    SELECT c.id, c.title, c.duration_minutes, c.status, c.assigned_at, c.created_at,
           c.course_id, c.branch_id, co.course_name, br.branch_name,
           (SELECT COUNT(*) FROM questions q WHERE q.cet_id = c.id) AS question_count,
           (SELECT GROUP_CONCAT(DISTINCT sub.subject_name ORDER BY sub.subject_name SEPARATOR ', ')
              FROM questions q2 JOIN subjects sub ON q2.subject_id = sub.id WHERE q2.cet_id = c.id) AS subjects_covered
    FROM cets c
    JOIN courses co ON c.course_id = co.id
    LEFT JOIN branches br ON c.branch_id = br.id
    WHERE c.teacher_id = ?
    ORDER BY c.created_at DESC
");
$cetStmt->bind_param("i", $teacher_id);
$cetStmt->execute();
$cets = $cetStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ---- attempt/results summary per CET, for the Results tab ----
$resultsSummaryStmt = $conn->prepare("
    SELECT c.id AS cet_id,
           COUNT(a.id) AS attempted_count,
           SUM(CASE WHEN a.declared = 1 THEN 1 ELSE 0 END) AS declared_count
    FROM cets c
    LEFT JOIN attempts a ON a.cet_id = c.id AND a.status = 'submitted'
    WHERE c.teacher_id = ?
    GROUP BY c.id
");
$resultsSummaryStmt->bind_param("i", $teacher_id);
$resultsSummaryStmt->execute();
$resultsSummaryRaw = $resultsSummaryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$resultsSummary = [];
foreach ($resultsSummaryRaw as $r) { $resultsSummary[$r['cet_id']] = $r; }

$subjectCount = count($subjects);
$cetCount = count($cets);
$assignedCount = count(array_filter($cets, fn($c) => $c['status'] === 'assigned'));

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teacher Dashboard — CrustExams</title>
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

  /* ---------------- SIDEBAR ---------------- */
  .sidebar{position:fixed;top:18px;left:18px;bottom:18px;width:250px;background:#fff;border-radius:22px;box-shadow:0 20px 45px -25px rgba(24,26,42,.25);padding:24px 18px;display:flex;flex-direction:column;z-index:50;}
  .brand{display:flex;align-items:center;gap:10px;font-family:'Sora',sans-serif;font-weight:800;font-size:17.5px;padding:0 6px 18px;border-bottom:1px solid var(--line);margin-bottom:18px;}
  .brand-mark{width:32px;height:32px;border-radius:9px;background:var(--cream);display:flex;align-items:center;justify-content:center;overflow:hidden;flex:none;}
  .brand-mark img{width:100%;height:100%;object-fit:contain;}
  .brand span{color:var(--indigo-2);}
  .nav-link{position:relative;display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:14px;font-size:13.5px;font-weight:600;color:var(--muted);margin-bottom:6px;cursor:pointer;background:transparent;border:none;width:100%;text-align:left;transition:color .25s ease;}
  .nav-link .ic{width:30px;height:30px;border-radius:9px;background:var(--cream);display:flex;align-items:center;justify-content:center;flex:none;transition:background .25s ease, box-shadow .25s ease;}
  .nav-link.active{color:var(--ink);}
  .nav-link.active .ic{background:linear-gradient(120deg,var(--indigo-2),var(--violet));box-shadow:0 10px 20px -8px rgba(92,86,224,.6);}
  .nav-link.active .ic svg path, .nav-link.active .ic svg rect, .nav-link.active .ic svg circle{stroke:#fff;}
  .nav-link:not(.active):hover{color:var(--ink);}
  .nav-link:not(.active):hover .ic{background:#EFEDFD;}
  .side-help{margin-top:auto;background:linear-gradient(150deg,var(--indigo-2),var(--violet));border-radius:18px;padding:20px;color:#fff;position:relative;overflow:hidden;}
  .side-help::before{content:"";position:absolute;top:-30px;right:-30px;width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.15);}
  .side-help p{font-size:12.5px;font-weight:700;margin-bottom:2px;position:relative;}
  .side-help span{font-size:11.5px;color:rgba(255,255,255,.8);display:block;margin-bottom:12px;position:relative;}
  .side-help .admin-chip{display:flex;align-items:center;gap:9px;position:relative;}
  .admin-avatar{width:30px;height:30px;border-radius:50%;background:var(--amber);color:var(--ink);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex:none;overflow:hidden;}
  .admin-avatar img{width:100%;height:100%;object-fit:cover;}
  .side-help small{font-size:11px;opacity:.85;display:block;}
  .side-help b{font-size:12.5px;}
  .logout-btn{margin-top:14px;width:100%;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.4);color:#fff;padding:9px;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;transition:background .2s ease;}
  .logout-btn:hover{background:rgba(255,255,255,.3);}

  /* ---------------- MAIN ---------------- */
  .main{margin-left:290px;padding:26px 32px 50px;}
  .topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;flex-wrap:wrap;gap:16px;}
  .crumb{font-size:12.5px;color:var(--muted);margin-bottom:4px;}
  .crumb b{color:var(--ink);}
  .topbar h1{font-size:22px;font-weight:800;}
  .top-right{display:flex;align-items:center;gap:16px;}
  .search-box{background:#fff;border:1px solid var(--line);border-radius:12px;padding:10px 16px;font-size:13px;color:var(--muted);width:210px;box-shadow:0 6px 16px -12px rgba(24,26,42,.2);}
  .top-admin{display:flex;align-items:center;gap:9px;background:#fff;padding:6px 14px 6px 6px;border-radius:100px;box-shadow:0 6px 16px -12px rgba(24,26,42,.2);}
  .top-admin .admin-avatar{width:30px;height:30px;}
  .top-admin span{font-size:13px;font-weight:600;}
  .banner{display:none;align-items:center;gap:10px;padding:13px 18px;border-radius:14px;font-size:14px;font-weight:500;margin-bottom:22px;}

  .tab-panel{display:none;}
  .tab-panel.active{display:block;animation:panelIn .4s cubic-bezier(.2,.7,.3,1);}
  @keyframes panelIn{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);}}

  .stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:24px;}
  .stat-card{background:#fff;border-radius:18px;padding:22px;display:flex;justify-content:space-between;align-items:flex-start;box-shadow:0 16px 36px -26px rgba(24,26,42,.35);transition:transform .3s ease, box-shadow .3s ease;}
  .stat-card:hover{transform:translateY(-4px);box-shadow:0 20px 40px -22px rgba(24,26,42,.4);}
  .stat-card span{font-size:12.5px;color:var(--muted);display:block;margin-bottom:6px;}
  .stat-card b{font-family:'Sora',sans-serif;font-size:24px;font-weight:800;}
  .stat-icon{width:46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center;flex:none;}
  .stat-card:nth-child(1) .stat-icon{background:linear-gradient(135deg,var(--indigo-2),var(--violet));}
  .stat-card:nth-child(2) .stat-icon{background:linear-gradient(135deg,var(--amber),#ff9d47);}
  .stat-card:nth-child(3) .stat-icon{background:linear-gradient(135deg,var(--coral),#ff9080);}

  .promo-row{display:grid;grid-template-columns:1.3fr 1fr;gap:20px;margin-bottom:24px;}
  .promo-card{border-radius:20px;padding:30px;position:relative;overflow:hidden;}
  .promo-a{background:#fff;box-shadow:0 16px 36px -26px rgba(24,26,42,.35);display:flex;align-items:center;gap:26px;}
  .promo-a .txt span{font-size:12px;color:var(--muted);font-weight:600;}
  .promo-a .txt h3{font-size:20px;margin:6px 0 10px;}
  .promo-a .txt p{font-size:13.5px;color:var(--muted);line-height:1.6;max-width:280px;}
  .promo-a .graphic{flex:none;width:130px;height:130px;border-radius:20px;background:linear-gradient(140deg,var(--indigo-2),var(--violet),var(--coral));display:flex;align-items:center;justify-content:center;animation:floaty 4s ease-in-out infinite;}
  @keyframes floaty{0%,100%{transform:translateY(0) rotate(0deg);}50%{transform:translateY(-8px) rotate(3deg);}}
  .promo-b{background:linear-gradient(140deg,var(--ink),#242645);color:#fff;display:flex;flex-direction:column;justify-content:center;}
  .promo-b h3{font-size:19px;margin-bottom:10px;}
  .promo-b p{font-size:13.5px;color:rgba(255,255,255,.75);line-height:1.6;margin-bottom:18px;}
  .promo-b button{align-self:flex-start;background:linear-gradient(120deg,var(--indigo-2),var(--violet));color:#fff;border:none;padding:11px 22px;border-radius:100px;font-weight:700;font-size:13px;cursor:pointer;transition:transform .2s ease;}
  .promo-b button:hover{transform:translateY(-2px);}

  .panel{background:#fff;border-radius:20px;box-shadow:0 16px 36px -26px rgba(24,26,42,.35);overflow:hidden;margin-bottom:24px;}
  .panel-head{display:flex;justify-content:space-between;align-items:center;padding:22px 26px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:12px;}
  .panel-head h2{font-size:17px;font-weight:700;}
  .panel-head p{font-size:12.5px;color:var(--muted);margin-top:2px;}

  .mini-list{padding:6px 26px 20px;}
  .mini-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--line);}
  .mini-row:last-child{border-bottom:none;}
  .mini-avatar{width:34px;height:34px;border-radius:50%;background:var(--cream);color:var(--indigo-2);display:flex;align-items:center;justify-content:center;font-family:'Sora',sans-serif;font-weight:700;font-size:13px;flex:none;}
  .mini-row .info p{font-size:13.5px;font-weight:600;}
  .mini-row .info span{font-size:12px;color:var(--muted);}
  .mini-empty{padding:30px 26px;text-align:center;color:var(--muted);font-size:13.5px;}

  .btn-add{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:100px;border:none;cursor:pointer;background:linear-gradient(120deg,var(--indigo-2),var(--violet));color:#fff;font-family:'Sora',sans-serif;font-weight:600;font-size:13.5px;transition:transform .2s ease, box-shadow .2s ease;}
  .btn-add:hover{transform:translateY(-2px);box-shadow:0 12px 22px -8px rgba(92,86,224,.55);}
  .btn-add:disabled{opacity:.45;cursor:not-allowed;transform:none;box-shadow:none;}

  table{width:100%;border-collapse:collapse;}
  thead th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);padding:14px 26px;border-bottom:1px solid var(--line);font-weight:600;}
  tbody td{padding:15px 26px;font-size:13.5px;border-bottom:1px solid var(--line);vertical-align:middle;}
  tbody tr:last-child td{border-bottom:none;}
  tbody tr:hover{background:var(--cream);}
  .name-cell{display:flex;align-items:center;gap:10px;font-weight:600;}
  .muted-cell{color:var(--muted);font-family:'IBM Plex Mono',monospace;font-size:12px;}
  .course-badge{display:inline-flex;padding:4px 11px;border-radius:100px;background:rgba(92,86,224,.1);color:var(--indigo-2);font-size:12px;font-weight:600;white-space:nowrap;}
  .status-badge{display:inline-flex;padding:4px 12px;border-radius:100px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;}
  .status-badge.draft{background:rgba(255,182,72,.18);color:#b8790e;}
  .status-badge.assigned{background:rgba(52,211,153,.16);color:#0f8a5f;}
  .row-actions{display:flex;gap:8px;flex-wrap:wrap;}
  .icon-btn{width:32px;height:32px;border-radius:9px;border:1.5px solid var(--line);background:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s ease;}
  .icon-btn.edit:hover{border-color:var(--indigo-2);background:var(--indigo-2);}
  .icon-btn.edit:hover svg path{stroke:#fff;}
  .icon-btn.del:hover{border-color:var(--coral);background:var(--coral);}
  .icon-btn.del:hover svg path{stroke:#fff;}
  .empty-row{padding:40px 26px;text-align:center;color:var(--muted);font-size:14px;}
  .btn-mini{padding:8px 16px;border-radius:100px;border:1.5px solid var(--line);background:#fff;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s ease;white-space:nowrap;}
  .btn-mini.primary{border-color:var(--indigo-2);color:var(--indigo-2);}
  .btn-mini.primary:hover{background:var(--indigo-2);color:#fff;}
  .btn-mini.assign{border-color:#0f8a5f;color:#0f8a5f;}
  .btn-mini.assign:hover{background:#0f8a5f;color:#fff;}
  .btn-mini.unassign{border-color:var(--coral);color:var(--coral);}
  .btn-mini.unassign:hover{background:var(--coral);color:#fff;}

  /* ---------------- PROFILE TAB ---------------- */
  .profile-banner-wrap{position:relative;margin-bottom:60px;}
  .profile-banner{position:relative;height:200px;border-radius:20px;overflow:hidden;background:linear-gradient(120deg,var(--indigo),var(--indigo-2),var(--violet),var(--coral));background-size:220% 220%;animation:gradientShift 12s ease-in-out infinite;}
  @keyframes gradientShift{0%,100%{background-position:0% 30%;}50%{background-position:100% 70%;}}
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
  .form-field input, .form-field select{width:100%;padding:12px 15px;border-radius:12px;border:1.5px solid var(--line);background:var(--cream);font-size:14px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s ease, background .2s ease;}
  .form-field input:focus, .form-field select:focus{border-color:var(--indigo-2);background:#fff;}
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
  .modal .field{margin-bottom:14px;}
  .modal label{display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;}
  .modal input, .modal select{width:100%;padding:12px 15px;border-radius:12px;border:1.5px solid var(--line);background:var(--cream);font-size:14px;outline:none;transition:border-color .2s ease, background .2s ease;}
  .modal input:focus, .modal select:focus{border-color:var(--indigo-2);background:#fff;}
  .modal-actions{display:flex;gap:10px;margin-top:22px;}
  .btn-cancel{flex:1;padding:12px;border-radius:100px;border:1.5px solid var(--line);background:#fff;font-weight:600;font-size:14px;cursor:pointer;font-family:'Sora',sans-serif;}
  .btn-confirm{flex:1;padding:12px;border-radius:100px;border:none;background:var(--ink);color:#fff;font-weight:600;font-size:14px;cursor:pointer;font-family:'Sora',sans-serif;}
  .field-row2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}

  /* ---------------- CROP MODAL ---------------- */
  .crop-stage{max-height:380px;overflow:hidden;background:#111;border-radius:14px;}
  .crop-stage.avatar .cropper-view-box,
  .crop-stage.avatar .cropper-face{border-radius:50%;}
  .crop-stage.avatar .cropper-view-box{box-shadow:0 0 0 1px #fff;outline:0;}

  @media(max-width:980px){
    .sidebar{position:relative;top:0;left:0;bottom:auto;width:auto;margin:14px;height:auto;}
    .main{margin-left:0;padding:0 16px 40px;}
    .stats-row, .promo-row, .form-grid{grid-template-columns:1fr;}
    table{display:block;overflow-x:auto;}
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
    Teacher Profile
  </button>
  <button class="nav-link" data-tab="subjects">
    <span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4 19.5V6a2 2 0 012-2h11a1 1 0 011 1v13" stroke="#8A8AA3" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 22a2 2 0 01-2-2v0a2 2 0 012-2h13" stroke="#8A8AA3" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
    Create a Subject
  </button>
  <button class="nav-link" data-tab="cets">
    <span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 11l3 3L22 4" stroke="#8A8AA3" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" stroke="#8A8AA3" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
    Create a CET
  </button>
  <button class="nav-link" data-tab="assign">
    <span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4 19v-1a4 4 0 014-4h4a4 4 0 014 4v1" stroke="#8A8AA3" stroke-width="1.8" stroke-linecap="round"/><circle cx="10" cy="7" r="3" stroke="#8A8AA3" stroke-width="1.8"/></svg></span>
    Assign CET
  </button>
  <button class="nav-link" data-tab="results">
    <span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 2l2.5 6.5L21 9l-5 4.5L17.5 21 12 17l-5.5 4L8 13.5 3 9l6.5-.5L12 2z" stroke="#8A8AA3" stroke-width="1.5" stroke-linejoin="round"/></svg></span>
    Results
  </button>

  <div class="side-help">
    <p>Teacher</p>
    <span><?= h($college['college_name'] ?? '') ?></span>
    <div class="admin-chip">
      <div class="admin-avatar">
        <?php if (!empty($teacher['profile_pic'])): ?>
          <img src="<?= h($teacher['profile_pic']) ?>" alt="">
        <?php else: ?>
          <?= h(strtoupper(substr($teacher_name ?: 'T', 0, 1))) ?>
        <?php endif; ?>
      </div>
      <div>
        <b><?= h($teacher_name) ?></b>
        <small><?= h($teacher['email'] ?? '') ?></small>
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
    <div class="top-right">
      <input class="search-box" placeholder="Type here...">
      <div class="top-admin">
        <div class="admin-avatar">
          <?php if (!empty($teacher['profile_pic'])): ?>
            <img src="<?= h($teacher['profile_pic']) ?>" alt="">
          <?php else: ?>
            <?= h(strtoupper(substr($teacher_name ?: 'T', 0, 1))) ?>
          <?php endif; ?>
        </div>
        <span><?= h(explode(' ', $teacher_name)[0] ?? 'Teacher') ?></span>
      </div>
    </div>
  </div>

  <!-- ================= DASHBOARD TAB ================= -->
  <section class="tab-panel active" id="tab-dashboard">
    <div class="stats-row">
      <div class="stat-card">
        <div><span>Subjects</span><b id="countSubjects"><?= (int)$subjectCount ?></b></div>
        <div class="stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 19.5V6a2 2 0 012-2h11a1 1 0 011 1v13" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
      </div>
      <div class="stat-card">
        <div><span>CETs Created</span><b id="countCets"><?= (int)$cetCount ?></b></div>
        <div class="stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M9 11l3 3L22 4" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
      </div>
      <div class="stat-card">
        <div><span>CETs Assigned</span><b id="countAssigned"><?= (int)$assignedCount ?></b></div>
        <div class="stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
      </div>
    </div>

    <div class="promo-row">
      <div class="promo-card promo-a">
        <div class="graphic">
          <svg width="46" height="46" viewBox="0 0 24 24" fill="none"><path d="M22 10L12 5 2 10l10 5 10-5z" stroke="#fff" stroke-width="1.6" stroke-linejoin="round"/><path d="M6 12v5c0 1.5 2.7 3 6 3s6-1.5 6-3v-5" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <div class="txt">
          <span>WELCOME BACK</span>
          <h3><?= h($teacher_name) ?></h3>
          <p>Build a subject, write a CET under it, and assign it to your students — all from the tabs on the left.</p>
        </div>
      </div>
      <div class="promo-b promo-card">
        <h3>Start a new CET</h3>
        <p>Create the question paper for a subject you teach.</p>
        <button onclick="switchTab('cets')">Go to Create a CET →</button>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div>
          <h2>Your recent CETs</h2>
          <p>The latest tests you've built.</p>
        </div>
      </div>
      <div class="mini-list">
        <?php if (empty($cets)): ?>
          <div class="mini-empty">No CETs yet — head to "Create a CET" to build your first one.</div>
        <?php else: foreach (array_slice($cets, 0, 5) as $c): ?>
          <div class="mini-row">
            <div class="mini-avatar"><?= h(strtoupper(substr($c['title'], 0, 1))) ?></div>
            <div class="info" style="flex:1;">
              <p><?= h($c['title']) ?></p>
              <span><?= h($c['course_name']) ?><?= $c['branch_name'] ? ' · ' . h($c['branch_name']) : '' ?> · <?= $c['subjects_covered'] ? h($c['subjects_covered']) : 'no subjects yet' ?> · <?= (int)$c['question_count'] ?> questions</span>
            </div>
            <span class="status-badge <?= h($c['status']) ?>"><?= h($c['status']) ?></span>
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
            <?php if (!empty($teacher['banner_image'])): ?><img src="<?= h($teacher['banner_image']) ?>" alt=""><?php endif; ?>
            <button type="button" class="banner-edit" onclick="document.getElementById('bannerFileInput').click()">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M12 20h9" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/></svg>
              Change banner
            </button>
          </div>
          <div class="profile-head">
            <div class="profile-avatar-wrap">
              <img class="profile-avatar" src="<?= !empty($teacher['profile_pic']) ? h($teacher['profile_pic']) : 'https://api.dicebear.com/7.x/initials/svg?seed=' . urlencode($teacher_name) ?>" alt="">
              <div class="avatar-edit" onclick="document.getElementById('avatarFileInput').click()">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M12 20h9" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/></svg>
              </div>
            </div>
            <div class="profile-head-txt">
              <h2><?= h($teacher_name) ?></h2>
              <span>Teacher · <?= h($college['college_name'] ?? '') ?></span>
            </div>
          </div>
        </div>
      </div>

      <form action="update_teacher_profile.php" method="POST" enctype="multipart/form-data" id="profileForm">
        <input type="file" name="avatar" id="avatarFileInput" accept="image/*" style="display:none;" onchange="handleFileSelect(this,'avatar')">
        <input type="file" name="banner" id="bannerFileInput" accept="image/*" style="display:none;" onchange="handleFileSelect(this,'banner')">

        <div class="form-grid" style="padding-top:36px;">
          <div class="form-field">
            <label>Full name</label>
            <input type="text" name="full_name" value="<?= h($teacher_name) ?>" required>
          </div>
          <div class="form-field">
            <label>Email</label>
            <input type="email" name="email" value="<?= h($teacher['email'] ?? '') ?>" required>
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

  <!-- ================= SUBJECTS TAB ================= -->
  <section class="tab-panel" id="tab-subjects">
    <div class="panel">
      <div class="panel-head">
        <div>
          <h2>Subjects</h2>
          <p>A subject belongs to one course + branch, so the same subject name can exist separately per branch.</p>
        </div>
        <button class="btn-add" onclick="openModal('addSubjectModal')" <?= empty($courses) ? 'disabled title="Ask your Admin to create a course first"' : '' ?>>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></svg>
          Add Subject
        </button>
      </div>
      <table>
        <thead><tr><th>Subject</th><th>Course</th><th>Branch</th><th>Created by</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($subjects)): ?>
            <tr><td colspan="5" class="empty-row">No subjects yet — click "Add Subject" to create your first one.</td></tr>
          <?php else: foreach ($subjects as $sub): ?>
            <tr>
              <td><div class="name-cell"><?= h($sub['subject_name']) ?></div></td>
              <td><span class="course-badge"><?= h($sub['course_name']) ?></span></td>
              <td class="muted-cell"><?= h($sub['branch_name'] ?: '—') ?></td>
              <td class="muted-cell"><?= h($sub['teacher_name'] ?: '—') ?></td>
              <td>
                <?php if ((int)$sub['created_by_teacher_id'] === (int)$teacher_id): ?>
                <div class="row-actions">
                  <button class="icon-btn edit" title="Edit" onclick="openEditSubject('<?= (int)$sub['id'] ?>','<?= h(addslashes($sub['subject_name'])) ?>')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 20h9" stroke="#5C56E0" stroke-width="1.8" stroke-linecap="round"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z" stroke="#5C56E0" stroke-width="1.8" stroke-linejoin="round"/></svg>
                  </button>
                  <a href="delete_subject.php?id=<?= (int)$sub['id'] ?>" class="icon-btn del" title="Remove" onclick="return confirm('Remove this subject? Any CETs on it will be removed too.');">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M9 7V4h6v3M6 7l1 13a2 2 0 002 2h6a2 2 0 002-2l1-13" stroke="#FF6E5E" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </a>
                </div>
                <?php else: ?>
                  <span class="muted-cell">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ================= CETs TAB ================= -->
  <section class="tab-panel" id="tab-cets">
    <div class="panel">
      <div class="panel-head">
        <div>
          <h2>Your CETs</h2>
          <p>Build the question paper, then manage its questions.</p>
        </div>
        <button class="btn-add" onclick="openModal('addCetModal')" <?= empty($courses) ? 'disabled title="Ask your Admin to create a course first"' : '' ?>>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></svg>
          Create CET
        </button>
      </div>
      <table>
        <thead><tr><th>Title</th><th>Course · Branch</th><th>Subjects covered</th><th>Duration</th><th>Questions</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($cets)): ?>
            <tr><td colspan="7" class="empty-row">No CETs yet — click "Create CET" to build your first one.</td></tr>
          <?php else: foreach ($cets as $c): ?>
            <tr>
              <td><div class="name-cell"><?= h($c['title']) ?></div></td>
              <td><span class="course-badge"><?= h($c['course_name']) ?><?= $c['branch_name'] ? ' · ' . h($c['branch_name']) : '' ?></span></td>
              <td class="muted-cell"><?= $c['subjects_covered'] ? h($c['subjects_covered']) : '—' ?></td>
              <td class="muted-cell"><?= (int)$c['duration_minutes'] ?> min</td>
              <td class="muted-cell"><?= (int)$c['question_count'] ?></td>
              <td><span class="status-badge <?= h($c['status']) ?>"><?= h($c['status']) ?></span></td>
              <td>
                <div class="row-actions">
                  <a href="manage_cet_questions.php?cet_id=<?= (int)$c['id'] ?>" class="btn-mini primary">Manage Questions</a>
                  <button class="icon-btn edit" title="Edit" onclick="openEditCet('<?= (int)$c['id'] ?>','<?= h(addslashes($c['title'])) ?>','<?= (int)$c['duration_minutes'] ?>')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 20h9" stroke="#5C56E0" stroke-width="1.8" stroke-linecap="round"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z" stroke="#5C56E0" stroke-width="1.8" stroke-linejoin="round"/></svg>
                  </button>
                  <a href="delete_cet.php?id=<?= (int)$c['id'] ?>" class="icon-btn del" title="Remove" onclick="return confirm('Delete this CET and all its questions?');">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M9 7V4h6v3M6 7l1 13a2 2 0 002 2h6a2 2 0 002-2l1-13" stroke="#FF6E5E" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ================= ASSIGN CET TAB ================= -->
  <section class="tab-panel" id="tab-assign">
    <div class="panel">
      <div class="panel-head">
        <div>
          <h2>Assign CET</h2>
          <p>Assigning makes the CET available to every student on that course + branch.</p>
        </div>
      </div>
      <table>
        <thead><tr><th>Title</th><th>Course · Branch</th><th>Subjects covered</th><th>Questions</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($cets)): ?>
            <tr><td colspan="6" class="empty-row">No CETs yet — create one first.</td></tr>
          <?php else: foreach ($cets as $c): ?>
            <tr>
              <td><div class="name-cell"><?= h($c['title']) ?></div></td>
              <td><span class="course-badge"><?= h($c['course_name']) ?><?= $c['branch_name'] ? ' · ' . h($c['branch_name']) : '' ?></span></td>
              <td class="muted-cell"><?= $c['subjects_covered'] ? h($c['subjects_covered']) : '—' ?></td>
              <td class="muted-cell"><?= (int)$c['question_count'] ?></td>
              <td><span class="status-badge <?= h($c['status']) ?>"><?= h($c['status']) ?></span></td>
              <td>
                <?php if ($c['status'] === 'assigned'): ?>
                  <a href="assign_cet_toggle.php?id=<?= (int)$c['id'] ?>&action=unassign" class="btn-mini unassign" onclick="return confirm('Unassign this CET? Students will no longer see it.');">Unassign</a>
                <?php else: ?>
                  <a href="assign_cet_toggle.php?id=<?= (int)$c['id'] ?>&action=assign" class="btn-mini assign" <?= (int)$c['question_count'] === 0 ? 'onclick="alert(\'Add at least one question before assigning.\'); return false;"' : '' ?>>Assign</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ================= RESULTS TAB ================= -->
  <section class="tab-panel" id="tab-results">
    <div class="panel">
      <div class="panel-head">
        <div>
          <h2>Results</h2>
          <p>Pick a CET to see how each student scored, then declare results for all of them at once.</p>
        </div>
      </div>
      <table>
        <thead><tr><th>Title</th><th>Course · Branch</th><th>Attempted</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($cets)): ?>
            <tr><td colspan="5" class="empty-row">No CETs yet.</td></tr>
          <?php else: foreach ($cets as $c):
            $summary = $resultsSummary[$c['id']] ?? ['attempted_count' => 0, 'declared_count' => 0];
            $attempted = (int)$summary['attempted_count'];
            $declared = (int)$summary['declared_count'];
          ?>
            <tr>
              <td><div class="name-cell"><?= h($c['title']) ?></div></td>
              <td><span class="course-badge"><?= h($c['course_name']) ?><?= $c['branch_name'] ? ' · ' . h($c['branch_name']) : '' ?></span></td>
              <td class="muted-cell"><?= $attempted ?> student<?= $attempted !== 1 ? 's' : '' ?></td>
              <td>
                <?php if ($attempted === 0): ?>
                  <span class="status-badge draft">No attempts yet</span>
                <?php elseif ($declared === $attempted): ?>
                  <span class="status-badge assigned">Declared</span>
                <?php else: ?>
                  <span class="status-badge draft"><?= $declared ?>/<?= $attempted ?> declared</span>
                <?php endif; ?>
              </td>
              <td>
                <a href="cet_results.php?cet_id=<?= (int)$c['id'] ?>" class="btn-mini primary">View Results</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

<!-- ---------------- ADD SUBJECT MODAL ---------------- -->
<div class="modal-overlay" id="addSubjectModal">
  <div class="modal">
    <h3>Add a Subject</h3>
    <p class="sub">Pick the course + branch this subject belongs to.</p>
    <form method="POST" action="add_subject.php">
      <div class="field"><label>Subject name</label><input type="text" name="subject_name" placeholder="e.g. Data Structures" required></div>
      <div class="field">
        <label>Course</label>
        <select name="course_id" id="subjectCourseSelect" required onchange="populateSubjectBranches()">
          <option value="">Choose a course</option>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['course_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Branch (optional)</label>
        <select name="branch_id" id="subjectBranchSelect">
          <option value="">No branch</option>
        </select>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('addSubjectModal')">Cancel</button>
        <button type="submit" class="btn-confirm">Add Subject</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------------- EDIT SUBJECT MODAL ---------------- -->
<div class="modal-overlay" id="editSubjectModal">
  <div class="modal">
    <h3>Edit Subject</h3>
    <form method="POST" action="update_subject.php">
      <input type="hidden" name="subject_id" id="editSubjectId">
      <div class="field"><label>Subject name</label><input type="text" name="subject_name" id="editSubjectName" required></div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('editSubjectModal')">Cancel</button>
        <button type="submit" class="btn-confirm">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------------- ADD CET MODAL ---------------- -->
<div class="modal-overlay" id="addCetModal">
  <div class="modal">
    <h3>Create a CET</h3>
    <p class="sub">Pick which batch of students this exam is for — you'll tag each question with its own subject afterwards.</p>
    <form method="POST" action="add_cet.php">
      <div class="field"><label>CET title</label><input type="text" name="title" placeholder="e.g. Common Entrance Test 2026" required></div>
      <div class="field">
        <label>Course</label>
        <select name="course_id" id="newCetCourseSelect" required onchange="populateCetBranches()">
          <option value="">Choose a course</option>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['course_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Branch (optional)</label>
        <select name="branch_id" id="newCetBranchSelect">
          <option value="">No branch</option>
        </select>
      </div>
      <div class="field">
        <label>Duration</label>
        <div class="field-row2">
          <input type="number" name="hours" min="0" max="12" placeholder="Hours" value="1" required>
          <input type="number" name="minutes" min="0" max="59" placeholder="Minutes" value="0" required>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('addCetModal')">Cancel</button>
        <button type="submit" class="btn-confirm">Create CET</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------------- EDIT CET MODAL ---------------- -->
<div class="modal-overlay" id="editCetModal">
  <div class="modal">
    <h3>Edit CET</h3>
    <form method="POST" action="update_cet.php">
      <input type="hidden" name="cet_id" id="editCetId">
      <div class="field"><label>CET title</label><input type="text" name="title" id="editCetTitle" required></div>
      <div class="field">
        <label>Duration</label>
        <div class="field-row2">
          <input type="number" name="hours" id="editCetHours" min="0" max="12" required>
          <input type="number" name="minutes" id="editCetMinutes" min="0" max="59" required>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('editCetModal')">Cancel</button>
        <button type="submit" class="btn-confirm">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------------- CROP IMAGE MODAL ---------------- -->
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
  const branchesByCourse = <?= json_encode($branchesByCourse) ?>;

  const tabLabels = {
    dashboard: 'Dashboard',
    profile: 'Teacher Profile',
    subjects: 'Create a Subject',
    cets: 'Create a CET',
    assign: 'Assign CET',
    results: 'Results'
  };
  function switchTab(tab){
    document.querySelectorAll('.nav-link').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    document.getElementById('pageTitle').textContent = tabLabels[tab];
    document.getElementById('crumb').innerHTML = 'Pages / <b>' + tabLabels[tab] + '</b>';
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url);
  }
  document.querySelectorAll('.nav-link').forEach(btn => {
    btn.addEventListener('click', () => switchTab(btn.dataset.tab));
  });

  function openModal(id){ document.getElementById(id).classList.add('show'); }
  function closeModal(id){ document.getElementById(id).classList.remove('show'); }
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.classList.remove('show'); });
  });

  function populateSubjectBranches(){
    const courseId = document.getElementById('subjectCourseSelect').value;
    const branchSelect = document.getElementById('subjectBranchSelect');
    branchSelect.innerHTML = '<option value="">No branch</option>';
    (branchesByCourse[courseId] || []).forEach(b => {
      const opt = document.createElement('option');
      opt.value = b.id; opt.textContent = b.name;
      branchSelect.appendChild(opt);
    });
  }

  function populateCetBranches(){
    const courseId = document.getElementById('newCetCourseSelect').value;
    const branchSelect = document.getElementById('newCetBranchSelect');
    branchSelect.innerHTML = '<option value="">No branch</option>';
    (branchesByCourse[courseId] || []).forEach(b => {
      const opt = document.createElement('option');
      opt.value = b.id; opt.textContent = b.name;
      branchSelect.appendChild(opt);
    });
  }

  function openEditSubject(id, name){
    document.getElementById('editSubjectId').value = id;
    document.getElementById('editSubjectName').value = name;
    openModal('editSubjectModal');
  }

  function openEditCet(id, title, durationMinutes){
    document.getElementById('editCetId').value = id;
    document.getElementById('editCetTitle').value = title;
    document.getElementById('editCetHours').value = Math.floor(durationMinutes / 60);
    document.getElementById('editCetMinutes').value = durationMinutes % 60;
    openModal('editCetModal');
  }

  function animateCount(el){
    const target = parseInt(el.textContent, 10);
    if (isNaN(target)) return;
    let cur = 0;
    const step = Math.max(target / 30, 1);
    el.textContent = '0';
    const t = setInterval(() => {
      cur += step;
      if (cur >= target) { cur = target; clearInterval(t); }
      el.textContent = Math.round(cur);
    }, 25);
  }
  animateCount(document.getElementById('countSubjects'));
  animateCount(document.getElementById('countCets'));
  animateCount(document.getElementById('countAssigned'));

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
