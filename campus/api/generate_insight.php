<?php
/**
 * REST API Endpoint: /api/generate_insight.php
 *
 * Generates or retrieves an AI-driven monthly spending narrative insight for the logged-in student.
 * Module 3: AI-Generated Monthly Spending Insights
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ai_insights.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = getDbConnection();
$userId = currentUserId();

// Support JSON body or standard POST/GET parameters
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true) ?? [];

$month = trim($jsonInput['month'] ?? $_POST['month'] ?? $_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$threshold = (float)($jsonInput['threshold'] ?? $_POST['threshold'] ?? $_GET['threshold'] ?? 25.0);
if ($threshold < 1.0) $threshold = 1.0;
if ($threshold > 300.0) $threshold = 300.0;

$forceRegenerate = filter_var($jsonInput['force'] ?? $_POST['force'] ?? $_GET['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

try {
    // 1. Gather monthly financials
    $fin = getMonthlyFinancialSummary($pdo, $userId, $month);

    // 2. Check student's AI opt-in setting
    if (!$fin['ai_suggestions_enabled']) {
        echo json_encode([
            'success' => false,
            'opted_out' => true,
            'error'   => 'AI Insights are currently disabled in your Profile settings. Please enable "AI Category Suggestions & Insights" under Account Settings to generate monthly reports.'
        ]);
        exit;
    }

    // 3. Check for existing insight unless forced regeneration requested
    $existingInsight = getMonthlyInsight($pdo, $userId, $month);

    if ($existingInsight && !$forceRegenerate) {
        $trendData = calculateCategoryGrowthTrends($pdo, $userId, $month, (float)$existingInsight['threshold_used']);
        $promptTemplate = getLlmPromptTemplate(
            $fin['student_name'],
            $fin['month_name'],
            $fin['income'],
            $fin['expense'],
            $fin['net_savings'],
            $fin['monthly_savings_goal'],
            $existingInsight['flags'] ?? []
        );

        echo json_encode([
            'success'         => true,
            'cached'          => true,
            'insight'         => $existingInsight,
            'financials'      => $fin,
            'trends'          => $trendData,
            'prompt_template' => $promptTemplate
        ]);
        exit;
    }

    // 4. Calculate category trends against historical baseline
    $trendData = calculateCategoryGrowthTrends($pdo, $userId, $month, $threshold);
    $flagged = $trendData['flagged_categories'];

    // 5. Generate tailored actionable student advice
    $advice = generateActionableAdvice($flagged, $fin['monthly_allowance'], $fin['monthly_savings_goal']);

    // 6. Generate 3 to 5 sentence plain-language narrative summary
    $narrative = generateMonthlyNarrative(
        $fin['student_name'],
        $fin['month_name'],
        $fin['income'],
        $fin['expense'],
        $fin['net_savings'],
        $fin['monthly_savings_goal'],
        $flagged,
        $advice
    );

    // 7. Formulate key alert headline and tip text
    if (!empty($flagged)) {
        $primaryFlag = $flagged[0];
        $growthFlagText = "{$primaryFlag['name']} spending rose {$primaryFlag['growth_percent']}% compared to historical trend.";
    } else {
        $growthFlagText = "All categories remained within your normal spending boundaries.";
    }

    $tipText = !empty($advice) ? $advice[0]['headline'] : "Keep tracking daily expenses to maintain financial peace of mind.";

    // 8. Persist insight
    $insightId = saveMonthlyInsight(
        $pdo,
        $userId,
        $month,
        $narrative,
        $growthFlagText,
        $tipText,
        $flagged,
        $advice,
        (int)$threshold
    );

    // 9. Load saved insight with parsed JSON fields
    $savedInsight = getMonthlyInsight($pdo, $userId, $month);

    // 10. Generate LLM Prompt Template for developer/API export
    $promptTemplate = getLlmPromptTemplate(
        $fin['student_name'],
        $fin['month_name'],
        $fin['income'],
        $fin['expense'],
        $fin['net_savings'],
        $fin['monthly_savings_goal'],
        $flagged
    );

    echo json_encode([
        'success'         => true,
        'cached'          => false,
        'insight'         => $savedInsight,
        'financials'      => $fin,
        'trends'          => $trendData,
        'prompt_template' => $promptTemplate
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to generate monthly insight: ' . $e->getMessage()
    ]);
}
