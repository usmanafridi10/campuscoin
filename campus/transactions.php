<?php
require_once 'config.php';
requireLogin();
$pageTitle = "Transactions";
$activePage = "transactions";
include "includes/header.php";
?>
<div class="app-shell">
    <?php include "includes/sidebar.php"; ?>

    <main class="main-content">
        <?php include "includes/topbar.php"; ?>

        <section class="page-content">
            <div class="page-header">
                <div>
                    <p class="eyebrow">Every rupee, organized</p>
                    <h1>Transactions</h1>
                    <p class="muted">Search, filter and edit your income and expenses.</p>
                </div>
                <button class="primary-btn" type="button" onclick="openTransactionModal('expense')">＋ Add transaction</button>
            </div>

            <div class="filter-bar panel">
                <input type="search" id="transactionSearch" placeholder="Search transactions...">
                <select id="transactionFilterType">
                    <option value="all">All types</option>
                    <option value="income">Income</option>
                    <option value="expense">Expense</option>
                </select>
                <select id="transactionFilterCategory">
                    <option value="all">All categories</option>
                </select>
                <button class="outline-btn" type="button" onclick="clearTransactionFilters()">Clear</button>
            </div>

            <section class="panel">
                <div class="section-heading">
                    <h3>All transactions</h3>
                    <span id="transactionCountLabel">0 transactions</span>
                </div>
                <div class="full-transaction-list" id="fullTransactionList"></div>
            </section>

            <section class="panel recurring-panel">
                <div class="section-heading">
                    <div>
                        <h3>Recurring transactions</h3>
                        <p class="muted">Plan regular income or expenses for the month.</p>
                    </div>
                    <button class="outline-btn" type="button" onclick="openRecurringModal()">＋ Add recurring</button>
                </div>
                <div id="recurringList" class="recurring-list"></div>
            </section>
        </section>

        <div class="transaction-modal" id="transactionModal" aria-hidden="true">
            <div class="transaction-modal-box">
                <button class="modal-close" type="button" onclick="closeTransactionModal()">×</button>
                <p class="eyebrow" id="transactionModalEyebrow">New transaction</p>
                <h2 id="transactionModalTitle">Add expense</h2>
                <p class="muted">Enter the transaction details below.</p>

                <form id="transactionForm">
                    <input type="hidden" id="transactionId" value="">
                    <input type="hidden" id="transactionType" value="expense">

                    <label>
                        Title
                        <input type="text" id="transactionTitle" placeholder="e.g. Lunch at campus cafe" required>
                    </label>

                    <label>
                        Amount (PKR)
                        <input type="number" id="transactionAmount" min="1" step="0.01" placeholder="0.00" required>
                    </label>

                    <label>
                        Category
                        <select id="transactionCategory" required></select>
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
                        <button class="cancel-btn" type="button" onclick="closeTransactionModal()"><span class="cancel-icon">×</span>Cancel</button>
                        <button class="primary-btn full-btn" type="submit">Save transaction</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="transaction-modal" id="recurringModal" aria-hidden="true">
            <div class="transaction-modal-box">
                <button class="modal-close" type="button" onclick="closeRecurringModal()">×</button>
                <p class="eyebrow">Monthly planning</p>
                <h2>Add recurring transaction</h2>
                <p class="muted">Create a simple monthly reminder for regular money movement.</p>
                <form id="recurringForm">
                    <label>Title<input type="text" id="recurringTitle" placeholder="e.g. Monthly allowance" required></label>
                    <label>Amount (PKR)<input type="number" id="recurringAmount" min="1" step="0.01" required></label>
                    <label>Type<select id="recurringType"><option value="income">Income</option><option value="expense">Expense</option></select></label>
                    <div class="transaction-form-actions">
                        <button class="cancel-btn" type="button" onclick="closeRecurringModal()"><span class="cancel-icon">×</span>Cancel</button>
                        <button class="primary-btn full-btn" type="submit">Save recurring</button>
                    </div>
                </form>
            </div>
        </div>

        <?php include "includes/footer.php"; ?>
    </main>
</div>
<script>
    initializeTransactionsPage();
</script>
