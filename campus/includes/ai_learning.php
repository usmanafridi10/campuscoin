<?php
/**
 * Campus Coin - Adaptive Per-User AI Category Learning Engine
 * 
 * Provides:
 * 1. Token & bigram extraction from descriptions.
 * 2. Per-user category prediction with weights and reinforcement.
 * 3. Learning (upserting tokens, increasing final category weight, decreasing wrong category weight).
 * 4. Confirmation tracking (reinforcement).
 * 5. Suggestion logging and acceptance rate metrics.
 * 6. User category rule management (edit, delete, reset).
 */

if (!defined('CAMPUS_COIN_BOOTSTRAP')) {
    define('CAMPUS_COIN_BOOTSTRAP', true);
}

/**
 * Extracts normalized tokens and adjacent bigrams from a transaction description.
 *
 * @param string $text
 * @return array
 */
function extractTokensAndBigrams(string $text): array
{
    $clean = mb_strtolower(trim($text));
    // Replace non-alphanumeric characters with space
    $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $clean);
    $words = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);

    if (empty($words)) {
        return [];
    }

    // Common English / Urdu Roman stop words to ignore as single tokens
    $stopWords = [
        'a', 'an', 'the', 'in', 'on', 'at', 'to', 'for', 'of', 'and', 'or', 'is', 'was',
        'my', 'by', 'with', 'from', 'as', 'it', 'me', 'we', 'he', 'she', 'they', 'rs',
        'pkr', 'rupees', 'ka', 'ki', 'ke', 'ko', 'se', 'per', 'hai', 'tha', 'thi'
    ];

    $tokens = [];
    $filteredWords = [];

    foreach ($words as $w) {
        if (mb_strlen($w) >= 2 && !in_array($w, $stopWords, true)) {
            $tokens[] = $w;
            $filteredWords[] = $w;
        }
    }

    // Generate bigrams from consecutive words
    $wordCount = count($filteredWords);
    for ($i = 0; $i < $wordCount - 1; $i++) {
        $bigram = $filteredWords[$i] . ' ' . $filteredWords[$i + 1];
        $tokens[] = $bigram;
    }

    return array_values(array_unique($tokens));
}

/**
 * Predicts the most appropriate category for a given description and user.
 * Prioritizes student's own learned keywords over system seed defaults.
 *
 * @param PDO|int $arg1 PDO instance or userId
 * @param int|string|null $arg2 userId or description
 * @param string|null $arg3 description
 * @return array|null
 */
