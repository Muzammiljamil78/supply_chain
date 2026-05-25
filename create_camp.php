<?php
require_once '../config.php';
if (!isset($_SESSION['user_id'])) { header('Location: login'); exit; }
$user_id = (int)$_SESSION['user_id'];

// Public OR assigned to this publisher
$base_access = "(o.is_public = 1 OR EXISTS (
    SELECT 1 FROM offer_publisher_access opa 
    WHERE opa.offer_id = o.id 
    AND opa.publisher_id = $user_id
))";

$offers_raw = [];
$res = $conn->query("
    SELECT o.id, o.name, o.image, o.category, o.payout_type,
           GROUP_CONCAT(e.id ORDER BY e.payout_amount DESC SEPARATOR '||') as event_ids,
           GROUP_CONCAT(e.event_name ORDER BY e.payout_amount DESC SEPARATOR '||') as event_names,
           GROUP_CONCAT(e.payout_amount ORDER BY e.payout_amount DESC SEPARATOR '||') as event_payouts
    FROM offers o
    LEFT JOIN offer_events e ON o.id = e.offer_id
    WHERE o.status = 'active' AND $base_access
    GROUP BY o.id ORDER BY o.created_at DESC
");
while ($row = $res->fetch_assoc()) $offers_raw[] = $row;

$my_camps = [];
$stmt = $conn->prepare("
    SELECT c.*, o.name as offer_name, o.image as offer_image, o.payout_type,
           o.status as offer_status, e.event_name, e.payout_amount as max_payout
    FROM camps c
    JOIN offers o ON c.offer_id = o.id
    LEFT JOIN offer_events e ON c.event_id = e.id
    WHERE c.user_id = ?
    ORDER BY c.created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $my_camps[] = $row;
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_camp'])) {
    $offer_id       = (int)$_POST['offer_id'];
    $event_id       = (int)($_POST['event_id'] ?? 0);
    $camp_name      = trim($_POST['camp_name']);
    $user_payout    = (float)($_POST['user_payout'] ?? 0);
    $refer_payout   = (float)($_POST['refer_payout'] ?? 0);
    $refer_enabled  = isset($_POST['refer_enabled']) ? 1 : 0;
    $mobile_enabled = isset($_POST['mobile_enabled']) ? 1 : 0;
    $steps_raw      = $_POST['steps'] ?? [];
    $steps          = json_encode(array_values(array_filter(array_map('trim', (array)$steps_raw))));
    $slug           = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $camp_name)) . '-' . substr(md5(uniqid()), 0, 4);



    $max_payout = 0;
    if ($event_id > 0) {
        $ps = $conn->prepare("SELECT payout_amount FROM offer_events WHERE id=? AND offer_id=?");
        $ps->bind_param("ii", $event_id, $offer_id);
        $ps->execute();
        $pr = $ps->get_result()->fetch_assoc();
        $ps->close();
        $max_payout = $pr ? (float)$pr['payout_amount'] : 0;
    }
    if ($offer_id <= 0 || $camp_name === '') {
        $_SESSION['camp_error'] = "Please fill all required fields.";
    } elseif (($user_payout + $refer_payout) > $max_payout && $max_payout > 0) {
        $_SESSION['camp_error'] = "User + Refer payout cannot exceed ₹{$max_payout}";
    } else {
        $stmt = $conn->prepare("INSERT INTO camps (user_id, offer_id, event_id, camp_name, slug, user_payout, refer_payout, steps, refer_enabled, mobile_enabled, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
        $stmt->bind_param("iiissddsii", $user_id, $offer_id, $event_id, $camp_name, $slug, $user_payout, $refer_payout, $steps, $refer_enabled, $mobile_enabled);
        if ($stmt->execute()) { $_SESSION['camp_success'] = "Camp created successfully!"; }
        else { $_SESSION['camp_error'] = "Error: " . $conn->error; }
        $stmt->close();
    }
    header("Location: create_camp"); exit;
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_camp'])) {
    $camp_id        = (int)$_POST['camp_id'];
    $camp_name      = trim($_POST['camp_name']);
    $user_payout    = (float)($_POST['user_payout'] ?? 0);
    $refer_payout   = (float)($_POST['refer_payout'] ?? 0);
    $refer_enabled  = isset($_POST['refer_enabled']) ? 1 : 0;
    $mobile_enabled = isset($_POST['mobile_enabled']) ? 1 : 0;
    $steps_raw      = $_POST['steps'] ?? [];
    $steps          = json_encode(array_values(array_filter(array_map('trim', (array)$steps_raw))));
    $stmt = $conn->prepare("UPDATE camps SET camp_name=?, user_payout=?, refer_payout=?, steps=?, refer_enabled=?, mobile_enabled=? WHERE id=? AND user_id=?");
    $stmt->bind_param("sddsii" . "ii", $camp_name, $user_payout, $refer_payout, $steps, $refer_enabled, $mobile_enabled, $camp_id, $user_id);
    if ($stmt->execute()) { $_SESSION['camp_success'] = "Camp updated!"; }
    else { $_SESSION['camp_error'] = "Update failed: " . $conn->error; }
    $stmt->close();
    header("Location: create_camp"); exit;
}

if (isset($_GET['toggle'], $_GET['camp_id'])) {
    $camp_id = (int)$_GET['camp_id'];
    $new_status = $_GET['toggle'] === 'active' ? 'paused' : 'active';
    $stmt = $conn->prepare("UPDATE camps SET status=? WHERE id=? AND user_id=?");
    $stmt->bind_param("sii", $new_status, $camp_id, $user_id);
    $stmt->execute(); $stmt->close();
    header("Location: create_camp"); exit;
}

if (isset($_GET['delete_camp'])) {
    $camp_id = (int)$_GET['delete_camp'];
    $stmt = $conn->prepare("DELETE FROM camps WHERE id=? AND user_id=?");
    $stmt->bind_param("ii", $camp_id, $user_id);
    $stmt->execute(); $stmt->close();
    $_SESSION['camp_success'] = "Camp deleted.";
    header("Location: create_camp"); exit;
}

