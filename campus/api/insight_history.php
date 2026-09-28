<?php
/**
 * REST API Endpoint: /api/insight_history.php
 *
 * Retrieves historical AI monthly insights for the logged-in student.
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

try {
    $history = getInsightHistory($pdo, $userId);

    echo json_encode([
        'success' => true,
        'count'   => count($history),
        'history' => $history
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to retrieve insight history: ' . $e->getMessage()
    ]);
}
