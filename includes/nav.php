<?php $current = basename($_SERVER['PHP_SELF']); $initials = strtoupper(substr($user['name'] ?? 'A', 0, 1)); ?>
<nav class="navbar">
  <div class="nav-logo">
    <img src="https://www.avyukta.in/assets/images/logoo.png" alt="Avyukta" onerror="this.style.display='none'">
    <div class="nav-logo-text">Hire<span>AI</span></div>
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
