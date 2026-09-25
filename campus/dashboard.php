<?php
require_once 'config.php';
requireLogin();
$pageTitle = "Dashboard";
$activePage = "dashboard";
include "includes/header.php";
?>
<div class="app-shell">
    <?php include "includes/sidebar.php"; ?>

    <main class="main-content">
        <?php include "includes/topbar.php"; ?>

        <section class="page-content">
            <div class="welcome-row">
                <div>
                    <p class="eyebrow">Campus Coin Dashboard</p>
                    <h1 id="welcomeName">Good evening</h1>
                    <p class="muted" id="welcomeSummary">Here’s how your month is looking so far.</p>
                </div>
                <a href="#quick-actions" class="text-link">View monthly summary →</a>
            </div>

            <div class="dashboard-alert-row">
                <section class="alert-card">
                    <div class="section-heading">
                        <h3>Budget alerts</h3>
                        <span>Smart reminders</span>
                    </div>
                    <div id="budgetAlerts"></div>
                </section>
                <section class="alert-card">
                    <div class="section-heading">
                        <h3>Monthly comparison</h3>
                        <span>Current vs previous</span>
                    </div>
                    <p class="comparison-text" id="monthlyComparison">Loading your monthly comparison...</p>
                </section>
            </div>

            <div class="dashboard-grid">
                <section class="balance-card">
                    <div class="balance-content">
                        <p>This month's balance</p>
                        <h2 id="balanceAmount">Rs. 248.50</h2>
                        <div class="money-stats">
                            <div>
                                <span>↑ Income</span>
                                <strong id="incomeAmount">Rs. 420.00</strong>
                            </div>
                            <div>
                                <span>↓ Expenses</span>
                                <strong id="expenseAmount">Rs. 171.50</strong>
                            </div>
                        </div>
                        <div class="quick-actions" id="quick-actions">
                            <button class="primary-btn" type="button" onclick="openTransactionModal('income')">＋ Add income</button>
                            <button class="outline-btn" type="button" onclick="openTransactionModal('expense')">＋ Add expense</button>
                        </div>
                    </div>
                </section>

                <aside class="tip-card" id="dashboardSavingTip">
                    <div class="card-heading">
                        <span class="mini-icon">☆</span>
                        <span>Saving tip</span>
                        <span class="tip-pinned-badge" id="tipPinnedBadge" hidden>📌 Pinned</span>
                    </div>
                    <p>Food delivery spending rose 40% compared to your usual average this month.</p>
                    <div class="tip-actions">
                        <button type="button" id="pinTipButton" onclick="pinSavingTip()">Pin this tip</button>
                        <button type="button" id="dismissTipButton" onclick="dismissSavingTip()">Dismiss</button>
                    </div>
                </aside>

                <section class="small-card">
                    <p class="card-label">Daily spending limit</p>
                    <div class="category-highlight">
                        <div class="category-icon food">₨</div>
                        <div>
                            <h3 id="dailyLimitText">Rs. 0.00</h3>
                            <p id="dailyLimitStatus">Set your daily spending limit.</p><button class="text-link" type="button" onclick="setDailyLimit()">Set limit</button>
                        </div>
                    </div>
                </section>

                <section class="small-card">
                    <p class="card-label">Top category this month</p>
                    <div class="category-highlight">
                        <div class="category-icon food">♧</div>
                        <div>
                            <h3>Food</h3>
                            <p>Rs. 62 spent · 6 orders</p>
                        </div>
                    </div>
                </section>

                <section class="small-card">
                    <div class="budget-title">
                        <div>
                            <p class="card-label">Budget vs actual — Food</p>
                            <strong>Rs. 62 spent</strong>
                        </div>
                        <span>Rs. 90 limit</span>
                    </div>
                    <div class="progress-track">
                        <span style="width: 69%;"></span>
                    </div>
                </section>

                <section class="transactions-card">
                    <div class="section-heading">
                        <h3>Recent transactions</h3>
                        <a href="reports.php">View all</a>
                    </div>
                    <div class="transaction-list" id="transactionList"></div>
                </section>

                <aside class="side-stack">
                    <section class="spending-card">
                        <div class="section-heading">
                            <h3>Spending by category</h3>
                        </div>
                        <div class="legend-list">
                            <div><span class="dot food-dot"></span>Food <b>Rs. 62.00</b></div>
                            <div><span class="dot rent-dot"></span>Hostel/Rent <b>Rs. 45.00</b></div>
                            <div><span class="dot transport-dot"></span>Transport <b>Rs. 28.00</b></div>
                            <div><span class="dot academic-dot"></span>Academics <b>Rs. 21.50</b></div>
                            <div><span class="dot sub-dot"></span>Subscriptions <b>Rs. 15.00</b></div>
                        </div>
                    </section>

                    <section class="chart-card">
                        <div class="section-heading">
                            <h3>Income vs expense — 6 months</h3>
                        </div>
                        <canvas id="incomeExpenseChart"></canvas>
                    </section>
                </aside>
            </div>
        </section>

        <div class="transaction-modal" id="transactionModal" aria-hidden="true">
            <div class="transaction-modal-box">
                <button class="modal-close" type="button" onclick="closeTransactionModal()">×</button>
                <p class="eyebrow" id="transactionModalEyebrow">New transaction</p>
                <h2 id="transactionModalTitle">Add income</h2>
                <p class="muted">Enter the transaction details below.</p>

                <form id="transactionForm">
                    <input type="hidden" id="transactionId" value="">
                    <input type="hidden" id="transactionType" value="income">

                    <label>
                        Title
                        <input type="text" id="transactionTitle" placeholder="e.g. Monthly allowance" required>
                    </label>

                    <label>
                        Amount (PKR)
                        <input type="number" id="transactionAmount" min="1" step="0.01" placeholder="0.00" required>
                    </label>

                    <label>
                        Category
                        <select id="transactionCategory" required>
                        </select>
                    </label>

                    <div class="form-grid-two">
                        <label>
                            Date
                            <input type="date" id="transactionDate" required>
                        </label>

                        <label>
                            Time
                            <input type="time" id="transactionTime" required>
                        </label>
                    </div>

                    <div class="transaction-form-actions">
                        <button class="cancel-btn" type="button" onclick="closeTransactionModal()">
                            <span class="cancel-icon">×</span>
                            Cancel
                        </button>
                        <button class="primary-btn full-btn" id="transactionSaveButton" type="submit">Save transaction</button>
                    </div>
                </form>
            </div>
        </div>

        <?php include "includes/footer.php"; ?>
    </main>
</div>
<script>
    initializeDashboard();
</script>
