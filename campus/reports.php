<?php
/**
 * CampusCoin - Monthly Reports & Financial Analytics (Module 2)
 *
 * Requirements:
 * 2.1 Category-wise monthly report: Total spending per category for selected month (pie/donut chart + table).
 * 2.2 Income vs. expense report: Last six months, grouped bar/line chart (income, expense, net savings).
 * 2.3 Daily and weekly summaries: Daily spending timeline and weekly totals (line/bar chart + progress tracks).
 * 2.4 Filters: Date range (from/to), category, and type/source. Filters apply across all charts and tables.
 * 2.5 Export: Export report as PDF (jsPDF) or PNG image (html2canvas), plus print view.
 */

require_once 'config.php';
requireLogin();

$user = currentUser();
if (!$user) {
    header('Location: logout.php');
    exit;
}

$userId = currentUserId();
$pdo = getDbConnection();

// Fetch categories for filter dropdown
$catStmt = $pdo->prepare("
    SELECT category_id, name, type, icon 
    FROM categories 
    WHERE is_default = 1 OR user_id = :uid 
    ORDER BY type ASC, name ASC
");
$catStmt->execute([':uid' => $userId]);
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

// Initial filter dates (current month by default)
$defaultFrom = date('Y-m-01');
$defaultTo   = date('Y-m-t');

$pageTitle = "Monthly Reports & Analytics";
$activePage = "reports";
include "includes/header.php";
?>

<!-- HTML2Canvas & jsPDF for Client-side PDF/PNG Export (Requirement 2.5) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<div class="app-shell">
    <?php include "includes/sidebar.php"; ?>

    <main class="main-content">
        <?php include "includes/topbar.php"; ?>

        <section class="page-content" id="reportContentArea">
            <nav class="breadcrumbs" aria-label="Breadcrumb">
                <a href="index.php">Home</a>
                <span class="bc-sep">/</span>
                <span class="bc-current" aria-current="page">Reports & Analytics</span>
            </nav>

            <div class="page-header" style="flex-wrap: wrap; gap: 16px;">
                <div>
                    <p class="eyebrow">Financial Intelligence</p>
                    <h1>Monthly Reports & Analytics</h1>
                    <p class="muted">Visualize your spending distribution, 6-month cash flow trends, and daily spending velocity.</p>
                </div>
                <div class="header-action-group" style="display:flex; flex-wrap:wrap; gap:8px;">
                    <!-- Requirement 2.5: Export as PDF, CSV, or Image -->
                    <button class="outline-btn" type="button" id="btnExportCsv" onclick="exportReportCSV()" style="display:inline-flex; align-items:center; gap:6px;">
                        📊 Export CSV
                    </button>
                    <button class="outline-btn" type="button" id="btnExportPdf" onclick="exportReportPDF()" style="display:inline-flex; align-items:center; gap:6px;">
                        📄 Export PDF
                    </button>
                    <button class="outline-btn" type="button" id="btnExportPng" onclick="exportReportPNG()" style="display:inline-flex; align-items:center; gap:6px;">
                        🖼️ Export PNG
                    </button>
                    <button class="primary-btn" type="button" onclick="window.print()" style="display:inline-flex; align-items:center; gap:6px;">
                        🖨️ Print
                    </button>
                </div>
            </div>

            <!-- ========================================================= -->
            <!-- 2.4 UNIVERSAL FILTERS BAR                                 -->
            <!-- ========================================================= -->
            <form id="reportFilterForm" class="filter-bar panel" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; padding:16px 20px; margin-bottom:20px;">
                <div class="filter-item" style="display:flex; flex-direction:column; gap:4px;">
                    <label for="reportDateFrom" style="font-size:12px; font-weight:600; color:var(--muted);">From Date</label>
                    <input type="date" id="reportDateFrom" name="date_from" value="<?php echo e($defaultFrom); ?>" style="padding:6px 10px; border-radius:6px; border:1px solid var(--line); font-size:13px;">
                </div>

                <div class="filter-item" style="display:flex; flex-direction:column; gap:4px;">
                    <label for="reportDateTo" style="font-size:12px; font-weight:600; color:var(--muted);">To Date</label>
                    <input type="date" id="reportDateTo" name="date_to" value="<?php echo e($defaultTo); ?>" style="padding:6px 10px; border-radius:6px; border:1px solid var(--line); font-size:13px;">
                </div>

                <div class="filter-item" style="display:flex; flex-direction:column; gap:4px; min-width:160px;">
                    <label for="reportCategory" style="font-size:12px; font-weight:600; color:var(--muted);">Category</label>
                    <select id="reportCategory" name="category_id" style="padding:6px 10px; border-radius:6px; border:1px solid var(--line); font-size:13px;">
                        <option value="all">All Categories</option>
                        <optgroup label="Expenses">
                            <?php foreach ($categories as $cat): ?>
                                <?php if ($cat['type'] === 'expense'): ?>
                                    <option value="<?php echo $cat['category_id']; ?>">
                                        <?php echo e($cat['icon'] . ' ' . $cat['name']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Income">
                            <?php foreach ($categories as $cat): ?>
                                <?php if ($cat['type'] === 'income'): ?>
                                    <option value="<?php echo $cat['category_id']; ?>">
                                        <?php echo e($cat['icon'] . ' ' . $cat['name']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <div class="filter-item" style="display:flex; flex-direction:column; gap:4px;">
                    <label for="reportType" style="font-size:12px; font-weight:600; color:var(--muted);">Flow Type</label>
                    <select id="reportType" name="type" style="padding:6px 10px; border-radius:6px; border:1px solid var(--line); font-size:13px;">
                        <option value="all">All (Income & Expenses)</option>
                        <option value="expense">Expenses Only</option>
                        <option value="income">Income Only</option>
                    </select>
                </div>

                <!-- Quick Presets -->
                <div class="filter-item" style="display:flex; gap:6px;">
                    <button type="button" class="outline-btn preset-btn" data-preset="this_month" style="font-size:12px; padding:6px 10px;">This Month</button>
                    <button type="button" class="outline-btn preset-btn" data-preset="last_month" style="font-size:12px; padding:6px 10px;">Last Month</button>
                    <button type="button" class="outline-btn preset-btn" data-preset="last_6m" style="font-size:12px; padding:6px 10px;">Last 6 Mos</button>
                </div>

                <div class="filter-actions-inline" style="display:flex; gap:8px;">
                    <button class="primary-btn" type="submit" style="padding:6px 16px; font-size:13px;">Apply Filter</button>
                    <button class="outline-btn" type="button" onclick="resetReportFilters()" style="padding:6px 12px; font-size:13px;">Reset</button>
                </div>
            </form>

            <!-- Loading Spinner Indicator -->
            <div id="reportLoadingIndicator" style="display:none; text-align:center; padding:20px; font-size:14px; color:var(--accent);">
                <span>⌛ Refreshing analytics data...</span>
            </div>

            <!-- ========================================================= -->
            <!-- KPI CARDS SUMMARY                                         -->
            <!-- ========================================================= -->
            <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 20px;">
                <div class="metric-card" style="border-left: 4px solid #16a34a;">
                    <span class="metric-title">Total Income</span>
                    <strong class="metric-value" id="kpiIncome" style="color:#16a34a;">Rs. 0.00</strong>
                    <span class="metric-subtitle" id="kpiIncomeCount">0 transactions</span>
                </div>

                <div class="metric-card" style="border-left: 4px solid #dc2626;">
                    <span class="metric-title">Total Expenses</span>
                    <strong class="metric-value" id="kpiExpense" style="color:#dc2626;">Rs. 0.00</strong>
                    <span class="metric-subtitle" id="kpiExpenseCount">0 transactions</span>
                </div>

                <div class="metric-card" style="border-left: 4px solid var(--accent);">
                    <span class="metric-title">Net Balance</span>
                    <strong class="metric-value" id="kpiBalance">Rs. 0.00</strong>
                    <span class="metric-subtitle" id="kpiSavingsRate">0% savings rate</span>
                </div>

                <div class="metric-card" style="border-left: 4px solid #d97706;">
                    <span class="metric-title">Daily Average Spend</span>
                    <strong class="metric-value" id="kpiDailyAvg">Rs. 0.00</strong>
                    <span class="metric-subtitle" id="kpiDaysRange">Across 30 days</span>
                </div>
            </div>

            <!-- ========================================================= -->
            <!-- 2.3 DAILY & WEEKLY SUMMARIES (Requirement 2.3)             -->
            <!-- ========================================================= -->
            <div class="report-summary-cards" style="display:grid; grid-template-columns: 2fr 1.2fr; gap:20px; margin-bottom:24px;">
                <!-- Daily Spending Timeline Chart -->
                <section class="panel">
                    <div class="section-heading" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                        <div>
                            <h3 style="margin:0; font-size:16px;">Daily Spending Pace</h3>
                            <span class="muted" style="font-size:12px;">Daily expense timeline for the selected range</span>
                        </div>
                        <div style="display:flex; gap:12px; font-size:12px;">
                            <span>Today: <strong id="todaySpendDisplay">Rs. 0.00</strong></span>
                            <span>Peak Day: <strong id="highestDayDisplay">Rs. 0.00</strong></span>
                        </div>
                    </div>
                    <div style="height: 220px; position: relative;">
                        <canvas id="dailySpendingChart"></canvas>
                    </div>
                </section>

                <!-- Weekly Spending Breakdown Buckets -->
                <section class="panel">
                    <div class="section-heading" style="margin-bottom:14px;">
                        <h3 style="margin:0; font-size:16px;">Weekly Distribution</h3>
                        <span class="muted" style="font-size:12px;">Spending pace by month quartile (Weeks 1 to 4+)</span>
                    </div>
                    <div class="weekly-progress-grid" id="weeklySpendingGrid" style="display:flex; flex-direction:column; gap:12px;">
                        <!-- Injected via JavaScript -->
                    </div>
                </section>
            </div>

            <!-- ========================================================= -->
            <!-- 2.1 & 2.2 CHARTS & CATEGORY ANALYTICS                     -->
            <!-- ========================================================= -->
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:24px;">
                <!-- 2.2 Income vs. Expense 6-Month Trend Chart -->
                <section class="panel">
                    <div class="section-heading" style="margin-bottom:14px;">
                        <h3 style="margin:0; font-size:16px;">6-Month Income vs. Expense & Net Savings</h3>
                        <span class="muted" style="font-size:12px;">Cash flow trends and net savings comparison</span>
                    </div>
                    <div style="height: 300px; position: relative;">
                        <canvas id="sixMonthCashFlowChart"></canvas>
                    </div>
                </section>

                <!-- 2.1 Category-wise Donut Chart -->
                <section class="panel">
                    <div class="section-heading" style="margin-bottom:14px;">
                        <h3 style="margin:0; font-size:16px;">Category Spending Distribution</h3>
                        <span class="muted" style="font-size:12px;">Proportion of expenses by category</span>
                    </div>
                    <div style="height: 300px; position: relative; display:flex; justify-content:center;">
                        <canvas id="categoryDonutChart"></canvas>
                    </div>
                </section>
            </div>

            <!-- 2.1 Category-wise Breakdown Table -->
            <section class="panel" style="padding:0; overflow:hidden; margin-bottom:24px;">
                <div style="padding:16px 20px; border-bottom:1px solid var(--line); display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0; font-size:16px;">Category-Wise Summary Table</h3>
                    <span class="badge" id="categoryTableSummaryBadge" style="background:#eef6f5; color:var(--accent); font-weight:700; padding:4px 10px; border-radius:12px; font-size:12px;">
                        0 categories
                    </span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="data-table" id="categoryReportTable" style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr style="background:#f8fafc; border-bottom:1px solid var(--line); text-align:left;">
                                <th style="padding:12px 16px;">Category</th>
                                <th style="padding:12px 16px;">Type</th>
                                <th style="padding:12px 16px;">Transactions</th>
                                <th style="padding:12px 16px;">Total Amount</th>
                                <th style="padding:12px 16px; min-width:160px;">Share of Spending</th>
                            </tr>
                        </thead>
                        <tbody id="categoryTableBody">
                            <tr>
                                <td colspan="5" style="text-align:center; padding:24px; color:var(--muted);">
                                    Loading category breakdown...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <?php include "includes/footer.php"; ?>
    </main>
</div>

<script>
// Global Chart instances
let sixMonthChartInstance = null;
let categoryDonutChartInstance = null;
let dailyChartInstance = null;

// Palette for Category Donut Chart
const chartColors = [
    '#0e7490', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6',
    '#ec4899', '#3b82f6', '#14b8a6', '#f97316', '#6366f1',
    '#84cc16', '#a855f7'
];

/**
 * Loads report data from /api/reports.php and updates all widgets & charts.
 */
function fetchAndRenderReports() {
    const from = document.getElementById("reportDateFrom").value;
    const to = document.getElementById("reportDateTo").value;
    const cat = document.getElementById("reportCategory").value;
    const type = document.getElementById("reportType").value;

    const loadingIndicator = document.getElementById("reportLoadingIndicator");
    if (loadingIndicator) loadingIndicator.style.display = "block";

    const queryParams = new URLSearchParams({
        date_from: from,
        date_to: to,
        category_id: cat,
        type: type
    });

    fetch(`api/reports.php?${queryParams.toString()}`)
        .then(res => {
            if (!res.ok) throw new Error("Network error fetching report data");
            return res.json();
        })
        .then(data => {
            if (loadingIndicator) loadingIndicator.style.display = "none";
            if (!data.success) {
                alert("Failed to load report: " + (data.error || "Unknown error"));
                return;
            }

            renderKpis(data.kpis);
            renderWeeklyBuckets(data.weekly_buckets);
            renderDailyChart(data.daily_timeline);
            renderSixMonthChart(data.six_month_trend);
            renderCategoryDonut(data.category_breakdown);
            renderCategoryTable(data.category_breakdown);
        })
        .catch(err => {
            if (loadingIndicator) loadingIndicator.style.display = "none";
            console.error("Report fetch failed:", err);
        });
}

function renderKpis(kpis) {
    document.getElementById("kpiIncome").textContent = "Rs. " + Number(kpis.total_income).toLocaleString("en-PK", {minimumFractionDigits: 2});
    document.getElementById("kpiIncomeCount").textContent = kpis.income_count + " transactions";

    document.getElementById("kpiExpense").textContent = "Rs. " + Number(kpis.total_expense).toLocaleString("en-PK", {minimumFractionDigits: 2});
    document.getElementById("kpiExpenseCount").textContent = kpis.expense_count + " transactions";

    const balElem = document.getElementById("kpiBalance");
    balElem.textContent = "Rs. " + Number(kpis.net_balance).toLocaleString("en-PK", {minimumFractionDigits: 2});
    balElem.style.color = kpis.net_balance >= 0 ? "#16a34a" : "#dc2626";

    document.getElementById("kpiSavingsRate").textContent = kpis.savings_rate + "% savings rate";
    document.getElementById("kpiDailyAvg").textContent = "Rs. " + Number(kpis.daily_average).toLocaleString("en-PK", {minimumFractionDigits: 2});
    document.getElementById("kpiDaysRange").textContent = `Across ${kpis.days_in_range} days in filter`;

    document.getElementById("todaySpendDisplay").textContent = "Rs. " + Number(kpis.today_spend).toLocaleString("en-PK", {minimumFractionDigits: 2});
    document.getElementById("highestDayDisplay").textContent = `Rs. ${Number(kpis.highest_day_spend).toLocaleString("en-PK", {minimumFractionDigits: 2})} (${kpis.highest_day_date})`;
}

function renderWeeklyBuckets(buckets) {
    const container = document.getElementById("weeklySpendingGrid");
    if (!container) return;
    container.innerHTML = "";

    Object.entries(buckets).forEach(([label, info]) => {
        const item = document.createElement("div");
        item.style.display = "flex";
        item.style.flexDirection = "column";
        item.style.gap = "4px";

        item.innerHTML = `
            <div style="display:flex; justify-content:space-between; font-size:13px;">
                <span style="font-weight:600; color:var(--ink);">${label}</span>
                <strong>Rs. ${Number(info.expense).toLocaleString("en-PK", {minimumFractionDigits: 2})} <span class="muted" style="font-weight:400; font-size:12px;">(${info.pct}%)</span></strong>
            </div>
            <div class="progress-track" style="background:#edf2f7; height:8px; border-radius:4px; overflow:hidden;">
                <div style="width:${Math.min(100, Math.max(3, info.pct))}%; background:var(--accent); height:100%; border-radius:4px; transition:width 0.4s ease;"></div>
            </div>
        `;
        container.appendChild(item);
    });
}

function renderDailyChart(timeline) {
    const ctx = document.getElementById("dailySpendingChart")?.getContext("2d");
    if (!ctx) return;

    if (dailyChartInstance) dailyChartInstance.destroy();

    const labels = timeline.map(t => t.day_label);
    const data = timeline.map(t => t.expense_amount);

    dailyChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Daily Spend (PKR)',
                data: data,
                borderColor: '#0e7490',
                backgroundColor: 'rgba(14, 116, 144, 0.1)',
                borderWidth: 2.5,
                fill: true,
                tension: 0.35,
                pointRadius: 3,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ` Rs. ${Number(ctx.raw).toLocaleString('en-PK', {minimumFractionDigits: 2})}`
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: val => 'Rs. ' + val
                    }
                }
            }
        }
    });
}

