<?php require_once __DIR__ . '/includes/auth_check.php';
$oid = $user['org_id'];
$user_role_key = strtolower(str_replace([' ', '-'], '_', trim((string)($user['role'] ?? ''))));
$can_manage_campaigns = in_array($user_role_key, ['super_admin', 'admin'], true);

// ── AJAX: recent activity fragment ──────────────────────────────────────────
if (($_GET['ajax'] ?? '') === 'recent') {
  $ra_per   = 8;
  $ra_total = (int)(db_fetch_one("SELECT COUNT(*) c FROM candidates WHERE org_id=?",[$oid],'i')['c']??0);
  $ra_pages = max(1,(int)ceil($ra_total/$ra_per));
  $ra_page  = max(1, min($ra_pages, (int)($_GET['ra_page']??1)));
  $ra_off   = ($ra_page-1)*$ra_per;
  $rows     = db_fetch_all("SELECT c.id,c.name,c.phone,c.status,c.updated_at,camp.name campaign_name,ir.total_score,ir.pass_fail FROM candidates c LEFT JOIN campaigns camp ON c.campaign_id=camp.id LEFT JOIN interview_results ir ON c.id=ir.candidate_id WHERE c.org_id=? ORDER BY c.updated_at DESC LIMIT ? OFFSET ?",[$oid,$ra_per,$ra_off],'iii');
  $grads=['A'=>'135deg,#6366F1,#8B5CF6','B'=>'135deg,#3B82F6,#6366F1','C'=>'135deg,#0EA5E9,#3B82F6','D'=>'135deg,#10B981,#059669','E'=>'135deg,#F59E0B,#D97706','F'=>'135deg,#EF4444,#DC2626','G'=>'135deg,#8B5CF6,#7C3AED','H'=>'135deg,#06B6D4,#0891B2','I'=>'135deg,#84CC16,#65A30D','J'=>'135deg,#F97316,#EA580C','K'=>'135deg,#EC4899,#DB2777','L'=>'135deg,#14B8A6,#0D9488','M'=>'135deg,#6366F1,#4F46E5','N'=>'135deg,#3B82F6,#2563EB','O'=>'135deg,#10B981,#047857','P'=>'135deg,#F59E0B,#B45309','Q'=>'135deg,#EF4444,#B91C1C','R'=>'135deg,#8B5CF6,#6D28D9','S'=>'135deg,#F97316,#C2410C','T'=>'135deg,#06B6D4,#0E7490','U'=>'135deg,#84CC16,#4D7C0F','V'=>'135deg,#EC4899,#BE185D','W'=>'135deg,#14B8A6,#0F766E','X'=>'135deg,#6366F1,#4338CA','Y'=>'135deg,#F59E0B,#92400E','Z'=>'135deg,#EF4444,#991B1B'];
  ob_start();
  foreach($rows as $r):
    $init=$init2=strtoupper(substr(trim($r['name']??'?'),0,1));
    $parts=array_filter(explode(' ',trim($r['name']??'')));
    if(count($parts)>1) $init2.=strtoupper(substr(end($parts),0,1));
    $grad=$grads[$init]??'135deg,#6366F1,#8B5CF6';
    $when=!empty($r['updated_at'])?(function($t){$d=time()-strtotime($t);if($d<60)return 'Just now';if($d<3600)return round($d/60).'m ago';if($d<86400)return round($d/3600).'h ago';return date('d M',strtotime($t));})($r['updated_at']):'';
  ?>
  <div class="act-row">
    <div class="act-avatar" style="background:linear-gradient(<?=$grad?>)"><?=$init2?></div>
    <div style="flex:1;min-width:0">
      <a href="candidate_detail?id=<?=$r['id']?>" style="font-size:13px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block;color:var(--text);text-decoration:none"><?=htmlspecialchars($r['name']??'Unknown')?></a>
      <div style="font-size:11px;color:var(--gray);margin-top:2px;display:flex;align-items:center;gap:6px">
        <span><?=htmlspecialchars($r['campaign_name']??'—')?></span>
        <?php if($when):?><span style="color:#CBD5E1">·</span><span><?=$when?></span><?php endif;?>
      </div>
    </div>
    <span class="badge badge-<?=$r['status']?>" style="flex-shrink:0"><?=ucfirst(str_replace('_',' ',$r['status']))?></span>
    <?php if($r['total_score']!==null): $sc=$r['total_score']; $pf=$r['pass_fail']; $sc_color=$pf==='pass'?'#10B981':'#EF4444'; ?>
    <div class="act-score"><div class="act-score-val" style="color:<?=$sc_color?>"><?=$sc?></div><div class="act-score-max">/100</div></div>
    <?php else:?>
    <div class="act-score"><div style="font-size:11px;color:var(--gray);font-weight:600">Pending</div></div>
    <?php endif;?>
  </div>
  <?php endforeach;
  $html = ob_get_clean();
  header('Content-Type: application/json');
  echo json_encode(['html'=>$html,'page'=>$ra_page,'pages'=>$ra_pages,'total'=>$ra_total,'from'=>$ra_total>0?($ra_page-1)*$ra_per+1:0,'to'=>min($ra_page*$ra_per,$ra_total)]);
  exit;
}
// ────────────────────────────────────────────────────────────────────────────

