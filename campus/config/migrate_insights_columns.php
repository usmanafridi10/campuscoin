<?php
require_once __DIR__ . '/../config.php';
$pdo = getDbConnection();

try {
    $cols = $pdo->query("SHOW COLUMNS FROM insights")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('flags_json', $cols, true)) {
        $pdo->exec("ALTER TABLE insights ADD COLUMN flags_json TEXT DEFAULT NULL AFTER growth_flag_text");
        echo "Added flags_json to insights.\n";
    }
    if (!in_array('advice_json', $cols, true)) {
        $pdo->exec("ALTER TABLE insights ADD COLUMN advice_json TEXT DEFAULT NULL AFTER tip_text");
        echo "Added advice_json to insights.\n";
    }
    if (!in_array('threshold_used', $cols, true)) {
        $pdo->exec("ALTER TABLE insights ADD COLUMN threshold_used INT NOT NULL DEFAULT 25 AFTER is_pinned");
        echo "Added threshold_used to insights.\n";
    }
    echo "Insights table migration complete.\n";
} catch (PDOException $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
}