function renderSixMonthChart(history) {
    const ctx = document.getElementById("sixMonthCashFlowChart")?.getContext("2d");
    if (!ctx) return;

    if (sixMonthChartInstance) sixMonthChartInstance.destroy();

    const labels = history.map(h => h.month_label);
    const incomeData = history.map(h => h.income);
    const expenseData = history.map(h => h.expense);
    const netSavingsData = history.map(h => h.net_savings);

    sixMonthChartInstance = new Chart(ctx, {
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Income',
                    data: incomeData,
                    backgroundColor: '#10b981',
                    borderRadius: 4,
                    barPercentage: 0.6
                },
                {
                    type: 'bar',
                    label: 'Expenses',
                    data: expenseData,
                    backgroundColor: '#ef4444',
                    borderRadius: 4,
                    barPercentage: 0.6
                },
                {
                    type: 'line',
                    label: 'Net Savings',
                    data: netSavingsData,
                    borderColor: '#0e7490',
                    borderWidth: 3,
                    fill: false,
                    tension: 0.3,
                    pointBackgroundColor: '#0e7490'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.dataset.label}: Rs. ${Number(ctx.raw).toLocaleString('en-PK', {minimumFractionDigits: 2})}`
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        callback: val => 'Rs. ' + val
                    }
                }
            }
        }
    });
}

function renderCategoryDonut(breakdown) {
    const ctx = document.getElementById("categoryDonutChart")?.getContext("2d");
    if (!ctx) return;

    if (categoryDonutChartInstance) categoryDonutChartInstance.destroy();

    if (breakdown.length === 0) {
        ctx.clearRect(0, 0, 300, 300);
        return;
    }

    const labels = breakdown.map(b => b.name);
    const data = breakdown.map(b => b.amount);
    const colors = breakdown.map((_, i) => chartColors[i % chartColors.length]);

    categoryDonutChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: colors,
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, font: { size: 12 } }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const val = Number(ctx.raw);
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = total > 0 ? Math.round((val / total) * 100) : 0;
                            return ` ${ctx.label}: Rs. ${val.toLocaleString('en-PK', {minimumFractionDigits: 2})} (${pct}%)`;
                        }
                    }
                }
            },
            cutout: '65%'
        }
    });
}

function renderCategoryTable(breakdown) {
    const tbody = document.getElementById("categoryTableBody");
    const badge = document.getElementById("categoryTableSummaryBadge");
    if (!tbody) return;

    if (badge) badge.textContent = `${breakdown.length} categories`;

    if (breakdown.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" style="text-align:center; padding:28px; color:var(--muted);">
                    No transactions found for the selected filter range.
                </td>
            </tr>
        `;
        return;
    }

    let rowsHtml = "";
    breakdown.forEach((cat, index) => {
        const color = chartColors[index % chartColors.length];
        const typeBadge = cat.type === 'income' 
            ? `<span style="background:#eaf6ee; color:#1e7e34; padding:3px 8px; border-radius:10px; font-size:11px; font-weight:700;">Income</span>`
            : `<span style="background:#fef2f2; color:#dc2626; padding:3px 8px; border-radius:10px; font-size:11px; font-weight:700;">Expense</span>`;

        rowsHtml += `
            <tr style="border-bottom:1px solid var(--line);">
                <td style="padding:12px 16px; font-weight:600; color:var(--ink); display:flex; align-items:center; gap:8px;">
                    <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:${color};"></span>
                    <span>${cat.icon}</span>
                    <span>${cat.name}</span>
                </td>
                <td style="padding:12px 16px;">${typeBadge}</td>
                <td style="padding:12px 16px; color:var(--muted);">${cat.tx_count}</td>
                <td style="padding:12px 16px; font-weight:700;">Rs. ${Number(cat.amount).toLocaleString("en-PK", {minimumFractionDigits: 2})}</td>
                <td style="padding:12px 16px;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <div style="flex:1; background:#edf2f7; height:8px; border-radius:4px; overflow:hidden;">
                            <div style="width:${cat.percentage}%; background:${color}; height:100%; border-radius:4px;"></div>
                        </div>
                        <span style="font-size:12px; font-weight:600; width:45px;">${cat.percentage}%</span>
                    </div>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = rowsHtml;
}

// -------------------------------------------------------------
// FILTER CONTROLS & PRESETS (Requirement 2.4)
// -------------------------------------------------------------
document.getElementById("reportFilterForm")?.addEventListener("submit", function(e) {
    e.preventDefault();
    fetchAndRenderReports();
});

function resetReportFilters() {
    const now = new Date();
    const firstDay = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
    const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0];

    document.getElementById("reportDateFrom").value = firstDay;
    document.getElementById("reportDateTo").value = lastDay;
    document.getElementById("reportCategory").value = "all";
    document.getElementById("reportType").value = "all";

    fetchAndRenderReports();
}

// Quick Preset Buttons
document.querySelectorAll(".preset-btn").forEach(btn => {
    btn.addEventListener("click", function() {
        const preset = this.getAttribute("data-preset");
        const now = new Date();
        let from, to;

        if (preset === "this_month") {
            from = new Date(now.getFullYear(), now.getMonth(), 1);
            to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        } else if (preset === "last_month") {
            from = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            to = new Date(now.getFullYear(), now.getMonth(), 0);
        } else if (preset === "last_6m") {
            from = new Date(now.getFullYear(), now.getMonth() - 5, 1);
            to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        }

        if (from && to) {
            document.getElementById("reportDateFrom").value = from.toISOString().split('T')[0];
            document.getElementById("reportDateTo").value = to.toISOString().split('T')[0];
            fetchAndRenderReports();
        }
    });
});

// -------------------------------------------------------------
// 2.5 EXPORT REPORT AS PDF OR PNG (Requirement 2.5)
// -------------------------------------------------------------
function exportReportPDF() {
    const reportArea = document.getElementById("reportContentArea");
    if (!reportArea) return;

    const btn = document.getElementById("btnExportPdf");
    const originalText = btn.textContent;
    btn.textContent = "⌛ Generating PDF...";
    btn.disabled = true;

    html2canvas(reportArea, { scale: 2, useCORS: true }).then(canvas => {
        const imgData = canvas.toDataURL("image/png");
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF("p", "mm", "a4");

        const imgWidth = 210; // A4 width in mm
        const pageHeight = 295; // A4 height in mm
        const imgHeight = (canvas.height * imgWidth) / canvas.width;
        let heightLeft = imgHeight;
        let position = 0;

        pdf.addImage(imgData, "PNG", 0, position, imgWidth, imgHeight);
        heightLeft -= pageHeight;

        while (heightLeft > 0) {
            position = heightLeft - imgHeight;
            pdf.addPage();
            pdf.addImage(imgData, "PNG", 0, position, imgWidth, imgHeight);
            heightLeft -= pageHeight;
        }

        const dateStr = new Date().toISOString().split('T')[0];
        pdf.save(`CampusCoin_Report_${dateStr}.pdf`);

        btn.textContent = originalText;
        btn.disabled = false;
    }).catch(err => {
        console.error("PDF generation failed:", err);
        btn.textContent = originalText;
        btn.disabled = false;
        alert("Could not generate PDF. Please try the Print option.");
    });
}

function exportReportPNG() {
    const reportArea = document.getElementById("reportContentArea");
    if (!reportArea) return;

    const btn = document.getElementById("btnExportPng");
    const originalText = btn.textContent;
    btn.textContent = "⌛ Capturing Image...";
    btn.disabled = true;

    html2canvas(reportArea, { scale: 2, useCORS: true }).then(canvas => {
        const link = document.createElement("a");
        const dateStr = new Date().toISOString().split('T')[0];
        link.download = `CampusCoin_Report_${dateStr}.png`;
        link.href = canvas.toDataURL("image/png");
        link.click();

        btn.textContent = originalText;
        btn.disabled = false;
    }).catch(err => {
        console.error("PNG capture failed:", err);
        btn.textContent = originalText;
        btn.disabled = false;
        alert("Could not capture report image.");
    });
}

function exportReportCSV() {
    const from = document.getElementById("reportDateFrom").value;
    const to = document.getElementById("reportDateTo").value;
    const cat = document.getElementById("reportCategory").value;
    const type = document.getElementById("reportType").value;

    const url = `api/reports.php?format=csv&date_from=${encodeURIComponent(from)}&date_to=${encodeURIComponent(to)}&category_id=${encodeURIComponent(cat)}&type=${encodeURIComponent(type)}`;
    window.location.href = url;
}

// Initial fetch on page load
document.addEventListener("DOMContentLoaded", fetchAndRenderReports);
</script>
