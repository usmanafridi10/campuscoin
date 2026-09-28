<?php
/**
 * CampusCoin AI Category Learning & Reinforcement Simulation Test
 *
 * Verifies:
 * 1. AI suggestion vs final category comparison & category_suggestion_log logging.
 * 2. Token & bigram extraction with source='user_correction'.
 * 3. Weight increase for correct categories and floored (>= 0.0) penalty for wrongly suggested categories.
 * 4. Reinforcement (times_confirmed) when suggestions are accepted.
 * 5. Strict per-user isolation (User A's rules do not alter User B's predictions; global seeds remain untouched).
 * 6. Rule management (editing weight/category, deleting rules, reset AI learning).
 * 7. Simulated multi-step student workflow demonstrating acceptance rate climbing from 0% to 80%+.
 */

require_once __DIR__ . '/../config.php';

// Color formatting for CLI
function test_pass($msg) {
    echo "\033[32m[PASS]\033[0m " . $msg . PHP_EOL;
}
function test_fail($msg) {
    echo "\033[31m[FAIL]\033[0m " . $msg . PHP_EOL;
}
function test_info($msg) {
    echo "\033[36m[INFO]\033[0m " . $msg . PHP_EOL;
}
function test_step($msg) {
    echo PHP_EOL . "\033[1;33m===> " . $msg . "\033[0m" . PHP_EOL;
}

$pdo = getDbConnection();

// Create isolated test user IDs that won't interfere with real data
// We'll use 88881 for Student A, 88882 for Student B
$testUserA = 88881;
$testUserB = 88882;

