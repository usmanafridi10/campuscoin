<?php
/**
 * REST API Endpoint: /api/reports.php
 *
 * Aggregates monthly and filtered transaction reports:
 * 1. KPIs (total income, total expense, net balance, savings rate)
 * 2. Category-wise expense distribution for donut chart and table
 * 3. 6-Month Income vs. Expense and Net Savings for grouped bar/line chart
 * 4. Daily spending timeline for current period
 * 5. Weekly breakdown buckets (Weeks 1 to 4+)
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = getDbConnection();
$userId = currentUserId();

// 1. Sanitize Filters
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo   = trim($_GET['date_to'] ?? date('Y-m-t'));
$catFilter = trim($_GET['category_id'] ?? 'all');
$typeFilter = trim($_GET['type'] ?? 'all');

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-t');
}
if ($dateFrom > $dateTo) {
    $temp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $temp;
}

// Build query filter conditions
$whereConditions = ['t.user_id = :uid', 't.date BETWEEN :date_from AND :date_to'];
$params = [
    ':uid'       => $userId,
    ':date_from' => $dateFrom,
    ':date_to'   => $dateTo
];

if ($catFilter !== 'all' && is_numeric($catFilter) && (int)$catFilter > 0) {
    $whereConditions[] = 't.category_id = :cat_id';
    $params[':cat_id'] = (int)$catFilter;
}

if ($typeFilter === 'income' || $typeFilter === 'expense') {
    $whereConditions[] = 't.type = :type_val';
    $params[':type_val'] = $typeFilter;
}

$whereClause = implode(' AND ', $whereConditions);

// =========================================================================
// CSV EXPORT (Requirement 2.5: Raw data export for selected period)
// =========================================================================
if (isset($_GET['format']) && strtolower($_GET['format']) === 'csv') {
    $rawTxSql = "
        SELECT 
            t.date,
            t.title,
            t.description,
            c.name AS category_name,
            t.type,
            t.amount
        FROM transactions t
        LEFT JOIN categories c ON t.category_id = c.category_id
        WHERE $whereClause
        ORDER BY t.date DESC, t.transaction_id DESC
    ";
    $rawTxStmt = $pdo->prepare($rawTxSql);
    $rawTxStmt->execute($params);
    $rawRows = $rawTxStmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="campuscoin_report_' . $dateFrom . '_to_' . $dateTo . '.csv"');
    
    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Excel compatibility
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Title', 'Description', 'Category', 'Type', 'Amount (Rs.)']);
    foreach ($rawRows as $row) {
        fputcsv($out, [
            $row['date'],
            $row['title'],
            $row['description'],
            $row['category_name'] ?? 'Uncategorized',
            ucfirst($row['type']),
            number_format((float)$row['amount'], 2, '.', '')
        ]);
    }
    fclose($out);
    exit;
}

// =========================================================================
// 1. KPI TOTALS
// =========================================================================
$kpiSql = "
    SELECT 
        COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END), 0) AS total_expense,
        COALESCE(SUM(CASE WHEN t.type = 'income' THEN 1 ELSE 0 END), 0) AS income_count,
        COALESCE(SUM(CASE WHEN t.type = 'expense' THEN 1 ELSE 0 END), 0) AS expense_count,
        COUNT(*) AS total_count
    FROM transactions t
    WHERE $whereClause
";
$kpiStmt = $pdo->prepare($kpiSql);
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

$totalIncome  = (float)$kpi['total_income'];
$totalExpense = (float)$kpi['total_expense'];
$netBalance   = $totalIncome - $totalExpense;
$savingsRate  = ($totalIncome > 0) ? round(($netBalance / $totalIncome) * 100, 1) : 0.0;

// =========================================================================
// 2. CATEGORY BREAKDOWN (For Donut/Pie Chart & Table)
// =========================================================================
$catSql = "
    SELECT 
        c.category_id,
        c.name AS category_name,
        c.type AS category_type,
        c.icon AS category_icon,
        COALESCE(SUM(t.amount), 0) AS total_amount,
        COUNT(t.transaction_id) AS tx_count
    FROM transactions t
    JOIN categories c ON t.category_id = c.category_id
    WHERE $whereClause
    GROUP BY c.category_id, c.name, c.type, c.icon
    ORDER BY total_amount DESC
";
$catStmt = $pdo->prepare($catSql);
$catStmt->execute($params);
$catRows = $catStmt->fetchAll(PDO::FETCH_ASSOC);

$baseTotal = ($typeFilter === 'income') ? $totalIncome : $totalExpense;
if ($baseTotal <= 0 && $typeFilter === 'all') {
    $baseTotal = $totalExpense > 0 ? $totalExpense : ($totalIncome > 0 ? $totalIncome : 1.0);
}

$categoryBreakdown = [];
foreach ($catRows as $cr) {
    $amt = (float)$cr['total_amount'];
    $pct = ($baseTotal > 0) ? round(($amt / $baseTotal) * 100, 1) : 0.0;
    $categoryBreakdown[] = [
        'category_id'   => (int)$cr['category_id'],
        'name'          => $cr['category_name'],
        'type'          => $cr['category_type'],
        'icon'          => $cr['category_icon'] ?? '🏷️',
        'amount'        => $amt,
        'percentage'    => $pct,
        'tx_count'      => (int)$cr['tx_count']
    ];
}

// =========================================================================
// 3. 6-MONTH INCOME VS. EXPENSE & NET SAVINGS (Grouped Bar/Line Chart)
// =========================================================================
// Generate past 6 months
$sixMonthLabels = [];
$sixMonthData = [];

for ($i = 5; $i >= 0; $i--) {
    $mTimestamp = strtotime("-$i months", strtotime(date('Y-m-01')));
    $ymKey = date('Y-m', $mTimestamp);
    $sixMonthLabels[$ymKey] = date('M Y', $mTimestamp);
    $sixMonthData[$ymKey] = [
        'ym'          => $ymKey,
        'month_label' => date('M Y', $mTimestamp),
        'income'      => 0.0,
        'expense'     => 0.0,
        'net_savings' => 0.0
    ];
}

$sixMonthStart = date('Y-m-01', strtotime('-5 months', strtotime(date('Y-m-01'))));
$sixMonthEnd   = date('Y-m-t');

$historySql = "
    SELECT 
        DATE_FORMAT(t.date, '%Y-%m') AS ym,
        COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END), 0) AS monthly_income,
        COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END), 0) AS monthly_expense
    FROM transactions t
    WHERE t.user_id = :uid
      AND t.date BETWEEN :start_date AND :end_date
    GROUP BY ym
    ORDER BY ym ASC
";
$histStmt = $pdo->prepare($historySql);
$histStmt->execute([
    ':uid'        => $userId,
    ':start_date' => $sixMonthStart,
    ':end_date'   => $sixMonthEnd
]);

while ($hRow = $histStmt->fetch(PDO::FETCH_ASSOC)) {
    $ym = $hRow['ym'];
    if (isset($sixMonthData[$ym])) {
        $inc = (float)$hRow['monthly_income'];
        $exp = (float)$hRow['monthly_expense'];
        $sixMonthData[$ym]['income'] = $inc;
        $sixMonthData[$ym]['expense'] = $exp;
        $sixMonthData[$ym]['net_savings'] = $inc - $exp;
    }
}

// =========================================================================
// 4. DAILY SPENDING TIMELINE
// =========================================================================
$dailySql = "
    SELECT 
        t.date,
        DATE_FORMAT(t.date, '%d %b') AS day_label,
        COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END), 0) AS expense_amount,
        COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END), 0) AS income_amount,
        COUNT(*) AS tx_count
    FROM transactions t
    WHERE $whereClause
    GROUP BY t.date, day_label
    ORDER BY t.date ASC
";
$dailyStmt = $pdo->prepare($dailySql);
$dailyStmt->execute($params);
$dailyRows = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

$todayDate = date('Y-m-d');
$todaySpend = 0.0;
$highestDaySpend = 0.0;
$highestDayDate = '-';
$dailyTimeline = [];

foreach ($dailyRows as $dr) {
    $exp = (float)$dr['expense_amount'];
    $inc = (float)$dr['income_amount'];
    if ($dr['date'] === $todayDate) {
        $todaySpend = $exp;
    }
    if ($exp > $highestDaySpend) {
        $highestDaySpend = $exp;
        $highestDayDate = $dr['day_label'];
    }
    $dailyTimeline[] = [
        'date'           => $dr['date'],
        'day_label'      => $dr['day_label'],
        'expense_amount' => $exp,
        'income_amount'  => $inc,
        'tx_count'       => (int)$dr['tx_count']
    ];
}

// Days tracked in filter range
$startTs = strtotime($dateFrom);
$endTs   = strtotime($dateTo);
$daysInRange = max(1, (int)round(($endTs - $startTs) / 86400) + 1);
$dailyAverage = ($daysInRange > 0) ? round($totalExpense / $daysInRange, 2) : 0.0;

// =========================================================================
// 5. WEEKLY BREAKDOWN (BUCKETS 1 to 4+)
// =========================================================================
$weeklySql = "
    SELECT 
        CASE 
            WHEN DAY(t.date) BETWEEN 1 AND 7 THEN 'Week 1 (1st–7th)'
            WHEN DAY(t.date) BETWEEN 8 AND 14 THEN 'Week 2 (8th–14th)'
            WHEN DAY(t.date) BETWEEN 15 AND 21 THEN 'Week 3 (15th–21st)'
            ELSE 'Week 4+ (22nd–End)'
        END AS week_bucket,
        COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END), 0) AS weekly_expense,
        COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END), 0) AS weekly_income,
        COUNT(*) AS tx_count
    FROM transactions t
    WHERE $whereClause
    GROUP BY week_bucket
";
$weeklyStmt = $pdo->prepare($weeklySql);
$weeklyStmt->execute($params);

$weeklyBuckets = [
    'Week 1 (1st–7th)'   => ['expense' => 0.0, 'income' => 0.0, 'count' => 0, 'pct' => 0],
    'Week 2 (8th–14th)'  => ['expense' => 0.0, 'income' => 0.0, 'count' => 0, 'pct' => 0],
    'Week 3 (15th–21st)' => ['expense' => 0.0, 'income' => 0.0, 'count' => 0, 'pct' => 0],
    'Week 4+ (22nd–End)' => ['expense' => 0.0, 'income' => 0.0, 'count' => 0, 'pct' => 0]
];

while ($wRow = $weeklyStmt->fetch(PDO::FETCH_ASSOC)) {
    $bucket = $wRow['week_bucket'];
    if (isset($weeklyBuckets[$bucket])) {
        $wExp = (float)$wRow['weekly_expense'];
        $weeklyBuckets[$bucket]['expense'] = $wExp;
        $weeklyBuckets[$bucket]['income'] = (float)$wRow['weekly_income'];
        $weeklyBuckets[$bucket]['count'] = (int)$wRow['tx_count'];
        $weeklyBuckets[$bucket]['pct'] = ($totalExpense > 0) ? round(($wExp / $totalExpense) * 100) : 0;
    }
}

// Output complete JSON payload
echo json_encode([
    'success' => true,
    'filters' => [
        'date_from'   => $dateFrom,
        'date_to'     => $dateTo,
        'category_id' => $catFilter,
        'type'        => $typeFilter
    ],
    'kpis' => [
        'total_income'       => $totalIncome,
        'total_expense'      => $totalExpense,
        'net_balance'        => $netBalance,
        'savings_rate'       => $savingsRate,
        'income_count'       => (int)$kpi['income_count'],
        'expense_count'      => (int)$kpi['expense_count'],
        'total_count'        => (int)$kpi['total_count'],
        'daily_average'      => $dailyAverage,
        'today_spend'        => $todaySpend,
        'highest_day_spend'  => $highestDaySpend,
        'highest_day_date'   => $highestDayDate,
        'days_in_range'      => $daysInRange
    ],
    'category_breakdown' => $categoryBreakdown,
    'six_month_trend'    => array_values($sixMonthData),
    'daily_timeline'     => $dailyTimeline,
    'weekly_buckets'     => $weeklyBuckets
]);
