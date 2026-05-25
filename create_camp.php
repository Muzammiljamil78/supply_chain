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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{
  --bg:#fafbfc;
  --surface:#ffffff;
  --surface2:#f6f8fa;
  --border:#eaeef2;
  --border2:#d0d7de;
  --text:#1f2328;
  --muted:#656d76;
  --accent:#0969da;
  --accent-light:#ddf4ff;
  --green:#1a7f37;
  --green-light:#dafbe1;
  --gold:#9a6700;
  --gold-light:#fff8c5;
  --red:#cf222e;
  --red-light:#ffebe9;
  --radius:10px;
  --shadow-sm:0 1px 2px rgba(31,35,40,.04);
  --shadow:0 2px 8px rgba(31,35,40,.06);
  --shadow-lg:0 8px 24px rgba(31,35,40,.1);
  --transition:0.2s cubic-bezier(0.4,0,0.2,1);
}
html{scroll-behavior:smooth}
body{font-family:'Inter',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding-bottom:80px;line-height:1.5}
@media(min-width:1024px){.page-topbar,.page-tabs{margin-left:288px!important}.page-main{margin-left:288px}}

::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:99px}
::-webkit-scrollbar-thumb:hover{background:var(--muted)}


/* ── TOPBAR ── */
.topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:16px 20px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:100;transition:box-shadow var(--transition)}
.topbar:hover{box-shadow:var(--shadow-sm)}
.topbar-icon{width:36px;height:36px;border-radius:var(--radius);background:var(--accent-light);display:flex;align-items:center;justify-content:center;font-size:15px;color:var(--accent);flex-shrink:0}
.topbar-title{font-size:15px;font-weight:600;color:var(--text)}
.topbar-sub{font-size:12px;color:var(--muted);font-weight:400}
.topbar-count{margin-left:auto;background:var(--surface2);border:1px solid var(--border);color:var(--muted);font-size:11px;font-weight:500;padding:4px 12px;border-radius:99px}

/* ── TABS ── */
.tabs{display:flex;gap:0;background:var(--surface);border-bottom:1px solid var(--border);position:sticky;top:66px;z-index:99}
.tab{flex:1;padding:14px 12px;font-size:13px;font-weight:500;text-align:center;color:var(--muted);border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;transition:all var(--transition)}
.tab:hover{color:var(--text);background:var(--surface2)}
.tab.active{color:var(--accent);border-bottom-color:var(--accent);font-weight:600}
.tab i{display:block;font-size:15px;margin-bottom:4px}

/* ── WRAP ── */
.wrap{max-width:640px;margin:0 auto;padding:24px 16px}

/* ── SECTION ── */
.section{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;margin-bottom:16px;box-shadow:var(--shadow-sm);transition:all var(--transition);animation:fadeIn 0.3s ease}
.section:hover{box-shadow:var(--shadow)}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.sec-head{display:flex;align-items:center;gap:12px;margin-bottom:16px}
.sec-num{width:26px;height:26px;border-radius:50%;background:var(--surface2);border:1px solid var(--border);color:var(--muted);font-size:12px;font-weight:600;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all var(--transition)}
.sec-num.done{background:var(--green-light);border-color:var(--green);color:var(--green)}
.sec-title{font-size:13px;font-weight:600;color:var(--text);letter-spacing:-0.01em}
.sec-sub{font-size:11px;color:var(--accent);margin-left:auto;font-weight:500}


/* ── OFFER CARDS ── */
.offers-scroll{display:flex;flex-direction:column;gap:8px;max-height:280px;overflow-y:auto;padding-right:4px}
.offer-card{display:flex;align-items:center;gap:14px;padding:12px 14px;border:1px solid var(--border);border-radius:var(--radius);cursor:pointer;transition:all var(--transition);background:var(--surface)}
.offer-card:hover{border-color:var(--accent);background:var(--accent-light);transform:translateY(-1px);box-shadow:var(--shadow)}
.offer-card.selected{border-color:var(--accent);background:var(--accent-light);box-shadow:0 0 0 3px rgba(9,105,218,.1)}
.offer-card img{width:40px;height:40px;border-radius:8px;object-fit:cover;flex-shrink:0}
.offer-card .offer-name{font-size:13px;font-weight:600;color:var(--text);line-height:1.3}
.offer-card .offer-cat{font-size:11px;color:var(--muted);margin-top:2px}
.offer-payout{margin-left:auto;background:var(--green-light);color:var(--green);font-size:12px;font-weight:600;padding:4px 10px;border-radius:99px;flex-shrink:0}

