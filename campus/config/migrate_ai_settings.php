<?php
require_once __DIR__ . '/../config.php';
$pdo = getDbConnection();

try {
    $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'ai_suggestions_enabled'")->fetch();
    if (!$check) {
        $pdo->exec("ALTER TABLE users ADD COLUMN ai_suggestions_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER monthly_savings_goal");
        echo "Added ai_suggestions_enabled column to users table.\n";
    } else {
        echo "ai_suggestions_enabled already exists.\n";
    }
} catch (PDOException $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
}
