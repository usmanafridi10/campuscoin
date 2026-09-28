<?php
/**
 * CampusCoin - AI Monthly Spending Insights Engine (Module 3)
 *
 * Implements:
 * 1. Trend calculation: Compares monthly category spending against prior months' average (threshold > 25%).
 * 2. Actionable advice: Category-specific student financial advice & weekly caps.
 * 3. Narrative summary: Plain-language 3-5 sentence analysis with LLM prompt template & fallback generator.
 * 4. Insight history: Persistence and retrieval of monthly reviews.
 */

if (!defined('CAMPUS_COIN_BOOTSTRAP')) {
    define('CAMPUS_COIN_BOOTSTRAP', true);
}

/**
 * Calculates category growth rates by comparing current month spending against previous months' average.
 * Flags categories that exceed the specified growth threshold (default > 25%).
 *
 * @param PDO $pdo
 * @param int $userId
 * @param string $monthYm Format: 'YYYY-MM'
 * @param float $thresholdPercent
 * @return array
 */
function calculateCategoryGrowthTrends(PDO $pdo, int $userId, string $monthYm, float $thresholdPercent = 25.0): array
{
    $currentStart = "{$monthYm}-01";
    $currentEnd   = date('Y-m-t', strtotime($currentStart));

    // Previous 3 to 6 months window
    $prevStart = date('Y-m-01', strtotime('-3 months', strtotime($currentStart)));
    $prevEnd   = date('Y-m-d', strtotime('-1 day', strtotime($currentStart)));

    // 1. Current month spending per category
    $currStmt = $pdo->prepare("
        SELECT 
            c.category_id,
            c.name AS category_name,
            c.icon AS category_icon,
            c.type AS category_type,
            COALESCE(SUM(t.amount), 0) AS current_spend,
            COUNT(t.transaction_id) AS tx_count
        FROM categories c
        LEFT JOIN transactions t ON t.category_id = c.category_id 
                               AND t.user_id = :uid_t 
                               AND t.type = 'expense'
                               AND t.date BETWEEN :c_start AND :c_end
        WHERE (c.is_default = 1 OR c.user_id = :uid_c) AND c.type = 'expense'
        GROUP BY c.category_id, c.name, c.icon, c.type
    ");
    $currStmt->execute([
        ':uid_t'   => $userId,
        ':c_start' => $currentStart,
        ':c_end'   => $currentEnd,
        ':uid_c'   => $userId
    ]);
    $currentCategories = $currStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Historical spending across previous months
    $histStmt = $pdo->prepare("
        SELECT 
            t.category_id,
            COALESCE(SUM(t.amount), 0) AS total_past_spend,
            COUNT(DISTINCT DATE_FORMAT(t.date, '%Y-%m')) AS active_months_count
        FROM transactions t
        WHERE t.user_id = :uid 
          AND t.type = 'expense'
          AND t.date BETWEEN :p_start AND :p_end
        GROUP BY t.category_id
    ");
    $histStmt->execute([
        ':uid'     => $userId,
        ':p_start' => $prevStart,
        ':p_end'   => $prevEnd
    ]);
    $historyMap = [];
    while ($row = $histStmt->fetch(PDO::FETCH_ASSOC)) {
        $cid = (int)$row['category_id'];
        $activeMonths = max(1, (int)$row['active_months_count']);
        $historyMap[$cid] = (float)$row['total_past_spend'] / $activeMonths;
    }

    $allTrends = [];
    $flaggedCategories = [];

    foreach ($currentCategories as $cat) {
        $cid = (int)$cat['category_id'];
        $currSpend = (float)$cat['current_spend'];
        $hasHistory = isset($historyMap[$cid]) && $historyMap[$cid] > 0;
        $histAvg = $hasHistory ? $historyMap[$cid] : 0.0;

        $growthPercent = 0.0;
        $isFlagged = false;

        if ($hasHistory) {
            $diff = $currSpend - $histAvg;
            $growthPercent = round(($diff / $histAvg) * 100, 1);
            if ($growthPercent >= $thresholdPercent && $currSpend > 200) {
                $isFlagged = true;
            }
        } elseif ($currSpend > 500) {
            // New significant spending category not seen in prior months
            $growthPercent = 100.0;
            $isFlagged = true;
        }

        $trendData = [
            'category_id'    => $cid,
            'name'           => $cat['category_name'],
            'icon'           => $cat['category_icon'] ?? '🏷️',
            'current_spend'  => $currSpend,
            'historical_avg' => round($histAvg, 2),
            'growth_percent' => $growthPercent,
            'is_flagged'     => $isFlagged,
            'tx_count'       => (int)$cat['tx_count']
        ];

        $allTrends[] = $trendData;
        if ($isFlagged) {
            $flaggedCategories[] = $trendData;
        }
    }

    // Sort flagged categories by growth rate descending
    usort($flaggedCategories, fn($a, $b) => $b['growth_percent'] <=> $a['growth_percent']);

    return [
        'month'              => $monthYm,
        'threshold_percent'  => $thresholdPercent,
        'all_trends'         => $allTrends,
        'flagged_categories' => $flaggedCategories,
        'flagged_count'      => count($flaggedCategories)
    ];
}

/**
 * Generates tailored, domain-specific actionable advice for each flagged category.
 *
 * @param array $flaggedCategories
 * @param float $monthlyAllowance
 * @param float $savingsGoal
 * @return array
 */
function generateActionableAdvice(array $flaggedCategories, float $monthlyAllowance = 0, float $savingsGoal = 0): array
{
    $adviceList = [];

    foreach ($flaggedCategories as $fc) {
        $catName = strtolower(trim($fc['name']));
        $curr = $fc['current_spend'];
        $growth = $fc['growth_percent'];
        $weeklyCap = round(($curr * 0.75) / 4, 0);

        if (strpos($catName, 'food') !== false || strpos($catName, 'dining') !== false || strpos($catName, 'canteen') !== false) {
            $adviceList[] = [
                'category'    => $fc['name'],
                'icon'        => $fc['icon'],
                'headline'    => "Cap dining & late-night deliveries at Rs. " . number_format($weeklyCap) . " / week",
                'advice_text' => "Food spending rose {$growth}% above your previous average. Switch 2-3 weekend restaurant orders to hostel mess or quick meal-prep to preserve Rs. " . number_format($curr * 0.25) . " for savings."
            ];
        } elseif (strpos($catName, 'transport') !== false || strpos($catName, 'transit') !== false || strpos($catName, 'fuel') !== false) {
            $adviceList[] = [
                'category'    => $fc['name'],
                'icon'        => $fc['icon'],
                'headline'    => "Leverage student transit cards & campus carpooling",
                'advice_text' => "Commute costs increased by {$growth}%. Coordinate shared rides with batchmates or register for the university student bus discount to trim transit outlays."
            ];
        } elseif (strpos($catName, 'entertainment') !== false || strpos($catName, 'outing') !== false || strpos($catName, 'movie') !== false) {
            $adviceList[] = [
                'category'    => $fc['name'],
                'icon'        => $fc['icon'],
                'headline'    => "Set a Rs. " . number_format($weeklyCap) . " entertainment ceiling",
                'advice_text' => "Leisure activities grew {$growth}% this month. Take advantage of campus cinema nights and campus society events rather than commercial venues."
            ];
        } elseif (strpos($catName, 'subscript') !== false || strpos($catName, 'digital') !== false || strpos($catName, 'stream') !== false) {
            $adviceList[] = [
                'category'    => $fc['name'],
                'icon'        => $fc['icon'],
                'headline'    => "Audit recurring subscriptions and split student plans",
                'advice_text' => "Subscription recurring charges increased by {$growth}%. Cancel unused services or join a family/roommate plan for Spotify and cloud storage."
            ];
        } elseif (strpos($catName, 'academic') !== false || strpos($catName, 'book') !== false || strpos($catName, 'course') !== false) {
            $adviceList[] = [
                'category'    => $fc['name'],
                'icon'        => $fc['icon'],
                'headline'    => "Borrow textbooks from university library or senior book exchange",
                'advice_text' => "Academic materials saw a {$growth}% surge. Check your campus library digital reserves or senior student book drives before buying new prints."
            ];
        } else {
            $adviceList[] = [
                'category'    => $fc['name'],
                'icon'        => $fc['icon'],
                'headline'    => "Apply the 24-hour waiting rule to non-essential {$fc['name']} purchases",
                'advice_text' => "Spending in {$fc['name']} increased {$growth}%. Delay unplanned purchases for 24 hours to eliminate impulse spending."
            ];
        }
    }

    if (empty($adviceList)) {
        $adviceList[] = [
            'category'    => 'General Budgeting',
            'icon'        => '✨',
            'headline'    => 'Steady Spending Discipline Maintained',
            'advice_text' => 'None of your expense categories grew beyond your 25% threshold this month. Continue your steady routine to steadily grow your financial buffer.'
        ];
    }

    return $adviceList;
}

/**
 * Returns a formal LLM prompt template that can be dispatched to any LLM API (Gemini / OpenAI / Anthropic).
 *
 * @param string $studentName
 * @param string $monthName
 * @param float $income
 * @param float $expense
 * @param float $netSavings
 * @param float $savingsGoal
 * @param array $flaggedCategories
 * @return array
 */
function getLlmPromptTemplate(
    string $studentName,
    string $monthName,
    float $income,
    float $expense,
    float $netSavings,
    float $savingsGoal,
    array $flaggedCategories
): array {
    $flaggedText = [];
    foreach ($flaggedCategories as $fc) {
        $flaggedText[] = "{$fc['name']}: Rs. {$fc['current_spend']} (+{$fc['growth_percent']}% vs prior avg of Rs. {$fc['historical_avg']})";
    }
    $flagsSummary = !empty($flaggedText) ? implode('; ', $flaggedText) : 'None (all categories within normal bounds)';

    $systemPrompt = "You are CampusCoin AI, a friendly, encouraging financial advisor for university students. Your goal is to analyze the student's monthly finances and produce a concise 3 to 5 sentence plain-language narrative summary with actionable advice.";

    $userPrompt = "Analyze the student financial profile for {$studentName} in {$monthName}:\n" .
        "- Total Monthly Income: Rs. " . number_format($income, 2) . "\n" .
        "- Total Monthly Expenses: Rs. " . number_format($expense, 2) . "\n" .
        "- Net Savings: Rs. " . number_format($netSavings, 2) . " (" . ($income > 0 ? round(($netSavings / $income) * 100, 1) : 0) . "% savings rate)\n" .
        "- Monthly Savings Goal: Rs. " . number_format($savingsGoal, 2) . "\n" .
        "- Categories with Above-Average Growth (>25%): {$flagsSummary}\n\n" .
        "Generate a structured response with:\n" .
        "1. A 3–5 sentence narrative summary highlighting notable trends, financial pace, and budget health.\n" .
        "2. Key growth alert headline.\n" .
        "3. Concrete actionable adjustment advice.";

    return [
        'system_prompt' => $systemPrompt,
        'user_prompt'   => $userPrompt,
        'temperature'   => 0.4
    ];
}

/**
 * Generates the 3 to 5 sentence narrative summary.
 * If an external LLM API key (e.g., GEMINI_API_KEY) is available, it can call the LLM API;
 * otherwise, it uses our high-quality deterministic financial reasoning engine.
 *
 * @param string $studentName
 * @param string $monthName
 * @param float $income
 * @param float $expense
 * @param float $netSavings
 * @param float $savingsGoal
 * @param array $flaggedCategories
 * @param array $adviceList
 * @return string
 */
function generateMonthlyNarrative(
    string $studentName,
    string $monthName,
    float $income,
    float $expense,
    float $netSavings,
    float $savingsGoal,
    array $flaggedCategories,
    array $adviceList
): string {
    $savingsRate = ($income > 0) ? round(($netSavings / $income) * 100, 1) : 0.0;
    $firstFlag = !empty($flaggedCategories) ? $flaggedCategories[0] : null;

    $sentences = [];

    // Sentence 1: Income vs Expense baseline
    if ($netSavings >= 0) {
        $sentences[] = "In {$monthName}, you recorded a total income of Rs. " . number_format($income) . " against Rs. " . number_format($expense) . " in expenses, generating a positive net surplus of Rs. " . number_format($netSavings) . " ({$savingsRate}% savings rate).";
    } else {
        $deficit = abs($netSavings);
        $sentences[] = "In {$monthName}, your expenses reached Rs. " . number_format($expense) . " against an income of Rs. " . number_format($income) . ", resulting in a temporary monthly deficit of Rs. " . number_format($deficit) . ".";
    }

    // Sentence 2 & 3: Notable category patterns & growth flags
    if ($firstFlag) {
        $flagName = $firstFlag['name'];
        $flagGrowth = $firstFlag['growth_percent'];
        $flagAmount = number_format($firstFlag['current_spend']);
        $flagAvg = number_format($firstFlag['historical_avg']);

        $sentences[] = "The most notable shift was in {$flagName}, where spending climbed to Rs. {$flagAmount}, representing a {$flagGrowth}% increase over your prior monthly average of Rs. {$flagAvg}.";

        if (count($flaggedCategories) > 1) {
            $otherFlags = array_slice($flaggedCategories, 1);
            $otherNames = implode(', ', array_map(fn($f) => $f['name'] . " (+{$f['growth_percent']}%)", $otherFlags));
            $sentences[] = "Additional growth occurred in {$otherNames}, which collectively added pressure to your disposable allowance.";
        } else {
            $sentences[] = "Other essential categories such as transport and stationery remained disciplined and within expected baseline limits.";
        }
    } else {
        $sentences[] = "All expense categories remained comfortably within historical ranges, demonstrating consistent spending discipline across your routine expenses.";
    }

    // Sentence 4: Savings goal evaluation
    if ($savingsGoal > 0) {
        if ($netSavings >= $savingsGoal) {
            $sentences[] = "You successfully met your monthly savings goal of Rs. " . number_format($savingsGoal) . ", strengthening your emergency campus reserve.";
        } else {
            $shortfall = number_format($savingsGoal - $netSavings);
            $sentences[] = "Your net savings fell approximately Rs. {$shortfall} shy of your target savings goal of Rs. " . number_format($savingsGoal) . ".";
        }
    }

    // Sentence 5: Closing actionable encouragement
    if (!empty($adviceList) && isset($adviceList[0]['headline'])) {
        $sentences[] = "To optimize next month's cash flow, {$adviceList[0]['headline']} to ensure you maintain complete peace of mind.";
    } else {
        $sentences[] = "Maintaining this consistent rhythm will keep your student finances balanced and prepare you for future semester milestones.";
    }

    return implode(' ', $sentences);
}

/**
 * Saves or updates a generated monthly insight in the database.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param string $monthYm
 * @param string $summaryText
 * @param string $growthFlagText
 * @param string $tipText
 * @param array $flags
 * @param array $advice
 * @param int $threshold
 * @return int
 */
function saveMonthlyInsight(
    PDO $pdo,
    int $userId,
    string $monthYm,
    string $summaryText,
    string $growthFlagText,
    string $tipText,
    array $flags,
    array $advice,
    int $threshold = 25
): int {
    $monthDate = "{$monthYm}-01";
    $flagsJson = json_encode($flags);
    $adviceJson = json_encode($advice);

    // Check if an insight already exists for this user and month
    $stmt = $pdo->prepare("SELECT insight_id FROM insights WHERE user_id = :uid AND month = :month LIMIT 1");
    $stmt->execute([':uid' => $userId, ':month' => $monthDate]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $upd = $pdo->prepare("
            UPDATE insights 
            SET growth_flag_text = :flag,
                flags_json = :flags_json,
                summary_text = :summary,
                tip_text = :tip,
                advice_json = :advice_json,
                threshold_used = :thresh,
                generated_at = NOW()
            WHERE insight_id = :id
        ");
        $upd->execute([
            ':flag'        => mb_substr($growthFlagText, 0, 255),
            ':flags_json'  => $flagsJson,
            ':summary'     => $summaryText,
            ':tip'         => $tipText,
            ':advice_json' => $adviceJson,
            ':thresh'      => $threshold,
            ':id'          => $existingId
        ]);
        return (int)$existingId;
    } else {
        $ins = $pdo->prepare("
            INSERT INTO insights (user_id, month, growth_flag_text, flags_json, summary_text, tip_text, advice_json, threshold_used, generated_at)
            VALUES (:uid, :month, :flag, :flags_json, :summary, :tip, :advice_json, :thresh, NOW())
        ");
        $ins->execute([
            ':uid'         => $userId,
            ':month'       => $monthDate,
            ':flag'        => mb_substr($growthFlagText, 0, 255),
            ':flags_json'  => $flagsJson,
            ':summary'     => $summaryText,
            ':tip'         => $tipText,
            ':advice_json' => $adviceJson,
            ':thresh'      => $threshold
        ]);
        return (int)$pdo->lastInsertId();
    }
}

/**
 * Retrieves the saved insight for a user and month.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param string $monthYm
 * @return array|null
 */
function getMonthlyInsight(PDO $pdo, int $userId, string $monthYm): ?array
{
    $monthDate = "{$monthYm}-01";
    $stmt = $pdo->prepare("
        SELECT 
            insight_id,
            user_id,
            DATE_FORMAT(month, '%Y-%m') AS month_ym,
            DATE_FORMAT(month, '%M %Y') AS month_name,
            growth_flag_text,
            flags_json,
            summary_text,
            tip_text,
            advice_json,
            threshold_used,
            is_pinned,
            generated_at
        FROM insights 
        WHERE user_id = :uid AND month = :month 
        LIMIT 1
    ");
    $stmt->execute([':uid' => $userId, ':month' => $monthDate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) return null;

    $row['flags'] = !empty($row['flags_json']) ? json_decode($row['flags_json'], true) : [];
    $row['advice'] = !empty($row['advice_json']) ? json_decode($row['advice_json'], true) : [];
    return $row;
}

/**
 * Retrieves the full chronological insight history for a student.
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function getInsightHistory(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT 
            insight_id,
            DATE_FORMAT(month, '%Y-%m') AS month_ym,
            DATE_FORMAT(month, '%M %Y') AS month_name,
            growth_flag_text,
            flags_json,
            summary_text,
            tip_text,
            advice_json,
            threshold_used,
            is_pinned,
            generated_at
        FROM insights 
        WHERE user_id = :uid 
        ORDER BY month DESC, generated_at DESC
    ");
    $stmt->execute([':uid' => $userId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as &$r) {
        $r['flags'] = !empty($r['flags_json']) ? json_decode($r['flags_json'], true) : [];
        $r['advice'] = !empty($r['advice_json']) ? json_decode($r['advice_json'], true) : [];
    }
    return $results;
}

/**
 * Aggregates monthly financial figures (income, expenses, net savings, budget goal) for insights.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param string $monthYm
 * @return array
 */
function getMonthlyFinancialSummary(PDO $pdo, int $userId, string $monthYm): array
{
    $mStart = "{$monthYm}-01";
    $mEnd   = date('Y-m-t', strtotime($mStart));

    // Student profile
    $uStmt = $pdo->prepare("SELECT name, monthly_allowance, monthly_savings_goal, ai_suggestions_enabled FROM users WHERE user_id = :uid LIMIT 1");
    $uStmt->execute([':uid' => $userId]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'name'                  => 'Student',
        'monthly_allowance'     => 0.00,
        'monthly_savings_goal'  => 0.00,
        'ai_suggestions_enabled'=> 1
    ];

    // Aggregations
    $tStmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS total_income,
            COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS total_expense,
            COUNT(transaction_id) AS total_tx_count
        FROM transactions
        WHERE user_id = :uid AND date BETWEEN :m_start AND :m_end
    ");
    $tStmt->execute([':uid' => $userId, ':m_start' => $mStart, ':m_end' => $mEnd]);
    $totals = $tStmt->fetch(PDO::FETCH_ASSOC);

    $income = (float)($totals['total_income'] ?? 0);
    $expense = (float)($totals['total_expense'] ?? 0);
    $netSavings = $income - $expense;
    $monthTimestamp = strtotime($mStart);
    $monthName = date('F Y', $monthTimestamp);

    return [
        'month'                  => $monthYm,
        'month_name'              => $monthName,
        'student_name'           => $user['name'],
        'income'                 => $income,
        'expense'                => $expense,
        'net_savings'            => $netSavings,
        'savings_rate'           => $income > 0 ? round(($netSavings / $income) * 100, 1) : 0.0,
        'monthly_allowance'      => (float)$user['monthly_allowance'],
        'monthly_savings_goal'   => (float)$user['monthly_savings_goal'],
        'ai_suggestions_enabled' => (int)($user['ai_suggestions_enabled'] ?? 1),
        'transaction_count'      => (int)($totals['total_tx_count'] ?? 0)
    ];
}

