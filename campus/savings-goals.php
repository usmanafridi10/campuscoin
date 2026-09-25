<?php
require_once 'config.php';
requireLogin();
$pageTitle = "Savings Goals";
$activePage = "goals";
include "includes/header.php";
?>
<div class="app-shell">
    <?php include "includes/sidebar.php"; ?>
    <main class="main-content">
        <?php include "includes/topbar.php"; ?>
        <section class="page-content">
            <div class="page-header">
                <div>
                    <p class="eyebrow">Save with a target</p>
                    <h1>Savings Goals</h1>
                    <p class="muted">Create goals and watch your progress grow.</p>
                </div>
                <button class="primary-btn" type="button" onclick="openGoalModal()">＋ New goal</button>
            </div>
            <div class="goal-grid" id="goalGrid"></div>
        </section>

        <div class="transaction-modal" id="goalModal" aria-hidden="true">
            <div class="transaction-modal-box">
                <button class="modal-close" type="button" onclick="closeGoalModal()">×</button>
                <p class="eyebrow">Savings plan</p>
                <h2 id="goalModalTitle">Add savings goal</h2>
                <form id="goalForm">
                    <input type="hidden" id="goalId">
                    <label>Goal name<input type="text" id="goalName" placeholder="e.g. New laptop" required></label>
                    <label>Target amount (PKR)<input type="number" id="goalTarget" min="1" step="0.01" required></label>
                    <label>Amount saved (PKR)<input type="number" id="goalSaved" min="0" step="1" required></label>
                    <label id="goalAddAmountWrap" style="display:none;">Add amount (PKR)<input type="number" id="goalAddAmount" min="1" step="1" placeholder="e.g. 400"></label>
                    <div class="transaction-form-actions">
                        <button class="cancel-btn" type="button" onclick="closeGoalModal()"><span class="cancel-icon">×</span>Cancel</button>
                        <button class="primary-btn full-btn" type="submit">Save goal</button>
                    </div>
                </form>
            </div>
        </div>
        <?php include "includes/footer.php"; ?>
    </main>
</div>
<script>
    initializeGoalsPage();
</script>