/* ── EVENT PILLS ── */
.event-pills{display:flex;flex-wrap:wrap;gap:8px}
.epill{border:1px solid var(--border);border-radius:99px;padding:8px 16px;font-size:12px;font-weight:500;color:var(--muted);cursor:pointer;transition:all var(--transition);display:flex;align-items:center;gap:8px;background:var(--surface)}
.epill:hover{border-color:var(--accent);color:var(--text);background:var(--accent-light)}
.epill.selected{border-color:var(--accent);background:var(--accent-light);color:var(--accent)}
.epill .epay{color:var(--green);font-weight:600}

/* ── PAYOUT ── */
.payout-info{background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between}
.payout-info .pi-label{font-size:11px;color:var(--muted);margin-bottom:2px;font-weight:400}
.payout-info .pi-val{font-size:16px;font-weight:700}
.pi-val.green{color:var(--green)}
.pi-val.gold{color:var(--gold)}
.payout-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.bar-wrap{background:var(--border);border-radius:99px;height:4px;overflow:hidden}
.bar-fill{height:100%;border-radius:99px;background:var(--accent);transition:width 0.3s ease,background 0.3s ease}
.bar-label{font-size:11px;color:var(--muted);text-align:right;margin-top:6px}


/* Sidebar */
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    width: 288px;
    z-index: 9999 !important;
    background: var(--surface);
    border-right:1px solid var(--border);
    transition: transform 0.3s ease;
}
@media (max-width: 1024px) {
    .page-topbar, .page-tabs {
        margin-left: 0 !important;
        width: 100% !important;
        z-index: 10 !important;
    }
    .sidebar {
        transform: translateX(-100%);
        box-shadow: none;
    }
    .sidebar.active {
        transform: translateX(0);
        box-shadow: var(--shadow-lg);
    }
    .sidebar-overlay {
        position: fixed;
        inset: 0;
        background: rgba(31,35,40,0.4);
        backdrop-filter: blur(2px);
        z-index: 9998;
        display: none;
    }
    .sidebar-overlay.active {
        display: block;
    }
}

/* ── INPUTS ── */
.inp-group{margin-bottom:14px}
.inp-group label{display:block;font-size:12px;font-weight:500;color:var(--muted);margin-bottom:6px}
.inp{width:100%;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-size:14px;font-family:'Inter',sans-serif;color:var(--text);outline:none;transition:all var(--transition)}
.inp:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(9,105,218,.1)}
.inp::placeholder{color:var(--border2)}
input[type=number].inp::-webkit-inner-spin-button{opacity:.5}


/* ── STEPS ── */
.step-row{display:flex;align-items:center;gap:10px;margin-bottom:8px;animation:fadeIn 0.2s ease}
.step-badge{width:24px;height:24px;border-radius:50%;background:var(--surface2);border:1px solid var(--border);color:var(--muted);font-size:11px;font-weight:600;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.step-inp{flex:1;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:9px 12px;font-size:13px;font-family:'Inter',sans-serif;color:var(--text);outline:none;transition:all var(--transition)}
.step-inp:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(9,105,218,.1)}
.step-inp::placeholder{color:var(--border2)}
.step-del{width:28px;height:28px;border-radius:6px;background:transparent;border:1px solid transparent;color:var(--border2);display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:all var(--transition)}
.step-del:hover{background:var(--red-light);border-color:rgba(207,34,46,.2);color:var(--red)}
.add-step-btn{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:500;color:var(--muted);background:none;border:1px dashed var(--border2);border-radius:8px;padding:10px 14px;cursor:pointer;width:100%;justify-content:center;transition:all var(--transition);margin-top:8px}
.add-step-btn:hover{border-color:var(--accent);color:var(--accent);background:var(--accent-light)}