function predictCategory($arg1, $arg2 = null, $arg3 = null): ?array
{
    if ($arg1 instanceof PDO) {
        $pdo = $arg1;
        $userId = (int)$arg2;
        $description = (string)$arg3;
    } else {
        $pdo = getDbConnection();
        $userId = (int)$arg1;
        $description = (string)$arg2;
    }

    $tokens = extractTokensAndBigrams($description);
    if (empty($tokens)) {
        return null;
    }

    // Build prepared query with dynamic placeholders
    $placeholders = [];
    $params = [':uid' => $userId];
    foreach ($tokens as $idx => $token) {
        $key = ':kw' . $idx;
        $placeholders[] = $key;
        $params[$key] = $token;
    }

    $inClause = implode(',', $placeholders);

    // Fetch matching keywords: user's personal rules + global seed rules
    $sql = "
        SELECT 
            ck.keyword_id,
            ck.user_id,
            ck.category_id,
            ck.keyword,
            ck.weight,
            ck.times_confirmed,
            ck.source,
            c.name as category_name,
            c.type as category_type,
            c.icon as category_icon
        FROM category_keywords ck
        JOIN categories c ON ck.category_id = c.category_id
        WHERE (ck.user_id = :uid OR ck.user_id IS NULL)
          AND ck.keyword IN ($inClause)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $matches = $stmt->fetchAll();

    if (empty($matches)) {
        return null;
    }

    // Calculate score per category
    $scores = [];
    $categoryDetails = [];

    foreach ($matches as $row) {
        $catId = (int)$row['category_id'];
        $baseWeight = (float)$row['weight'];
        $confirmed = (int)$row['times_confirmed'];
        $isUserSpecific = ($row['user_id'] !== null && (int)$row['user_id'] === $userId);
        $isBigram = (strpos($row['keyword'], ' ') !== false);

        // Scoring heuristic:
        // - User corrections receive strong precedence (x2.5 multiplier + confirmation bonus)
        // - Bigrams provide stronger context (x1.5 multiplier)
        $score = $baseWeight;
        if ($isBigram) {
            $score *= 1.5;
        }
        if ($isUserSpecific) {
            $score = ($score * 2.5) + ($confirmed * 0.5);
        }

        if (!isset($scores[$catId])) {
            $scores[$catId] = 0.0;
            $categoryDetails[$catId] = [
                'category_id'   => $catId,
                'name'          => $row['category_name'],
                'category_name' => $row['category_name'],
                'type'          => $row['category_type'],
                'category_type' => $row['category_type'],
                'icon'          => $row['category_icon'],
                'source'        => $isUserSpecific ? 'user_correction' : 'seed'
            ];
        }

        $scores[$catId] += $score;
        if ($isUserSpecific) {
            $categoryDetails[$catId]['source'] = 'user_correction';
        }
    }

    if (empty($scores)) {
        return null;
    }

    // Find the category with highest score
    arsort($scores);
    $bestCatId = key($scores);

    $best = $categoryDetails[$bestCatId];
    $best['score'] = round($scores[$bestCatId], 2);

    return $best;
}

/**
 * Learns from user category selections or overrides.
 *
 * @param PDO|int $arg1
 * @param int|string $arg2
 * @param string|int $arg3
 * @param int|null $arg4
 * @param int|null $arg5
 * @return void
 */
