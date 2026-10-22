<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.html');
    exit;
}

$college_id = $_SESSION['college_id'];
$admin_id   = $_SESSION['admin_id'];

$collegeStmt = $conn->prepare("SELECT college_name, college_code, phone FROM colleges WHERE id = ?");
$collegeStmt->bind_param("i", $college_id);
$collegeStmt->execute();
$college = $collegeStmt->get_result()->fetch_assoc();
$collegeStmt->close();

$adminStmt = $conn->prepare("SELECT full_name, email FROM admins WHERE id = ?");
$adminStmt->bind_param("i", $admin_id);
$adminStmt->execute();
$adminRow = $adminStmt->get_result()->fetch_assoc();
$adminStmt->close();
$admin_name = $adminRow['full_name'] ?? '';

$teacherCount = $conn->query("SELECT COUNT(*) AS c FROM teachers WHERE college_id = $college_id")->fetch_assoc()['c'];
$studentCount = $conn->query("SELECT COUNT(*) AS c FROM students WHERE college_id = $college_id")->fetch_assoc()['c'];

$teacherStmt = $conn->prepare("SELECT id, full_name, email, created_at FROM teachers WHERE college_id = ? ORDER BY created_at DESC");
$teacherStmt->bind_param("i", $college_id);
$teacherStmt->execute();
$teachers = $teacherStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recentTeachers = array_slice($teachers, 0, 4);

