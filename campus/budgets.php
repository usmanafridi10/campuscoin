<?php
require_once 'config.php';
requireLogin();
$pageTitle = "Budgets";
$activePage = "budgets";
include "includes/header.php";
?>
<div class="app-shell">
    <?php include "includes/sidebar.php"; ?>

    <main class="main-content">
        <?php include "includes/topbar.php"; ?>

        <section class="page-content">
            <div class="page-header">
                <div>
                    <p class="eyebrow">Plan before you spend</p>
                    <h1>Budgets</h1>
                    <p class="muted">Set monthly limits and keep track of your spending.</p>
                </div>
                <button class="primary-btn" type="button" onclick="openBudgetModal()">＋ New budget</button>
            </div>

            <div class="budget-grid" id="budgetGrid"></div>
        </section>

        <div class="transaction-modal" id="budgetModal" aria-hidden="true">
            <div class="transaction-modal-box budget-modal-box">
                <button class="modal-close" type="button" onclick="closeBudgetModal()">×</button>

                <p class="eyebrow" id="budgetModalEyebrow">Monthly budget</p>
                <h2 id="budgetModalTitle">Add budget</h2>
                <p class="muted">Set a spending limit for one category.</p>

                <form id="budgetForm">
                    <input type="hidden" id="budgetId">

                    <label>
                        Category
                        <select id="budgetCategory" required></select>
                    </label>

                    <label>
                        Monthly limit (PKR)
                        <input type="number" id="budgetAmount" min="1" step="0.01" placeholder="e.g. 5000" required>
                    </label>

                    <div class="transaction-form-actions">
                        <button class="cancel-btn" type="button" onclick="closeBudgetModal()">
                            <span class="cancel-icon">×</span>
                            Cancel
                        </button>
                        <button class="primary-btn full-btn" type="submit">Save budget</button>
                    </div>
                </form>
            </div>
        </div>

        <?php include "includes/footer.php"; ?>
    </main>
</div>
<script>
    initializeBudgetsPage();
</script>