$_sr = db_fetch_one(
  "SELECT
     (SELECT COUNT(*) FROM campaigns WHERE org_id=?) campaigns,
     COUNT(*) candidates,
     SUM(status IN ('interview_completed','shortlisted','rejected')) completed,
     SUM(status='shortlisted') shortlisted,
     SUM(status='pending') pending,
     SUM(status='rejected') rejected
   FROM candidates WHERE org_id=?",
  [$oid, $oid], 'ii'
);
$stats = [
  'campaigns'   => (int)($_sr['campaigns']   ?? 0),
  'candidates'  => (int)($_sr['candidates']  ?? 0),
  'completed'   => (int)($_sr['completed']   ?? 0),
  'shortlisted' => (int)($_sr['shortlisted'] ?? 0),
  'pending'     => (int)($_sr['pending']     ?? 0),
  'rejected'    => (int)($_sr['rejected']    ?? 0),
];
$rate = $stats['completed']>0 ? round($stats['shortlisted']/$stats['completed']*100) : 0;
$ra_per  = 8;
$ra_page = max(1,(int)($_GET['ra_page']??1));
$ra_total= (int)(db_fetch_one("SELECT COUNT(*) c FROM candidates WHERE org_id=?",[$oid],'i')['c']??0);
$ra_pages= max(1,(int)ceil($ra_total/$ra_per));
$ra_page = min($ra_page,$ra_pages);
$ra_off  = ($ra_page-1)*$ra_per;
$recent = db_fetch_all("SELECT c.id,c.name,c.phone,c.status,c.updated_at,camp.name campaign_name,ir.total_score,ir.pass_fail FROM candidates c LEFT JOIN campaigns camp ON c.campaign_id=camp.id LEFT JOIN interview_results ir ON c.id=ir.candidate_id WHERE c.org_id=? ORDER BY c.updated_at DESC LIMIT ? OFFSET ?",[$oid,$ra_per,$ra_off],'iii');
$campaigns = db_fetch_all("SELECT ca.*,COUNT(DISTINCT c.id) total_candidates,SUM(c.status='shortlisted') shortlisted_count,SUM(c.status IN ('interview_completed','shortlisted','rejected')) done_count FROM campaigns ca LEFT JOIN candidates c ON ca.id=c.campaign_id WHERE ca.org_id=? GROUP BY ca.id ORDER BY ca.created_at DESC LIMIT 6",[$oid],'i');
$daily=db_fetch_all("SELECT DATE(updated_at) day,COUNT(*) cnt FROM candidates WHERE org_id=? AND status IN ('interview_completed','shortlisted','rejected') AND updated_at>=DATE_SUB(NOW(),INTERVAL 14 DAY) GROUP BY DATE(updated_at) ORDER BY day ASC",[$oid],'i');
$cl=[];$cd=[];
for($i=13;$i>=0;$i--){$d=date('Y-m-d',strtotime("-$i days"));$cl[]=date('d M',strtotime($d));$f=array_values(array_filter($daily,fn($r)=>$r['day']===$d));$cd[]=$f?(int)$f[0]['cnt']:0;}
?><!DOCTYPE html><html lang="en"><head><title>Dashboard — Avyukta Intellicall AI Hire</title><?php include __DIR__.'/includes/head.php';?>
<style>
/* DASHBOARD SPACING OVERRIDES */
.dash-main .card,.dash-bottom .card{margin-bottom:0}
.dash-main{margin-bottom:12px}
.dash-bottom{margin-bottom:0}

