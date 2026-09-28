<?php
/**
 * REST API Endpoint: /correct-category
 *
 * Accepts student category corrections or confirmations:
 * - Records entry in category_suggestion_log
 * - Runs learnCategory() on overrides or confirmCategorySuggestion() on acceptance
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

// Support both JSON payload and multipart/urlencoded POST
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$description = trim($input['description'] ?? '');
$finalCategoryId = (int)($input['final_category_id'] ?? $input['category_id'] ?? 0);
$suggestedCategoryId = isset($input['suggested_category_id']) && $input['suggested_category_id'] !== ''
    ? (int)$input['suggested_category_id'] 
    : null;
$transactionId = isset($input['transaction_id']) && (int)$input['transaction_id'] > 0 
    ? (int)$input['transaction_id'] 
    : null;

if (empty($description) || $finalCategoryId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => 'Missing required fields: description and final_category_id.'
    ]);
    exit;
}

$pdo = getDbConnection();
$userId = currentUserId();

$recorded = recordCategorySuggestionLog($pdo, $userId, $transactionId, $description, $suggestedCategoryId, $finalCategoryId);

$isAccepted = ($suggestedCategoryId !== null && $suggestedCategoryId === $finalCategoryId);

echo json_encode([
    'success'     => $recorded,
    'message'     => $isAccepted ? 'Suggestion confirmed and reinforced.' : 'Correction recorded and AI model updated.',
    'is_accepted' => $isAccepted ? 1 : 0,
    'user_id'     => $userId
]);
