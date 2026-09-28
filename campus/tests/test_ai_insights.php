<?php
/**
 * Automated Test Suite: AI-Generated Monthly Spending Insights (Module 3)
 * File: campus/tests/test_ai_insights.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ai_insights.php';

$pdo = getDbConnection();

echo "========================================================\n";
echo "RUNNING MODULE 3 TEST SUITE: AI MONTHLY SPENDING INSIGHTS\n";
echo "========================================================\n";

$testsPassed = 0;
$testsTotal = 0;

function assertTest(bool $condition, string $testName) {
    global $testsPassed, $testsTotal;
    $testsTotal++;
    if ($condition) {
        echo "  [PASS] {$testName}\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] {$testName}\n";
    }
}

// 1. Setup isolated test user & categories
$testEmail = 'insight_tester_' . time() . '@campuscoin.test';
$pdo->prepare("DELETE FROM users WHERE email = :email")->execute([':email' => $testEmail]);

$insUser = $pdo->prepare("
    INSERT INTO users (name, email, password_hash, role, monthly_allowance, monthly_savings_goal, ai_suggestions_enabled)
    VALUES ('Ayesha Khan', :email, 'hashed_pw', 'student', 25000.00, 5000.00, 1)
");
$insUser->execute([':email' => $testEmail]);
$testUserId = (int)$pdo->lastInsertId();

// Retrieve or create categories for testing
$catStmt = $pdo->prepare("SELECT name, category_id FROM categories WHERE name IN ('Food & Dining', 'Transportation') ORDER BY category_id");
$catStmt->execute();
$cats = $catStmt->fetchAll(PDO::FETCH_KEY_PAIR);

if (!isset($cats['Food & Dining'])) {
    $pdo->prepare("INSERT INTO categories (name, type, icon, is_default) VALUES ('Food & Dining', 'expense', '🍔', 1)")->execute();
    $foodCatId = (int)$pdo->lastInsertId();
} else {
    $foodCatId = (int)$cats['Food & Dining'];
}

if (!isset($cats['Transportation'])) {
    $pdo->prepare("INSERT INTO categories (name, type, icon, is_default) VALUES ('Transportation', 'expense', '🚌', 1)")->execute();
    $transCatId = (int)$pdo->lastInsertId();
} else {
    $transCatId = (int)$cats['Transportation'];
}

$currentMonthYm = '2026-09';
$currentDate = '2026-09-15';
$priorMonth1 = '2026-08-15';
$priorMonth2 = '2026-07-15';
$priorMonth3 = '2026-06-15';

// Clear any prior test transactions
$pdo->prepare("DELETE FROM transactions WHERE user_id = :uid")->execute([':uid' => $testUserId]);
$pdo->prepare("DELETE FROM insights WHERE user_id = :uid")->execute([':uid' => $testUserId]);

// Populate baseline transactions:
// Prior months: Food = Rs. 2,000/mo avg, Transport = Rs. 1,000/mo avg
$insTx = $pdo->prepare("
    INSERT INTO transactions (user_id, category_id, amount, type, title, description, date)
    VALUES (:uid, :cid, :amt, 'expense', :title, :desc, :dt)
");

// Prior Month 1 (Aug)
$insTx->execute([':uid' => $testUserId, ':cid' => $foodCatId, ':amt' => 2000.00, ':title' => 'Cafe August', ':desc' => 'Cafe August spend', ':dt' => $priorMonth1]);
$insTx->execute([':uid' => $testUserId, ':cid' => $transCatId, ':amt' => 1000.00, ':title' => 'Bus August', ':desc' => 'Bus August pass', ':dt' => $priorMonth1]);

// Prior Month 2 (Jul)
$insTx->execute([':uid' => $testUserId, ':cid' => $foodCatId, ':amt' => 2000.00, ':title' => 'Cafe July', ':desc' => 'Cafe July spend', ':dt' => $priorMonth2]);
$insTx->execute([':uid' => $testUserId, ':cid' => $transCatId, ':amt' => 1000.00, ':title' => 'Bus July', ':desc' => 'Bus July pass', ':dt' => $priorMonth2]);

// Prior Month 3 (Jun)
$insTx->execute([':uid' => $testUserId, ':cid' => $foodCatId, ':amt' => 2000.00, ':title' => 'Cafe June', ':desc' => 'Cafe June spend', ':dt' => $priorMonth3]);
$insTx->execute([':uid' => $testUserId, ':cid' => $transCatId, ':amt' => 1000.00, ':title' => 'Bus June', ':desc' => 'Bus June pass', ':dt' => $priorMonth3]);

// Current Month (Sep):
// Food jumps to Rs. 3,500 (+75.0% growth > 25% threshold)
// Transport stays at Rs. 1,050 (+5.0% growth <= 25% threshold)
$insTx->execute([':uid' => $testUserId, ':cid' => $foodCatId, ':amt' => 3500.00, ':title' => 'Cafe September Spurt', ':desc' => 'Cafe September Spurt', ':dt' => $currentDate]);
$insTx->execute([':uid' => $testUserId, ':cid' => $transCatId, ':amt' => 1050.00, ':title' => 'Bus September', ':desc' => 'Bus September', ':dt' => $currentDate]);

// Current Month Income: Rs. 20,000
$insIncome = $pdo->prepare("
    INSERT INTO transactions (user_id, category_id, amount, type, title, description, date)
    VALUES (:uid, :cid, 20000.00, 'income', 'Monthly Student Allowance', 'Student stipend', '2026-09-01')
");
$insIncome->execute([':uid' => $testUserId, ':cid' => $foodCatId]);

echo "\n--- TEST 1: Category Growth Rates & Threshold Flagging ---\n";
$trends = calculateCategoryGrowthTrends($pdo, $testUserId, $currentMonthYm, 25.0);

assertTest(isset($trends['all_trends']) && count($trends['all_trends']) >= 2, "Found all active categories in trend analysis");
assertTest($trends['flagged_count'] === 1, "Exactly 1 category exceeded >25% growth threshold");

$flagged = $trends['flagged_categories'][0] ?? null;
assertTest($flagged !== null && $flagged['category_id'] == $foodCatId, "Food & Dining was correctly identified as the growth category");
assertTest($flagged !== null && abs($flagged['growth_percent'] - 75.0) < 0.2, "Growth percentage accurately calculated at ~75.0% (Current: 3500 vs Avg: 2000)");

echo "\n--- TEST 2: Configurable Growth Threshold ---\n";
// Threshold set to 80% -> Food at 75% should NOT be flagged
$trendsHigh = calculateCategoryGrowthTrends($pdo, $testUserId, $currentMonthYm, 80.0);
assertTest($trendsHigh['flagged_count'] === 0, "No categories flagged when threshold raised to 80%");

// Threshold set to 3% -> Both Food (75%) and Transport (5%) should be flagged
$trendsLow = calculateCategoryGrowthTrends($pdo, $testUserId, $currentMonthYm, 3.0);
assertTest($trendsLow['flagged_count'] === 2, "Both Food and Transport flagged when threshold lowered to 3%");

echo "\n--- TEST 3: Actionable Student Advice Generation ---\n";
$advice = generateActionableAdvice($trends['flagged_categories'], 25000.00, 5000.00);
assertTest(!empty($advice), "Actionable advice list generated");
assertTest(isset($advice[0]['category']) && strpos($advice[0]['category'], 'Food') !== false, "Advice is specifically tailored to Food & Dining");
assertTest(strpos($advice[0]['headline'], 'Cap') !== false || strpos($advice[0]['headline'], 'dining') !== false, "Advice headline includes actionable weekly ceiling");

echo "\n--- TEST 4: Financial Summary & LLM Prompt Template ---\n";
$fin = getMonthlyFinancialSummary($pdo, $testUserId, $currentMonthYm);
assertTest($fin['income'] == 20000.00, "Income aggregated correctly (Rs. 20,000)");
assertTest($fin['expense'] == 4550.00, "Expense aggregated correctly (Rs. 4,550)");
assertTest($fin['net_savings'] == 15450.00, "Net savings calculated correctly (Rs. 15,450)");

$prompt = getLlmPromptTemplate(
    $fin['student_name'],
    $fin['month_name'],
    $fin['income'],
    $fin['expense'],
    $fin['net_savings'],
    $fin['monthly_savings_goal'],
    $trends['flagged_categories']
);
assertTest(!empty($prompt['system_prompt']), "System prompt for LLM generated");
assertTest(strpos($prompt['user_prompt'], 'Food & Dining') !== false, "User prompt incorporates flagged category data");
assertTest(strpos($prompt['user_prompt'], 'Ayesha Khan') !== false, "User prompt addresses student name");

echo "\n--- TEST 5: Plain-Language Narrative Summary (3 to 5 Sentences) ---\n";
$narrative = generateMonthlyNarrative(
    $fin['student_name'],
    $fin['month_name'],
    $fin['income'],
    $fin['expense'],
    $fin['net_savings'],
    $fin['monthly_savings_goal'],
    $trends['flagged_categories'],
    $advice
);

// Count sentences using punctuation regex, ignoring abbreviation 'Rs.' and decimal numbers
$cleanNarrative = preg_replace('/Rs\./i', 'Rs', $narrative);
$cleanNarrative = preg_replace('/\d+\.\d+/', 'number', $cleanNarrative);
$sentenceCount = preg_match_all('/[^\.!\?]+[\.!\?]+/', $cleanNarrative);
assertTest($sentenceCount >= 3 && $sentenceCount <= 5, "Narrative contains 3 to 5 sentences (actual count: {$sentenceCount})");
assertTest(strpos($narrative, 'Food & Dining') !== false, "Narrative explicitly mentions Food & Dining surge");
assertTest(strpos($narrative, '75%') !== false, "Narrative cites the calculated 75% growth rate");

echo "\n--- TEST 6: Persistence in insights Table & Insight History ---\n";
$growthFlagText = "Food & Dining spending rose 75% compared to historical trend.";
$tipText = $advice[0]['headline'];

$insightId = saveMonthlyInsight(
    $pdo,
    $testUserId,
    $currentMonthYm,
    $narrative,
    $growthFlagText,
    $tipText,
    $trends['flagged_categories'],
    $advice,
    25
);
assertTest($insightId > 0, "Monthly insight persisted with ID #{$insightId}");

$fetched = getMonthlyInsight($pdo, $testUserId, $currentMonthYm);
assertTest($fetched !== null, "Successfully retrieved saved insight for {$currentMonthYm}");
assertTest($fetched['growth_flag_text'] === $growthFlagText, "Flag text matches persisted string");
assertTest(count($fetched['flags']) === 1, "flags_json decoded correctly into array");
assertTest(!empty($fetched['advice']), "advice_json decoded correctly into array");

$history = getInsightHistory($pdo, $testUserId);
assertTest(count($history) >= 1, "getInsightHistory returns user's historical reviews");
assertTest($history[0]['month_ym'] === $currentMonthYm, "History ordered with newest review first");

echo "\n--- TEST 7: Opt-In / Opt-Out Privacy Verification ---\n";
$pdo->prepare("UPDATE users SET ai_suggestions_enabled = 0 WHERE user_id = :uid")->execute([':uid' => $testUserId]);
$finOptOut = getMonthlyFinancialSummary($pdo, $testUserId, $currentMonthYm);
assertTest($finOptOut['ai_suggestions_enabled'] === 0, "System honors student opt-out setting (ai_suggestions_enabled = 0)");

// Clean up
$pdo->prepare("DELETE FROM insights WHERE user_id = :uid")->execute([':uid' => $testUserId]);
$pdo->prepare("DELETE FROM transactions WHERE user_id = :uid")->execute([':uid' => $testUserId]);
$pdo->prepare("DELETE FROM users WHERE user_id = :uid")->execute([':uid' => $testUserId]);

echo "\n========================================================\n";
echo "MODULE 3 RESULTS: {$testsPassed} / {$testsTotal} TESTS PASSED\n";
echo "========================================================\n";

if ($testsPassed === $testsTotal) {
    echo "SUCCESS: ALL MODULE 3 TESTS PASSED PERFECTLY!\n";
    exit(0);
} else {
    echo "ERROR: SOME TESTS FAILED.\n";
    exit(1);
}