function learnCategory($arg1, $arg2 = null, $arg3 = null, $arg4 = null, $arg5 = null): void
{
    if ($arg1 instanceof PDO) {
        $pdo = $arg1;
        $userId = (int)$arg2;
        $description = (string)$arg3;
        $finalCategoryId = (int)$arg4;
        $wrongSuggestedCategoryId = $arg5 !== null ? (int)$arg5 : null;
    } else {
        $pdo = getDbConnection();
        $userId = (int)$arg1;
        $description = (string)$arg2;
        $finalCategoryId = (int)$arg3;
        $wrongSuggestedCategoryId = $arg4 !== null ? (int)$arg4 : null;
    }

    $tokens = extractTokensAndBigrams($description);
    if (empty($tokens) || $userId <= 0 || $finalCategoryId <= 0) {
        return;
    }

    $findStmt = $pdo->prepare("
        SELECT keyword_id, weight 
        FROM category_keywords 
        WHERE user_id = :uid AND category_id = :cid AND keyword = :kw
        LIMIT 1
    ");

    $updateWeightStmt = $pdo->prepare("
        UPDATE category_keywords 
        SET weight = weight + 1.00, source = 'user_correction', updated_at = NOW() 
        WHERE keyword_id = :kid
    ");

    $insertStmt = $pdo->prepare("
        INSERT INTO category_keywords (user_id, category_id, keyword, weight, times_confirmed, source)
        VALUES (:uid, :cid, :kw, 2.00, 0, 'user_correction')
    ");

    $decreaseStmt = $pdo->prepare("
        UPDATE category_keywords 
        SET weight = GREATEST(0.00, weight - 0.50), updated_at = NOW() 
        WHERE user_id = :uid AND category_id = :wrong_cid AND keyword = :kw
    ");

    foreach ($tokens as $token) {
        // 1. Reinforce/Insert token for final category (per-user)
        $findStmt->execute([
            ':uid' => $userId,
            ':cid' => $finalCategoryId,
            ':kw'  => $token
        ]);
        $existing = $findStmt->fetch();

        if ($existing) {
            $updateWeightStmt->execute([':kid' => $existing['keyword_id']]);
        } else {
            $insertStmt->execute([
                ':uid' => $userId,
                ':cid' => $finalCategoryId,
                ':kw'  => $token
            ]);
        }

        // 2. Decrease weight for wrongly suggested category (per-user only, floor at 0.00)
        if ($wrongSuggestedCategoryId !== null && $wrongSuggestedCategoryId !== $finalCategoryId) {
            $decreaseStmt->execute([
                ':uid'       => $userId,
                ':wrong_cid' => $wrongSuggestedCategoryId,
                ':kw'        => $token
            ]);
        }
    }
}

/**
 * Increments reinforcement when student accepts the AI suggestion.
 *
 * @param PDO|int $arg1
 * @param int|string $arg2
 * @param string|int $arg3
 * @param int|null $arg4
 * @return void
 */
function confirmCategorySuggestion($arg1, $arg2 = null, $arg3 = null, $arg4 = null): void
{
    if ($arg1 instanceof PDO) {
        $pdo = $arg1;
        $userId = (int)$arg2;
        $description = (string)$arg3;
        $confirmedCategoryId = (int)$arg4;
    } else {
        $pdo = getDbConnection();
        $userId = (int)$arg1;
        $description = (string)$arg2;
        $confirmedCategoryId = (int)$arg3;
    }

    $tokens = extractTokensAndBigrams($description);
    if (empty($tokens) || $userId <= 0 || $confirmedCategoryId <= 0) {
        return;
    }

    $findStmt = $pdo->prepare("
        SELECT keyword_id, times_confirmed 
        FROM category_keywords 
        WHERE user_id = :uid AND category_id = :cid AND keyword = :kw
        LIMIT 1
    ");

    $incrementStmt = $pdo->prepare("
        UPDATE category_keywords 
        SET times_confirmed = times_confirmed + 1, weight = weight + 0.50, updated_at = NOW() 
        WHERE keyword_id = :kid
    ");

    $insertConfirmedStmt = $pdo->prepare("
        INSERT INTO category_keywords (user_id, category_id, keyword, weight, times_confirmed, source)
        VALUES (:uid, :cid, :kw, 1.50, 1, 'user_correction')
    ");

    foreach ($tokens as $token) {
        $findStmt->execute([
            ':uid' => $userId,
            ':cid' => $confirmedCategoryId,
            ':kw'  => $token
        ]);
        $existing = $findStmt->fetch();

        if ($existing) {
            $incrementStmt->execute([':kid' => $existing['keyword_id']]);
        } else {
            // Establish as user-specific confirmed keyword
            $insertConfirmedStmt->execute([
                ':uid' => $userId,
                ':cid' => $confirmedCategoryId,
                ':kw'  => $token
            ]);
        }
    }
}

/**
 * Compares AI suggestion with final category, writes to category_suggestion_log,
 * and triggers learn() or confirmCategorySuggestion().
 *
 * @param PDO|int $arg1
 * @param mixed $arg2
 * @param mixed $arg3
 * @param mixed $arg4
 * @param mixed $arg5
 * @param mixed $arg6
 * @return bool
 */
function recordCategorySuggestionLog($arg1, $arg2 = null, $arg3 = null, $arg4 = null, $arg5 = null, $arg6 = null): bool
{
    if ($arg1 instanceof PDO) {
        $pdo = $arg1;
        $userId = (int)$arg2;
        $transId = $arg3 !== null ? (int)$arg3 : null;
        $description = (string)$arg4;
        $suggestedCatId = $arg5 !== null ? (int)$arg5 : null;
        $finalCatId = (int)$arg6;
    } else {
        $pdo = getDbConnection();
        $userId = (int)$arg1;
        $transId = $arg2 !== null ? (int)$arg2 : null;
        $description = (string)$arg3;
        $suggestedCatId = $arg4 !== null ? (int)$arg4 : null;
        $finalCatId = (int)$arg5;
    }

    if ($userId <= 0 || $finalCatId <= 0) {
        return false;
    }

    // If suggestedCatId was not provided, predict what AI would have suggested
    if ($suggestedCatId === null || $suggestedCatId <= 0) {
        $prediction = predictCategory($pdo, $userId, $description);
        $suggestedCatId = $prediction ? (int)$prediction['category_id'] : null;
    }

    $isAccepted = ($suggestedCatId !== null && (int)$suggestedCatId === (int)$finalCatId) ? 1 : 0;

    // Insert suggestion log entry
    $logStmt = $pdo->prepare("
        INSERT INTO category_suggestion_log 
            (user_id, transaction_id, description, suggested_category_id, final_category_id, is_accepted)
        VALUES 
            (:uid, :tid, :desc, :sugg, :final, :acc)
    ");
    $logStmt->execute([
        ':uid'   => $userId,
        ':tid'   => $transId,
        ':desc'  => mb_substr(trim($description), 0, 255),
        ':sugg'  => $suggestedCatId,
        ':final' => $finalCatId,
        ':acc'   => $isAccepted
    ]);

    // Update transactions.ai_suggested_category if transaction_id exists
    if ($transId !== null && $transId > 0 && $suggestedCatId !== null) {
        $updateTrans = $pdo->prepare("UPDATE transactions SET ai_suggested_category = :sugg WHERE transaction_id = :tid AND user_id = :uid");
        $updateTrans->execute([':sugg' => $suggestedCatId, ':tid' => $transId, ':uid' => $userId]);
    }

    // Trigger learning or reinforcement
    if ($isAccepted) {
        confirmCategorySuggestion($pdo, $userId, $description, $finalCatId);
    } else {
        learnCategory($pdo, $userId, $description, $finalCatId, $suggestedCatId);
    }

    return true;
}

/**
 * Computes the student's AI category acceptance rate and stats.
 *
 * @param PDO|int $arg1
 * @param int|null $arg2
 * @return array
 */
function getCategoryAiStats($arg1, $arg2 = null): array
{
    if ($arg1 instanceof PDO) {
        $pdo = $arg1;
        $userId = (int)$arg2;
    } else {
        $pdo = getDbConnection();
        $userId = (int)$arg1;
    }

    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_logged,
            COALESCE(SUM(CASE WHEN suggested_category_id IS NOT NULL THEN 1 ELSE 0 END), 0) as total_suggestions,
            COALESCE(SUM(CASE WHEN is_accepted = 1 AND suggested_category_id IS NOT NULL THEN 1 ELSE 0 END), 0) as accepted_suggestions
        FROM category_suggestion_log
        WHERE user_id = :uid
    ");
    $statsStmt->execute([':uid' => $userId]);
    $row = $statsStmt->fetch();

    $rulesStmt = $pdo->prepare("SELECT COUNT(*) FROM category_keywords WHERE user_id = :uid");
    $rulesStmt->execute([':uid' => $userId]);
    $rulesCount = (int)$rulesStmt->fetchColumn();

    $totalSuggestions = (int)($row['total_suggestions'] ?? 0);
    $accepted = (int)($row['accepted_suggestions'] ?? 0);
    $ratePercentage = ($totalSuggestions > 0) ? round(($accepted / $totalSuggestions) * 100, 1) : 0.0;

    return [
        'total_logged'          => (int)($row['total_logged'] ?? 0),
        'total_suggestions'     => $totalSuggestions,
        'accepted'              => $accepted,
        'accepted_suggestions'  => $accepted,
        'rate_percentage'       => $ratePercentage,
        'acceptance_rate'       => $ratePercentage,
        'rules_count'           => $rulesCount,
        'user_rules_count'      => $rulesCount
    ];
}

/**
 * Returns all learned category rules for the student.
 *
 * @param PDO|int $arg1
 * @param int|null $arg2
 * @return array
 */
function getUserCategoryRules($arg1, $arg2 = null): array
{
    if ($arg1 instanceof PDO) {
        $pdo = $arg1;
        $userId = (int)$arg2;
    } else {
        $pdo = getDbConnection();
        $userId = (int)$arg1;
    }

    $stmt = $pdo->prepare("
        SELECT 
            ck.keyword_id,
            ck.keyword,
            ck.weight,
            ck.times_confirmed,
            ck.source,
            ck.updated_at,
            c.category_id,
            c.name as category_name,
            c.type as category_type,
            c.icon as category_icon
        FROM category_keywords ck
        JOIN categories c ON ck.category_id = c.category_id
        WHERE ck.user_id = :uid
        ORDER BY ck.weight DESC, ck.times_confirmed DESC, ck.updated_at DESC
    ");
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

/**
 * Updates a learned category rule.
 *
 * @param PDO|int $arg1
 * @param mixed $arg2
 * @param mixed $arg3
 * @param mixed $arg4
 * @param mixed $arg5
 * @return bool
 */
function updateUserCategoryRule($arg1, $arg2 = null, $arg3 = null, $arg4 = null, $arg5 = null): bool
{
    if ($arg1 instanceof PDO) {
        $pdo = $arg1;
        $userId = (int)$arg2;
        $keywordId = (int)$arg3;
        $newCategoryId = (int)$arg4;
        $newWeight = (float)$arg5;
    } else {
        $pdo = getDbConnection();
        $userId = (int)$arg1;
        $keywordId = (int)$arg2;
        $newCategoryId = (int)$arg3;
        $newWeight = (float)$arg4;
    }

    $stmt = $pdo->prepare("
        UPDATE category_keywords 
        SET category_id = :cid, weight = :weight, source = 'user_correction', updated_at = NOW() 
        WHERE keyword_id = :kid AND user_id = :uid
    ");
    return $stmt->execute([
        ':cid'    => $newCategoryId,
        ':weight' => max(0.1, min(99.0, $newWeight)),
        ':kid'    => $keywordId,
        ':uid'    => $userId
    ]);
}

/**
 * Deletes a learned category rule.
 *
 * @param PDO|int $arg1
 * @param mixed $arg2
 * @param mixed $arg3
 * @return bool
 */
function deleteUserCategoryRule($arg1, $arg2 = null, $arg3 = null): bool
{
    if ($arg1 instanceof PDO) {
        $pdo = $arg1;
        $userId = (int)$arg2;
        $keywordId = (int)$arg3;
    } else {
        $pdo = getDbConnection();
        $userId = (int)$arg1;
        $keywordId = (int)$arg2;
    }

    $stmt = $pdo->prepare("
        DELETE FROM category_keywords 
        WHERE keyword_id = :kid AND user_id = :uid
    ");
    return $stmt->execute([
        ':kid' => $keywordId,
        ':uid' => $userId
    ]);
}

/**
 * Resets a student's learned rules and suggestion logs.
 *
 * @param PDO|int $arg1
 * @param int|null $arg2
 * @return bool
 */
function resetUserAiLearning($arg1, $arg2 = null): bool
{
    if ($arg1 instanceof PDO) {
        $pdo = $arg1;
        $userId = (int)$arg2;
    } else {
        $pdo = getDbConnection();
        $userId = (int)$arg1;
    }

    $stmt1 = $pdo->prepare("DELETE FROM category_keywords WHERE user_id = :uid");
    $stmt1->execute([':uid' => $userId]);

    $stmt2 = $pdo->prepare("DELETE FROM category_suggestion_log WHERE user_id = :uid");
    $stmt2->execute([':uid' => $userId]);

    return true;
}