$base_url = 'https://partner.osamcamp.in';
?>



<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Camp Builder</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{
  --font:'Inter',system-ui,-apple-system,sans-serif;
  --bg:#f8f9fa;
  --surface:#ffffff;
  --text:#171717;
  --text-secondary:#6b7280;
  --text-muted:#9ca3af;
  --accent:#171717;
  --accent-hover:#333;
  --highlight:#6366f1;
  --highlight-light:#eef2ff;
  --highlight-soft:rgba(99,102,241,.08);
  --border:#e5e7eb;
  --input-bg:#f9fafb;
  --green:#10b981;
  --green-light:#d1fae5;
  --gold:#f59e0b;
  --gold-light:#fef3c7;
  --red:#ef4444;
  --red-light:#fee2e2;
  --radius:12px;
  --radius-sm:8px;
  --transition:0.2s ease;
}
html{scroll-behavior:smooth}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;line-height:1.5;-webkit-font-smoothing:antialiased}


@media(min-width:1024px){.page-content{margin-left:288px}}

/* Sidebar */
.sidebar{position:fixed;left:0;top:0;bottom:0;width:288px;z-index:9999!important;background:var(--surface);border-right:1px solid var(--border);transition:transform .3s ease}
@media(max-width:1024px){
  .sidebar{transform:translateX(-100%);box-shadow:none}
  .sidebar.active{transform:translateX(0);box-shadow:0 20px 60px rgba(0,0,0,.15)}
  .sidebar-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);backdrop-filter:blur(2px);z-index:9998;display:none}
  .sidebar-overlay.active{display:block}
}

/* Progress bar */
.progress-bar-wrap{position:sticky;top:0;z-index:90;background:var(--surface);border-bottom:1px solid var(--border);padding:16px 24px 12px}
.progress-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.progress-label{font-size:13px;font-weight:600;color:var(--text)}
.progress-step{font-size:12px;color:var(--text-secondary)}
.progress-track{height:3px;background:var(--border);border-radius:99px;overflow:hidden}
.progress-fill{height:100%;background:var(--highlight);border-radius:99px;transition:width .4s cubic-bezier(.4,0,.2,1)}

/* Content area */
.page-content{background:var(--bg);min-height:100vh;padding-bottom:80px}
.content-wrap{max-width:860px;margin:0 auto;padding:32px 24px}
@media(max-width:640px){.content-wrap{padding:20px 16px}}


/* Page header */
.page-header{margin-bottom:32px}
.page-header h1{font-size:26px;font-weight:700;color:var(--text);letter-spacing:-.02em;margin-bottom:4px}
.page-header p{font-size:14px;color:var(--text-secondary)}

/* Step sections - open layout, no card borders */
.step-section{margin-bottom:48px;opacity:0;transform:translateY(16px);transition:all .4s cubic-bezier(.4,0,.2,1);pointer-events:none}
.step-section.visible{opacity:1;transform:translateY(0);pointer-events:all}
.step-section .section-heading{font-size:20px;font-weight:700;color:var(--text);letter-spacing:-.01em;margin-bottom:6px}
.step-section .section-desc{font-size:14px;color:var(--text-secondary);margin-bottom:20px}
.step-divider{height:1px;background:var(--border);margin:0 0 48px 0}

