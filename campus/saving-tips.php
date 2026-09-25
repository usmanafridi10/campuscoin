<?php
require_once 'config.php';
requireLogin();
$pageTitle = "Saving Tips";
$activePage = "tips";
include "includes/header.php";
?>
<div class="app-shell">
    <?php include "includes/sidebar.php"; ?>
    <main class="main-content">
        <?php include "includes/topbar.php"; ?>
        <section class="page-content">
            <div class="page-header"><div><p class="eyebrow">Small habits, bigger balance</p><h1>Saving tips</h1><p class="muted">Simple ideas for managing student expenses.</p></div></div>
<div class="tips-grid"><article class="tip-panel"><span class="tip-number">01</span><h3>Use a weekly food limit</h3><p>Choose a realistic weekly amount for snacks, cafes and delivery.</p><button onclick="showDemoMessage('Tip saved.')">Save tip</button></article><article class="tip-panel"><span class="tip-number">02</span><h3>Track small purchases</h3><p>Small daily purchases can add up. Add them to Campus Coin regularly.</p><button onclick="showDemoMessage('Tip saved.')">Save tip</button></article><article class="tip-panel"><span class="tip-number">03</span><h3>Plan transport costs</h3><p>Estimate your weekly travel cost before spending your allowance.</p><button onclick="showDemoMessage('Tip saved.')">Save tip</button></article><article class="tip-panel"><span class="tip-number">04</span><h3>Keep a savings target</h3><p>Give yourself a clear target and watch your progress each month.</p><button onclick="showDemoMessage('Tip saved.')">Save tip</button></article></div>
        </section>
        <?php include "includes/footer.php"; ?>
    </main>
</div>