// ---- student distribution across course + branch, for the dashboard chart ----
$distStmt = $conn->prepare("
    SELECT c.course_name, b.branch_name, COUNT(s.id) AS cnt
    FROM students s
    LEFT JOIN courses c ON s.course_id = c.id
    LEFT JOIN branches b ON s.branch_id = b.id
    WHERE s.college_id = ?
    GROUP BY s.course_id, s.branch_id
    ORDER BY cnt DESC
");
$distStmt->bind_param("i", $college_id);
$distStmt->execute();
$distribution = $distStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$distMax = 0;
foreach ($distribution as $d) { if ($d['cnt'] > $distMax) $distMax = $d['cnt']; }

// ---- palette + course-level aggregation for the donut chart ----
$chartColors = ['#5C56E0', '#8A63F3', '#FFB648', '#FF6E5E', '#34D399', '#38BDF8', '#F472B6', '#A78BFA'];
$courseAgg = [];
foreach ($distribution as $d) {
    $key = $d['course_name'] ?: 'Unassigned';
    $courseAgg[$key] = ($courseAgg[$key] ?? 0) + (int)$d['cnt'];
}
$donutTotal = array_sum($courseAgg);
$donutStops = [];
$legendItems = [];
$startDeg = 0;
$ci = 0;
foreach ($courseAgg as $name => $cnt) {
    $color = $chartColors[$ci % count($chartColors)];
    $deg = $donutTotal > 0 ? round(($cnt / $donutTotal) * 360, 2) : 0;
    $endDeg = $startDeg + $deg;
    $donutStops[] = "$color {$startDeg}deg {$endDeg}deg";
    $legendItems[] = ['name' => $name, 'cnt' => $cnt, 'color' => $color, 'pct' => $donutTotal > 0 ? round(($cnt / $donutTotal) * 100) : 0];
    $startDeg = $endDeg;
    $ci++;
}
$donutCss = implode(', ', $donutStops);

// ---- students (with assigned course/branch names) ----
$studentStmt = $conn->prepare("
    SELECT s.id, s.full_name, s.roll_number, s.email, s.created_at, s.course_id, s.branch_id,
           c.course_name, b.branch_name
    FROM students s
    LEFT JOIN courses c ON s.course_id = c.id
    LEFT JOIN branches b ON s.branch_id = b.id
    WHERE s.college_id = ?
    ORDER BY s.created_at DESC
");
$studentStmt->bind_param("i", $college_id);
$studentStmt->execute();
$students = $studentStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ---- courses ----
$courseStmt = $conn->prepare("SELECT id, course_name FROM courses WHERE college_id = ? ORDER BY course_name ASC");
$courseStmt->bind_param("i", $college_id);
$courseStmt->execute();
$courses = $courseStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ---- branches, grouped by course_id ----
$branchStmt = $conn->prepare("
    SELECT b.id, b.branch_name, b.course_id
    FROM branches b INNER JOIN courses c ON b.course_id = c.id
    WHERE c.college_id = ?
    ORDER BY b.branch_name ASC
");
$branchStmt->bind_param("i", $college_id);
$branchStmt->execute();
$branchesFlat = $branchStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$branchesByCourse = [];
foreach ($branchesFlat as $b) {
    $branchesByCourse[$b['course_id']][] = ['id' => $b['id'], 'name' => $b['branch_name']];
}

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — CrustExams</title>
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

  /* ---------------- SIDEBAR (floating card) ---------------- */
  .sidebar{
    position:fixed;top:18px;left:18px;bottom:18px;width:250px;
    background:#fff;border-radius:22px;box-shadow:0 20px 45px -25px rgba(24,26,42,.25);
    padding:24px 18px;display:flex;flex-direction:column;z-index:50;
  }
  .brand{display:flex;align-items:center;gap:10px;font-family:'Sora',sans-serif;font-weight:800;font-size:17.5px;padding:0 6px 18px;border-bottom:1px solid var(--line);margin-bottom:18px;}
  .brand-mark{width:32px;height:32px;border-radius:9px;background:var(--cream);display:flex;align-items:center;justify-content:center;overflow:hidden;flex:none;}
  .brand-mark img{width:100%;height:100%;object-fit:contain;}
  .brand span{color:var(--indigo-2);}

  .nav-link{
    position:relative;display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:14px;
    font-size:14px;font-weight:600;color:var(--muted);margin-bottom:6px;cursor:pointer;
    background:transparent;border:none;width:100%;text-align:left;
    transition:color .25s ease;
  }
  .nav-link .ic{
    width:30px;height:30px;border-radius:9px;background:var(--cream);display:flex;align-items:center;justify-content:center;flex:none;
    transition:background .25s ease, box-shadow .25s ease;
  }
  .nav-link.active{color:var(--ink);}
  .nav-link.active .ic{background:linear-gradient(120deg,var(--indigo-2),var(--violet));box-shadow:0 10px 20px -8px rgba(92,86,224,.6);}
  .nav-link.active .ic svg path, .nav-link.active .ic svg rect, .nav-link.active .ic svg circle{stroke:#fff;}
  .nav-link:not(.active):hover{color:var(--ink);}
  .nav-link:not(.active):hover .ic{background:#EFEDFD;}

  .side-help{
    margin-top:auto;background:linear-gradient(150deg,var(--indigo-2),var(--violet));border-radius:18px;
    padding:20px;color:#fff;position:relative;overflow:hidden;
  }
  .side-help::before{content:"";position:absolute;top:-30px;right:-30px;width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.15);}
  .side-help p{font-size:12.5px;font-weight:700;margin-bottom:2px;position:relative;}
  .side-help span{font-size:11.5px;color:rgba(255,255,255,.8);display:block;margin-bottom:12px;position:relative;}
  .side-help .admin-chip{display:flex;align-items:center;gap:9px;position:relative;}
  .admin-avatar{width:30px;height:30px;border-radius:50%;background:var(--amber);color:var(--ink);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex:none;}
  .side-help small{font-size:11px;opacity:.85;display:block;}
  .side-help b{font-size:12.5px;}
  .logout-btn{
    margin-top:14px;width:100%;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.4);color:#fff;
    padding:9px;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;position:relative;transition:background .2s ease;
  }
  .logout-btn:hover{background:rgba(255,255,255,.3);}

  /* ---------------- MAIN ---------------- */
  .main{margin-left:290px;padding:26px 32px 50px;}
  .topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;flex-wrap:wrap;gap:16px;}
  .crumb{font-size:12.5px;color:var(--muted);margin-bottom:4px;}
  .crumb b{color:var(--ink);}
  .topbar h1{font-size:22px;font-weight:800;}
  .top-right{display:flex;align-items:center;gap:16px;}
  .search-box{
    background:#fff;border:1px solid var(--line);border-radius:12px;padding:10px 16px;font-size:13px;color:var(--muted);
    width:210px;box-shadow:0 6px 16px -12px rgba(24,26,42,.2);
  }
  .top-admin{display:flex;align-items:center;gap:9px;background:#fff;padding:6px 14px 6px 6px;border-radius:100px;box-shadow:0 6px 16px -12px rgba(24,26,42,.2);}
  .top-admin .admin-avatar{width:30px;height:30px;}
  .top-admin span{font-size:13px;font-weight:600;}

  .banner{display:none;align-items:center;gap:10px;padding:13px 18px;border-radius:14px;font-size:14px;font-weight:500;margin-bottom:22px;}

  /* ---------------- TAB PANELS ---------------- */
  .tab-panel{display:none;}
  .tab-panel.active{display:block;animation:panelIn .4s cubic-bezier(.2,.7,.3,1);}
  @keyframes panelIn{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);}}

  .stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:24px;}
  .stat-card{
    background:#fff;border-radius:18px;padding:22px;display:flex;justify-content:space-between;align-items:flex-start;
    box-shadow:0 16px 36px -26px rgba(24,26,42,.35);transition:transform .3s ease, box-shadow .3s ease;
  }
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
  .promo-a .graphic{
    flex:none;width:130px;height:130px;border-radius:20px;
    background:linear-gradient(140deg,var(--indigo-2),var(--violet),var(--coral));
    display:flex;align-items:center;justify-content:center;
    animation:floaty 4s ease-in-out infinite;
  }
  @keyframes floaty{0%,100%{transform:translateY(0) rotate(0deg);}50%{transform:translateY(-8px) rotate(3deg);}}
  .promo-b{background:linear-gradient(140deg,var(--ink),#242645);color:#fff;display:flex;flex-direction:column;justify-content:center;}
  .promo-b h3{font-size:19px;margin-bottom:10px;}
  .promo-b p{font-size:13.5px;color:rgba(255,255,255,.75);line-height:1.6;margin-bottom:18px;}
  .promo-b button{
    align-self:flex-start;background:linear-gradient(120deg,var(--indigo-2),var(--violet));color:#fff;border:none;
    padding:11px 22px;border-radius:100px;font-weight:700;font-size:13px;cursor:pointer;transition:transform .2s ease;
  }
  .promo-b button:hover{transform:translateY(-2px);}

  .panel{background:#fff;border-radius:20px;box-shadow:0 16px 36px -26px rgba(24,26,42,.35);overflow:hidden;margin-bottom:24px;}
  .panel-head{display:flex;justify-content:space-between;align-items:center;padding:22px 26px;border-bottom:1px solid var(--line);}
  .panel-head h2{font-size:17px;font-weight:700;}
  .panel-head p{font-size:12.5px;color:var(--muted);margin-top:2px;}

  .mini-list{padding:6px 26px 20px;}
  .mini-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--line);}
  .mini-row:last-child{border-bottom:none;}
  .mini-avatar{width:34px;height:34px;border-radius:50%;background:var(--cream);color:var(--indigo-2);display:flex;align-items:center;justify-content:center;font-family:'Sora',sans-serif;font-weight:700;font-size:13px;flex:none;}
  .mini-row .info p{font-size:13.5px;font-weight:600;}
  .mini-row .info span{font-size:12px;color:var(--muted);}
  .mini-empty{padding:30px 26px;text-align:center;color:var(--muted);font-size:13.5px;}

  /* ---------------- CHARTS ROW (students per course/branch) ---------------- */
  .charts-row{display:grid;grid-template-columns:1fr 1.35fr;gap:20px;margin-bottom:24px;}

  .donut-wrap{padding:24px 26px 28px;display:flex;align-items:center;gap:26px;flex-wrap:wrap;}
  .donut{
    width:150px;height:150px;border-radius:50%;flex:none;position:relative;
    background:conic-gradient(var(--cream) 0deg 360deg);
    transition:background 1.2s ease;
  }
  .donut::after{
    content:"";position:absolute;top:14px;left:14px;right:14px;bottom:14px;border-radius:50%;
    background:#fff;display:flex;align-items:center;justify-content:center;
  }
  .donut-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:1;}
  .donut-center b{font-family:'Sora',sans-serif;font-size:22px;font-weight:800;}
  .donut-center span{font-size:10.5px;color:var(--muted);}
  .legend{display:flex;flex-direction:column;gap:10px;flex:1;min-width:150px;}
  .legend-item{display:flex;align-items:center;gap:10px;font-size:13px;}
  .legend-dot{width:10px;height:10px;border-radius:3px;flex:none;}
  .legend-item .l-name{flex:1;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .legend-item .l-val{font-family:'IBM Plex Mono',monospace;font-size:12px;color:var(--muted);}

  .barchart-wrap{padding:20px 26px 10px;}
  .barchart{display:flex;align-items:flex-end;gap:14px;height:190px;position:relative;border-bottom:1px solid var(--line);}
  .barchart::before, .barchart::after{content:"";position:absolute;left:0;right:0;border-top:1px dashed var(--line);}
  .barchart::before{top:0;}
  .barchart::after{top:50%;}
  .bar-col{display:flex;flex-direction:column;align-items:center;flex:1;height:100%;justify-content:flex-end;min-width:0;}
  .bar-count{font-size:11.5px;font-weight:700;margin-bottom:6px;font-family:'IBM Plex Mono',monospace;}
  .bar{
    width:100%;max-width:36px;border-radius:8px 8px 0 0;
    background:linear-gradient(180deg,var(--violet),var(--indigo-2));
    height:0%;transition:height 1.1s cubic-bezier(.2,.8,.3,1);
  }
  .bar.unassigned{background:linear-gradient(180deg,#c7c7d6,#9c9cb0);}
  .bar-labels{display:flex;gap:14px;margin-top:10px;}
  .bar-label{flex:1;text-align:center;font-size:10.5px;color:var(--muted);line-height:1.35;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

  /* ---------------- FORMS (Profile tab) ---------------- */
  .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;padding:24px 26px;}
  .form-field{margin-bottom:16px;}
  .form-field label{display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--ink);}
  .form-field input{
    width:100%;padding:12px 15px;border-radius:12px;border:1.5px solid var(--line);background:var(--cream);
    font-size:14px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s ease, background .2s ease;
  }
  .form-field input:focus{border-color:var(--indigo-2);background:#fff;}
  .form-actions{padding:4px 26px 26px;display:flex;justify-content:flex-end;}
  .btn-save{
    padding:13px 28px;border-radius:100px;border:none;background:linear-gradient(120deg,var(--indigo-2),var(--violet));
    color:#fff;font-weight:700;font-size:13.5px;cursor:pointer;transition:transform .2s ease, box-shadow .2s ease;
  }
  .btn-save:hover{transform:translateY(-2px);box-shadow:0 14px 26px -10px rgba(92,86,224,.6);}

  .danger-panel{border:1.5px solid rgba(255,110,94,.35);}
  .danger-panel .panel-head{border-bottom-color:rgba(255,110,94,.25);}
  .danger-panel .panel-head h2{color:var(--coral);}
  .danger-body{padding:20px 26px 26px;display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap;}
  .danger-body p{font-size:13px;color:var(--muted);max-width:420px;}
  .btn-danger{
    padding:12px 22px;border-radius:100px;border:1.5px solid var(--coral);background:#fff;color:var(--coral);
    font-weight:700;font-size:13px;cursor:pointer;transition:all .2s ease;white-space:nowrap;
  }
  .btn-danger:hover{background:var(--coral);color:#fff;}

  /* ---------------- TEACHERS TABLE ---------------- */
  .btn-add{
    display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:100px;border:none;cursor:pointer;
    background:linear-gradient(120deg,var(--indigo-2),var(--violet));color:#fff;font-family:'Sora',sans-serif;font-weight:600;font-size:13.5px;
    transition:transform .2s ease, box-shadow .2s ease;
  }
  .btn-add:hover{transform:translateY(-2px);box-shadow:0 12px 22px -8px rgba(92,86,224,.55);}
  .btn-add:disabled{opacity:.45;cursor:not-allowed;transform:none;box-shadow:none;}
  select.modal-select{
    width:100%;padding:12px 15px;border-radius:12px;border:1.5px solid var(--line);background:var(--cream);
    font-size:14px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s ease, background .2s ease;
  }
  select.modal-select:focus{border-color:var(--indigo-2);background:#fff;}
  table{width:100%;border-collapse:collapse;}
  thead th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);padding:14px 26px;border-bottom:1px solid var(--line);font-weight:600;}
  tbody td{padding:15px 26px;font-size:13.5px;border-bottom:1px solid var(--line);}
  tbody tr:last-child td{border-bottom:none;}
  tbody tr:hover{background:var(--cream);}
  .name-cell{display:flex;align-items:center;gap:10px;font-weight:600;}
  .muted-cell{color:var(--muted);font-family:'IBM Plex Mono',monospace;font-size:12px;}
  .course-badge{display:inline-flex;padding:4px 11px;border-radius:100px;background:rgba(92,86,224,.1);color:var(--indigo-2);font-size:12px;font-weight:600;white-space:nowrap;}
  .row-actions{display:flex;gap:8px;}
  .icon-btn{
    width:32px;height:32px;border-radius:9px;border:1.5px solid var(--line);background:#fff;display:flex;align-items:center;justify-content:center;
    cursor:pointer;transition:all .2s ease;
  }
  .icon-btn.edit:hover{border-color:var(--indigo-2);background:var(--indigo-2);}
  .icon-btn.edit:hover svg path{stroke:#fff;}
  .icon-btn.del:hover{border-color:var(--coral);background:var(--coral);}
  .icon-btn.del:hover svg path{stroke:#fff;}
  .empty-row{padding:40px 26px;text-align:center;color:var(--muted);font-size:14px;}

  /* ---------------- MODAL ---------------- */
  .modal-overlay{display:none;position:fixed;inset:0;background:rgba(24,26,42,.55);backdrop-filter:blur(3px);align-items:center;justify-content:center;z-index:200;padding:20px;}
  .modal-overlay.show{display:flex;}
  .modal{background:#fff;border-radius:22px;padding:32px;width:100%;max-width:420px;box-shadow:0 40px 80px -30px rgba(0,0,0,.5);animation:modalIn .3s cubic-bezier(.2,.7,.3,1);}
  @keyframes modalIn{from{opacity:0;transform:translateY(14px) scale(.98);}to{opacity:1;transform:translateY(0) scale(1);}}
  .modal h3{font-size:19px;font-weight:700;margin-bottom:6px;}
  .modal p.sub{color:var(--muted);font-size:13.5px;margin-bottom:22px;}
  .modal .field{margin-bottom:14px;}
  .modal label{display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;}
  .modal input{width:100%;padding:12px 15px;border-radius:12px;border:1.5px solid var(--line);background:var(--cream);font-size:14px;outline:none;transition:border-color .2s ease, background .2s ease;}
  .modal input:focus{border-color:var(--indigo-2);background:#fff;}
  .modal-actions{display:flex;gap:10px;margin-top:22px;}
  .btn-cancel{flex:1;padding:12px;border-radius:100px;border:1.5px solid var(--line);background:#fff;font-weight:600;font-size:14px;cursor:pointer;font-family:'Sora',sans-serif;}
  .btn-confirm{flex:1;padding:12px;border-radius:100px;border:none;background:var(--ink);color:#fff;font-weight:600;font-size:14px;cursor:pointer;font-family:'Sora',sans-serif;}

  @media(max-width:980px){
    .sidebar{position:relative;top:0;left:0;bottom:auto;width:auto;margin:14px;height:auto;}
    .main{margin-left:0;padding:0 16px 40px;}
    .stats-row, .promo-row, .form-grid, .charts-row{grid-template-columns:1fr;}
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
    <span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4 21V7l8-4 8 4v14" stroke="#8A8AA3" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 21v-6h6v6" stroke="#8A8AA3" stroke-width="1.8" stroke-linejoin="round"/></svg></span>
    College / Admin Profile
  </button>
  <button class="nav-link" data-tab="teachers">
    <span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4 19.5V6a2 2 0 012-2h11a1 1 0 011 1v13" stroke="#8A8AA3" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 22a2 2 0 01-2-2v0a2 2 0 012-2h13" stroke="#8A8AA3" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
    Teachers
  </button>
  <button class="nav-link" data-tab="students">
    <span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4 19v-1a4 4 0 014-4h4a4 4 0 014 4v1" stroke="#8A8AA3" stroke-width="1.8" stroke-linecap="round"/><circle cx="10" cy="7" r="3" stroke="#8A8AA3" stroke-width="1.8"/></svg></span>
    Students
  </button>
  <button class="nav-link" data-tab="courses">
    <span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M4 19.5A2.5 2.5 0 016.5 17H20" stroke="#8A8AA3" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z" stroke="#8A8AA3" stroke-width="1.8" stroke-linejoin="round"/></svg></span>
    Create Course
  </button>
  <button class="nav-link" data-tab="assign">
    <span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 11l3 3L22 4" stroke="#8A8AA3" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" stroke="#8A8AA3" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
    Assign Course
  </button>

  <div class="side-help">
    <p>College Admin</p>
    <span>Managing <?= h($college['college_name'] ?? 'your college') ?></span>
    <div class="admin-chip">
      <div class="admin-avatar"><?= h(strtoupper(substr($admin_name ?: 'A', 0, 1))) ?></div>
      <div>
        <b><?= h($admin_name) ?></b>
        <small><?= h($adminRow['email'] ?? '') ?></small>
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
        <div class="admin-avatar"><?= h(strtoupper(substr($admin_name ?: 'A', 0, 1))) ?></div>
        <span><?= h(explode(' ', $admin_name)[0] ?? 'Admin') ?></span>
      </div>
    </div>
  </div>

  <!-- ================= DASHBOARD TAB ================= -->
  <section class="tab-panel active" id="tab-dashboard">
    <div class="stats-row">
      <div class="stat-card">
        <div><span>Teachers</span><b id="countTeachers"><?= (int)$teacherCount ?></b></div>
        <div class="stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 19.5V6a2 2 0 012-2h11a1 1 0 011 1v13" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
      </div>
      <div class="stat-card">
        <div><span>Students</span><b id="countStudents"><?= (int)$studentCount ?></b></div>
        <div class="stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 19v-1a4 4 0 014-4h4a4 4 0 014 4v1" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/><circle cx="10" cy="7" r="3" stroke="#fff" stroke-width="1.8"/></svg></div>
      </div>
      <div class="stat-card">
        <div><span>College Code</span><b style="font-size:19px;"><?= h($college['college_code'] ?: '—') ?></b></div>
        <div class="stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 21V7l8-4 8 4v14" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/></svg></div>
      </div>
    </div>

    <div class="promo-row">
      <div class="promo-card promo-a">
        <div class="graphic">
          <svg width="46" height="46" viewBox="0 0 24 24" fill="none"><path d="M22 10L12 5 2 10l10 5 10-5z" stroke="#fff" stroke-width="1.6" stroke-linejoin="round"/><path d="M6 12v5c0 1.5 2.7 3 6 3s6-1.5 6-3v-5" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <div class="txt">
          <span>ABOUT YOUR COLLEGE</span>
          <h3><?= h($college['college_name'] ?? '') ?></h3>
          <p>Every teacher and student you add here belongs only to your college — nothing is shared with any other college on CrustExams.</p>
        </div>
      </div>
      <div class="promo-card promo-b">
        <h3>Manage your Teachers</h3>
        <p>Add a new teacher account so they can start building CETs for your students.</p>
        <button onclick="switchTab('teachers')">Go to Teachers →</button>
      </div>
    </div>

    <div class="charts-row">
      <div class="panel">
        <div class="panel-head">
          <div>
            <h2>Students by Course</h2>
            <p>Share of your students on each course.</p>
          </div>
        </div>
        <?php if ($donutTotal === 0): ?>
          <div class="mini-empty">No students assigned to a course yet.</div>
        <?php else: ?>
          <div class="donut-wrap">
            <div class="donut" id="donutChart" data-donut="<?= h($donutCss) ?>">
              <div class="donut-center"><b id="donutTotal"><?= (int)$donutTotal ?></b><span>students</span></div>
            </div>
            <div class="legend">
              <?php foreach ($legendItems as $li): ?>
                <div class="legend-item">
                  <span class="legend-dot" style="background:<?= h($li['color']) ?>;"></span>
                  <span class="l-name"><?= h($li['name']) ?></span>
                  <span class="l-val"><?= (int)$li['cnt'] ?> · <?= $li['pct'] ?>%</span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="panel">
        <div class="panel-head">
          <div>
            <h2>Students by Course &amp; Branch</h2>
            <p>Headcount on every course/branch combination.</p>
          </div>
          <button class="btn-add" onclick="switchTab('assign')" style="padding:9px 18px;font-size:12.5px;">Assign more →</button>
        </div>
        <?php if (empty($distribution)): ?>
          <div class="mini-empty">No students yet — add students and assign them a course to see the breakdown here.</div>
        <?php else: ?>
          <div class="barchart-wrap">
            <div class="barchart">
              <?php foreach ($distribution as $d):
                $pct = $distMax > 0 ? round(($d['cnt'] / $distMax) * 100) : 0;
              ?>
                <div class="bar-col">
                  <div class="bar-count"><?= (int)$d['cnt'] ?></div>
                  <div class="bar<?= $d['course_name'] ? '' : ' unassigned' ?>" data-height="<?= $pct ?>%"></div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="bar-labels">
              <?php foreach ($distribution as $d):
                $label = $d['course_name'] ? ($d['course_name'] . (!empty($d['branch_name']) ? ' · ' . $d['branch_name'] : '')) : 'Unassigned';
              ?>
                <div class="bar-label" title="<?= h($label) ?>"><?= h($label) ?></div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div>
          <h2>Recently added teachers</h2>
          <p>The latest teacher accounts added to your college.</p>
        </div>
      </div>
      <div class="mini-list">
        <?php if (empty($recentTeachers)): ?>
          <div class="mini-empty">No teachers added yet.</div>
        <?php else: foreach ($recentTeachers as $t): ?>
          <div class="mini-row">
            <div class="mini-avatar"><?= h(strtoupper(substr($t['full_name'], 0, 1))) ?></div>
            <div class="info">
              <p><?= h($t['full_name']) ?></p>
              <span><?= h($t['email']) ?> · Added <?= h(date('d M Y', strtotime($t['created_at']))) ?></span>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </section>

  <!-- ================= PROFILE TAB ================= -->
  <section class="tab-panel" id="tab-profile">
    <div class="panel">
      <div class="panel-head">
        <div>
          <h2>College Information</h2>
          <p>Update your college's details and your own admin account.</p>
        </div>
      </div>
      <form action="update_profile.php" method="POST">
        <div class="form-grid">
          <div class="form-field">
            <label>College name</label>
            <input type="text" name="college_name" value="<?= h($college['college_name'] ?? '') ?>" required>
          </div>
          <div class="form-field">
            <label>College code</label>
            <input type="text" name="college_code" value="<?= h($college['college_code'] ?? '') ?>">
          </div>
          <div class="form-field">
            <label>Phone number</label>
            <input type="tel" name="phone" value="<?= h($college['phone'] ?? '') ?>" required>
          </div>
          <div class="form-field">
            <label>Admin full name</label>
            <input type="text" name="admin_name" value="<?= h($admin_name) ?>" required>
          </div>
          <div class="form-field">
            <label>Admin email</label>
            <input type="email" name="admin_email" value="<?= h($adminRow['email'] ?? '') ?>" required>
          </div>
          <div class="form-field">
            <label>New password (optional)</label>
            <input type="password" name="new_password" placeholder="Leave blank to keep current password" minlength="6">
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn-save">Save Changes</button>
        </div>
      </form>
    </div>

    <div class="panel danger-panel">
      <div class="panel-head">
        <div>
          <h2>Danger Zone</h2>
        </div>
      </div>
      <div class="danger-body">
        <p>Deleting your college removes your admin account and every teacher and student under it. This cannot be undone.</p>
        <form action="delete_college.php" method="POST" onsubmit="return confirm('Permanently delete this college and all its data? This cannot be undone.');">
          <button type="submit" class="btn-danger">Delete College</button>
        </form>
      </div>
    </div>
  </section>

  <!-- ================= TEACHERS TAB ================= -->
  <section class="tab-panel" id="tab-teachers">
    <div class="panel">
      <div class="panel-head">
        <div>
          <h2>Teachers</h2>
          <p>Added by you — teachers use these logins to build CETs.</p>
        </div>
        <button class="btn-add" onclick="openModal('addTeacherModal')">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></svg>
          Add Teacher
        </button>
      </div>
      <table>
        <thead><tr><th>Name</th><th>Email</th><th>Added on</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($teachers)): ?>
            <tr><td colspan="4" class="empty-row">No teachers added yet — click "Add Teacher" to add your first one.</td></tr>
          <?php else: foreach ($teachers as $t): ?>
            <tr>
              <td>
                <div class="name-cell">
                  <div class="mini-avatar"><?= h(strtoupper(substr($t['full_name'], 0, 1))) ?></div>
                  <?= h($t['full_name']) ?>
                </div>
              </td>
              <td><?= h($t['email']) ?></td>
              <td class="muted-cell"><?= h(date('d M Y', strtotime($t['created_at']))) ?></td>
              <td>
                <div class="row-actions">
                  <button class="icon-btn edit" title="Edit"
                    onclick="openEditTeacher('<?= (int)$t['id'] ?>','<?= h(addslashes($t['full_name'])) ?>','<?= h(addslashes($t['email'])) ?>')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 20h9" stroke="#5C56E0" stroke-width="1.8" stroke-linecap="round"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z" stroke="#5C56E0" stroke-width="1.8" stroke-linejoin="round"/></svg>
                  </button>
                  <a href="delete_teacher.php?id=<?= (int)$t['id'] ?>" class="icon-btn del" title="Remove" onclick="return confirm('Remove this teacher?');">
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

  <!-- ================= STUDENTS TAB ================= -->
  <section class="tab-panel" id="tab-students">
    <div class="panel">
      <div class="panel-head">
        <div>
          <h2>Students</h2>
          <p>Added by you — students use these logins to take CETs.</p>
        </div>
        <button class="btn-add" onclick="openModal('addStudentModal')">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></svg>
          Add Student
        </button>
      </div>
      <table>
        <thead><tr><th>Name</th><th>Roll number</th><th>Email</th><th>Course</th><th>Added on</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($students)): ?>
            <tr><td colspan="6" class="empty-row">No students added yet — click "Add Student" to add your first one.</td></tr>
          <?php else: foreach ($students as $s): ?>
            <tr>
              <td>
                <div class="name-cell">
                  <div class="mini-avatar"><?= h(strtoupper(substr($s['full_name'], 0, 1))) ?></div>
                  <?= h($s['full_name']) ?>
                </div>
              </td>
              <td class="muted-cell"><?= h($s['roll_number']) ?></td>
              <td><?= h($s['email'] ?: '—') ?></td>
              <td>
                <?php if (!empty($s['course_name'])): ?>
                  <span class="course-badge"><?= h($s['course_name']) ?><?= !empty($s['branch_name']) ? ' · ' . h($s['branch_name']) : '' ?></span>
                <?php else: ?>
                  <span class="muted-cell">Not assigned</span>
                <?php endif; ?>
              </td>
              <td class="muted-cell"><?= h(date('d M Y', strtotime($s['created_at']))) ?></td>
              <td>
                <div class="row-actions">
                  <button class="icon-btn edit" title="Edit"
                    onclick="openEditStudent('<?= (int)$s['id'] ?>','<?= h(addslashes($s['full_name'])) ?>','<?= h(addslashes($s['roll_number'])) ?>','<?= h(addslashes($s['email'] ?? '')) ?>')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 20h9" stroke="#5C56E0" stroke-width="1.8" stroke-linecap="round"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z" stroke="#5C56E0" stroke-width="1.8" stroke-linejoin="round"/></svg>
                  </button>
                  <a href="delete_student.php?id=<?= (int)$s['id'] ?>" class="icon-btn del" title="Remove" onclick="return confirm('Remove this student?');">
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

  <!-- ================= CREATE COURSE TAB ================= -->
  <section class="tab-panel" id="tab-courses">
    <div class="panel">
      <div class="panel-head">
        <div>
          <h2>Courses</h2>
          <p>e.g. B.C.A, B.Tech, B.Sc — the programs your college offers.</p>
        </div>
        <button class="btn-add" onclick="openModal('addCourseModal')">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></svg>
          Add Course
        </button>
      </div>
      <table>
        <thead><tr><th>Course</th><th>Branches</th><th>Added on</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($courses)): ?>
            <tr><td colspan="4" class="empty-row">No courses yet — click "Add Course" to create your first one.</td></tr>
          <?php else: foreach ($courses as $c):
            $courseBranches = $branchesByCourse[$c['id']] ?? [];
          ?>
            <tr>
              <td><div class="name-cell"><?= h($c['course_name']) ?></div></td>
              <td>
                <?php if (empty($courseBranches)): ?>
                  <span class="muted-cell">No branches</span>
                <?php else: foreach ($courseBranches as $b): ?>
                  <span class="course-badge" style="margin-right:6px;"><?= h($b['name']) ?></span>
                <?php endforeach; endif; ?>
              </td>
              <td class="muted-cell">—</td>
              <td>
                <div class="row-actions">
                  <button class="icon-btn edit" title="Edit course" onclick="openEditCourse('<?= (int)$c['id'] ?>','<?= h(addslashes($c['course_name'])) ?>')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 20h9" stroke="#5C56E0" stroke-width="1.8" stroke-linecap="round"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z" stroke="#5C56E0" stroke-width="1.8" stroke-linejoin="round"/></svg>
                  </button>
                  <a href="delete_course.php?id=<?= (int)$c['id'] ?>" class="icon-btn del" title="Remove course" onclick="return confirm('Delete this course and all its branches? Students on it will become unassigned.');">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M9 7V4h6v3M6 7l1 13a2 2 0 002 2h6a2 2 0 002-2l1-13" stroke="#FF6E5E" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div>
          <h2>Branches</h2>
          <p>e.g. "AI &amp; DS" or "Automobile" under B.Tech.</p>
        </div>
        <button class="btn-add" onclick="openModal('addBranchModal')" <?= empty($courses) ? 'disabled title="Add a course first"' : '' ?>>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></svg>
          Add Branch
        </button>
      </div>
      <table>
        <thead><tr><th>Branch</th><th>Course</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($branchesFlat)): ?>
            <tr><td colspan="3" class="empty-row">No branches yet.</td></tr>
          <?php else:
            $courseNameById = array_column($courses, 'course_name', 'id');
            foreach ($branchesFlat as $b): ?>
            <tr>
              <td><div class="name-cell"><?= h($b['branch_name']) ?></div></td>
              <td class="muted-cell"><?= h($courseNameById[$b['course_id']] ?? '—') ?></td>
              <td>
                <div class="row-actions">
                  <button class="icon-btn edit" title="Edit branch" onclick="openEditBranch('<?= (int)$b['id'] ?>','<?= h(addslashes($b['branch_name'])) ?>')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 20h9" stroke="#5C56E0" stroke-width="1.8" stroke-linecap="round"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z" stroke="#5C56E0" stroke-width="1.8" stroke-linejoin="round"/></svg>
                  </button>
                  <a href="delete_branch.php?id=<?= (int)$b['id'] ?>" class="icon-btn del" title="Remove branch" onclick="return confirm('Remove this branch?');">
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

  <!-- ================= ASSIGN COURSE STUDENT TAB ================= -->
  <section class="tab-panel" id="tab-assign">
    <div class="panel">
      <div class="panel-head">
        <div>
          <h2>Assign Course to Students</h2>
          <p>Search a student, then assign the course (and branch) they'll be tested on.</p>
        </div>
      </div>
      <div style="padding:20px 26px 0;">
        <input type="text" id="assignSearch" class="search-box" style="width:100%;" placeholder="Type here... search by name or roll number">
      </div>
      <table>
        <thead><tr><th>Name</th><th>Roll number</th><th>Current assignment</th><th></th></tr></thead>
        <tbody id="assignRows">
          <?php if (empty($students)): ?>
            <tr><td colspan="4" class="empty-row">No students added yet.</td></tr>
          <?php else: foreach ($students as $s): ?>
            <tr class="assign-row" data-search="<?= h(strtolower($s['full_name'] . ' ' . $s['roll_number'])) ?>">
              <td>
                <div class="name-cell">
                  <div class="mini-avatar"><?= h(strtoupper(substr($s['full_name'], 0, 1))) ?></div>
                  <?= h($s['full_name']) ?>
                </div>
              </td>
              <td class="muted-cell"><?= h($s['roll_number']) ?></td>
              <td>
                <?php if (!empty($s['course_name'])): ?>
                  <span class="course-badge"><?= h($s['course_name']) ?><?= !empty($s['branch_name']) ? ' · ' . h($s['branch_name']) : '' ?></span>
                <?php else: ?>
                  <span class="muted-cell">Not assigned</span>
                <?php endif; ?>
              </td>
              <td>
                <button class="btn-add" style="padding:8px 16px;font-size:12.5px;"
                  onclick="openAssignModal('<?= (int)$s['id'] ?>','<?= h(addslashes($s['full_name'])) ?>','<?= (int)($s['course_id'] ?? 0) ?>','<?= (int)($s['branch_id'] ?? 0) ?>')"
                  <?= empty($courses) ? 'disabled title="Create a course first"' : '' ?>>
                  Assign
                </button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      <p class="mini-empty" id="assignNoResults" style="display:none;">No students match your search.</p>
    </div>
  </section>
</main>

<!-- ---------------- ADD TEACHER MODAL ---------------- -->
<div class="modal-overlay" id="addTeacherModal">
  <div class="modal">
    <h3>Add a Teacher</h3>
    <p class="sub">They'll use this email and password to log in and build CETs.</p>
    <form method="POST" action="add_teacher.php">
      <div class="field"><label>Full name</label><input type="text" name="full_name" placeholder="Teacher's full name" required></div>
      <div class="field"><label>Email</label><input type="email" name="email" placeholder="teacher@college.edu" required></div>
      <div class="field"><label>Password</label><input type="password" name="password" placeholder="Create a password" minlength="6" required></div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('addTeacherModal')">Cancel</button>
        <button type="submit" class="btn-confirm">Add Teacher</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------------- EDIT TEACHER MODAL ---------------- -->
<div class="modal-overlay" id="editTeacherModal">
  <div class="modal">
    <h3>Edit Teacher</h3>
    <p class="sub">Leave password blank to keep their current password.</p>
    <form method="POST" action="update_teacher.php">
      <input type="hidden" name="teacher_id" id="editTeacherId">
      <div class="field"><label>Full name</label><input type="text" name="full_name" id="editTeacherName" required></div>
      <div class="field"><label>Email</label><input type="email" name="email" id="editTeacherEmail" required></div>
      <div class="field"><label>New password (optional)</label><input type="password" name="password" placeholder="Leave blank to keep current" minlength="6"></div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('editTeacherModal')">Cancel</button>
        <button type="submit" class="btn-confirm">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------------- ADD STUDENT MODAL ---------------- -->
<div class="modal-overlay" id="addStudentModal">
  <div class="modal">
    <h3>Add a Student</h3>
    <p class="sub">They'll use their roll number and password to log in and take CETs.</p>
    <form method="POST" action="add_student.php">
      <div class="field"><label>Full name</label><input type="text" name="full_name" placeholder="Student's full name" required></div>
      <div class="field"><label>Roll number</label><input type="text" name="roll_number" placeholder="e.g. CS2026-014" required></div>
      <div class="field"><label>Email (optional)</label><input type="email" name="email" placeholder="student@college.edu"></div>
      <div class="field"><label>Password</label><input type="password" name="password" placeholder="Create a password" minlength="6" required></div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('addStudentModal')">Cancel</button>
        <button type="submit" class="btn-confirm">Add Student</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------------- EDIT STUDENT MODAL ---------------- -->
<div class="modal-overlay" id="editStudentModal">
  <div class="modal">
    <h3>Edit Student</h3>
    <p class="sub">Leave password blank to keep their current password.</p>
    <form method="POST" action="update_student.php">
      <input type="hidden" name="student_id" id="editStudentId">
      <div class="field"><label>Full name</label><input type="text" name="full_name" id="editStudentName" required></div>
      <div class="field"><label>Roll number</label><input type="text" name="roll_number" id="editStudentRoll" required></div>
      <div class="field"><label>Email (optional)</label><input type="email" name="email" id="editStudentEmail"></div>
      <div class="field"><label>New password (optional)</label><input type="password" name="password" placeholder="Leave blank to keep current" minlength="6"></div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('editStudentModal')">Cancel</button>
        <button type="submit" class="btn-confirm">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------------- ADD COURSE MODAL ---------------- -->
<div class="modal-overlay" id="addCourseModal">
  <div class="modal">
    <h3>Add a Course</h3>
    <p class="sub">e.g. B.C.A, B.Tech, B.Sc</p>
    <form method="POST" action="add_course.php">
      <div class="field"><label>Course name</label><input type="text" name="course_name" placeholder="e.g. B.Tech" required></div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('addCourseModal')">Cancel</button>
        <button type="submit" class="btn-confirm">Add Course</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------------- EDIT COURSE MODAL ---------------- -->
<div class="modal-overlay" id="editCourseModal">
  <div class="modal">
    <h3>Edit Course</h3>
    <form method="POST" action="update_course.php">
      <input type="hidden" name="course_id" id="editCourseId">
      <div class="field"><label>Course name</label><input type="text" name="course_name" id="editCourseName" required></div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('editCourseModal')">Cancel</button>
        <button type="submit" class="btn-confirm">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------------- ADD BRANCH MODAL ---------------- -->
<div class="modal-overlay" id="addBranchModal">
  <div class="modal">
    <h3>Add a Branch</h3>
    <p class="sub">e.g. "AI &amp; DS" or "Automobile" under B.Tech</p>
    <form method="POST" action="add_branch.php">
      <div class="field">
        <label>Course</label>
        <select class="modal-select" name="course_id" required>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['course_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Branch name</label><input type="text" name="branch_name" placeholder="e.g. AI &amp; DS" required></div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('addBranchModal')">Cancel</button>
        <button type="submit" class="btn-confirm">Add Branch</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------------- EDIT BRANCH MODAL ---------------- -->
<div class="modal-overlay" id="editBranchModal">
  <div class="modal">
    <h3>Edit Branch</h3>
    <form method="POST" action="update_branch.php">
      <input type="hidden" name="branch_id" id="editBranchId">
      <div class="field"><label>Branch name</label><input type="text" name="branch_name" id="editBranchName" required></div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('editBranchModal')">Cancel</button>
        <button type="submit" class="btn-confirm">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------------- ASSIGN COURSE MODAL ---------------- -->
<div class="modal-overlay" id="assignCourseModal">
  <div class="modal">
    <h3>Assign Course</h3>
    <p class="sub" id="assignStudentName">—</p>
    <form method="POST" action="assign_course.php">
      <input type="hidden" name="student_id" id="assignStudentId">
      <div class="field">
        <label>Course</label>
        <select class="modal-select" name="course_id" id="assignCourseSelect" required onchange="populateAssignBranches()">
          <option value="">Choose a course</option>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['course_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Branch (optional)</label>
        <select class="modal-select" name="branch_id" id="assignBranchSelect">
          <option value="">No branch</option>
        </select>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('assignCourseModal')">Cancel</button>
        <button type="submit" class="btn-confirm">Assign</button>
      </div>
    </form>
  </div>
</div>

<script>
  const branchesByCourse = <?= json_encode($branchesByCourse) ?>;
</script>

<script>
  // ---------- sidebar tab switching ----------
  const tabLabels = {
    dashboard: 'Dashboard',
    profile: 'College / Admin Profile',
    teachers: 'Teachers',
    students: 'Students',
    courses: 'Create Course',
    assign: 'Assign Course Student'
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

  // ---------- modals ----------
  function openModal(id){ document.getElementById(id).classList.add('show'); }
  function closeModal(id){ document.getElementById(id).classList.remove('show'); }
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.classList.remove('show'); });
  });
  function openEditTeacher(id, name, email){
    document.getElementById('editTeacherId').value = id;
    document.getElementById('editTeacherName').value = name;
    document.getElementById('editTeacherEmail').value = email;
    openModal('editTeacherModal');
  }

  function openEditStudent(id, name, roll, email){
    document.getElementById('editStudentId').value = id;
    document.getElementById('editStudentName').value = name;
    document.getElementById('editStudentRoll').value = roll;
    document.getElementById('editStudentEmail').value = email;
    openModal('editStudentModal');
  }

  function openEditCourse(id, name){
    document.getElementById('editCourseId').value = id;
    document.getElementById('editCourseName').value = name;
    openModal('editCourseModal');
  }

  function openEditBranch(id, name){
    document.getElementById('editBranchId').value = id;
    document.getElementById('editBranchName').value = name;
    openModal('editBranchModal');
  }

  function populateAssignBranches(preselectBranchId){
    const courseId = document.getElementById('assignCourseSelect').value;
    const branchSelect = document.getElementById('assignBranchSelect');
    branchSelect.innerHTML = '<option value="">No branch</option>';
    const list = branchesByCourse[courseId] || [];
    list.forEach(b => {
      const opt = document.createElement('option');
      opt.value = b.id;
      opt.textContent = b.name;
      if (preselectBranchId && String(preselectBranchId) === String(b.id)) opt.selected = true;
      branchSelect.appendChild(opt);
    });
  }

  function openAssignModal(studentId, studentName, courseId, branchId){
    document.getElementById('assignStudentId').value = studentId;
    document.getElementById('assignStudentName').textContent = 'Assigning: ' + studentName;
    const courseSelect = document.getElementById('assignCourseSelect');
    courseSelect.value = courseId && courseId !== '0' ? courseId : '';
    populateAssignBranches(branchId && branchId !== '0' ? branchId : null);
    openModal('assignCourseModal');
  }

  // ---------- search filter (Assign Course Student tab) ----------
  const assignSearch = document.getElementById('assignSearch');
  if (assignSearch) {
    assignSearch.addEventListener('input', () => {
      const q = assignSearch.value.trim().toLowerCase();
      const rows = document.querySelectorAll('#assignRows .assign-row');
      let visibleCount = 0;
      rows.forEach(row => {
        const match = row.dataset.search.includes(q);
        row.style.display = match ? '' : 'none';
        if (match) visibleCount++;
      });
      const noResults = document.getElementById('assignNoResults');
      if (noResults) noResults.style.display = (visibleCount === 0 && rows.length > 0) ? 'block' : 'none';
    });
  }


  // ---------- animated counters ----------
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
  animateCount(document.getElementById('countTeachers'));
  animateCount(document.getElementById('countStudents'));

  // ---------- animate donut chart ----------
  const donut = document.getElementById('donutChart');
  if (donut) {
    const targetGradient = donut.dataset.donut;
    requestAnimationFrame(() => {
      setTimeout(() => { donut.style.background = 'conic-gradient(' + targetGradient + ')'; }, 150);
    });
    const donutTotalEl = document.getElementById('donutTotal');
    if (donutTotalEl) animateCount(donutTotalEl);
  }

  // ---------- animate vertical bar chart ----------
  requestAnimationFrame(() => {
    document.querySelectorAll('.bar').forEach(bar => {
      bar.style.height = bar.dataset.height || '0%';
    });
  });

  // ---------- restore tab + banner from query params ----------
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