/* Offer grid */
.offer-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
@media(min-width:768px){.offer-grid{grid-template-columns:repeat(3,1fr)}}
.offer-tile{position:relative;background:var(--surface);border-radius:var(--radius);overflow:hidden;cursor:pointer;transition:all var(--transition);border:2px solid transparent}
.offer-tile:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.06)}
.offer-tile.selected{border-color:var(--highlight);box-shadow:0 0 0 3px var(--highlight-soft)}
.offer-tile img{width:100%;height:100px;object-fit:cover;display:block}
@media(min-width:768px){.offer-tile img{height:120px}}
.offer-tile-body{padding:12px 14px}
.offer-tile-name{font-size:13px;font-weight:600;color:var(--text);line-height:1.3;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.offer-tile-meta{font-size:11px;color:var(--text-muted)}
.offer-tile-payout{position:absolute;top:8px;right:8px;background:rgba(0,0,0,.7);color:#fff;font-size:11px;font-weight:700;padding:4px 8px;border-radius:6px;backdrop-filter:blur(4px)}
.offer-tile-check{position:absolute;top:8px;left:8px;width:24px;height:24px;border-radius:50%;background:var(--highlight);color:#fff;display:none;align-items:center;justify-content:center;font-size:11px}
.offer-tile.selected .offer-tile-check{display:flex}


/* Inputs */
.form-input{width:100%;height:48px;background:var(--input-bg);border:1.5px solid transparent;border-radius:var(--radius-sm);padding:0 16px;font-size:15px;font-family:var(--font);color:var(--text);outline:none;transition:all var(--transition)}
.form-input:focus{border-color:var(--highlight);background:var(--surface);box-shadow:0 0 0 3px var(--highlight-soft)}
.form-input::placeholder{color:var(--text-muted)}
.form-label{display:block;font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em}

/* Event pills */
.event-grid{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px}
.event-pill{padding:10px 18px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:13px;font-weight:500;color:var(--text-secondary);cursor:pointer;transition:all var(--transition);display:flex;align-items:center;gap:8px;background:var(--surface)}
.event-pill:hover{border-color:var(--highlight);color:var(--text)}
.event-pill.selected{border-color:var(--highlight);background:var(--highlight-light);color:var(--highlight)}
.event-pill .ev-amount{font-weight:700;color:var(--green)}

/* Payout section */
.payout-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;background:var(--input-bg);border-radius:var(--radius-sm);margin-bottom:16px}
.payout-header .ph-label{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.03em;margin-bottom:2px}
.payout-header .ph-value{font-size:20px;font-weight:700}
.payout-header .ph-value.green{color:var(--green)}
.payout-header .ph-value.gold{color:var(--gold)}
.payout-inputs{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
@media(max-width:480px){.payout-inputs{grid-template-columns:1fr}}
.payout-bar-track{height:4px;background:var(--border);border-radius:99px;overflow:hidden;margin-bottom:6px}
.payout-bar-fill{height:100%;background:var(--highlight);border-radius:99px;transition:width .3s ease,background .3s ease}
.payout-bar-label{font-size:12px;color:var(--text-muted);text-align:right}


/* Steps builder */
.step-row{display:flex;align-items:center;gap:10px;margin-bottom:10px;animation:slideUp .25s ease}
@keyframes slideUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.step-num{width:28px;height:28px;border-radius:50%;background:var(--highlight-light);color:var(--highlight);font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.step-input{flex:1;height:44px;background:var(--input-bg);border:1.5px solid transparent;border-radius:var(--radius-sm);padding:0 14px;font-size:14px;font-family:var(--font);color:var(--text);outline:none;transition:all var(--transition)}
.step-input:focus{border-color:var(--highlight);background:var(--surface);box-shadow:0 0 0 3px var(--highlight-soft)}
.step-input::placeholder{color:var(--text-muted)}
.step-remove{width:28px;height:28px;border-radius:6px;background:transparent;border:none;color:var(--text-muted);display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:all var(--transition)}
.step-remove:hover{background:var(--red-light);color:var(--red)}
.add-step-btn{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500;color:var(--text-muted);background:none;border:2px dashed var(--border);border-radius:var(--radius-sm);padding:12px 16px;cursor:pointer;width:100%;justify-content:center;transition:all var(--transition);margin-top:8px}
.add-step-btn:hover{border-color:var(--highlight);color:var(--highlight);background:var(--highlight-soft)}

/* Toggle switches */
.toggle-item{display:flex;align-items:center;justify-content:space-between;padding:16px 0;border-bottom:1px solid var(--border)}
.toggle-item:last-child{border-bottom:none}
.toggle-info .ti-label{font-size:14px;font-weight:500;color:var(--text)}
.toggle-info .ti-desc{font-size:12px;color:var(--text-muted);margin-top:2px}
.toggle-sw{position:relative;width:44px;height:24px;flex-shrink:0}
.toggle-sw input{opacity:0;width:0;height:0;position:absolute}
.toggle-track{position:absolute;inset:0;background:var(--border);border-radius:12px;cursor:pointer;transition:.3s}
.toggle-track::before{content:'';position:absolute;width:18px;height:18px;background:#fff;border-radius:9px;top:3px;left:3px;transition:.3s;box-shadow:0 1px 3px rgba(0,0,0,.15)}
input:checked~.toggle-track{background:var(--highlight)}
input:checked~.toggle-track::before{transform:translateX(20px)}


/* Submit button */
.btn-primary{width:100%;height:52px;background:var(--accent);color:#fff;border:none;border-radius:var(--radius-sm);font-size:15px;font-weight:600;font-family:var(--font);cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;transition:all var(--transition);letter-spacing:-.01em}
.btn-primary:hover{background:var(--accent-hover);transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn-primary:active{transform:scale(.98)}

/* Link preview */
.link-preview{background:var(--input-bg);border-radius:var(--radius-sm);padding:14px 16px;font-family:'SF Mono','Fira Code',monospace;font-size:12px;color:var(--text-secondary);word-break:break-all;line-height:1.7;margin-bottom:12px}

/* Alerts */
.alert{display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:var(--radius-sm);font-size:13px;font-weight:500;margin-bottom:20px;animation:slideUp .3s ease}
.alert-success{background:var(--green-light);color:#065f46}
.alert-error{background:var(--red-light);color:#991b1b}
.alert-close{margin-left:auto;background:none;border:none;color:inherit;cursor:pointer;opacity:.6}
.alert-close:hover{opacity:1}

/* My Camps table */
.camps-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.camps-header h2{font-size:20px;font-weight:700;color:var(--text);letter-spacing:-.01em}
.camps-count{font-size:12px;font-weight:600;color:var(--text-muted);background:var(--input-bg);padding:4px 12px;border-radius:99px}


.camp-row{background:var(--surface);border-radius:var(--radius);padding:20px;margin-bottom:12px;display:flex;flex-wrap:wrap;align-items:flex-start;gap:16px;transition:all var(--transition)}
.camp-row:hover{box-shadow:0 4px 16px rgba(0,0,0,.04)}
.camp-row-img{width:48px;height:48px;border-radius:10px;object-fit:cover;flex-shrink:0}
.camp-row-info{flex:1;min-width:200px}
.camp-row-name{font-size:15px;font-weight:600;color:var(--text);margin-bottom:2px}
.camp-row-offer{font-size:12px;color:var(--text-muted)}
.camp-row-badges{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.badge{font-size:11px;font-weight:600;padding:3px 10px;border-radius:5px}
.badge-green{background:var(--green-light);color:#065f46}
.badge-red{background:var(--red-light);color:#991b1b}
.badge-gold{background:var(--gold-light);color:#92400e}
.badge-blue{background:var(--highlight-light);color:var(--highlight)}
.badge-gray{background:var(--input-bg);color:var(--text-muted)}
.camp-row-right{display:flex;flex-direction:column;gap:8px;align-items:flex-end;flex-shrink:0}
@media(max-width:640px){.camp-row{flex-direction:column}.camp-row-right{align-items:stretch;width:100%}}
.camp-link-box{display:flex;align-items:center;gap:8px;background:var(--input-bg);border-radius:6px;padding:8px 12px;width:100%}
.camp-link-box span{font-size:11px;color:var(--text-muted);font-family:'SF Mono','Fira Code',monospace;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.camp-link-box button{flex-shrink:0;background:none;border:none;color:var(--highlight);cursor:pointer;font-size:12px;transition:color var(--transition)}
.camp-link-box button:hover{color:var(--text)}
.camp-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.act-btn{font-size:11px;font-weight:600;padding:7px 12px;border-radius:6px;border:none;cursor:pointer;display:flex;align-items:center;gap:5px;transition:all var(--transition);font-family:var(--font);text-decoration:none}
.act-report{background:var(--highlight-light);color:var(--highlight)}
.act-report:hover{background:#ddd6fe}
.act-edit{background:var(--gold-light);color:#92400e}
.act-edit:hover{background:#fde68a}
.act-pause{background:var(--input-bg);color:var(--text-muted)}
.act-pause:hover{background:var(--border);color:var(--text)}
.act-del{background:var(--red-light);color:var(--red)}
.act-del:hover{background:#fecaca}


/* Edit modal - full screen overlay centered card */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(6px);z-index:9999;display:none;align-items:center;justify-content:center;padding:20px}
.modal-overlay.show{display:flex}
.modal-card{background:var(--surface);border-radius:var(--radius);width:100%;max-width:520px;max-height:90vh;overflow-y:auto;padding:32px;animation:modalIn .3s cubic-bezier(.4,0,.2,1)}
@keyframes modalIn{from{opacity:0;transform:scale(.95) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
.modal-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}
.modal-top h3{font-size:18px;font-weight:700;color:var(--text)}
.modal-close-btn{width:32px;height:32px;border-radius:8px;background:var(--input-bg);border:none;color:var(--text-muted);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;transition:all var(--transition)}
.modal-close-btn:hover{background:var(--border);color:var(--text)}

/* Empty state */
.empty-state{text-align:center;padding:60px 24px}
.empty-state .empty-icon{font-size:48px;margin-bottom:16px;opacity:.4}
.empty-state .empty-title{font-size:16px;font-weight:600;color:var(--text-muted);margin-bottom:6px}
.empty-state .empty-desc{font-size:13px;color:var(--text-muted)}

/* Hidden tab panels for JS compat */
.tab-panel{display:block}
.tabs{display:none}

/* Mobile menu btn */
.mobile-menu-btn{display:none;background:none;border:none;font-size:20px;color:var(--text);cursor:pointer;padding:4px}
@media(max-width:1024px){.mobile-menu-btn{display:flex;align-items:center}}

/* toast */
@keyframes toastSlide{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
</style>
</head>
<body>

<?php include 'sidebar.php'; ?>


<!-- Progress bar -->
<div class="page-content">
<div class="progress-bar-wrap" id="progressWrap">
    <div class="progress-header">
        <span class="progress-label" id="progressLabel">Step 1 of 5</span>
        <span class="progress-step" id="progressStepName">Select Offer</span>
    </div>
    <div class="progress-track">
        <div class="progress-fill" id="progressFill" style="width:20%"></div>
    </div>
</div>

<!-- Hidden tabs for JS compat -->
<div class="tabs" id="mainTabs">
    <button class="tab active" onclick="switchTab('create')" id="tab-create">Create</button>
    <button class="tab" onclick="switchTab('camps')" id="tab-camps">My Camps</button>
</div>

<div class="content-wrap">

<!-- Alerts -->
<?php if(isset($_SESSION['camp_success'])): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i><?= htmlspecialchars($_SESSION['camp_success']) ?><button class="alert-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button></div>
<?php unset($_SESSION['camp_success']); endif; ?>
<?php if(isset($_SESSION['camp_error'])): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($_SESSION['camp_error']) ?><button class="alert-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button></div>
<?php unset($_SESSION['camp_error']); endif; ?>

<!-- Page header -->
<div class="page-header">
    <div style="display:flex;align-items:center;gap:12px">
        <button class="mobile-menu-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
        <div>
            <h1>Camp Builder</h1>
            <p>Create a new campaign in 5 simple steps</p>
        </div>
    </div>
</div>


<!-- CREATE FORM -->
<div class="tab-panel active" id="panel-create">
<form method="POST" id="campForm">
<input type="hidden" name="create_camp" value="1">
<input type="hidden" name="offer_id" id="f_offer_id">
<input type="hidden" name="event_id" id="f_event_id">

<!-- STEP 1: Select Offer -->
<div class="step-section visible" id="step1">
    <h2 class="section-heading">Select Offer</h2>
    <p class="section-desc">Choose an offer to promote in your campaign</p>
    <?php if(empty($offers_raw)): ?>
    <div class="empty-state">
        <div class="empty-icon">📭</div>
        <div class="empty-title">No active offers available</div>
        <div class="empty-desc">Check back later for new offers</div>
    </div>
    <?php else: ?>
    <div class="offer-grid">
        <?php foreach($offers_raw as $o):
            $eids  = $o['event_ids']    ? explode('||',$o['event_ids'])    : [];
            $enames= $o['event_names']  ? explode('||',$o['event_names'])  : [];
            $epays = $o['event_payouts']? explode('||',$o['event_payouts']): [];
            $evArr = [];
            foreach($eids as $i => $eid) $evArr[] = ['id'=>$eid,'name'=>$enames[$i]??'','payout'=>$epays[$i]??0];
            $maxP  = !empty($epays) ? max($epays) : 0;
        ?>
        <div class="offer-tile" onclick="selectOffer(<?= $o['id'] ?>,'<?= htmlspecialchars($o['name'],ENT_QUOTES) ?>',<?= htmlspecialchars(json_encode($evArr),ENT_QUOTES) ?>)" data-id="<?= $o['id'] ?>">
            <div class="offer-tile-check"><i class="fas fa-check"></i></div>
            <div class="offer-tile-payout">₹<?= number_format($maxP,0) ?></div>
            <img src="<?= htmlspecialchars($o['image']) ?>" onerror="this.src='https://via.placeholder.com/300x120/f9fafb/9ca3af?text=Offer'">
            <div class="offer-tile-body">
                <div class="offer-tile-name"><?= htmlspecialchars($o['name']) ?></div>
                <div class="offer-tile-meta"><?= htmlspecialchars($o['category']) ?> · <?= htmlspecialchars($o['payout_type']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<div class="step-divider" id="div1" style="display:none"></div>


<!-- STEP 2: Camp Short Name -->
<div class="step-section" id="step2">
    <h2 class="section-heading">Camp Name</h2>
    <p class="section-desc">Give your campaign a short, memorable name</p>
    <div>
        <label class="form-label">Campaign Name</label>
        <input type="text" name="camp_name" id="camp_name" class="form-input" placeholder="e.g. Cashback Offer March" required autocomplete="off">
    </div>
</div>
<div class="step-divider" id="div2" style="display:none"></div>

<!-- STEP 3: Event & Payout -->
<div class="step-section" id="step3">
    <h2 class="section-heading">Event & Payout</h2>
    <p class="section-desc">Select an event and split the earnings</p>
    <div class="event-grid" id="eventPills"></div>
    <div id="payoutConfig" style="display:none">
        <div class="payout-header">
            <div>
                <div class="ph-label">Event Payout</div>
                <div class="ph-value gold" id="maxLbl">₹0</div>
            </div>
            <div style="text-align:right">
                <div class="ph-label">Your Profit</div>
                <div class="ph-value green" id="remLbl">₹0</div>
            </div>
        </div>
        <div class="payout-inputs">
            <div>
                <label class="form-label">User Gets (₹)</label>
                <input type="number" name="user_payout" id="user_payout" class="form-input" min="0" step="1" placeholder="0" oninput="updateBar()">
            </div>
            <div id="refer_payout_wrap">
                <label class="form-label">Referrer Gets (₹)</label>
                <input type="number" name="refer_payout" id="refer_payout" class="form-input" min="0" step="1" placeholder="0" oninput="updateBar()">
            </div>
        </div>
        <div class="payout-bar-track"><div class="payout-bar-fill" id="payBar" style="width:0%"></div></div>
        <div class="payout-bar-label" id="barTxt">₹0 / ₹0</div>
    </div>
</div>
<div class="step-divider" id="div3" style="display:none"></div>


<!-- STEP 4: Steps for User -->
<div class="step-section" id="step4">
    <h2 class="section-heading">Steps for User</h2>
    <p class="section-desc">What users need to do to complete & earn rewards</p>
    <div id="stepsWrap">
        <div class="step-row">
            <div class="step-num">1</div>
            <input type="text" name="steps[]" class="step-input" placeholder="e.g. Download the app">
            <button type="button" class="step-remove" onclick="removeStep(this)"><i class="fas fa-times" style="font-size:10px"></i></button>
        </div>
        <div class="step-row">
            <div class="step-num">2</div>
            <input type="text" name="steps[]" class="step-input" placeholder="e.g. Register & verify mobile">
            <button type="button" class="step-remove" onclick="removeStep(this)"><i class="fas fa-times" style="font-size:10px"></i></button>
        </div>
        <div class="step-row">
            <div class="step-num">3</div>
            <input type="text" name="steps[]" class="step-input" placeholder="e.g. Complete first transaction">
            <button type="button" class="step-remove" onclick="removeStep(this)"><i class="fas fa-times" style="font-size:10px"></i></button>
        </div>
    </div>
    <button type="button" class="add-step-btn" onclick="addStep()"><i class="fas fa-plus"></i> Add Step</button>
</div>
<div class="step-divider" id="div4" style="display:none"></div>

<!-- STEP 5: Settings & Launch -->
<div class="step-section" id="step5">
    <h2 class="section-heading">Settings & Launch</h2>
    <p class="section-desc">Configure options and launch your campaign</p>

    <div style="margin-bottom:24px">
        <div class="toggle-item">
            <div class="toggle-info">
                <div class="ti-label"><i class="fas fa-mobile-alt" style="color:var(--highlight);margin-right:8px"></i>Mobile Number</div>
                <div class="ti-desc">Collect mobile number on camp page</div>
            </div>
            <label class="toggle-sw">
                <input type="checkbox" name="mobile_enabled" id="mobile_enabled" checked>
                <div class="toggle-track"></div>
            </label>
        </div>
        <div class="toggle-item">
            <div class="toggle-info">
                <div class="ti-label"><i class="fas fa-share-alt" style="color:var(--gold);margin-right:8px"></i>Refer & Earn</div>
                <div class="ti-desc">Enable referral with UPI payout</div>
            </div>
            <label class="toggle-sw">
                <input type="checkbox" name="refer_enabled" id="refer_enabled" checked onchange="toggleReferPay()">
                <div class="toggle-track"></div>
            </label>
        </div>
    </div>

    <div style="margin-bottom:20px">
        <label class="form-label">Camp Link Preview</label>
        <div class="link-preview" id="linkPrev">Select an offer and enter a name to preview...</div>
        <div id="referPrevWrap" style="display:none;margin-top:8px">
            <label class="form-label" style="color:var(--gold)"><i class="fas fa-share-alt" style="margin-right:4px"></i>Refer Link</label>
            <div class="link-preview" id="referPrev" style="background:var(--gold-light)"></div>
        </div>
    </div>

    <button type="submit" class="btn-primary">
        <i class="fas fa-rocket"></i> Launch Campaign
    </button>
</div>

</form>
</div><!-- /panel-create -->


<!-- MY CAMPS SECTION -->
<div style="margin-top:56px">
<div class="camps-header">
    <h2>Your Campaigns</h2>
    <?php if(!empty($my_camps)): ?>
    <span class="camps-count"><?= count($my_camps) ?> total</span>
    <?php endif; ?>
</div>

<div class="tab-panel" id="panel-camps">
<?php if(empty($my_camps)): ?>
<div class="empty-state">
    <div class="empty-icon">🏕️</div>
    <div class="empty-title">No campaigns yet</div>
    <div class="empty-desc">Create your first campaign above to get started</div>
</div>
<?php else: ?>
<?php foreach($my_camps as $camp):
    $link      = $base_url . '/offer/' . $camp['slug'];
    $refer_link = $base_url . '/reffer/' . $camp['slug'];
    $steps_arr = json_decode($camp['steps'] ?? '[]', true) ?: [];
    $sj        = htmlspecialchars(json_encode($steps_arr), ENT_QUOTES);
    $isActive  = $camp['status']==='active' && $camp['offer_status']==='active';
?>
<div class="camp-row">
    <img class="camp-row-img" src="<?= htmlspecialchars($camp['offer_image']) ?>" onerror="this.src='https://via.placeholder.com/48/f9fafb/9ca3af?text=?'">
    <div class="camp-row-info">
        <div class="camp-row-name"><?= htmlspecialchars($camp['camp_name']) ?></div>
        <div class="camp-row-offer"><?= htmlspecialchars($camp['offer_name']) ?> · <?= htmlspecialchars($camp['event_name'] ?? '—') ?></div>
        <div class="camp-row-badges">
            <span class="badge <?= $isActive ? 'badge-green' : 'badge-red' ?>"><?= $isActive ? '● Active' : '⏸ Paused' ?></span>
            <span class="badge badge-gray">U ₹<?= number_format($camp['user_payout'],0) ?></span>
            <?php if($camp['refer_enabled']): ?><span class="badge badge-gold">R ₹<?= number_format($camp['refer_payout'],0) ?></span><?php endif; ?>
            <?php if($camp['mobile_enabled']): ?><span class="badge badge-blue">📱 Mobile</span><?php endif; ?>
            <span class="badge badge-gray">👁 <?= $camp['total_clicks'] ?></span>
        </div>
    </div>


    <div class="camp-row-right">
        <div class="camp-link-box">
            <span><?= htmlspecialchars($link) ?></span>
            <button type="button" onclick="copyTxt('<?= htmlspecialchars($link) ?>')" title="Copy"><i class="fas fa-copy"></i></button>
        </div>
        <?php if($camp['refer_enabled']): ?>
        <div class="camp-link-box" style="background:var(--gold-light)">
            <span style="color:var(--text)"><?= htmlspecialchars($refer_link) ?></span>
            <button type="button" onclick="copyTxt('<?= htmlspecialchars($refer_link) ?>')" title="Copy" style="color:var(--gold)"><i class="fas fa-copy"></i></button>
        </div>
        <?php endif; ?>
        <div class="camp-actions">
            <a class="act-btn act-report" href="campreport?camp_id=<?= $camp['id'] ?>"><i class="fas fa-chart-bar"></i> Report</a>
            <button class="act-btn act-edit" onclick="openEdit(<?= $camp['id'] ?>,'<?= htmlspecialchars($camp['camp_name'],ENT_QUOTES) ?>',<?= $camp['user_payout'] ?>,<?= $camp['refer_payout'] ?>,<?= $camp['refer_enabled'] ?>,<?= $camp['mobile_enabled'] ?>,'<?= $camp['max_payout'] ?>',<?= $sj ?>)"><i class="fas fa-edit"></i> Edit</button>
            <a class="act-btn act-pause" href="?toggle=<?= $camp['status'] ?>&camp_id=<?= $camp['id'] ?>"><i class="fas fa-<?= $camp['status']==='active'?'pause':'play' ?>"></i> <?= $camp['status']==='active'?'Pause':'Resume' ?></a>
            <button class="act-btn act-del" onclick="delCamp(<?= $camp['id'] ?>)"><i class="fas fa-trash"></i></button>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div><!-- /panel-camps -->
</div>

</div><!-- /content-wrap -->
</div><!-- /page-content -->


<!-- EDIT MODAL - Full screen overlay centered card -->
<div class="modal-overlay" id="editModal">
<div class="modal-card">
    <div class="modal-top">
        <h3><i class="fas fa-edit" style="color:var(--gold);margin-right:8px"></i>Edit Campaign</h3>
        <button class="modal-close-btn" onclick="closeEdit()"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" id="editForm">
        <input type="hidden" name="edit_camp" value="1">
        <input type="hidden" name="camp_id" id="edit_camp_id">

        <div style="margin-bottom:16px">
            <label class="form-label">Camp Name</label>
            <input type="text" name="camp_name" id="edit_camp_name" class="form-input" required>
        </div>

        <div class="payout-header" style="margin-bottom:16px">
            <div>
                <div class="ph-label">Event Payout</div>
                <div class="ph-value gold" id="e_maxLbl">₹0</div>
            </div>
            <div style="text-align:right">
                <div class="ph-label">Remaining</div>
                <div class="ph-value green" id="e_remLbl">₹0</div>
            </div>
        </div>

        <div class="payout-inputs" style="margin-bottom:20px">
            <div>
                <label class="form-label">User Gets (₹)</label>
                <input type="number" name="user_payout" id="e_user_pay" class="form-input" min="0" step="1" oninput="updateEditBar()">
            </div>
            <div id="e_refer_wrap">
                <label class="form-label">Referrer Gets (₹)</label>
                <input type="number" name="refer_payout" id="e_refer_pay" class="form-input" min="0" step="1" oninput="updateEditBar()">
            </div>
        </div>

        <div style="margin-bottom:20px">
            <label class="form-label">Steps</label>
            <div id="editStepsWrap"></div>
            <button type="button" class="add-step-btn" onclick="addEditStep('')"><i class="fas fa-plus"></i> Add Step</button>
        </div>

        <div style="margin-bottom:24px">
            <div class="toggle-item">
                <div class="toggle-info"><div class="ti-label"><i class="fas fa-mobile-alt" style="color:var(--highlight);margin-right:8px"></i>Mobile Input</div></div>
                <label class="toggle-sw"><input type="checkbox" name="mobile_enabled" id="e_mobile"><div class="toggle-track"></div></label>
            </div>
            <div class="toggle-item">
                <div class="toggle-info"><div class="ti-label"><i class="fas fa-share-alt" style="color:var(--gold);margin-right:8px"></i>Refer & Earn</div></div>
                <label class="toggle-sw"><input type="checkbox" name="refer_enabled" id="e_refer" onchange="toggleEditRefer()"><div class="toggle-track"></div></label>
            </div>
        </div>

        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save Changes</button>
    </form>
</div>
</div>


<script>
const BASE = '<?= $base_url ?>';
let curMax = 0, stepCnt = 3, editMax = 0, editStepCnt = 0;
let currentStep = 1;

const stepNames = ['Select Offer','Camp Name','Event & Payout','Steps for User','Settings & Launch'];

// ── PROGRESS BAR ──
function updateProgress(step) {
    currentStep = step;
    const pct = (step / 5) * 100;
    document.getElementById('progressFill').style.width = pct + '%';
    document.getElementById('progressLabel').textContent = 'Step ' + step + ' of 5';
    document.getElementById('progressStepName').textContent = stepNames[step - 1] || '';
}

// ── SHOW/HIDE STEPS with animation ──
function showStep(n) {
    const el = document.getElementById('step' + n);
    const div = document.getElementById('div' + (n - 1));
    if (el) { el.classList.add('visible'); }
    if (div) { div.style.display = 'block'; }
}

// ── TABS (kept for JS compat) ──
function switchTab(t) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
    document.getElementById('panel-' + t).classList.add('active');
    document.getElementById('tab-' + t).classList.add('active');
}


// ── SELECT OFFER (Step 1 → reveals Step 2, 3, 4, 5) ──
function selectOffer(id, name, evArr) {
    document.getElementById('f_offer_id').value = id;
    document.querySelectorAll('.offer-tile').forEach(c => c.classList.remove('selected'));
    document.querySelector(`.offer-tile[data-id="${id}"]`).classList.add('selected');

    // Show subsequent steps with slight delay for animation
    setTimeout(() => { showStep(2); updateProgress(2); }, 100);
    setTimeout(() => { showStep(3); }, 200);
    setTimeout(() => { showStep(4); }, 300);
    setTimeout(() => { showStep(5); }, 400);

    // Build event pills
    const pills = document.getElementById('eventPills');
    pills.innerHTML = '';
    evArr.forEach(ev => {
        const p = document.createElement('div');
        p.className = 'event-pill';
        p.innerHTML = `<i class="fas fa-bolt" style="color:var(--gold);font-size:11px"></i>${ev.name}<span class="ev-amount">₹${parseFloat(ev.payout).toFixed(0)}</span>`;
        p.onclick = () => selectEvent(ev.id, ev.payout, p);
        pills.appendChild(p);
    });
    document.getElementById('payoutConfig').style.display = 'none';
    updateLink();
}

// ── SELECT EVENT ──
function selectEvent(id, payout, el) {
    document.getElementById('f_event_id').value = id;
    document.querySelectorAll('.event-pill').forEach(p => p.classList.remove('selected'));
    el.classList.add('selected');
    curMax = parseFloat(payout);
    document.getElementById('maxLbl').textContent = '₹' + curMax.toFixed(0);
    document.getElementById('payoutConfig').style.display = 'block';
    document.getElementById('user_payout').value = '';
    document.getElementById('refer_payout').value = '';
    updateBar();
    updateProgress(3);
}


// ── PAYOUT BAR ──
function updateBar() {
    const u = parseFloat(document.getElementById('user_payout').value)||0;
    const r = parseFloat(document.getElementById('refer_payout').value)||0;
    const tot = u+r, rem = curMax-tot;
    document.getElementById('remLbl').textContent = '₹'+Math.max(0,rem).toFixed(0);
    document.getElementById('remLbl').style.color = rem < 0 ? 'var(--red)' : 'var(--green)';
    const pct = curMax>0 ? Math.min(100,(tot/curMax)*100) : 0;
    const bar = document.getElementById('payBar');
    bar.style.width = pct+'%';
    bar.style.background = pct>100 ? 'var(--red)' : pct>80 ? 'var(--gold)' : 'var(--highlight)';
    document.getElementById('barTxt').textContent = `₹${tot.toFixed(0)} / ₹${curMax.toFixed(0)}`;
}

function toggleReferPay() {
    const on = document.getElementById('refer_enabled').checked;
    document.getElementById('refer_payout_wrap').style.display = on ? 'block' : 'none';
    if (!on) { document.getElementById('refer_payout').value=0; updateBar(); }
    updateLink();
}

// ── SIDEBAR ──
if (!document.querySelector('.sidebar-overlay')) {
    document.body.insertAdjacentHTML('beforeend', '<div class="sidebar-overlay" onclick="toggleSidebar()"></div>');
}

function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    if (sidebar) {
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : 'auto';
    }
}

// ── STEPS ──
function addStep() {
    stepCnt++;
    const d = document.createElement('div');
    d.className = 'step-row';
    d.innerHTML = `<div class="step-num">${stepCnt}</div><input type="text" name="steps[]" class="step-input" placeholder="Step ${stepCnt}"><button type="button" class="step-remove" onclick="removeStep(this)"><i class="fas fa-times" style="font-size:10px"></i></button>`;
    document.getElementById('stepsWrap').appendChild(d);
    updateProgress(4);
}
function removeStep(btn) {
    btn.closest('.step-row').remove();
    renumber('stepsWrap','step-num');
    stepCnt = document.querySelectorAll('#stepsWrap .step-row').length;
}
function renumber(wrapId, numClass) {
    document.querySelectorAll('#'+wrapId+' .step-row').forEach((r,i)=>{
        r.querySelector('.'+numClass).textContent = i+1;
    });
}


// ── LINK PREVIEW ──
function updateLink() {
    const name = document.getElementById('camp_name').value.trim();
    if (!name) { document.getElementById('linkPrev').textContent = 'Select an offer and enter a name to preview...'; return; }
    const slug = name.toLowerCase().replace(/[^a-z0-9]+/g,'-')+'-xxxxxx';
    document.getElementById('linkPrev').innerHTML = `${BASE}/offer/<strong>${slug}</strong>`;
    const referOn = document.getElementById('refer_enabled')?.checked;
    const refWrap = document.getElementById('referPrevWrap');
    if (referOn) {
        document.getElementById('referPrev').innerHTML = `${BASE}/reffer/<strong>${slug}</strong>`;
        refWrap.style.display = 'block';
    } else {
        refWrap.style.display = 'none';
    }
}
document.getElementById('camp_name').addEventListener('input', function() {
    updateLink();
    if (this.value.trim()) updateProgress(2);
});

// ── COPY ──
function copyTxt(txt) {
    navigator.clipboard.writeText(txt).then(() => {
        const toast = document.createElement('div');
        toast.innerHTML = `<i class="fas fa-check-circle"></i> Copied!`;
        toast.style = `
            position:fixed;top:20px;right:20px;
            background:var(--text);color:#fff;padding:10px 20px;
            border-radius:8px;font-size:13px;font-weight:500;
            z-index:99999;box-shadow:0 8px 24px rgba(0,0,0,.2);
            animation:toastSlide .3s ease;
            display:flex;align-items:center;gap:8px;
            font-family:var(--font);
        `;
        document.body.appendChild(toast);
        setTimeout(() => { toast.style.opacity='0'; toast.style.transition='.3s'; setTimeout(()=>toast.remove(),300); }, 1500);
    });
}

// ── DELETE ──
function delCamp(id) {
    Swal.fire({title:'Delete Campaign?',text:'This cannot be undone.',icon:'warning',
        showCancelButton:true,confirmButtonColor:'#ef4444',cancelButtonColor:'#6b7280',
        confirmButtonText:'Delete',
        customClass:{popup:'rounded-2xl'}
    }).then(r=>{ if(r.isConfirmed) window.location='?delete_camp='+id; });
}


// ── FORM SUBMIT ──
document.getElementById('campForm').addEventListener('submit', function(e) {
    if (!document.getElementById('f_offer_id').value) {
        e.preventDefault();
        Swal.fire({icon:'warning',title:'Select an Offer',customClass:{popup:'rounded-2xl'}}); return;
    }
    const u = parseFloat(document.getElementById('user_payout').value)||0;
    const r = parseFloat(document.getElementById('refer_payout').value)||0;
    if (curMax>0 && (u+r)>curMax) {
        e.preventDefault();
        Swal.fire({icon:'error',title:'Payout Exceeded!',text:`₹${(u+r).toFixed(0)} > ₹${curMax.toFixed(0)}`,customClass:{popup:'rounded-2xl'}}); return;
    }
    document.querySelectorAll('#stepsWrap input[name="steps[]"]').forEach(i => i.removeAttribute('name'));
    document.querySelectorAll('#stepsWrap .step-input').forEach(inp => {
        const v = inp.value.trim();
        if (v) {
            const h = document.createElement('input');
            h.type='hidden'; h.name='steps[]'; h.value=v;
            this.appendChild(h);
        }
    });
});

// ══ EDIT MODAL ══
function openEdit(id,name,uP,rP,refOn,mobOn,maxP,stepsArr) {
    editMax = parseFloat(maxP)||0;
    document.getElementById('edit_camp_id').value   = id;
    document.getElementById('edit_camp_name').value = name;
    document.getElementById('e_user_pay').value     = uP;
    document.getElementById('e_refer_pay').value    = rP;
    document.getElementById('e_refer').checked      = refOn==1;
    document.getElementById('e_mobile').checked     = mobOn==1;
    document.getElementById('e_maxLbl').textContent = '₹'+editMax.toFixed(0);
    document.getElementById('e_refer_wrap').style.display = refOn==1 ? 'block':'none';

    const wrap = document.getElementById('editStepsWrap');
    wrap.innerHTML = ''; editStepCnt = 0;
    (stepsArr||[]).forEach(s => addEditStep(s));
    if (editStepCnt===0) addEditStep('');
    updateEditBar();
    document.getElementById('editModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeEdit() {
    document.getElementById('editModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}


function addEditStep(val) {
    editStepCnt++;
    const d = document.createElement('div');
    d.className = 'step-row';
    d.innerHTML = `<div class="step-num">${editStepCnt}</div><input type="text" class="step-input" value="${(val||'').replace(/"/g,'&quot;')}" placeholder="Step ${editStepCnt}"><button type="button" class="step-remove" onclick="removeEditStep(this)"><i class="fas fa-times" style="font-size:10px"></i></button>`;
    document.getElementById('editStepsWrap').appendChild(d);
}
function removeEditStep(btn) {
    btn.closest('.step-row').remove();
    renumber('editStepsWrap','step-num');
    editStepCnt = document.querySelectorAll('#editStepsWrap .step-row').length;
}
function updateEditBar() {
    const u = parseFloat(document.getElementById('e_user_pay').value)||0;
    const r = parseFloat(document.getElementById('e_refer_pay').value)||0;
    const rem = editMax-(u+r);
    document.getElementById('e_remLbl').textContent = '₹'+Math.max(0,rem).toFixed(0);
    document.getElementById('e_remLbl').style.color = rem<0 ? 'var(--red)':'var(--green)';
}
function toggleEditRefer() {
    const on = document.getElementById('e_refer').checked;
    document.getElementById('e_refer_wrap').style.display = on?'block':'none';
    if (!on) { document.getElementById('e_refer_pay').value=0; updateEditBar(); }
}

document.getElementById('editForm').addEventListener('submit', function(e) {
    const u = parseFloat(document.getElementById('e_user_pay').value)||0;
    const r = parseFloat(document.getElementById('e_refer_pay').value)||0;
    if (editMax>0 && (u+r)>editMax) {
        e.preventDefault();
        Swal.fire({icon:'error',title:'Payout Exceeded!',customClass:{popup:'rounded-2xl'}}); return;
    }
    document.querySelectorAll('#editStepsWrap input[name="steps[]"]').forEach(i=>i.removeAttribute('name'));
    document.querySelectorAll('#editStepsWrap .step-input').forEach(inp=>{
        const v=inp.value.trim();
        if(v){const h=document.createElement('input');h.type='hidden';h.name='steps[]';h.value=v;this.appendChild(h);}
    });
});

document.getElementById('editModal').addEventListener('click',function(e){if(e.target===this)closeEdit();});
</script>

<?php include 'footer.php'; ?>
</body>
</html>
