<?php
require_once __DIR__ . '/../config.php';

$pdo = getDbConnection();

echo "Running AI Learning Tables Migration...\n";

$sql = "
CREATE TABLE IF NOT EXISTS `category_keywords` (
    `keyword_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `keyword` VARCHAR(100) NOT NULL,
    `weight` DECIMAL(8, 2) NOT NULL DEFAULT 1.00,
    `times_confirmed` INT UNSIGNED NOT NULL DEFAULT 0,
    `source` ENUM('seed', 'user_correction') NOT NULL DEFAULT 'user_correction',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`keyword_id`),
    INDEX `idx_cat_kw_user` (`user_id`),
    INDEX `idx_cat_kw_keyword` (`keyword`),
    INDEX `idx_cat_kw_user_kw` (`user_id`, `keyword`),
    CONSTRAINT `fk_cat_kw_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cat_kw_cat` FOREIGN KEY (`category_id`) 
        REFERENCES `categories` (`category_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `category_suggestion_log` (
    `log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `transaction_id` INT UNSIGNED DEFAULT NULL,
    `description` VARCHAR(255) NOT NULL,
    `suggested_category_id` INT UNSIGNED DEFAULT NULL,
    `final_category_id` INT UNSIGNED NOT NULL,
    `is_accepted` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`log_id`),
    KEY `fk_sugg_log_user` (`user_id`),
    KEY `fk_sugg_log_trans` (`transaction_id`),
    KEY `fk_sugg_log_sugg_cat` (`suggested_category_id`),
    KEY `fk_sugg_log_final_cat` (`final_category_id`),
    INDEX `idx_sugg_log_user_acc` (`user_id`, `is_accepted`),
    CONSTRAINT `fk_sugg_log_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sugg_log_trans` FOREIGN KEY (`transaction_id`) 
        REFERENCES `transactions` (`transaction_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_sugg_log_sugg_cat` FOREIGN KEY (`suggested_category_id`) 
        REFERENCES `categories` (`category_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_sugg_log_final_cat` FOREIGN KEY (`final_category_id`) 
        REFERENCES `categories` (`category_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

$pdo->exec($sql);
echo "Tables category_keywords and category_suggestion_log created successfully!\n";

// Seed initial global keywords (user_id IS NULL)
$checkSeed = $pdo->query("SELECT COUNT(*) FROM `category_keywords` WHERE `user_id` IS NULL AND `source` = 'seed'");
if ((int)$checkSeed->fetchColumn() === 0) {
    echo "Seeding initial global category keywords...\n";

    // Map seed categories by name
    $catStmt = $pdo->query("SELECT category_id, LOWER(name) as name FROM categories WHERE is_default = 1");
    $catMap = [];
    while ($row = $catStmt->fetch()) {
        $catMap[$row['name']] = (int)$row['category_id'];
    }

    $seeds = [
        'food' => ['cafe', 'coffee', 'lunch', 'dinner', 'burger', 'pizza', 'biryani', 'tea', 'chai', 'food', 'canteen', 'snack', 'mcdonald', 'kfc', 'bakery', 'shawarma', 'fast food'],
        'transport' => ['bus', 'uber', 'careem', 'rikshaw', 'rickshaw', 'metro', 'train', 'petrol', 'fuel', 'ticket', 'fare', 'transit', 'van', 'bike', 'bus ticket'],
        'hostel/rent' => ['hostel', 'rent', 'mess', 'room', 'electricity', 'roommate', 'laundry', 'hostel fee', 'room rent'],
        'academics' => ['book', 'tuition', 'stationery', 'copy', 'photocopy', 'pen', 'exam', 'fee', 'course', 'library', 'notebook', 'tuition fee'],
        'subscriptions' => ['netflix', 'spotify', 'youtube', 'icloud', 'google', 'prime', 'hosting', 'domain', 'subscription', 'chatgpt'],
        'entertainment' => ['movie', 'cinema', 'outing', 'game', 'gaming', 'bowling', 'concert', 'trip', 'cinema ticket'],
        'allowance' => ['allowance', 'pocket money', 'family', 'dad', 'mom', 'parents', 'monthly allowance'],
        'part-time job' => ['salary', 'job', 'freelance', 'gig', 'internship', 'teaching', 'tutoring', 'stipend', 'part time'],
        'scholarship' => ['scholarship', 'grant', 'aid', 'bursary', 'merit scholarship'],
        'gift' => ['gift', 'eidi', 'present', 'prize', 'birthday gift'],
        'other income' => ['bonus', 'cashback', 'reward', 'refund'],
        'miscellaneous' => ['misc', 'general', 'other', 'fees']
    ];

    $insertSeed = $pdo->prepare("
        INSERT INTO `category_keywords` (`user_id`, `category_id`, `keyword`, `weight`, `times_confirmed`, `source`)
        VALUES (NULL, :cat_id, :keyword, 1.00, 0, 'seed')
    ");

    $count = 0;
    foreach ($seeds as $catName => $keywords) {
        if (!isset($catMap[$catName])) continue;
        $catId = $catMap[$catName];
        foreach ($keywords as $kw) {
            $insertSeed->execute([
                ':cat_id'  => $catId,
                ':keyword' => strtolower(trim($kw))
            ]);
            $count++;
        }
    }
    echo "Seeded {$count} global keyword seeds successfully!\n";
} else {
    echo "Global keyword seeds already exist, skipping seed insertion.\n";
}
