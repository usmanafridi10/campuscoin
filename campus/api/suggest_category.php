<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = currentUser();
if ($user && isset($user['ai_suggestions_enabled']) && (int)$user['ai_suggestions_enabled'] === 0) {
    echo json_encode(['success' => true, 'suggested' => false, 'enabled' => false, 'message' => 'AI suggestions disabled in settings']);
    exit;
}

$title = trim($_GET['title'] ?? $_GET['description'] ?? '');
if (mb_strlen($title) < 2) {
    echo json_encode(['success' => true, 'suggested' => false, 'enabled' => true]);
    exit;
}

$pdo = getDbConnection();
$userId = currentUserId();

$prediction = predictCategory($pdo, $userId, $title);

if ($prediction) {
    echo json_encode([
        'success'       => true,
        'suggested'     => true,
        'category_id'   => (int)$prediction['category_id'],
        'category_name' => $prediction['name'],
        'type'          => $prediction['type'],
        'icon'          => $prediction['icon'],
        'score'         => (float)$prediction['score'],
        'source'        => $prediction['source']
    ]);
} else {
    echo json_encode([
        'success'   => true,
        'suggested' => false
    ]);
}