/* ── TOGGLE ── */
.toggle-row{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:10px;transition:all var(--transition)}
.toggle-row:hover{border-color:var(--border2)}
.toggle-info .tl{font-size:13px;font-weight:500;color:var(--text)}
.toggle-info .ts{font-size:11px;color:var(--muted);margin-top:2px}
.toggle-sw{position:relative;width:42px;height:22px;flex-shrink:0}
.toggle-sw input{opacity:0;width:0;height:0;position:absolute}
.toggle-track{position:absolute;inset:0;background:var(--border2);border-radius:99px;cursor:pointer;transition:0.3s}
.toggle-track::before{content:'';position:absolute;width:16px;height:16px;background:#fff;border-radius:50%;top:3px;left:3px;transition:0.3s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
input:checked~.toggle-track{background:var(--accent)}
input:checked~.toggle-track::before{transform:translateX(20px)}

/* ── LINK BOX ── */
.link-box{background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:12px 14px;font-family:'SF Mono','Fira Code',monospace;font-size:12px;color:var(--muted);word-break:break-all;margin-bottom:14px;line-height:1.6}


/* ── SUBMIT BTN ── */
.submit-btn{width:100%;background:var(--accent);color:#fff;border:none;border-radius:8px;padding:14px;font-size:14px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all var(--transition);letter-spacing:-0.01em}
.submit-btn:hover{background:#0860ca;box-shadow:var(--shadow)}
.submit-btn:active{transform:scale(.98)}

/* ── CAMP CARDS ── */
.camp-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px;margin-bottom:12px;box-shadow:var(--shadow-sm);transition:all var(--transition);animation:fadeIn 0.3s ease}
.camp-card:hover{box-shadow:var(--shadow);border-color:var(--border2)}
.camp-top{display:flex;align-items:center;gap:12px;margin-bottom:12px}
.camp-img{width:44px;height:44px;border-radius:8px;object-fit:cover;flex-shrink:0}
.camp-name{font-size:14px;font-weight:600;color:var(--text);line-height:1.3;margin-bottom:2px}
.camp-offer{font-size:11px;color:var(--muted)}
.camp-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.chip{font-size:11px;font-weight:500;padding:3px 10px;border-radius:99px}
.chip-green{background:var(--green-light);color:var(--green)}
.chip-gold{background:var(--gold-light);color:var(--gold)}
.chip-blue{background:var(--accent-light);color:var(--accent)}
.chip-red{background:var(--red-light);color:var(--red)}
.chip-purple{background:var(--surface2);color:var(--muted);border:1px solid var(--border)}
.camp-link-row{background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:10px 12px;display:flex;align-items:center;gap:8px;margin-bottom:10px}
.camp-link-row span{font-size:11px;color:var(--muted);font-family:'SF Mono','Fira Code',monospace;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.camp-link-row button{flex-shrink:0;background:none;border:none;color:var(--accent);cursor:pointer;font-size:13px;padding:2px 6px;transition:color var(--transition)}
.camp-link-row button:hover{color:var(--text)}
.steps-preview{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.step-tag{font-size:11px;color:var(--muted);background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:4px 8px}
.camp-actions{display:flex;gap:8px;flex-wrap:wrap}
.act-btn{font-size:12px;font-weight:500;padding:8px 12px;border-radius:8px;border:1px solid;cursor:pointer;display:flex;align-items:center;gap:5px;transition:all var(--transition);font-family:'Inter',sans-serif;text-decoration:none}
.act-copy{background:var(--accent-light);color:var(--accent);border-color:transparent}
.act-copy:hover{background:#c8e1ff}
.act-edit{background:var(--gold-light);color:var(--gold);border-color:transparent}
.act-edit:hover{background:#fef3b5}
.act-pause{background:var(--surface2);color:var(--muted);border-color:var(--border)}
.act-pause:hover{background:var(--border);color:var(--text)}
.act-del{background:var(--red-light);color:var(--red);border-color:transparent}
.act-del:hover{background:#ffd7d5}


/* ── MODAL ── */
.modal-bg{position:fixed;inset:0;background:rgba(31,35,40,.5);backdrop-filter:blur(4px);z-index:999;display:none;align-items:flex-end;justify-content:center;padding:0}
.modal-bg.show{display:flex}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:16px 16px 0 0;width:100%;max-width:600px;max-height:92vh;overflow-y:auto;padding:24px 20px 36px;animation:slideUp .3s ease}
@keyframes slideUp{from{transform:translateY(40px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-handle{width:32px;height:4px;background:var(--border2);border-radius:99px;margin:0 auto 20px}
.modal-title{font-size:16px;font-weight:600;color:var(--text);margin-bottom:20px;display:flex;align-items:center;justify-content:space-between}
.modal-close{background:var(--surface2);border:1px solid var(--border);color:var(--muted);width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:13px;transition:all var(--transition)}
.modal-close:hover{background:var(--border);color:var(--text)}

/* ── ALERTS ── */
.alert{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:var(--radius);font-size:13px;font-weight:500;margin-bottom:16px;animation:fadeIn 0.3s ease}
.alert-success{background:var(--green-light);border:1px solid rgba(26,127,55,.2);color:var(--green)}
.alert-error{background:var(--red-light);border:1px solid rgba(207,34,46,.2);color:var(--red)}

/* ── EMPTY STATE ── */
.empty{text-align:center;padding:48px 24px}
.empty-icon{font-size:40px;margin-bottom:16px;opacity:.4}
.empty-title{font-size:14px;font-weight:600;color:var(--muted);margin-bottom:6px}
.empty-sub{font-size:12px;color:var(--border2)}

/* ── HIDDEN STEPS WRAPPER ── */
#stepsWrap{display:block}

@keyframes slideDown {
    from { transform: translate(-50%, -20px); opacity: 0; }
    to { transform: translate(-50%, 0); opacity: 1; }
}

/* tab panels */
.tab-panel{display:none}
.tab-panel.active{display:block}
</style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<br>
<br>
<br>


<!-- TOPBAR -->
<div class="topbar page-topbar" id="mainTopbar" style="z-index: 100; display: flex; align-items: center; justify-content: center; text-align: center; gap: 12px; position: relative;">
    
    <button class="menu-btn" onclick="toggleSidebar()" style="display:none; position: absolute; left: 16px; background:none; border:none; font-size: 18px; color:var(--text); cursor:pointer;">
        <i class="fas fa-bars"></i>
    </button>

    <div style="display: flex; align-items: center; gap: 10px;">
        <div class="topbar-icon"><i class="fas fa-campground"></i></div>
        <div style="display: flex; flex-direction: column; align-items: flex-start;">
            <div class="topbar-title" style="line-height: 1.2;">Camp Builder</div>
            <div class="topbar-sub" style="line-height: 1.2;">Create & manage campaigns</div>
        </div>
    </div>

    <?php if(!empty($my_camps)): ?>
    <div class="topbar-count" style="position: absolute; right: 16px;"><?= count($my_camps) ?> camps</div>
    <?php endif; ?>
</div>
<!-- TABS -->
<div class="tabs page-tabs" id="mainTabs">
    <button class="tab active" onclick="switchTab('create')" id="tab-create">
        <i class="fas fa-plus-circle"></i> Create
    </button>
    <button class="tab" onclick="switchTab('camps')" id="tab-camps">
        <i class="fas fa-list"></i> My Camps
        <?php if(!empty($my_camps)): ?>
        <span style="font-size:9px;background:var(--accent);color:#fff;padding:2px 6px;border-radius:99px;margin-left:4px"><?= count($my_camps) ?></span>
        <?php endif; ?>
    </button>
</div>

<main class="page-main">
<div class="wrap">

<!-- ALERTS -->
<?php if(isset($_SESSION['camp_success'])): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i><?= htmlspecialchars($_SESSION['camp_success']) ?><button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;color:inherit;cursor:pointer"><i class="fas fa-times"></i></button></div>
<?php unset($_SESSION['camp_success']); endif; ?>
<?php if(isset($_SESSION['camp_error'])): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($_SESSION['camp_error']) ?><button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;color:inherit;cursor:pointer"><i class="fas fa-times"></i></button></div>
<?php unset($_SESSION['camp_error']); endif; ?>


<!-- ══ CREATE TAB ══ -->
<div class="tab-panel active" id="panel-create">
<form method="POST" id="campForm">
<input type="hidden" name="create_camp" value="1">
<input type="hidden" name="offer_id" id="f_offer_id">
<input type="hidden" name="event_id" id="f_event_id">

<!-- 1: Camp Name -->
<div class="section" id="s1">
    <div class="sec-head">
        <div class="sec-num" id="n1">1</div>
        <div class="sec-title">Camp Name</div>
    </div>
    <div class="inp-group" style="margin-bottom:0">
        <input type="text" name="camp_name" id="camp_name" class="inp" placeholder="Enter a short name for your campaign" required autocomplete="off">
    </div>
</div>

<!-- 2: Select Offer -->
<div class="section" id="s2">
    <div class="sec-head">
        <div class="sec-num" id="n2">2</div>
        <div class="sec-title">Select Offer</div>
        <div class="sec-sub" id="sel-offer-name"></div>
    </div>
    <?php if(empty($offers_raw)): ?>
    <div class="empty"><div class="empty-icon">📭</div><div class="empty-title">No active offers</div></div>
    <?php else: ?>
    <div class="offers-scroll">
        <?php foreach($offers_raw as $o):
            $eids  = $o['event_ids']    ? explode('||',$o['event_ids'])    : [];
            $enames= $o['event_names']  ? explode('||',$o['event_names'])  : [];
            $epays = $o['event_payouts']? explode('||',$o['event_payouts']): [];
            $evArr = [];
            foreach($eids as $i => $eid) $evArr[] = ['id'=>$eid,'name'=>$enames[$i]??'','payout'=>$epays[$i]??0];
            $maxP  = !empty($epays) ? max($epays) : 0;
        ?>
        <div class="offer-card" onclick="selectOffer(<?= $o['id'] ?>,'<?= htmlspecialchars($o['name'],ENT_QUOTES) ?>',<?= htmlspecialchars(json_encode($evArr),ENT_QUOTES) ?>)" data-id="<?= $o['id'] ?>">
            <img src="<?= htmlspecialchars($o['image']) ?>" onerror="this.src='https://via.placeholder.com/40'">
            <div class="flex-1" style="min-width:0">
                <div class="offer-name"><?= htmlspecialchars($o['name']) ?></div>
                <div class="offer-cat"><?= htmlspecialchars($o['category']) ?> · <?= htmlspecialchars($o['payout_type']) ?></div>
            </div>
            <div class="offer-payout">₹<?= number_format($maxP,0) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>


<!-- 3: Event & Payout -->
<div class="section" id="s3" style="display:none">
    <div class="sec-head">
        <div class="sec-num" id="n3">3</div>
        <div class="sec-title">Event & Payout</div>
    </div>
    <p style="font-size:12px;color:var(--muted);margin-bottom:12px">Select an event, then split the payout:</p>
    <div class="event-pills" id="eventPills"></div>
    <div id="payoutConfig" style="display:none;margin-top:16px">
        <div class="payout-info">
            <div><div class="pi-label">Event Payout</div><div class="pi-val gold" id="maxLbl">₹0</div></div>
            <div style="text-align:right"><div class="pi-label">Your Profit</div><div class="pi-val green" id="remLbl">₹0</div></div>
        </div>
        <div class="payout-grid">
            <div class="inp-group" style="margin-bottom:0">
                <label>User Gets (₹)</label>
                <input type="number" name="user_payout" id="user_payout" class="inp" min="0" step="1" placeholder="0" oninput="updateBar()">
            </div>
            <div class="inp-group" style="margin-bottom:0" id="refer_payout_wrap">
                <label>Referrer Gets (₹)</label>
                <input type="number" name="refer_payout" id="refer_payout" class="inp" min="0" step="1" placeholder="0" oninput="updateBar()">
            </div>
        </div>
        <div class="bar-wrap"><div class="bar-fill" id="payBar" style="width:0%"></div></div>
        <div class="bar-label" id="barTxt">₹0 / ₹0</div>
    </div>
</div>

<!-- 4: Steps -->
<div class="section" id="s4" style="visibility:hidden;height:0;overflow:hidden;padding:0;margin:0;border:0">
    <div class="sec-head">
        <div class="sec-num" id="n4">4</div>
        <div class="sec-title">Steps for User</div>
    </div>
    <p style="font-size:12px;color:var(--muted);margin-bottom:12px">What the user needs to do to complete & earn:</p>
    <div id="stepsWrap">
        <div class="step-row">
            <div class="step-badge">1</div>
            <input type="text" name="steps[]" class="step-inp" placeholder="e.g. Download the app">
            <div class="step-del" onclick="removeStep(this)"><i class="fas fa-times" style="font-size:10px"></i></div>
        </div>
        <div class="step-row">
            <div class="step-badge">2</div>
            <input type="text" name="steps[]" class="step-inp" placeholder="e.g. Register & verify mobile">
            <div class="step-del" onclick="removeStep(this)"><i class="fas fa-times" style="font-size:10px"></i></div>
        </div>
        <div class="step-row">
            <div class="step-badge">3</div>
            <input type="text" name="steps[]" class="step-inp" placeholder="e.g. Complete first transaction">
            <div class="step-del" onclick="removeStep(this)"><i class="fas fa-times" style="font-size:10px"></i></div>
        </div>
    </div>
    <button type="button" class="add-step-btn" onclick="addStep()"><i class="fas fa-plus"></i> Add Step</button>
</div>


<!-- 5: Settings -->
<div class="section" id="s5" style="display:none">
    <div class="sec-head">
        <div class="sec-num" id="n5">5</div>
        <div class="sec-title">Settings</div>
    </div>
    <div class="toggle-row">
        <div class="toggle-info">
            <div class="tl"><i class="fas fa-mobile-alt" style="color:var(--accent);margin-right:8px"></i>Mobile Number</div>
            <div class="ts">Show mobile field on camp page</div>
        </div>
        <label class="toggle-sw">
            <input type="checkbox" name="mobile_enabled" id="mobile_enabled" checked>
            <div class="toggle-track"></div>
        </label>
    </div>
    <div class="toggle-row">
        <div class="toggle-info">
            <div class="tl"><i class="fas fa-share-alt" style="color:var(--gold);margin-right:8px"></i>Refer & Earn</div>
            <div class="ts">Show refer UPI field on camp page</div>
        </div>
        <label class="toggle-sw">
            <input type="checkbox" name="refer_enabled" id="refer_enabled" checked onchange="toggleReferPay()">
            <div class="toggle-track"></div>
        </label>
    </div>
</div>

<!-- 6: Preview & Submit -->
<div class="section" id="s6" style="display:none">
    <div class="sec-head">
        <div class="sec-num" id="n6">6</div>
        <div class="sec-title">Your Camp Links</div>
    </div>
    <div style="font-size:11px;font-weight:500;color:var(--muted);margin-bottom:6px">Offer Link</div>
    <div class="link-box" id="linkPrev">Fill details above to preview link...</div>
    <div id="referPrevWrap" style="display:none">
        <div style="font-size:11px;font-weight:500;color:var(--gold);margin-bottom:6px;margin-top:12px"><i class="fas fa-share-alt" style="margin-right:4px"></i>Refer Link</div>
        <div class="link-box" id="referPrev" style="border-color:rgba(154,103,0,.2);background:var(--gold-light)"></div>
    </div>
    <button type="submit" class="submit-btn">
        <i class="fas fa-rocket"></i> Launch Camp
    </button>
</div>

</form>
</div><!-- /panel-create -->


<!-- ══ MY CAMPS TAB ══ -->
<div class="tab-panel" id="panel-camps">
<?php if(empty($my_camps)): ?>
<div class="empty">
    <div class="empty-icon">🏕️</div>
    <div class="empty-title">No camps yet</div>
    <div class="empty-sub">Create your first camp from the Create tab</div>
</div>
<?php else: ?>
<?php foreach($my_camps as $camp):
    $link      = $base_url . '/offer/' . $camp['slug'];
    $refer_link = $base_url . '/reffer/' . $camp['slug'];
    $steps_arr = json_decode($camp['steps'] ?? '[]', true) ?: [];
    $sj        = htmlspecialchars(json_encode($steps_arr), ENT_QUOTES);
    $isActive  = $camp['status']==='active' && $camp['offer_status']==='active';
?>
<div class="camp-card">
    <div class="camp-top">
        <img class="camp-img" src="<?= htmlspecialchars($camp['offer_image']) ?>" onerror="this.src='https://via.placeholder.com/44'">
        <div style="flex:1;min-width:0">
            <div class="camp-name"><?= htmlspecialchars($camp['camp_name']) ?></div>
            <div class="camp-offer"><?= htmlspecialchars($camp['offer_name']) ?> · <?= htmlspecialchars($camp['event_name'] ?? '—') ?></div>
        </div>
    </div>
    <div class="camp-chips">
        <span class="chip <?= $isActive ? 'chip-green' : 'chip-red' ?>"><?= $isActive ? '● Active' : '⏸ Paused' ?></span>
        <span class="chip chip-purple">U ₹<?= number_format($camp['user_payout'],0) ?></span>
        <?php if($camp['refer_enabled']): ?><span class="chip chip-gold">R ₹<?= number_format($camp['refer_payout'],0) ?></span><?php endif; ?>
        <?php if($camp['mobile_enabled']): ?><span class="chip chip-blue">📱 Mobile</span><?php endif; ?>
        <span class="chip chip-purple">👁 <?= $camp['total_clicks'] ?></span>
    </div>

    <!-- Offer Link -->
    <div style="font-size:11px;font-weight:500;color:var(--muted);margin-bottom:4px">Offer Link</div>
    <div class="camp-link-row">
        <span><?= htmlspecialchars($link) ?></span>
        <button onclick="copyTxt('<?= htmlspecialchars($link) ?>')" title="Copy"><i class="fas fa-copy"></i></button>
    </div>

    <!-- Refer Link -->
    <?php if($camp['refer_enabled']): ?>
    <div style="font-size:11px;font-weight:500;color:var(--gold);margin-bottom:4px;margin-top:8px">
        <i class="fas fa-share-alt" style="margin-right:4px"></i>Refer Link
    </div>
    <div class="camp-link-row" style="border-color:rgba(154,103,0,.15);background:var(--gold-light)">
        <span style="color:var(--text)"><?= htmlspecialchars($refer_link) ?></span>
        <button onclick="copyTxt('<?= htmlspecialchars($refer_link) ?>')" title="Copy Refer Link" style="color:var(--gold)"><i class="fas fa-copy"></i></button>
    </div>
    <?php endif; ?>

    <?php if(!empty($steps_arr)): ?>
    <div class="steps-preview">
        <?php foreach($steps_arr as $si => $step): ?>
        <span class="step-tag"><?= ($si+1) ?>. <?= htmlspecialchars(mb_substr($step,0,28)) ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="camp-actions">
        <?php if($camp['refer_enabled']): ?>
        <?php endif; ?>
        <a class="act-btn act-copy" href="campreport?camp_id=<?= $camp['id'] ?>">
         <i class="fas fa-chart-bar"></i> Report </a>
        <button class="act-btn act-edit" onclick="openEdit(<?= $camp['id'] ?>,'<?= htmlspecialchars($camp['camp_name'],ENT_QUOTES) ?>',<?= $camp['user_payout'] ?>,<?= $camp['refer_payout'] ?>,<?= $camp['refer_enabled'] ?>,<?= $camp['mobile_enabled'] ?>,'<?= $camp['max_payout'] ?>',<?= $sj ?>)"><i class="fas fa-edit"></i> Edit</button>
        <a class="act-btn act-pause" href="?toggle=<?= $camp['status'] ?>&camp_id=<?= $camp['id'] ?>"><i class="fas fa-<?= $camp['status']==='active'?'pause':'play' ?>"></i> <?= $camp['status']==='active'?'Pause':'Resume' ?></a>
        <button class="act-btn act-del" onclick="delCamp(<?= $camp['id'] ?>)"><i class="fas fa-trash"></i></button>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div><!-- /panel-camps -->

</div><!-- /wrap -->
</main>


<!-- ══ EDIT MODAL ══ -->
<div class="modal-bg" id="editModal">
<div class="modal">
    <div class="modal-handle"></div>
    <div class="modal-title">
        <span><i class="fas fa-edit" style="color:var(--gold);margin-right:8px"></i>Edit Camp</span>
        <div class="modal-close" onclick="closeEdit()"><i class="fas fa-times"></i></div>
    </div>
    <form method="POST" id="editForm">
        <input type="hidden" name="edit_camp" value="1">
        <input type="hidden" name="camp_id" id="edit_camp_id">

        <div class="inp-group">
            <label>Camp Name</label>
            <input type="text" name="camp_name" id="edit_camp_name" class="inp" required>
        </div>

        <div class="payout-info" style="margin-bottom:14px">
            <div><div class="pi-label">Event Payout</div><div class="pi-val gold" id="e_maxLbl">₹0</div></div>
            <div style="text-align:right"><div class="pi-label">Remaining</div><div class="pi-val green" id="e_remLbl">₹0</div></div>
        </div>

        <div class="payout-grid">
            <div class="inp-group" style="margin-bottom:0">
                <label>User Gets (₹)</label>
                <input type="number" name="user_payout" id="e_user_pay" class="inp" min="0" step="1" oninput="updateEditBar()">
            </div>
            <div class="inp-group" style="margin-bottom:0" id="e_refer_wrap">
                <label>Referrer Gets (₹)</label>
                <input type="number" name="refer_payout" id="e_refer_pay" class="inp" min="0" step="1" oninput="updateEditBar()">
            </div>
        </div>

        <div style="margin:16px 0 8px">
            <div style="font-size:12px;font-weight:500;color:var(--muted);margin-bottom:10px">Steps</div>
            <div id="editStepsWrap"></div>
            <button type="button" class="add-step-btn" onclick="addEditStep('')"><i class="fas fa-plus"></i> Add Step</button>
        </div>

        <div style="margin:16px 0">
            <div class="toggle-row">
                <div class="toggle-info"><div class="tl"><i class="fas fa-mobile-alt" style="color:var(--accent);margin-right:8px"></i>Mobile Input</div></div>
                <label class="toggle-sw"><input type="checkbox" name="mobile_enabled" id="e_mobile"><div class="toggle-track"></div></label>
            </div>
            <div class="toggle-row">
                <div class="toggle-info"><div class="tl"><i class="fas fa-share-alt" style="color:var(--gold);margin-right:8px"></i>Refer & Earn</div></div>
                <label class="toggle-sw"><input type="checkbox" name="refer_enabled" id="e_refer" onchange="toggleEditRefer()"><div class="toggle-track"></div></label>
            </div>
        </div>

        <button type="submit" class="submit-btn"><i class="fas fa-save"></i> Save Changes</button>
    </form>
</div>
</div>


<script>
const BASE = '<?= $base_url ?>';
let curMax = 0, stepCnt = 3, editMax = 0, editStepCnt = 0;

// ── TABS ──
function switchTab(t) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
    document.getElementById('panel-' + t).classList.add('active');
    document.getElementById('tab-' + t).classList.add('active');
}

// ── SELECT OFFER ──
function selectOffer(id, name, evArr) {
    document.getElementById('f_offer_id').value = id;
    document.querySelectorAll('.offer-card').forEach(c => c.classList.remove('selected'));
    document.querySelector(`.offer-card[data-id="${id}"]`).classList.add('selected');
    document.getElementById('sel-offer-name').textContent = name;
    document.getElementById('n2').classList.add('done');

    // Show sections
    ['s3','s5','s6'].forEach(id => document.getElementById(id).style.display = 'block');

    // Show step4 properly
    const s4 = document.getElementById('s4');
    s4.style.visibility = 'visible';
    s4.style.height = 'auto';
    s4.style.overflow = 'visible';
    s4.style.padding = '20px';
    s4.style.margin = '0 0 16px 0';
    s4.style.border = '1px solid var(--border)';

    // Build event pills
    const pills = document.getElementById('eventPills');
    pills.innerHTML = '';
    evArr.forEach(ev => {
        const p = document.createElement('div');
        p.className = 'epill';
        p.innerHTML = `<i class="fas fa-bolt" style="color:var(--gold);font-size:10px"></i>${ev.name}<span class="epay">₹${parseFloat(ev.payout).toFixed(0)}</span>`;
        p.onclick = () => selectEvent(ev.id, ev.payout, p);
        pills.appendChild(p);
    });
    document.getElementById('payoutConfig').style.display = 'none';
    updateLink();
}

// ── SELECT EVENT ──
function selectEvent(id, payout, el) {
    document.getElementById('f_event_id').value = id;
    document.querySelectorAll('.epill').forEach(p => p.classList.remove('selected'));
    el.classList.add('selected');
    curMax = parseFloat(payout);
    document.getElementById('maxLbl').textContent = '₹' + curMax.toFixed(0);
    document.getElementById('payoutConfig').style.display = 'block';
    document.getElementById('user_payout').value = '';
    document.getElementById('refer_payout').value = '';
    updateBar();
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
    bar.style.background = pct>100 ? 'var(--red)' : pct>80 ? 'var(--gold)' : 'var(--accent)';
    document.getElementById('barTxt').textContent = `₹${tot.toFixed(0)} / ₹${curMax.toFixed(0)}`;
}

function toggleReferPay() {
    const on = document.getElementById('refer_enabled').checked;
    document.getElementById('refer_payout_wrap').style.display = on ? 'block' : 'none';
    if (!on) { document.getElementById('refer_payout').value=0; updateBar(); }
    updateLink();
}


// Sidebar Overlay setup
if (!document.querySelector('.sidebar-overlay')) {
    document.body.insertAdjacentHTML('beforeend', '<div class="sidebar-overlay" onclick="toggleSidebar()"></div>');
}

function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar'); 
    const overlay = document.querySelector('.sidebar-overlay');
    
    if (sidebar) {
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
        
        if (sidebar.classList.contains('active')) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = 'auto';
        }
    }
}

// ── STEPS ──
function addStep() {
    stepCnt++;
    const d = document.createElement('div');
    d.className = 'step-row';
    d.innerHTML = `<div class="step-badge">${stepCnt}</div><input type="text" name="steps[]" class="step-inp" placeholder="Step ${stepCnt}"><div class="step-del" onclick="removeStep(this)"><i class="fas fa-times" style="font-size:10px"></i></div>`;
    document.getElementById('stepsWrap').appendChild(d);
}
function removeStep(btn) {
    btn.closest('.step-row').remove();
    renumber('stepsWrap','step-badge');
    stepCnt = document.querySelectorAll('#stepsWrap .step-row').length;
}
function renumber(wrapId, badgeClass) {
    document.querySelectorAll('#'+wrapId+' .step-row').forEach((r,i)=>{
        r.querySelector('.'+badgeClass).textContent = i+1;
    });
}

// ── LINK PREVIEW ──
function updateLink() {
    const name = document.getElementById('camp_name').value.trim();
    if (!name) return;
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
document.getElementById('camp_name').addEventListener('input', updateLink);

// ── COPY ──
function copyTxt(txt) {
    navigator.clipboard.writeText(txt).then(() => {
        const toast = document.createElement('div');
        toast.innerHTML = `<i class="fas fa-check-circle"></i> Copied to clipboard`;
        toast.style = `
            position: fixed; top: 20px; left: 50%; transform: translateX(-50%);
            background: var(--text); color: #fff; padding: 8px 18px;
            border-radius: 8px; font-size: 12px; font-weight: 500;
            z-index: 9999; box-shadow: var(--shadow-lg);
            animation: slideDown 0.3s ease-out;
            display: flex; align-items: center; gap: 8px;
        `;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = '0.3s';
            setTimeout(() => toast.remove(), 300);
        }, 1500);
    });
}

// ── DELETE ──
function delCamp(id) {
    Swal.fire({title:'Delete Camp?',text:'This cannot be undone.',icon:'warning',
        showCancelButton:true,confirmButtonColor:'var(--red)',cancelButtonColor:'#666',
        confirmButtonText:'Delete',
        customClass:{popup:'rounded-2xl'}
    }).then(r=>{ if(r.isConfirmed) window.location='?delete_camp='+id; });
}


// ── CREATE FORM SUBMIT ──
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
    document.querySelectorAll('#stepsWrap .step-inp').forEach(inp => {
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
}
function closeEdit() { document.getElementById('editModal').classList.remove('show'); }

function addEditStep(val) {
    editStepCnt++;
    const d = document.createElement('div');
    d.className = 'step-row';
    d.innerHTML = `<div class="step-badge">${editStepCnt}</div><input type="text" class="step-inp" value="${(val||'').replace(/"/g,'&quot;')}" placeholder="Step ${editStepCnt}"><div class="step-del" onclick="removeEditStep(this)"><i class="fas fa-times" style="font-size:10px"></i></div>`;
    document.getElementById('editStepsWrap').appendChild(d);
}
function removeEditStep(btn) {
    btn.closest('.step-row').remove();
    renumber('editStepsWrap','step-badge');
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
    document.querySelectorAll('#editStepsWrap .step-inp').forEach(inp=>{
        const v=inp.value.trim();
        if(v){const h=document.createElement('input');h.type='hidden';h.name='steps[]';h.value=v;this.appendChild(h);}
    });
});

document.getElementById('editModal').addEventListener('click',function(e){if(e.target===this)closeEdit();});
</script>

<?php include 'footer.php'; ?>
</body>
</html>
