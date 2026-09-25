<?php
require_once 'config.php';
requireLogin();
$pageTitle = "Profile";
$activePage = "profile";
include "includes/header.php";
?>
<div class="app-shell">
    <?php include "includes/sidebar.php"; ?>
    <main class="main-content">
        <?php include "includes/topbar.php"; ?>
        <section class="page-content">
            <div class="page-header"><div><p class="eyebrow">Account settings</p><h1>Profile</h1><p class="muted">Manage your personal Campus Coin profile.</p></div></div>
<div class="profile-grid"><section class="panel profile-card"><div class="profile-avatar">AY</div><h2>Ayesha Khan</h2><p class="muted">Student account</p><div class="profile-info"><div><span>Email</span><strong>ayesha@example.com</strong></div><div><span>Currency</span><strong>PKR — Rs.</strong></div><div><span>Member since</span><strong>September 2026</strong></div></div></section><section class="panel form-panel"><h3>Personal information</h3><label>Full name<input type="text" value="Ayesha Khan"></label><label>Email address<input type="email" value="ayesha@example.com"></label><label>Monthly allowance<input type="number" value="420"></label><button class="primary-btn" onclick="showDemoMessage('Profile saved on the frontend. Connect this form to PHP/MySQL later.')">Save changes</button></section></div>
        </section>
        <?php include "includes/footer.php"; ?>
    </main>
</div>
