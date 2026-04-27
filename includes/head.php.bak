<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --navy:#080F1E;--navy2:#0D1B35;--navy3:#152848;
  --blue:#2563EB;--blue2:#3B82F6;--blue3:#60A5FA;
  --accent:#06B6D4;--green:#10B981;--orange:#F59E0B;
  --red:#EF4444;--purple:#8B5CF6;--pink:#EC4899;
  --white:#FFFFFF;--gray:#94A3B8;--gray2:#64748B;
  --light:#E2E8F0;--bg:#F0F4F8;--text:#0F172A;--text2:#334155;
  --card-shadow:0 1px 3px rgba(0,0,0,.07),0 4px 16px rgba(0,0,0,.05);
  --card-shadow-hover:0 8px 32px rgba(37,99,235,.15),0 2px 8px rgba(0,0,0,.08);
  --radius:16px;--radius-sm:10px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);line-height:1.5;-webkit-font-smoothing:antialiased}
a{text-decoration:none;color:var(--blue)}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-thumb{background:#CBD5E1;border-radius:99px}

/* NAVBAR */
.navbar{background:rgba(8,15,30,.97);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);padding:0 28px;height:66px;display:flex;align-items:center;justify-content:space-between;position:fixed;top:0;left:0;right:0;z-index:1000;border-bottom:1px solid rgba(255,255,255,.07);box-shadow:0 4px 32px rgba(0,0,0,.4)}
.nav-logo{display:flex;align-items:center;gap:10px}
.nav-logo img{height:34px;width:auto;object-fit:contain;filter:brightness(1.1)}
.nav-logo-text{font-size:20px;font-weight:900;color:#fff;letter-spacing:-0.5px}
.nav-logo-text span{background:linear-gradient(135deg,#3B82F6,#06B6D4);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.nav-links{display:flex;gap:2px}
.nav-links a{color:rgba(255,255,255,.5);padding:8px 14px;border-radius:9px;font-size:13px;font-weight:500;transition:all .2s;display:flex;align-items:center;gap:7px}
.nav-links a:hover{background:rgba(255,255,255,.07);color:#fff}
.nav-links a.active{background:rgba(37,99,235,.2);color:var(--blue3);border:1px solid rgba(37,99,235,.3)}
.nav-right{display:flex;align-items:center;gap:8px}
.nav-user{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);padding:6px 14px 6px 8px;border-radius:9px;font-size:13px;color:rgba(255,255,255,.7)}
.nav-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--accent));display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;flex-shrink:0}
.nav-logout{color:rgba(255,255,255,.5);font-size:13px;padding:7px 14px;border:1px solid rgba(255,255,255,.1);border-radius:9px;transition:all .2s;font-weight:500;display:flex;align-items:center;gap:6px}
.nav-logout:hover{color:#fff;border-color:var(--red);background:rgba(239,68,68,.12)}

/* LAYOUT */
.main-content{margin-top:66px;padding:28px;max-width:1500px;margin-left:auto;margin-right:auto;padding-top:90px}
.page-header{margin-bottom:24px}
.page-header h2{font-size:24px;font-weight:800;color:var(--text);letter-spacing:-.5px}
.page-header p{color:var(--gray2);font-size:14px;margin-top:4px}

/* CARDS */
.card{background:#fff;border-radius:var(--radius);padding:24px;box-shadow:var(--card-shadow);margin-bottom:20px;border:1px solid rgba(0,0,0,.04);transition:box-shadow .2s,transform .2s}
.card:hover{box-shadow:var(--card-shadow-hover)}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding-bottom:14px;border-bottom:1px solid #F1F5F9}
.card-header h3{font-size:15px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}

/* TABLE */
.table{width:100%;border-collapse:collapse}
.table th{text-align:left;font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.8px;padding:10px 14px;border-bottom:2px solid var(--light);background:#FAFBFC;white-space:nowrap}
.table td{padding:13px 14px;border-bottom:1px solid #F8FAFC;font-size:14px;vertical-align:middle}
.table tr:last-child td{border-bottom:none}
.table tbody tr{transition:background .15s;cursor:pointer}
.table tbody tr:hover td{background:#F5F8FF}

/* BADGES */
.badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.3px;display:inline-flex;align-items:center;gap:4px;white-space:nowrap}
.badge::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;opacity:.7}
.badge-pending{background:#FEF3C7;color:#92400E}
.badge-outreach_sent{background:#DBEAFE;color:#1E40AF}
.badge-interview_started{background:#EDE9FE;color:#5B21B6}
.badge-interview_completed{background:#CFFAFE;color:#155E75}
.badge-shortlisted{background:#D1FAE5;color:#065F46}
.badge-rejected{background:#FEE2E2;color:#991B1B}
.badge-on_hold{background:#F1F5F9;color:#475569}
.badge-active{background:#D1FAE5;color:#065F46}
.badge-draft{background:#F1F5F9;color:#475569}
.badge-paused{background:#FEF3C7;color:#92400E}
.badge-pass{background:#D1FAE5;color:#065F46}
.badge-fail{background:#FEE2E2;color:#991B1B}

/* BUTTONS */
.btn-primary{background:linear-gradient(135deg,#1D4ED8,#3B82F6);color:#fff;padding:10px 22px;border-radius:var(--radius-sm);border:none;cursor:pointer;font-size:14px;font-weight:600;display:inline-flex;align-items:center;gap:7px;transition:all .2s;box-shadow:0 4px 14px rgba(37,99,235,.35);white-space:nowrap}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(37,99,235,.45);color:#fff}
.btn-primary-sm{background:linear-gradient(135deg,#1D4ED8,#3B82F6);color:#fff;padding:6px 14px;border-radius:8px;border:none;cursor:pointer;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:5px;transition:all .2s;box-shadow:0 2px 8px rgba(37,99,235,.25);white-space:nowrap}
.btn-primary-sm:hover{transform:translateY(-1px);color:#fff}
.btn-sm{color:var(--blue);padding:6px 14px;border-radius:8px;border:1.5px solid rgba(37,99,235,.25);font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:5px;background:rgba(37,99,235,.04);cursor:pointer;transition:all .2s;white-space:nowrap}
.btn-sm:hover{background:var(--blue);color:#fff;border-color:var(--blue)}
.btn-danger{background:linear-gradient(135deg,#DC2626,#EF4444);color:#fff;padding:7px 16px;border-radius:8px;border:none;cursor:pointer;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:5px;transition:all .2s;box-shadow:0 2px 8px rgba(239,68,68,.25)}
.btn-danger:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(239,68,68,.4)}
.btn-green{background:linear-gradient(135deg,#059669,#10B981);color:#fff;padding:8px 20px;border-radius:var(--radius-sm);border:none;cursor:pointer;font-size:14px;font-weight:600;display:inline-flex;align-items:center;gap:6px;transition:all .2s;box-shadow:0 2px 10px rgba(16,185,129,.3)}
.btn-green:hover{transform:translateY(-1px);box-shadow:0 4px 18px rgba(16,185,129,.45)}
.btn-outline{color:var(--gray2);padding:7px 16px;border-radius:8px;border:1.5px solid var(--light);font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:5px;background:#fff;cursor:pointer;transition:all .2s;white-space:nowrap}
.btn-outline:hover{border-color:var(--blue);color:var(--blue)}
.btn-purple{background:linear-gradient(135deg,#7C3AED,#8B5CF6);color:#fff;padding:8px 18px;border-radius:9px;border:none;cursor:pointer;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;transition:all .2s;box-shadow:0 2px 10px rgba(139,92,246,.3)}
.btn-purple:hover{transform:translateY(-1px);box-shadow:0 4px 18px rgba(139,92,246,.45)}

/* FORMS */
.form-group{margin-bottom:18px}
.form-label{display:block;font-size:13px;font-weight:600;color:var(--text);margin-bottom:6px}
.form-control{width:100%;padding:10px 14px;border:1.5px solid var(--light);border-radius:var(--radius-sm);font-size:14px;color:var(--text);outline:none;transition:all .2s;background:#fff;font-family:inherit}
.form-control:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
select.form-control{cursor:pointer}
textarea.form-control{resize:vertical;min-height:80px}

/* ALERTS */
.alert{padding:14px 18px;border-radius:var(--radius-sm);margin-bottom:16px;font-size:14px;font-weight:500;display:flex;align-items:center;gap:10px}
.alert-success{background:#ECFDF5;color:#065F46;border:1px solid #A7F3D0}
.alert-error{background:#FEF2F2;color:#991B1B;border:1px solid #FECACA}
.alert-info{background:#EFF6FF;color:#1E40AF;border:1px solid #BFDBFE}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(8,15,30,.75);backdrop-filter:blur(10px);z-index:2000;align-items:center;justify-content:center;padding:20px}
.modal-overlay.active{display:flex;animation:fadeIn .2s ease}
.modal{background:#fff;border-radius:20px;padding:32px;width:100%;max-width:580px;max-height:88vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.3);animation:slideUp .25s cubic-bezier(.4,0,.2,1)}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}
.modal-header h3{font-size:19px;font-weight:800;color:var(--text)}
.modal-close{background:#F1F5F9;border:none;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;color:var(--gray2);transition:all .2s}
.modal-close:hover{background:var(--red);color:#fff}

/* VIDEO PLAYER */
.video-player-wrap{background:#0D1B35;border-radius:14px;overflow:hidden;aspect-ratio:16/9;display:flex;align-items:center;justify-content:center;position:relative}
.video-player-wrap video{width:100%;height:100%;object-fit:contain}
.no-video{text-align:center;color:rgba(255,255,255,.4);padding:40px}
.no-video i{font-size:48px;margin-bottom:12px;display:block}

/* SCORE RING */
.score-ring{display:inline-flex;align-items:center;justify-content:center;width:80px;height:80px;border-radius:50%;font-size:22px;font-weight:900;position:relative}

/* GRID UTILS */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px}
.grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
@media(max-width:1100px){.grid-4{grid-template-columns:repeat(2,1fr)}.grid-3{grid-template-columns:1fr}}
@media(max-width:768px){.grid-2,.grid-3,.grid-4{grid-template-columns:1fr}.nav-links{display:none}.main-content{padding:16px;padding-top:80px}}

/* ANIMATIONS */
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes slideUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}
@keyframes countUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
@keyframes shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
.animate-in{animation:slideUp .35s cubic-bezier(.4,0,.2,1) both}
.animate-in:nth-child(1){animation-delay:.04s}
.animate-in:nth-child(2){animation-delay:.08s}
.animate-in:nth-child(3){animation-delay:.12s}
.animate-in:nth-child(4){animation-delay:.16s}
.animate-in:nth-child(5){animation-delay:.20s}
.animate-in:nth-child(6){animation-delay:.24s}
.animate-in:nth-child(7){animation-delay:.28s}
.animate-in:nth-child(8){animation-delay:.32s}
.skeleton{background:linear-gradient(90deg,#F1F5F9 25%,#E2E8F0 50%,#F1F5F9 75%);background-size:200% 100%;animation:shimmer 1.5s infinite;border-radius:6px}
</style>