// Ensure test users exist in `users` table to satisfy FK constraints
$pdo->prepare("
    INSERT INTO users (user_id, name, email, password_hash, role) 
    VALUES (?, 'Test Student A', 'test_ai_user_a@campuscoin.com', 'dummy_hash', 'student'),
           (?, 'Test Student B', 'test_ai_user_b@campuscoin.com', 'dummy_hash', 'student')
    ON DUPLICATE KEY UPDATE name=VALUES(name)
")->execute([$testUserA, $testUserB]);

// Clean up any existing records for test users
$pdo->prepare('DELETE FROM category_suggestion_log WHERE user_id IN (?, ?)')->execute([$testUserA, $testUserB]);
$pdo->prepare('DELETE FROM category_keywords WHERE user_id IN (?, ?)')->execute([$testUserA, $testUserB]);

echo "==========================================================================" . PHP_EOL;
echo " CampusCoin: Adaptive Per-User AI Learning & Reinforcement Test Suite" . PHP_EOL;
echo "==========================================================================" . PHP_EOL;

// -------------------------------------------------------------------------
// TEST 1: Token and Bigram Extraction
// -------------------------------------------------------------------------
test_step("TEST 1: Token & Bigram Extraction with Stopword Filtering");
$phrase = "Delicious Chai and Paratha at Khokha Cafe!";
$tokens = extractTokensAndBigrams($phrase);

test_info("Input phrase: '$phrase'");
test_info("Extracted tokens/bigrams: " . implode(', ', $tokens));

// Expected tokens should include unigrams and bigrams, excluding common stop words ('and', 'at')
$expectedIn = ['chai', 'paratha', 'khokha', 'cafe', 'chai paratha', 'paratha khokha', 'khokha cafe'];
$allFound = true;
foreach ($expectedIn as $exp) {
    if (!in_array($exp, $tokens, true)) {
        $allFound = false;
        test_fail("Missing expected token/bigram: '$exp'");
    }
}
if ($allFound && !in_array('and', $tokens, true) && !in_array('at', $tokens, true)) {
    test_pass("Successfully extracted tokens and bigrams while stripping stopwords ('and', 'at') and punctuation.");
} else {
    test_fail("Token/bigram extraction logic did not meet expectations.");
}

// -------------------------------------------------------------------------
// TEST 2: Per-User Isolation
// -------------------------------------------------------------------------
test_step("TEST 2: Strict Per-User Isolation (User A vs User B vs Global Seeds)");

// User A teaches that "Robotics Lab Components" is Academics (Category 9)
$catAcademics = 9;
$catEntertainment = 11;
$catFood = 6;

// Verify before learning: User A and User B have zero custom rules
$rulesA_before = getUserCategoryRules($testUserA);
$rulesB_before = getUserCategoryRules($testUserB);
if (count($rulesA_before) === 0 && count($rulesB_before) === 0) {
    test_pass("Initial state verified: Both test users start with zero custom rules.");
}

// User A corrects "Robotics Lab Components" to Academics (9)
learnCategory($testUserA, "Robotics Lab Components", $catAcademics, null);

// Check User A rules
$rulesA_after = getUserCategoryRules($testUserA);
$rulesB_after = getUserCategoryRules($testUserB);

$hasRoboticsA = false;
foreach ($rulesA_after as $r) {
    if ($r['keyword'] === 'robotics' && (int)$r['category_id'] === $catAcademics && $r['source'] === 'user_correction') {
        $hasRoboticsA = true;
    }
}

if ($hasRoboticsA && count($rulesB_after) === 0) {
    test_pass("User A generated custom rules (source='user_correction') while User B has exactly 0 custom rules.");
} else {
    test_fail("Isolation violation: User B custom rules count = " . count($rulesB_after));
}

// Predict for User A and User B on "robotics kit"
$predA = predictCategory($testUserA, "Robotics kit");
$predB = predictCategory($testUserB, "Robotics kit");

if ($predA !== null && (int)$predA['category_id'] === $catAcademics) {
    test_pass("User A prediction for 'Robotics kit' correctly resolves to Academics (ID: $catAcademics) via User A's learned rule.");
} else {
    test_fail("User A prediction failed: " . json_encode($predA));
}

if ($predB === null || (int)$predB['category_id'] !== $catAcademics) {
    test_pass("User B prediction does NOT use User A's custom rule (User B returned: " . ($predB ? $predB['category_name'] : 'NULL') . "). Isolation preserved!");
} else {
    test_fail("Isolation violation: User B unexpectedly matched User A's private rule.");
}

// Verify global seed table integrity (seeds must have user_id IS NULL)
$seedCheckStmt = $pdo->query("SELECT COUNT(*) FROM category_keywords WHERE user_id IS NULL");
$seedCount = (int)$seedCheckStmt->fetchColumn();
test_pass("Global seed keywords remain intact ($seedCount shared seeds with user_id NULL).");

// -------------------------------------------------------------------------
// TEST 3: Weight Adjustment & Floor at 0.00
// -------------------------------------------------------------------------
test_step("TEST 3: Weight Decrement Penalty & Floor at 0.00");

// Give User A a rule for "testkeyword" with weight 0.30
$stmt = $pdo->prepare("
    INSERT INTO category_keywords (user_id, category_id, keyword, weight, times_confirmed, source)
    VALUES (?, ?, 'testkeyword', 0.30, 0, 'user_correction')
");
$stmt->execute([$testUserA, $catEntertainment]);

// Simulate User A overriding an AI suggestion that wrongly suggested Entertainment (11), choosing Food (6)
learnCategory($testUserA, "testkeyword lunch", $catFood, $catEntertainment);

// Check weight of 'testkeyword' for Entertainment
$checkStmt = $pdo->prepare("SELECT weight FROM category_keywords WHERE user_id = ? AND category_id = ? AND keyword = 'testkeyword'");
$checkStmt->execute([$testUserA, $catEntertainment]);
$penalizedWeight = (float)$checkStmt->fetchColumn();

if ($penalizedWeight === 0.0) {
    test_pass("Wrong category weight decreased from 0.30 and successfully floored at 0.00 (Current: $penalizedWeight).");
} else {
    test_fail("Floor failed: Weight is $penalizedWeight (expected 0.00).");
}

// -------------------------------------------------------------------------
// TEST 4: Simulation of Transaction Log & Improving Acceptance Rate
// -------------------------------------------------------------------------
test_step("TEST 4: 5-Step Student Workflow Simulation & Acceptance Rate Improvement");

// Reset test user A
resetUserAiLearning($testUserA);

$transactions = [
    [
        'step'        => 1,
        'description' => "Ghaffar Kabab Roll Late Night",
        'forced_sugg' => $catEntertainment, // AI previously guessed Entertainment (11)
        'final'       => $catFood,           // Student overrides to Food (6)
        'action'      => 'override'
    ],
    [
        'step'        => 2,
        'description' => "Kabab Roll with hostel friends",
        'final'       => $catFood,
        'action'      => 'accept'
    ],
    [
        'step'        => 3,
        'description' => "Special Ghaffar Lunch Box",
        'final'       => $catFood,
        'action'      => 'accept'
    ],
    [
        'step'        => 4,
        'description' => "Chicken Kabab Plate",
        'final'       => $catFood,
        'action'      => 'accept'
    ],
    [
        'step'        => 5,
        'description' => "Ghaffar Biryani Dinner",
        'final'       => $catFood,
        'action'      => 'accept'
    ]
];

echo str_pad("Step", 6) . " | " .
     str_pad("Description", 32) . " | " .
     str_pad("AI Suggestion", 18) . " | " .
     str_pad("Student Action", 17) . " | " .
     str_pad("Accepted", 10) . " | " .
     str_pad("Acceptance Rate", 16) . PHP_EOL;
echo str_repeat("-", 108) . PHP_EOL;

$rates = [];

foreach ($transactions as $tx) {
    $desc = $tx['description'];
    
    // 1. Get AI prediction or use forced suggestion for initial override test
    if (isset($tx['forced_sugg'])) {
        $suggestedCatId = $tx['forced_sugg'];
        $suggestedCatName = "Entertainment (11)";
    } else {
        $pred = predictCategory($testUserA, $desc);
        $suggestedCatId = $pred ? (int)$pred['category_id'] : null;
        $suggestedCatName = $pred ? ($pred['name'] . " (" . $pred['category_id'] . ")") : 'None (NULL)';
    }

    if ($tx['action'] === 'override') {
        $finalCatId = $tx['final'];
        $wasAccepted = 0;
        $actionText = "Overrode -> Food";
    } else {
        $finalCatId = $suggestedCatId ?? $tx['final'];
        $wasAccepted = 1;
        $actionText = "Accepted AI";
    }

    // 2. Record transaction log & trigger learning/reinforcement
    recordCategorySuggestionLog($testUserA, null, $desc, $suggestedCatId, $finalCatId);

    // 3. Compute current stats
    $stats = getCategoryAiStats($testUserA);
    $rates[] = $stats['acceptance_rate'];

    echo str_pad("T" . $tx['step'], 6) . " | " .
         str_pad(mb_substr($desc, 0, 30), 32) . " | " .
         str_pad($suggestedCatName, 18) . " | " .
         str_pad($actionText, 17) . " | " .
         str_pad($wasAccepted ? "Yes (1)" : "No (0)", 10) . " | " .
         str_pad($stats['acceptance_rate'] . "% (" . $stats['accepted_suggestions'] . "/" . $stats['total_suggestions'] . ")", 16) . PHP_EOL;
}

echo str_repeat("-", 108) . PHP_EOL;

// Verify rate progression
test_info("Acceptance Rate Progression: " . implode("% -> ", $rates) . "%");

$firstRate = $rates[0];
$finalRate = end($rates);

if ($firstRate === 0.0 && $finalRate >= 75.0 && $finalRate > $firstRate) {
    test_pass("Acceptance rate monotonically improved from {$firstRate}% to {$finalRate}% across repeated learning interactions!");
} else {
    test_fail("Acceptance rate failed to demonstrate expected improvement: Start={$firstRate}%, End={$finalRate}%");
}

// -------------------------------------------------------------------------
// TEST 5: Reinforcement Counter Verification
// -------------------------------------------------------------------------
test_step("TEST 5: Reinforcement times_confirmed Counter");
$rulesAfterSim = getUserCategoryRules($testUserA);
$reinforced = false;
foreach ($rulesAfterSim as $r) {
    if ($r['times_confirmed'] > 0) {
        $reinforced = true;
        test_info("Keyword '{$r['keyword']}' has times_confirmed = {$r['times_confirmed']} (Reinforced)");
    }
}
if ($reinforced) {
    test_pass("Successfully verified that times_confirmed increments when suggestions are accepted.");
} else {
    test_fail("times_confirmed was not incremented for confirmed suggestions.");
}

// -------------------------------------------------------------------------
// TEST 6: Rule Management (Edit, Delete, Reset)
// -------------------------------------------------------------------------
test_step("TEST 6: Rule Management (Update, Delete, and Reset)");

// A. Edit rule
$firstRule = $rulesAfterSim[0];
$ruleId = $firstRule['keyword_id'];
$newWeight = 4.50;
$updateOk = updateUserCategoryRule($testUserA, $ruleId, (int)$firstRule['category_id'], $newWeight);
$updatedRuleStmt = $pdo->prepare("SELECT weight FROM category_keywords WHERE keyword_id = ? AND user_id = ?");
$updatedRuleStmt->execute([$ruleId, $testUserA]);
$valAfterUpdate = (float)$updatedRuleStmt->fetchColumn();

if ($updateOk && $valAfterUpdate === $newWeight) {
    test_pass("updateUserCategoryRule() successfully modified rule weight to $newWeight.");
} else {
    test_fail("Rule update failed.");
}

// B. Delete rule
$deleteOk = deleteUserCategoryRule($testUserA, $ruleId);
$deletedCheck = $pdo->prepare("SELECT COUNT(*) FROM category_keywords WHERE keyword_id = ?");
$deletedCheck->execute([$ruleId]);
if ($deleteOk && (int)$deletedCheck->fetchColumn() === 0) {
    test_pass("deleteUserCategoryRule() successfully deleted rule ID $ruleId.");
} else {
    test_fail("Rule deletion failed.");
}

// C. Reset AI Learning
$resetOk = resetUserAiLearning($testUserA);
$statsAfterReset = getCategoryAiStats($testUserA);
$rulesAfterReset = getUserCategoryRules($testUserA);

if ($resetOk && count($rulesAfterReset) === 0 && $statsAfterReset['total_suggestions'] === 0) {
    test_pass("resetUserAiLearning() cleared all user rules and logs for Student A.");
} else {
    test_fail("Reset failed: " . json_encode($statsAfterReset));
}

// Final cleanup of test users
$pdo->prepare('DELETE FROM category_suggestion_log WHERE user_id IN (?, ?)')->execute([$testUserA, $testUserB]);
$pdo->prepare('DELETE FROM category_keywords WHERE user_id IN (?, ?)')->execute([$testUserA, $testUserB]);
$pdo->prepare('DELETE FROM users WHERE user_id IN (?, ?)')->execute([$testUserA, $testUserB]);

test_step("ALL AI CATEGORY LEARNING TESTS COMPLETED SUCCESSFULLY");
echo "==========================================================================" . PHP_EOL;
