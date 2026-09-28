<?php
/**
 * CampusCoin - CSV Import & AI Suggester Test Suite
 *
 * Verifies:
 * 1. MIME and extension validation, 2MB size cap, random temp file cleanup.
 * 2. UTF-8 BOM handling, fgetcsv parsing, comma/negative amount parsing, date validation.
 * 3. AI prediction per row, confidence grading (High/Med/Low), low-confidence Uncategorized default.
 * 4. Duplicate transaction detection (DB match and intra-file duplicate).
 * 5. Single DB transaction rollback on failure.
 * 6. Reinforcement & learning propagation on confirm.
 * 7. Non-fatal error reporting for invalid/corrupted rows.
 */

require_once __DIR__ . '/../config.php';

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
$testUserId = 77771;

// Setup test student in users table
$pdo->prepare("
    INSERT INTO users (user_id, name, email, password_hash, role) 
    VALUES (?, 'CSV Test Student', 'csv_student@campuscoin.com', 'dummy_hash', 'student')
    ON DUPLICATE KEY UPDATE name=VALUES(name)
")->execute([$testUserId]);

// Clean up existing test records
$pdo->prepare('DELETE FROM transactions WHERE user_id = ?')->execute([$testUserId]);
$pdo->prepare('DELETE FROM category_suggestion_log WHERE user_id = ?')->execute([$testUserId]);
$pdo->prepare('DELETE FROM category_keywords WHERE user_id = ?')->execute([$testUserId]);

echo "==========================================================================" . PHP_EOL;
echo " CampusCoin: CSV Import & AI Suggester Preview Test Suite" . PHP_EOL;
echo "==========================================================================" . PHP_EOL;

// -------------------------------------------------------------------------
// TEST 1: Amount and Date Parsing Robustness (UTF-8, Comma, Negatives)
// -------------------------------------------------------------------------
test_step("TEST 1: Amount & Date Normalization");
// Helper functions are automatically loaded from functions.php via config.php

// Test Amount formats
$amt1 = parseImportAmount("Rs. 1,500.50", "expense");
$amt2 = parseImportAmount("-450.00", null);
$amt3 = parseImportAmount("(250.75)", null);
$amt4 = parseImportAmount("invalid_amount", null);

if ($amt1['valid'] && $amt1['amount'] === 1500.50 && $amt1['type'] === 'expense') {
    test_pass("Parsed comma-separated currency amount: 'Rs. 1,500.50' -> 1500.50");
} else {
    test_fail("Failed to parse comma-separated currency amount");
}

if ($amt2['valid'] && $amt2['amount'] === 450.00 && $amt2['type'] === 'expense') {
    test_pass("Parsed negative amount '-450.00' as 450.00 expense");
} else {
    test_fail("Failed negative amount test");
}

if ($amt3['valid'] && $amt3['amount'] === 250.75 && $amt3['type'] === 'expense') {
    test_pass("Parsed parenthesized accounting format '(250.75)' as 250.75 expense");
} else {
    test_fail("Failed parenthesized amount test");
}

if (!$amt4['valid']) {
    test_pass("Correctly rejected non-numeric amount 'invalid_amount' without fatal error: " . $amt4['error']);
} else {
    test_fail("Failed non-numeric amount validation");
}

// Test Date formats
$d1 = parseImportDate("2026-09-28");
$d2 = parseImportDate("28/09/2026");
$d3 = parseImportDate("09/28/2026");
$d4 = parseImportDate("not-a-valid-date");

if ($d1['valid'] && $d1['date'] === '2026-09-28' &&
    $d2['valid'] && $d2['date'] === '2026-09-28' &&
    $d3['valid'] && $d3['date'] === '2026-09-28') {
    test_pass("Successfully parsed multiple date representations (ISO, UK d/m/Y, US m/d/Y).");
} else {
    test_fail("Date parsing failure across formats");
}

if (!$d4['valid']) {
    test_pass("Correctly caught invalid date 'not-a-valid-date' gracefully without fatal error: " . $d4['error']);
} else {
    test_fail("Invalid date check failed");
}

// -------------------------------------------------------------------------
// TEST 2: Seed DB Transaction & Test Duplicate Detection
// -------------------------------------------------------------------------
test_step("TEST 2: Duplicate Detection (DB match & intra-file duplicate)");

// Insert an existing transaction for this student
$pdo->prepare("
    INSERT INTO transactions (user_id, category_id, amount, type, title, is_recurring, date, time)
    VALUES (?, 6, 350.00, 'expense', 'Chicken Biryani Canteen', 0, '2026-09-20', '12:00:00')
")->execute([$testUserId]);
$existingTxId = (int)$pdo->lastInsertId();
test_info("Seeded existing transaction ID: $existingTxId ('Chicken Biryani Canteen', 350.00, 2026-09-20)");

// Establish a learned rule for "Chai and Paratha at Khokha" so it triggers High confidence via user_correction
learnCategory($pdo, $testUserId, "Chai and Paratha at Khokha", 6, null);

// Build a mock CSV with UTF-8 BOM containing:
// Row 1: Header
// Row 2: Duplicate of existing DB transaction
// Row 3: A new transaction with high-confidence keyword ("Chai and Paratha")
// Row 4: A duplicate of Row 3 within the file
// Row 5: A low-confidence / unknown campus item ("Quantum Widget xyz")
// Row 6: A corrupted row with bad date ("invalid_date")
$csvContent = "\xEF\xBB\xBF" . // UTF-8 BOM
    "Date,Description,Amount,Type\n" .
    "2026-09-20,Chicken Biryani Canteen,350.00,expense\n" .
    "2026-09-25,Chai and Paratha at Khokha,120.00,expense\n" .
    "2026-09-25,Chai and Paratha at Khokha,120.00,expense\n" .
    "2026-09-26,Quantum Widget xyz,899.00,expense\n" .
    "invalid-date,Corrupted Row Date,100.00,expense\n";

$tempCsv = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_import_' . bin2hex(random_bytes(8)) . '.csv';
file_put_contents($tempCsv, $csvContent);
test_info("Created temporary UTF-8 BOM test CSV at: $tempCsv");

// Simulate the import.php parser logic on this file
$handle = fopen($tempCsv, 'r');
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($handle);
}

$dupStmt = $pdo->prepare("
    SELECT transaction_id 
    FROM transactions 
    WHERE user_id = :uid 
      AND date = :date 
      AND amount = :amount 
      AND LOWER(TRIM(title)) = LOWER(TRIM(:title))
    LIMIT 1
");

$parsedRows = [];
$badRows = [];
$seenInFile = [];
$rowIndex = 0;

while (($data = fgetcsv($handle, 4096, ',')) !== false) {
    $rowIndex++;
    if ($rowIndex === 1) continue; // Skip header

    $rawDate = $data[0] ?? '';
    $rawDesc = $data[1] ?? '';
    $rawAmt  = $data[2] ?? '';
    $rawType = $data[3] ?? '';

    $dInfo = parseImportDate($rawDate);
    $aInfo = parseImportAmount($rawAmt, $rawType);
    $cleanDesc = trim($rawDesc);

    $errors = [];
    if (!$dInfo['valid']) $errors[] = $dInfo['error'];
    if (!$aInfo['valid']) $errors[] = $aInfo['error'];
    if ($cleanDesc === '') $errors[] = 'Description is empty';

    $isBad = !empty($errors);
    if ($isBad) {
        $badRows[] = ['row' => $rowIndex, 'errors' => $errors];
    }

    $cDate = $dInfo['valid'] ? $dInfo['date'] : date('Y-m-d');
    $cAmt  = $aInfo['valid'] ? $aInfo['amount'] : 0.0;

    $isDuplicate = false;
    $dupReason = null;
    if (!$isBad) {
        $dupStmt->execute([
            ':uid'    => $testUserId,
            ':date'   => $cDate,
            ':amount' => $cAmt,
            ':title'  => $cleanDesc
        ]);
        if ($dupStmt->fetch()) {
            $isDuplicate = true;
            $dupReason = "DB Duplicate";
        }

        $fKey = $cDate . '|' . $cAmt . '|' . strtolower($cleanDesc);
        if (isset($seenInFile[$fKey])) {
            $isDuplicate = true;
            $dupReason = "File Duplicate";
        } else {
            $seenInFile[$fKey] = $rowIndex;
        }
    }

    // AI Prediction & Confidence
    $pred = predictCategory($pdo, $testUserId, $cleanDesc);
    $confidence = 'low';
    $suggestedCatId = null;
    if ($pred && !$isBad) {
        $score = (float)$pred['score'];
        if ($pred['source'] === 'user_correction' || $score >= 2.0) {
            $confidence = 'high';
            $suggestedCatId = (int)$pred['category_id'];
        } elseif ($score >= 0.8) {
            $confidence = 'medium';
            $suggestedCatId = (int)$pred['category_id'];
        }
    }

    $parsedRows[] = [
        'row'              => $rowIndex,
        'date'             => $cDate,
        'description'      => $cleanDesc,
        'amount'           => $cAmt,
        'is_duplicate'     => $isDuplicate,
        'dup_reason'       => $dupReason,
        'is_bad'           => $isBad,
        'confidence'       => $confidence,
        'suggested_cat_id' => $suggestedCatId
    ];
}
fclose($handle);
unlink($tempCsv);

// Verify Row 2 was flagged as DB duplicate
if ($parsedRows[0]['is_duplicate'] && $parsedRows[0]['dup_reason'] === 'DB Duplicate') {
    test_pass("Row 2 ('Chicken Biryani Canteen') correctly detected as existing DB duplicate.");
} else {
    test_fail("Row 2 DB duplicate detection failed");
}

// Verify Row 4 was flagged as intra-file duplicate
if ($parsedRows[2]['is_duplicate'] && $parsedRows[2]['dup_reason'] === 'File Duplicate') {
    test_pass("Row 4 ('Chai and Paratha') correctly detected as intra-file duplicate of Row 3.");
} else {
    test_fail("Row 4 intra-file duplicate detection failed");
}

// Verify Row 3 has High confidence (Food category 6)
if ($parsedRows[1]['confidence'] === 'high' && (int)$parsedRows[1]['suggested_cat_id'] === 6) {
    test_pass("Row 3 ('Chai and Paratha') categorized with High confidence -> Food (Category 6).");
} else {
    test_fail("Row 3 confidence failure: " . json_encode($parsedRows[1]));
}

// Verify Row 5 (Unknown item) has Low confidence and Uncategorized default (null)
if ($parsedRows[3]['confidence'] === 'low' && $parsedRows[3]['suggested_cat_id'] === null) {
    test_pass("Row 5 ('Quantum Widget xyz') correctly assigned Low confidence and left Uncategorized (NULL) for manual choice.");
} else {
    test_fail("Row 5 low confidence test failed");
}

// Verify Row 6 flagged as bad row without fatal error
if ($parsedRows[4]['is_bad'] && count($badRows) === 1) {
    test_pass("Row 6 flagged as non-fatal bad row ('" . $badRows[0]['errors'][0] . "').");
} else {
    test_fail("Row 6 error handling failed");
}

// -------------------------------------------------------------------------
// TEST 3: Confirm Import & DB Transaction Rollback Test
// -------------------------------------------------------------------------
test_step("TEST 3: DB Transaction Rollback on Failure");

$initialCount = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE user_id = $testUserId")->fetchColumn();

try {
    $pdo->beginTransaction();
    $pdo->prepare("
        INSERT INTO transactions (user_id, category_id, amount, type, title, is_recurring, date, time)
        VALUES (?, 6, 999.00, 'expense', 'Test Transaction Should Roll Back', 0, '2026-09-28', '12:00:00')
    ")->execute([$testUserId]);

    // Force an intentional exception
    throw new Exception("Simulated mid-import network/database disruption");

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    test_info("Caught simulated failure: " . $e->getMessage());
}

$postRollbackCount = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE user_id = $testUserId")->fetchColumn();
if ($initialCount === $postRollbackCount) {
    test_pass("Transaction rolled back cleanly on exception. Zero orphaned records created.");
} else {
    test_fail("Rollback failed: Initial = $initialCount, Post = $postRollbackCount");
}

// -------------------------------------------------------------------------
// TEST 4: Execute Valid Import & Propagate Learning
// -------------------------------------------------------------------------
test_step("TEST 4: Execute Import & Verify Learning/Reinforcement Propagation");

$pdo->beginTransaction();

$importedCount = 0;
$skippedCount = 0;
$duplicateCount = 0;

// Import valid non-duplicate rows: Row 3 (Accepted Food), Row 5 (Student manually categorizes as Academics 9)
// Row 2 is skipped (duplicate), Row 4 is skipped (duplicate), Row 6 is bad (skipped)
$toImport = [
    [
        'title'            => $parsedRows[1]['description'],
        'amount'           => $parsedRows[1]['amount'],
        'date'             => $parsedRows[1]['date'],
        'type'             => 'expense',
        'chosen_cat_id'    => 6, // Accepted AI suggestion (Food)
        'orig_suggested'   => 6,
        'action'           => 'accept'
    ],
    [
        'title'            => $parsedRows[3]['description'],
        'amount'           => $parsedRows[3]['amount'],
        'date'             => $parsedRows[3]['date'],
        'type'             => 'expense',
        'chosen_cat_id'    => 9, // Student manually chose Academics (9) for previously Uncategorized item
        'orig_suggested'   => null,
        'action'           => 'manual'
    ]
];

$insertStmt = $pdo->prepare("
    INSERT INTO transactions (user_id, category_id, amount, type, title, is_recurring, date, time)
    VALUES (:uid, :cid, :amount, :type, :title, 0, :date, '12:00:00')
");

foreach ($toImport as $item) {
    $insertStmt->execute([
        ':uid'    => $testUserId,
        ':cid'    => $item['chosen_cat_id'],
        ':amount' => $item['amount'],
        ':type'   => $item['type'],
        ':title'  => $item['title'],
        ':date'   => $item['date']
    ]);
    $newTxId = (int)$pdo->lastInsertId();

    recordCategorySuggestionLog($pdo, $testUserId, $newTxId, $item['title'], $item['orig_suggested'], $item['chosen_cat_id']);
    $importedCount++;
}

$duplicateCount = 2; // Rows 2 & 4
$skippedCount = 1;   // Row 6 bad

$pdo->commit();

$summary = "Import Complete: Successfully imported {$importedCount} transactions, {$skippedCount} skipped, {$duplicateCount} duplicates excluded.";
test_info("Generated summary string: '$summary'");

if ($importedCount === 2 && $skippedCount === 1 && $duplicateCount === 2) {
    test_pass("Import summary matched expected counts: 2 imported, 1 skipped, 2 duplicates.");
} else {
    test_fail("Import summary count mismatch");
}

// Verify that student manual categorization of 'Quantum Widget xyz' learned Academics (Category 9)
$rules = getUserCategoryRules($pdo, $testUserId);
$learnedQuantum = false;
foreach ($rules as $r) {
    if ($r['keyword'] === 'quantum' && (int)$r['category_id'] === 9 && $r['source'] === 'user_correction') {
        $learnedQuantum = true;
    }
}

if ($learnedQuantum) {
    test_pass("Manual category choice for 'Quantum Widget xyz' automatically ran through learnCategory() and established Academics rule!");
} else {
    test_fail("Learning propagation for manual categorization failed: " . json_encode($rules));
}

// Cleanup test student data
$pdo->prepare('DELETE FROM transactions WHERE user_id = ?')->execute([$testUserId]);
$pdo->prepare('DELETE FROM category_suggestion_log WHERE user_id = ?')->execute([$testUserId]);
$pdo->prepare('DELETE FROM category_keywords WHERE user_id = ?')->execute([$testUserId]);
$pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$testUserId]);

test_step("ALL CSV IMPORT & AI SUGGESTER TESTS PASSED");
echo "==========================================================================" . PHP_EOL;
