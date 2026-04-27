<?php $current = basename($_SERVER['PHP_SELF']); $initials = strtoupper(substr($user['name'] ?? 'A', 0, 1)); ?>
<nav class="navbar">
  <div class="nav-logo">
    <div class="nav-logo-icon">
      <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
        <rect width="32" height="32" rx="8" fill="url(#av-grad)"/>
        <text x="16" y="22" font-family="Arial Black" font-size="14" font-weight="900" fill="white" text-anchor="middle">A</text>
        <defs>
          <linearGradient id="av-grad" x1="0" y1="0" x2="32" y2="32">
            <stop offset="0%" stop-color="#6B21A8"/>
            <stop offset="100%" stop-color="#2563EB"/>
          </linearGradient>
        </defs>
      </svg>
    </div>
    <div class="nav-logo-text">
      <span style="color:#A78BFA;font-weight:800">Hire</span><span style="color:#fff;font-weight:800">AI</span>
      <div style="font-size:9px;color:#6B7280;font-weight:500;letter-spacing:0.5px;line-height:1">by Avyukta Intellicall</div>
    </div>
  </div>
  <div class="nav-links">
    <a href="/dashboard.php" class="<?= $current==='dashboard.php'?'active':'' ?>"><i class="fa-solid fa-gauge-high fa-sm"></i> Dashboard</a>
    <a href="/campaigns.php" class="<?= $current==='campaigns.php'?'active':'' ?>"><i class="fa-solid fa-rocket fa-sm"></i> Campaigns</a>
    <a href="/candidates.php" class="<?= $current==='candidates.php'?'active':'' ?>"><i class="fa-solid fa-users fa-sm"></i> Candidates</a>
    <a href="/outreach.php" class="<?= $current==='outreach.php'?'active':'' ?>"><i class="fa-solid fa-paper-plane fa-sm"></i> Outreach</a>
  </div>
  <div class="nav-right">
    <div class="nav-user">
      <div class="nav-avatar"><?= htmlspecialchars($initials) ?></div>
      <span><?= htmlspecialchars($user['name'] ?? 'Admin') ?></span>
    </div>
    <a href="/logout.php" class="nav-logout"><i class="fa-solid fa-right-from-bracket fa-sm"></i> Logout</a>
  </div>
</nav>
