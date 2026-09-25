<?php
require_once 'config.php';
requireLogin();
$pageTitle = "Reports";
$activePage = "reports";
include "includes/header.php";
?>
<div class="app-shell">
    <?php include "includes/sidebar.php"; ?>

    <main class="main-content">
        <?php include "includes/topbar.php"; ?>

        <section class="page-content">
            <div class="page-header">
                <div>
                    <p class="eyebrow">Your money overview</p>
                    <h1>Reports</h1>
                    <p class="muted">Review income, expenses and spending patterns.</p>
                </div>
                <div class="button-row"><button class="outline-btn" type="button" onclick="exportTransactionsCSV()">Export CSV</button><button class="primary-btn" type="button" onclick="window.print()">Print report</button></div>
            </div>

            <div class="stats-row">
                <div class="stat-card"><span>Total income</span><strong id="reportIncome">Rs. 0.00</strong><small>From your saved transactions</small></div>
                <div class="stat-card"><span>Total expenses</span><strong id="reportExpense">Rs. 0.00</strong><small id="reportExpenseCount">0 transactions</small></div>
                <div class="stat-card"><span>Net balance</span><strong id="reportBalance">Rs. 0.00</strong><small id="reportSavings">0% of income saved</small></div>
            </div>

            <div class="content-grid">
                <section class="panel">
                    <div class="section-heading">
                        <h3>Monthly cash flow</h3>
                        <span>Last 6 months</span>
                    </div>
                    <canvas id="reportChart"></canvas>
                </section>

                <section class="panel">
                    <div class="section-heading">
                        <h3>Expense breakdown</h3>
                    </div>
                    <div class="breakdown" id="reportBreakdown"></div>
                </section>
            </div>
        </section>

        <?php include "includes/footer.php"; ?>
    </main>
</div>
<script>
    initializeReportsPage();
</script>
