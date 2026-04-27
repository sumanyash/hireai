<?php require_once __DIR__ . '/includes/auth_check.php';
$oid = $user['org_id'];
$stats = [
  'campaigns'   => db_fetch_one("SELECT COUNT(*) c FROM campaigns WHERE org_id=?",[$oid],'i')['c']??0,
  'candidates'  => db_fetch_one("SELECT COUNT(*) c FROM candidates WHERE org_id=?",[$oid],'i')['c']??0,
  'completed'   => db_fetch_one("SELECT COUNT(*) c FROM candidates WHERE org_id=? AND status IN ('interview_completed','shortlisted','rejected')",[$oid],'i')['c']??0,
  'shortlisted' => db_fetch_one("SELECT COUNT(*) c FROM candidates WHERE org_id=? AND status='shortlisted'",[$oid],'i')['c']??0,
  'pending'     => db_fetch_one("SELECT COUNT(*) c FROM candidates WHERE org_id=? AND status='pending'",[$oid],'i')['c']??0,
  'rejected'    => db_fetch_one("SELECT COUNT(*) c FROM candidates WHERE org_id=? AND status='rejected'",[$oid],'i')['c']??0,
];
$rate = $stats['completed']>0 ? round($stats['shortlisted']/$stats['completed']*100) : 0;
$recent = db_fetch_all("SELECT c.id,c.name,c.phone,c.status,c.updated_at,camp.name campaign_name,ir.total_score,ir.pass_fail FROM candidates c LEFT JOIN campaigns camp ON c.campaign_id=camp.id LEFT JOIN interview_results ir ON c.id=ir.candidate_id WHERE c.org_id=? ORDER BY c.updated_at DESC LIMIT 8",[$oid],'i');
$campaigns = db_fetch_all("SELECT ca.*,COUNT(DISTINCT c.id) total_candidates,SUM(c.status='shortlisted') shortlisted_count,SUM(c.status IN ('interview_completed','shortlisted','rejected')) done_count FROM campaigns ca LEFT JOIN candidates c ON ca.id=c.campaign_id WHERE ca.org_id=? GROUP BY ca.id ORDER BY ca.created_at DESC LIMIT 6",[$oid],'i');
$daily=db_fetch_all("SELECT DATE(updated_at) day,COUNT(*) cnt FROM candidates WHERE org_id=? AND status IN ('interview_completed','shortlisted','rejected') AND updated_at>=DATE_SUB(NOW(),INTERVAL 14 DAY) GROUP BY DATE(updated_at) ORDER BY day ASC",[$oid],'i');
$cl=[];$cd=[];
for($i=13;$i>=0;$i--){$d=date('Y-m-d',strtotime("-$i days"));$cl[]=date('d M',strtotime($d));$f=array_values(array_filter($daily,fn($r)=>$r['day']===$d));$cd[]=$f?(int)$f[0]['cnt']:0;}
?><!DOCTYPE html><html lang="en"><head><title>Dashboard — HireAI</title><?php include __DIR__.'/includes/head.php';?>
<style>
.kpi-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:24px}
@media(max-width:1200px){.kpi-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}
.kpi{background:#fff;border-radius:16px;padding:20px;box-shadow:var(--card-shadow);border:1px solid rgba(0,0,0,.04);position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s;cursor:default}
.kpi:hover{transform:translateY(-4px);box-shadow:var(--card-shadow-hover)}
.kpi-bg{position:absolute;bottom:-20px;right:-20px;font-size:80px;opacity:.06;transform:rotate(-10deg);pointer-events:none;user-select:none}
.kpi-val{font-size:36px;font-weight:900;line-height:1;letter-spacing:-1.5px;font-variant-numeric:tabular-nums}
.kpi-label{font-size:11px;font-weight:700;color:var(--gray);margin-top:6px;text-transform:uppercase;letter-spacing:.6px}
.kpi-trend{font-size:11px;margin-top:8px;display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:20px}
.dash-main{display:grid;grid-template-columns:1fr 320px;gap:20px;margin-bottom:20px}
.dash-bottom{display:grid;grid-template-columns:320px 1fr;gap:20px}
@media(max-width:1100px){.dash-main,.dash-bottom{grid-template-columns:1fr}}
.chart-wrap{height:210px;position:relative}
.pipe-row{display:flex;align-items:center;gap:12px;margin-bottom:13px}
.pipe-label{width:90px;font-size:12px;font-weight:600;color:var(--text2);flex-shrink:0}
.pipe-track{flex:1;background:#F1F5F9;border-radius:99px;height:9px;overflow:hidden}
.pipe-fill{height:9px;border-radius:99px;transition:width 1.3s cubic-bezier(.4,0,.2,1)}
.pipe-val{width:30px;text-align:right;font-size:13px;font-weight:800}
.act-row{display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid #F8FAFC}
.act-row:last-child{border-bottom:none}
.act-avatar{width:40px;height:40px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:800;color:#fff}
.camp-item{display:flex;align-items:center;justify-content:space-between;padding:13px 0;border-bottom:1px solid #F8FAFC;gap:12px}
.camp-item:last-child{border-bottom:none}
.prog-track{background:#F1F5F9;border-radius:99px;height:5px;margin-top:6px}
.prog-fill{height:5px;border-radius:99px;background:linear-gradient(90deg,var(--blue),var(--accent))}
.dash-banner{background:linear-gradient(135deg,#080F1E 0%,#0F2247 40%,#0D1B35 100%);border-radius:20px;padding:30px 36px;margin-bottom:24px;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;border:1px solid rgba(37,99,235,.2)}
.banner-grid{position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);background-size:28px 28px;pointer-events:none}
.banner-orb{position:absolute;top:-80px;right:-80px;width:320px;height:320px;border-radius:50%;background:radial-gradient(circle,rgba(37,99,235,.18),transparent 70%);pointer-events:none}
.banner-orb2{position:absolute;bottom:-60px;left:30%;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(6,182,212,.1),transparent 70%);pointer-events:none}
.banner-title{font-size:26px;font-weight:900;color:#fff;letter-spacing:-.5px;position:relative}
.banner-sub{font-size:14px;color:rgba(255,255,255,.45);margin-top:5px;position:relative}
.banner-actions{display:flex;gap:10px;margin-top:18px;position:relative;flex-wrap:wrap}
.banner-stats{display:flex;gap:28px;position:relative;flex-wrap:wrap}
.b-stat-val{font-size:36px;font-weight:900;color:#fff;line-height:1;letter-spacing:-1.5px}
.b-stat-label{font-size:11px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.6px;margin-top:4px}
</style>
</head><body>
<?php include __DIR__.'/includes/nav.php';?>
<div class="main-content">

<!-- BANNER -->
<div class="dash-banner animate-in">
  <div class="banner-grid"></div><div class="banner-orb"></div><div class="banner-orb2"></div>
  <div>
    <div class="banner-title">Welcome back, <?=htmlspecialchars($user['name']??'Admin')?> 👋</div>
    <div class="banner-sub"><?=date('l, d F Y')?> &nbsp;·&nbsp; HireAI by Avyukta Intellicall</div>
    <div class="banner-actions">
      <a href="campaigns.php?action=new" class="btn-primary" style="box-shadow:0 4px 18px rgba(37,99,235,.5)"><i class="fa-solid fa-plus"></i> New Campaign</a>
      <a href="candidates.php" class="btn-outline" style="background:rgba(255,255,255,.07);border-color:rgba(255,255,255,.15);color:rgba(255,255,255,.8)"><i class="fa-solid fa-users fa-sm"></i> All Candidates</a>
    </div>
  </div>
  <div class="banner-stats">
    <?php foreach([[$stats['candidates'],'Total Candidates'],[$stats['completed'],'Interviews Done'],[$rate.'%','Selection Rate']] as [$v,$l]):?>
    <div>
      <div class="b-stat-val" data-count="<?=preg_replace('/[^0-9]/','',str_replace('%','',$v))?>" data-suffix="<?=str_contains($v,'%')?'%':''?>"><?=$v?></div>
      <div class="b-stat-label"><?=$l?></div>
    </div>
    <?php endforeach;?>
  </div>
</div>

<!-- KPIs -->
<div class="kpi-grid">
<?php
$kpis=[
  ['fa-rocket','Campaigns',$stats['campaigns'],'#2563EB','#EFF6FF','🚀'],
  ['fa-users','Candidates',$stats['candidates'],'#7C3AED','#F5F3FF','👥'],
  ['fa-microphone','Interviews',$stats['completed'],'#0891B2','#ECFEFF','🎤'],
  ['fa-check-circle','Shortlisted',$stats['shortlisted'],'#059669','#ECFDF5','✅'],
  ['fa-clock','Pending',$stats['pending'],'#D97706','#FFFBEB','⏳'],
  ['fa-chart-pie','Selection Rate',$rate.'%','#DB2777','#FDF2F8','📊'],
];
foreach($kpis as $i=>[$icon,$label,$val,$color,$bg,$emoji]):?>
<div class="kpi animate-in" style="background:<?=$bg?>">
  <div class="kpi-bg"><?=$emoji?></div>
  <div style="font-size:13px;font-weight:700;color:<?=$color?>;opacity:.8;margin-bottom:8px;display:flex;align-items:center;gap:6px"><i class="fa-solid <?=$icon?> fa-sm"></i><?=$label?></div>
  <div class="kpi-val" style="color:<?=$color?>"><?=$val?></div>
</div>
<?php endforeach;?>
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
    <div style="margin-top:20px;background:linear-gradient(135deg,#ECFDF5,#D1FAE5);border-radius:14px;padding:16px;display:flex;align-items:center;justify-content:space-between">
      <div><div style="font-size:11px;font-weight:700;color:#065F46;text-transform:uppercase;letter-spacing:.5px">Selection Rate</div><div style="font-size:32px;font-weight:900;color:#10B981;letter-spacing:-1px"><?=$rate?>%</div></div>
      <div style="font-size:44px;animation:float 3s ease-in-out infinite">🏆</div>
    </div>
  </div>
</div>

<!-- CAMPAIGNS + RECENT -->
<div class="dash-bottom">
  <div class="card animate-in">
    <div class="card-header"><h3><i class="fa-solid fa-rocket" style="color:var(--orange)"></i> Campaigns</h3><a href="campaigns.php?action=new" class="btn-primary-sm"><i class="fa-solid fa-plus fa-xs"></i> New</a></div>
    <?php if(empty($campaigns)):?>
    <div style="text-align:center;padding:32px 0;color:var(--gray)"><i class="fa-solid fa-folder-open fa-3x" style="margin-bottom:12px;display:block;opacity:.3"></i><p style="font-weight:600;margin-bottom:12px">No campaigns yet</p><a href="campaigns.php?action=new" class="btn-primary">Create First Campaign</a></div>
    <?php else: foreach($campaigns as $c):$prog=$c['total_candidates']>0?round($c['done_count']/$c['total_candidates']*100):0;?>
    <div class="camp-item">
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($c['name'])?></div>
        <div style="font-size:12px;color:var(--gray);margin-top:2px"><?=$c['total_candidates']?> candidates · <?=(int)$c['shortlisted_count']?> shortlisted</div>
        <div class="prog-track"><div class="prog-fill" style="width:<?=$prog?>%"></div></div>
      </div>
      <div style="flex-shrink:0;display:flex;flex-direction:column;align-items:flex-end;gap:5px">
        <span class="badge badge-<?=$c['status']?>"><?=ucfirst($c['status'])?></span>
        <a href="candidates.php?campaign_id=<?=$c['id']?>" class="btn-sm" style="font-size:11px;padding:4px 10px">View</a>
      </div>
    </div>
    <?php endforeach; endif;?>
  </div>
  <div class="card animate-in">
    <div class="card-header"><h3><i class="fa-solid fa-clock-rotate-left" style="color:var(--accent)"></i> Recent Activity</h3><a href="candidates.php" class="btn-sm">View All <i class="fa-solid fa-arrow-right fa-xs"></i></a></div>
    <?php if(empty($recent)):?>
    <div style="text-align:center;padding:32px;color:var(--gray)">No activity yet. <a href="campaigns.php">Create a campaign →</a></div>
    <?php else:
    $colors=['#2563EB','#7C3AED','#059669','#D97706','#DC2626','#0891B2','#DB2777','#EA580C'];
    foreach($recent as $i=>$r):$init=strtoupper(substr($r['name']??'?',0,1));$clr=$colors[$i%count($colors)];?>
    <div class="act-row">
      <div class="act-avatar" style="background:<?=$clr?>"><?=$init?></div>
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($r['name']??'Unknown')?></div>
        <div style="font-size:12px;color:var(--gray);margin-top:1px"><?=htmlspecialchars($r['phone'])?> · <?=htmlspecialchars($r['campaign_name']??'—')?></div>
      </div>
      <span class="badge badge-<?=$r['status']?>"><?=ucfirst(str_replace('_',' ',$r['status']))?></span>
      <?php if($r['total_score']!==null):?>
      <div style="text-align:right;flex-shrink:0;min-width:54px"><div style="font-size:20px;font-weight:900;color:<?=$r['pass_fail']==='pass'?'#10B981':'#EF4444'?>"><?=$r['total_score']?></div><div style="font-size:10px;color:var(--gray)">/ 100</div></div>
      <?php else:?><div style="font-size:12px;color:var(--gray);min-width:54px;text-align:right">Pending</div><?php endif;?>
      <a href="candidate_detail.php?id=<?=$r['id']?>" class="btn-sm" style="font-size:11px;padding:5px 12px;flex-shrink:0">View</a>
    </div>
    <?php endforeach; endif;?>
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
document.querySelectorAll('.pipe-fill').forEach(el=>{setTimeout(()=>el.style.width=el.dataset.w+'%',300)});
document.querySelectorAll('[data-count]').forEach(el=>{
  const target=parseInt(el.dataset.count)||0,suffix=el.dataset.suffix||'';
  if(!target)return;let cur=0;const step=Math.max(1,Math.ceil(target/50));
  const t=setInterval(()=>{cur=Math.min(cur+step,target);el.textContent=cur+suffix;if(cur>=target)clearInterval(t)},25);
});
</script>
<?php include __DIR__.'/includes/footer.php';?>
</body></html>
