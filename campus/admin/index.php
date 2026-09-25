<?php
require_once '../config.php';
requireAdmin();
$pageTitle = 'Admin Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> | Campus Coin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="admin-page">
    <div class="admin-shell">
        <aside class="admin-sidebar">
            <div class="brand">
                <div class="brand-mark">C</div>
                <div>
                    <strong>Campus Coin</strong>
                    <span>Admin Panel</span>
                </div>
            </div>

            <nav class="admin-nav">
                <a class="active" href="index.php"><span>⌂</span> Dashboard</a>
                <a href="#users"><span>♙</span> Users</a>
                <a href="#transactions"><span>↕</span> Transactions</a>
                <a href="#budgets"><span>◎</span> Budgets</a>
                <a href="#categories"><span>☷</span> Categories</a>
            </nav>

            <div class="admin-sidebar-bottom">
                <a href="../dashboard.php">← Student dashboard</a>
                <a href="logout.php">↪ Logout</a>
            </div>
        </aside>

        <main class="admin-main">
            <header class="admin-topbar">
                <div>
                    <p class="eyebrow">Campus Coin Administration</p>
                    <h1>Good evening, Admin 👋</h1>
                    <p class="muted">Monitor your student finance platform at a glance.</p>
                </div>
                <div class="admin-top-actions">
                    <span class="admin-date" id="adminDateTime"></span>
                    <button class="icon-btn" id="themeToggle" type="button" title="Toggle theme">◐</button>
                    <div class="admin-avatar">AD</div>
                </div>
            </header>

            <section class="admin-content">
                <div class="admin-stat-grid">
                    <article class="admin-stat-card">
                        <span>Total users</span>
                        <strong id="adminUserCount">1</strong>
                        <small>Registered profiles</small>
                    </article>
                    <article class="admin-stat-card">
                        <span>Current transactions</span>
                        <strong id="adminTransactionCount">0</strong>
                        <small>This month</small>
                    </article>
                    <article class="admin-stat-card">
                        <span>Income</span>
                        <strong id="adminIncomeTotal">Rs. 0</strong>
                        <small>This month</small>
                    </article>
                    <article class="admin-stat-card">
                        <span>Expenses</span>
                        <strong id="adminExpenseTotal">Rs. 0</strong>
                        <small>This month</small>
                    </article>
                </div>

                <div class="admin-panel-grid">
                    <section class="admin-panel" id="users">
                        <div class="section-heading">
                            <div>
                                <h2>Users</h2>
                                <span>Current local profiles</span>
                            </div>
                        </div>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead>
                                    <tr><th>Name</th><th>Email</th><th>Status</th></tr>
                                </thead>
                                <tbody id="adminUsersTable"></tbody>
                            </table>
                        </div>
                    </section>

                    <section class="admin-panel" id="transactions">
                        <div class="section-heading">
                            <div>
                                <h2>Recent transactions</h2>
                                <span>Latest activity</span>
                            </div>
                        </div>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead>
                                    <tr><th>Title</th><th>Type</th><th>Amount</th><th>Date</th></tr>
                                </thead>
                                <tbody id="adminTransactionsTable"></tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <div class="admin-panel-grid">
                    <section class="admin-panel" id="budgets">
                        <div class="section-heading">
                            <div>
                                <h2>Budget overview</h2>
                                <span>Current month</span>
                            </div>
                        </div>
                        <div class="admin-list" id="adminBudgetList"></div>
                    </section>

                    <section class="admin-panel" id="categories">
                        <div class="section-heading">
                            <div>
                                <h2>Categories</h2>
                                <span>Built-in and custom</span>
                            </div>
                        </div>
                        <div class="admin-category-cloud" id="adminCategoryCloud"></div>
                    </section>
                </div>

                <section class="admin-panel admin-note-panel">
                    <div>
                        <p class="eyebrow">Admin note</p>
                        <h2>Professional overview, without changing your student dashboard.</h2>
                        <p class="muted">This frontend-only admin panel reads the same browser data used by the current Campus Coin demo. A real multi-user admin system would require PHP + MySQL backend storage.</p>
                    </div>
                    <a class="outline-btn" href="../dashboard.php">Open student dashboard</a>
                </section>
            </section>
        </main>
    </div>
    <script src="../assets/js/main.js"></script>
    <script src="../assets/js/admin.js"></script>
</body>
</html>