/* TOP BAR — greeting + KPIs in one row */
.dash-topbar{display:grid;grid-template-columns:auto 1fr;gap:16px;align-items:center;background:linear-gradient(135deg,#080F1E 0%,#0F2247 60%,#0D1B35 100%);border-radius:18px;padding:18px 24px;margin-bottom:18px;position:relative;overflow:hidden;border:1px solid rgba(37,99,235,.18)}
.dash-topbar::before{content:'';position:absolute;top:-60px;right:-60px;width:240px;height:240px;border-radius:50%;background:radial-gradient(circle,rgba(37,99,235,.2),transparent 70%);pointer-events:none}
.dash-topbar::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.035) 1px,transparent 1px);background-size:26px 26px;pointer-events:none}
.topbar-left{position:relative;z-index:1;padding-right:20px;border-right:1px solid rgba(255,255,255,.08)}
.topbar-greeting{font-size:10px;font-weight:700;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.9px;margin-bottom:3px}
.topbar-name{font-size:20px;font-weight:900;color:#fff;letter-spacing:-.4px;line-height:1.1}
.topbar-date{font-size:11px;color:rgba(255,255,255,.35);margin-top:3px}
.topbar-actions{display:flex;gap:6px;margin-top:10px}
.topbar-right{position:relative;z-index:1;display:grid;grid-template-columns:repeat(6,1fr);gap:10px}
@media(max-width:1100px){.dash-topbar{grid-template-columns:1fr}.topbar-left{border-right:none;padding-right:0;border-bottom:1px solid rgba(255,255,255,.08);padding-bottom:12px;margin-bottom:4px}.topbar-right{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){.topbar-right{grid-template-columns:repeat(2,1fr)}}

/* KPI CELLS inside topbar */
.kpi-cell{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);border-radius:12px;padding:10px 12px;transition:background .2s,transform .2s;cursor:default;animation:kpiIn .4s ease both}
.kpi-cell:hover{background:rgba(255,255,255,.1);transform:translateY(-2px)}
.kpi-cell-icon{font-size:13px;margin-bottom:5px}
.kpi-cell-val{font-size:22px;font-weight:900;color:#fff;line-height:1;letter-spacing:-1px}
.kpi-cell-label{font-size:9.5px;font-weight:700;color:rgba(255,255,255,.38);text-transform:uppercase;letter-spacing:.5px;margin-top:3px}
@keyframes kpiIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

/* LAYOUT */
.dash-main{display:grid;grid-template-columns:1fr 280px;gap:16px;margin-bottom:16px}
.dash-bottom{display:grid;grid-template-columns:280px 1fr;gap:16px}
@media(max-width:1100px){.dash-main,.dash-bottom{grid-template-columns:1fr}}
.chart-wrap{height:190px;position:relative}

/* PIPELINE */
.pipe-row{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.pipe-label{width:82px;font-size:12px;font-weight:600;color:var(--text2);flex-shrink:0}
.pipe-track{flex:1;background:#F1F5F9;border-radius:99px;height:7px;overflow:hidden}
.pipe-fill{height:7px;border-radius:99px;transition:width 1.3s cubic-bezier(.4,0,.2,1);width:0}
.pipe-val{width:26px;text-align:right;font-size:12px;font-weight:800}
.rate-box{margin-top:14px;border-radius:12px;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#ECFDF5,#D1FAE5);border:1px solid #A7F3D0}
.rate-val{font-size:28px;font-weight:900;color:#10B981;letter-spacing:-1px;line-height:1}
.rate-label{font-size:10px;font-weight:700;color:#065F46;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px}

/* RECENT ACTIVITY */
.act-row{display:flex;align-items:center;gap:10px;padding:9px 4px;border-bottom:1px solid #F8FAFC;border-radius:8px;transition:background .12s}
.act-row:hover{background:#F8FAFC}
.act-row:last-child{border-bottom:none}
.act-avatar{width:36px;height:36px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;box-shadow:0 2px 6px rgba(0,0,0,.12)}
.act-score{text-align:right;flex-shrink:0;min-width:52px}
.act-score-val{font-size:16px;font-weight:900;line-height:1}
.act-score-max{font-size:9px;color:var(--gray);font-weight:600}

/* PAGINATION (recent activity) */
.ra-pagination{display:flex;align-items:center;justify-content:space-between;padding:10px 0 2px;border-top:1px solid #F1F5F9;margin-top:8px}
.ra-pg-info{font-size:11px;color:var(--gray);font-weight:600}
.ra-pg-btns{display:flex;gap:4px}
.ra-pg-btn{display:inline-flex;align-items:center;gap:3px;padding:4px 10px;border-radius:8px;font-size:11px;font-weight:700;text-decoration:none;color:var(--text2);border:1.5px solid #E2E8F0;background:#fff;transition:all .12s}
.ra-pg-btn:hover{background:#F8FAFC}
.ra-pg-btn.active{background:var(--blue);color:#fff;border-color:var(--blue)}
.ra-pg-btn.disabled{opacity:.35;pointer-events:none}

/* CAMPAIGNS */
.camp-item{display:flex;align-items:center;justify-content:space-between;padding:10px 4px;border-bottom:1px solid #F8FAFC;gap:10px;border-radius:8px;transition:background .12s}
.camp-item:hover{background:#F8FAFC}
.camp-item:last-child{border-bottom:none}
.prog-track{background:#F1F5F9;border-radius:99px;height:4px;margin-top:5px;overflow:hidden}
.prog-fill{height:4px;border-radius:99px;background:linear-gradient(90deg,var(--blue),var(--accent));transition:width 1.3s cubic-bezier(.4,0,.2,1);width:0}
</style>
</head><body>
<?php include __DIR__.'/includes/nav.php';?>
<div class="main-content">

<?php
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>
<div class="dash-topbar">
  <div class="topbar-left">
    <div class="topbar-greeting"><?=$greeting?></div>
    <div class="topbar-name"><?=htmlspecialchars($user['name']??'Admin')?></div>
    <div class="topbar-date"><?=date('l, d F Y')?></div>
    <div class="topbar-actions">
      <?php if ($can_manage_campaigns): ?>
      <a href="campaigns?action=new" class="btn-primary" style="font-size:12px;padding:6px 14px;box-shadow:0 4px 14px rgba(37,99,235,.35)"><i class="fa-solid fa-plus fa-xs"></i> New Campaign</a>
      <?php endif; ?>
      <a href="candidates" style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;padding:6px 12px;border-radius:9px;border:1.5px solid rgba(255,255,255,.15);color:rgba(255,255,255,.75);text-decoration:none;background:rgba(255,255,255,.07);transition:background .15s"><i class="fa-solid fa-users fa-xs"></i> Candidates</a>
    </div>
  </div>
  <div class="topbar-right">
    <?php
    $kpis=[
      ['fa-rocket',      'Campaigns',  $stats['campaigns'],  'rgba(99,102,241,.9)',  ''],
      ['fa-users',       'Candidates', $stats['candidates'], 'rgba(59,130,246,.9)',  ''],
      ['fa-microphone',  'Interviews', $stats['completed'],  'rgba(6,182,212,.9)',   ''],
      ['fa-circle-check','Shortlisted',$stats['shortlisted'],'rgba(16,185,129,.9)',  ''],
      ['fa-clock',       'Pending',    $stats['pending'],    'rgba(245,158,11,.9)',  ''],
      ['fa-chart-pie',   'Sel. Rate',  $rate,                'rgba(236,72,153,.9)',  '%'],
    ];
    foreach($kpis as $i=>[$icon,$label,$val,$color,$sfx]):?>
    <div class="kpi-cell" style="animation-delay:<?=$i*50?>ms">
      <div class="kpi-cell-icon" style="color:<?=$color?>"><i class="fa-solid <?=$icon?>"></i></div>
      <div class="kpi-cell-val" data-count="<?=$val?>" data-suffix="<?=$sfx?>"><?=$val?><?=$sfx?></div>
      <div class="kpi-cell-label"><?=$label?></div>
    </div>
    <?php endforeach;?>
  </div>
</div>

<!-- CHART + PIPELINE -->
<div class="dash-main">
  <div class="card animate-in">
    <div class="card-header">
      <h3><i class="fa-solid fa-chart-bar" style="color:var(--blue)"></i> Interview Activity — Last 14 Days</h3>
      <span style="font-size:12px;color:var(--gray);background:#F1F5F9;padding:3px 10px;border-radius:20px">Bi-weekly</span>
    </div>
    <div class="chart-wrap"><canvas id="iChart"></canvas></div>
  </div>
  <div class="card animate-in">
    <div class="card-header"><h3><i class="fa-solid fa-filter" style="color:var(--purple)"></i> Hiring Pipeline</h3></div>
    <?php $pipes=[['Pending',$stats['pending'],'#F59E0B'],['Interviewed',$stats['completed'],'#3B82F6'],['Shortlisted',$stats['shortlisted'],'#10B981'],['Rejected',$stats['rejected'],'#EF4444']];
    foreach($pipes as [$lbl,$val,$clr]):$pct=$stats['candidates']>0?round($val/$stats['candidates']*100):0;?>
    <div class="pipe-row">
      <div class="pipe-label"><?=$lbl?></div>
      <div class="pipe-track"><div class="pipe-fill" style="width:0;background:<?=$clr?>" data-w="<?=$pct?>"></div></div>
      <div class="pipe-val" style="color:<?=$clr?>"><?=$val?></div>
    </div>
    <?php endforeach;?>
    <div class="rate-box">
      <div>
        <div class="rate-label"><i class="fa-solid fa-bullseye fa-xs"></i> Selection Rate</div>
        <div class="rate-val" data-count="<?=$rate?>" data-suffix="%"><?=$rate?>%</div>
        <div style="font-size:11px;color:#047857;margin-top:3px"><?=$stats['shortlisted']?> of <?=$stats['completed']?> interviewed</div>
      </div>
      <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#10B981,#059669);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(16,185,129,.35)">
        <i class="fa-solid fa-trophy" style="color:#fff;font-size:22px"></i>
      </div>
    </div>
  </div>
</div>

<!-- CAMPAIGNS + RECENT -->
<div class="dash-bottom">
  <div class="card animate-in">
    <div class="card-header"><h3><i class="fa-solid fa-rocket" style="color:var(--orange)"></i> Campaigns</h3><?php if ($can_manage_campaigns): ?><a href="campaigns?action=new" class="btn-primary-sm"><i class="fa-solid fa-plus fa-xs"></i> New</a><?php endif; ?></div>
    <?php if(empty($campaigns)):?>
    <div style="text-align:center;padding:32px 0;color:var(--gray)"><i class="fa-solid fa-folder-open fa-3x" style="margin-bottom:12px;display:block;opacity:.3"></i><p style="font-weight:600;margin-bottom:12px">No campaigns yet</p><?php if ($can_manage_campaigns): ?><a href="campaigns?action=new" class="btn-primary">Create First Campaign</a><?php else: ?><span style="font-size:12px">Campaign creation is available for Admin and Super Admin users.</span><?php endif; ?></div>
    <?php else: foreach($campaigns as $c):$prog=$c['total_candidates']>0?round($c['done_count']/$c['total_candidates']*100):0;?>
    <div class="camp-item">
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($c['name'])?></div>
        <div style="font-size:12px;color:var(--gray);margin-top:2px"><?=$c['total_candidates']?> candidates · <?=(int)$c['shortlisted_count']?> shortlisted</div>
        <div class="prog-track"><div class="prog-fill" data-w="<?=$prog?>"></div></div>
      </div>
      <div style="flex-shrink:0;display:flex;flex-direction:column;align-items:flex-end;gap:5px">
        <span class="badge badge-<?=$c['status']?>"><?=ucfirst($c['status'])?></span>
        <a href="candidates?campaign_id=<?=$c['id']?>" class="btn-sm" style="font-size:11px;padding:4px 10px">View</a>
      </div>
    </div>
    <?php endforeach; endif;?>
  </div>
  <div class="card animate-in">
    <div class="card-header"><h3><i class="fa-solid fa-clock-rotate-left" style="color:var(--accent)"></i> Recent Activity</h3><a href="candidates" class="btn-sm">View All <i class="fa-solid fa-arrow-right fa-xs"></i></a></div>
    <div id="ra-body">
    <?php if(empty($recent)):?>
    <div style="text-align:center;padding:32px;color:var(--gray)">No activity yet. <?php if ($can_manage_campaigns): ?><a href="campaigns">Create a campaign →</a><?php endif; ?></div>
    <?php else:
    $avatarGrads=['A'=>'135deg,#6366F1,#8B5CF6','B'=>'135deg,#3B82F6,#6366F1','C'=>'135deg,#0EA5E9,#3B82F6','D'=>'135deg,#10B981,#059669','E'=>'135deg,#F59E0B,#D97706','F'=>'135deg,#EF4444,#DC2626','G'=>'135deg,#8B5CF6,#7C3AED','H'=>'135deg,#06B6D4,#0891B2','I'=>'135deg,#84CC16,#65A30D','J'=>'135deg,#F97316,#EA580C','K'=>'135deg,#EC4899,#DB2777','L'=>'135deg,#14B8A6,#0D9488','M'=>'135deg,#6366F1,#4F46E5','N'=>'135deg,#3B82F6,#2563EB','O'=>'135deg,#10B981,#047857','P'=>'135deg,#F59E0B,#B45309','Q'=>'135deg,#EF4444,#B91C1C','R'=>'135deg,#8B5CF6,#6D28D9','S'=>'135deg,#F97316,#C2410C','T'=>'135deg,#06B6D4,#0E7490','U'=>'135deg,#84CC16,#4D7C0F','V'=>'135deg,#EC4899,#BE185D','W'=>'135deg,#14B8A6,#0F766E','X'=>'135deg,#6366F1,#4338CA','Y'=>'135deg,#F59E0B,#92400E','Z'=>'135deg,#EF4444,#991B1B'];
    foreach($recent as $i=>$r):
      $init = strtoupper(substr(trim($r['name']??'?'),0,1));
      $nameParts = array_filter(explode(' ',trim($r['name']??'')));
      $initials = $init;
      if(count($nameParts)>1) $initials .= strtoupper(substr(end($nameParts),0,1));
      $grad = $avatarGrads[$init] ?? '135deg,#6366F1,#8B5CF6';
      $when = !empty($r['updated_at']) ? (function($t){$d=time()-strtotime($t);if($d<60)return 'Just now';if($d<3600)return round($d/60).'m ago';if($d<86400)return round($d/3600).'h ago';return date('d M',$t=strtotime($t));})($r['updated_at']) : '';
    ?>
    <div class="act-row">
      <div class="act-avatar" style="background:linear-gradient(<?=$grad?>)"><?=$initials?></div>
      <div style="flex:1;min-width:0">
        <a href="candidate_detail?id=<?=$r['id']?>" style="font-size:13px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block;color:var(--text);text-decoration:none"><?=htmlspecialchars($r['name']??'Unknown')?></a>
        <div style="font-size:11px;color:var(--gray);margin-top:2px;display:flex;align-items:center;gap:6px">
          <span><?=htmlspecialchars($r['campaign_name']??'—')?></span>
          <?php if($when):?><span style="color:#CBD5E1">·</span><span><?=$when?></span><?php endif;?>
        </div>
      </div>
      <span class="badge badge-<?=$r['status']?>" style="flex-shrink:0"><?=ucfirst(str_replace('_',' ',$r['status']))?></span>
      <?php if($r['total_score']!==null):
        $sc=$r['total_score']; $pf=$r['pass_fail'];
        $sc_color=$pf==='pass'?'#10B981':'#EF4444';
      ?>
      <div class="act-score">
        <div class="act-score-val" style="color:<?=$sc_color?>"><?=$sc?></div>
        <div class="act-score-max">/100</div>
      </div>
      <?php else:?>
      <div class="act-score"><div style="font-size:11px;color:var(--gray);font-weight:600">Pending</div></div>
      <?php endif;?>
    </div>
    <?php endforeach; endif;?>
    </div>
    <?php if($ra_total>0):?>
    <div class="ra-pagination" id="ra-pagination">
      <span class="ra-pg-info" id="ra-info"></span>
      <div class="ra-pg-btns" id="ra-btns"></div>
    </div>
    <?php endif;?>
  </div>
</div>

</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('iChart').getContext('2d'),{
  type:'bar',
  data:{
    labels:<?=json_encode($cl)?>,
    datasets:[
      {label:'Interviews',data:<?=json_encode($cd)?>,backgroundColor:'rgba(37,99,235,.12)',borderColor:'#2563EB',borderWidth:2,borderRadius:8,borderSkipped:false},
      {label:'Trend',data:<?=json_encode($cd)?>,type:'line',borderColor:'#06B6D4',borderWidth:2.5,pointBackgroundColor:'#06B6D4',pointRadius:3,tension:.45,fill:{target:'origin',above:'rgba(6,182,212,.06)'}}
    ]
  },
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{backgroundColor:'rgba(8,15,30,.92)',titleColor:'#fff',bodyColor:'#94A3B8',padding:12,cornerRadius:10}},scales:{y:{beginAtZero:true,ticks:{stepSize:1,color:'#94A3B8',font:{size:11}},grid:{color:'#F1F5F9'},border:{display:false}},x:{ticks:{color:'#94A3B8',font:{size:11},maxRotation:45},grid:{display:false},border:{display:false}}}}
});
// Animate progress bars
setTimeout(()=>{
  document.querySelectorAll('.pipe-fill,.prog-fill').forEach(el=>{
    el.style.width=(el.dataset.w||el.getAttribute('style').match(/width:(\d+)%/)?.[1]||0)+'%';
  });
},350);

// Counter animation for all [data-count] elements
function animateCount(el) {
  const target = parseInt(el.dataset.count)||0;
  const suffix = el.dataset.suffix||'';
  if (!target) { el.textContent = '0'+suffix; return; }
  let cur = 0;
  const duration = 900, steps = 50;
  const step = Math.max(1, Math.ceil(target / steps));
  const interval = Math.round(duration / steps);
  const t = setInterval(()=>{
    cur = Math.min(cur + step, target);
    el.textContent = cur + suffix;
    if (cur >= target) clearInterval(t);
  }, interval);
}
const observer = new IntersectionObserver(entries=>{
  entries.forEach(e=>{ if(e.isIntersecting){ animateCount(e.target); observer.unobserve(e.target); } });
},{threshold:0.3});
document.querySelectorAll('[data-count]').forEach(el=>observer.observe(el));

// ── Recent Activity AJAX pagination ─────────────────────────────────────────
const RA_TOTAL = <?=(int)$ra_total?>, RA_PAGES = <?=(int)$ra_pages?>;
let raCurrent = <?=(int)$ra_page?>;

function renderRaPagination(page, pages, total, from, to) {
  const info = document.getElementById('ra-info');
  const btns = document.getElementById('ra-btns');
  if (!info || !btns) return;
  info.textContent = total > 0 ? `Showing ${from}–${to} of ${total}` : '';
  let html = `<button class="ra-pg-btn${page<=1?' disabled':''}" onclick="loadRaPage(${page-1})">‹ Prev</button>`;
  const start = Math.max(1, page-2), end = Math.min(pages, page+2);
  for (let p = start; p <= end; p++)
    html += `<button class="ra-pg-btn${p===page?' active':''}" onclick="loadRaPage(${p})">${p}</button>`;
  html += `<button class="ra-pg-btn${page>=pages?' disabled':''}" onclick="loadRaPage(${page+1})">Next ›</button>`;
  btns.innerHTML = html;
}

function loadRaPage(p) {
  if (p < 1 || p > RA_PAGES) return;
  const body = document.getElementById('ra-body');
  if (!body) return;
  body.style.opacity = '0.4';
  fetch(`dashboard?ajax=recent&ra_page=${p}`)
    .then(r => r.json())
    .then(d => {
      body.innerHTML = d.html;
      body.style.opacity = '1';
      raCurrent = d.page;
      renderRaPagination(d.page, d.pages, d.total, d.from, d.to);
    })
    .catch(() => { body.style.opacity = '1'; });
}

// Render pagination controls on first load
renderRaPagination(raCurrent, RA_PAGES, RA_TOTAL,
  RA_TOTAL > 0 ? (raCurrent-1)*8+1 : 0,
  Math.min(raCurrent*8, RA_TOTAL));
</script>
<?php include __DIR__.'/includes/footer.php';?>
</body></html>
