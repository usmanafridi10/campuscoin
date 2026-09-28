<?php
/**
 * CampusCoin - CSV Transaction Import with AI Suggestion Preview & Learning
 *
 * Requirements:
 * 1. Accept .csv only, max 2 MB, check MIME type & extension, random stored filename, delete after processing.
 * 2. Parse UTF-8 BOM, fgetcsv, handle invalid dates, negative/comma amounts, cap at 1000 rows.
 * 3. AI suggester for each row, PREVIEW table BEFORE saving:
 *    - date, description, amount, suggested category (editable dropdown), confidence badge (High/Med/Low), include checkbox.
 *    - Low confidence highlighted and left as "Uncategorized".
 *    - Duplicate detection (flag likely duplicates matching existing DB records or intra-file duplicates).
 * 4. Bulk actions:
 *    - "Apply this category to all rows with the same description"
 *    - "Accept all high-confidence suggestions"
 * 5. On confirm: insert in a single DB transaction (rollback on failure), send every edit to learn(), summary message.
 * 6. Clear error messages per bad row, never a fatal error.
 */

require_once __DIR__ . '/config.php';
requireLogin();

$user = currentUser();
if (!$user) {
    header('Location: logout.php');
    exit;
}

$userId = currentUserId();
$pdo = getDbConnection();

// Fetch available categories for this student
$catStmt = $pdo->prepare("
    SELECT category_id, name, type, icon 
    FROM categories 
    WHERE is_default = 1 OR user_id = :uid 
    ORDER BY type ASC, name ASC
");
$catStmt->execute([':uid' => $userId]);
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$flashSuccess = getFlashMessage('success');
$flashError = getFlashMessage('error');

// -------------------------------------------------------------
// POST HANDLERS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrfToken)) {
        $error = 'Security validation failed. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // =========================================================
        // 1. UPLOAD AND PARSE CSV FILE
        // =========================================================
        if ($action === 'upload_csv') {
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                $error = 'Please select a valid CSV file to upload.';
            } else {
                $file = $_FILES['csv_file'];
                $fileName = $file['name'];
                $fileTmp = $file['tmp_name'];
                $fileSize = (int)$file['size'];

                // Check extension
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if ($fileExt !== 'csv') {
                    $error = 'Invalid file format. Only .csv files are supported.';
                }
                // Check max 2 MB
                elseif ($fileSize > (2 * 1024 * 1024)) {
                    $error = 'File size exceeds the 2 MB limit. Please upload a smaller file.';
                } else {
                    // Check MIME type
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $fileTmp);
                    finfo_close($finfo);

                    $allowedMimes = [
                        'text/plain',
                        'text/csv',
                        'text/x-csv',
                        'application/vnd.ms-excel',
                        'text/comma-separated-values',
                        'application/csv',
                        'application/octet-stream'
                    ];

                    if (!in_array($mimeType, $allowedMimes, true)) {
                        $error = 'Security check: Invalid CSV MIME type (' . htmlspecialchars($mimeType) . '). Please upload a valid text CSV file.';
                    } else {
                        // Store in random temp file
                        $randomTempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cc_csv_' . bin2hex(random_bytes(16)) . '.csv';

                        if (!move_uploaded_file($fileTmp, $randomTempPath)) {
                            $error = 'Failed to safely process the uploaded file.';
                        } else {
                            $handle = fopen($randomTempPath, 'r');
                            if ($handle === false) {
                                $error = 'Unable to read the temporary CSV file.';
                            } else {
                                // Strip UTF-8 BOM if present
                                $bom = fread($handle, 3);
                                if ($bom !== "\xEF\xBB\xBF") {
                                    rewind($handle);
                                }

                                $previewRows = [];
                                $badRows = [];
                                $seenInFile = [];
                                $headerMap = [
                                    'date'        => null,
                                    'description' => null,
                                    'amount'      => null,
                                    'type'        => null
                                ];

                                $rowIndex = 0;
                                $dataRowCount = 0;

                                // Duplicate check prepared statement against DB
                                $dupStmt = $pdo->prepare("
                                    SELECT transaction_id, title, date, amount 
                                    FROM transactions 
                                    WHERE user_id = :uid 
                                      AND date = :date 
                                      AND amount = :amount 
                                      AND LOWER(TRIM(title)) = LOWER(TRIM(:title))
                                    LIMIT 1
                                ");

                                while (($data = fgetcsv($handle, 4096, ',')) !== false) {
                                    $rowIndex++;
                                    // Skip entirely empty rows
                                    if (empty(array_filter($data, fn($v) => trim($v) !== ''))) {
                                        continue;
                                    }

                                    // Auto-detect header row on row 1
                                    if ($rowIndex === 1) {
                                        $isHeader = false;
                                        foreach ($data as $colIdx => $colVal) {
                                            $colClean = strtolower(trim(preg_replace('/[^a-zA-Z]/', '', $colVal)));
                                            if (in_array($colClean, ['date', 'txdate', 'time', 'posted', 'day', 'timestamp'])) {
                                                $headerMap['date'] = $colIdx;
                                                $isHeader = true;
                                            } elseif (in_array($colClean, ['desc', 'description', 'title', 'memo', 'details', 'narration', 'payee', 'merchant', 'name'])) {
                                                $headerMap['description'] = $colIdx;
                                                $isHeader = true;
                                            } elseif (in_array($colClean, ['amount', 'total', 'cost', 'price', 'sum', 'value', 'net', 'debit'])) {
                                                $headerMap['amount'] = $colIdx;
                                                $isHeader = true;
                                            } elseif (in_array($colClean, ['type', 'txtype', 'direction', 'crdr', 'drcr'])) {
                                                $headerMap['type'] = $colIdx;
                                                $isHeader = true;
                                            }
                                        }

                                        if ($isHeader) {
                                            continue; // Header row consumed
                                        }
                                    }

                                    // Default column index fallback if headers were absent or incomplete
                                    $dateIdx = $headerMap['date'] ?? 0;
                                    $descIdx = $headerMap['description'] ?? 1;
                                    $amtIdx  = $headerMap['amount'] ?? 2;
                                    $typeIdx = $headerMap['type'] ?? 3;

                                    $rawDate = $data[$dateIdx] ?? '';
                                    $rawDesc = $data[$descIdx] ?? '';
                                    $rawAmt  = $data[$amtIdx] ?? '';
                                    $rawType = $data[$typeIdx] ?? '';

                                    $dataRowCount++;
                                    if ($dataRowCount > 1000) {
                                        // Capped at 1000 rows
                                        break;
                                    }

                                    // Parse and validate date
                                    $parsedDateInfo = parseImportDate($rawDate);
                                    // Parse and validate amount & type
                                    $parsedAmtInfo  = parseImportAmount($rawAmt, $rawType);
                                    $cleanDesc = trim($rawDesc);

                                    $rowErrors = [];
                                    if (!$parsedDateInfo['valid']) {
                                        $rowErrors[] = $parsedDateInfo['error'];
                                    }
                                    if (!$parsedAmtInfo['valid']) {
                                        $rowErrors[] = $parsedAmtInfo['error'];
                                    }
                                    if ($cleanDesc === '') {
                                        $rowErrors[] = 'Description is empty';
                                    }

                                    $isBadRow = !empty($rowErrors);
                                    if ($isBadRow) {
                                        $badRows[] = [
                                            'row'    => $rowIndex,
                                            'errors' => $rowErrors,
                                            'raw'    => implode(', ', $data)
                                        ];
                                    }

                                    $cleanDate = $parsedDateInfo['valid'] ? $parsedDateInfo['date'] : date('Y-m-d');
                                    $cleanAmt  = $parsedAmtInfo['valid'] ? $parsedAmtInfo['amount'] : 0.0;
                                    $cleanType = $parsedAmtInfo['type'];

                                    // Duplicate check
                                    $isDuplicate = false;
                                    $duplicateReason = null;

                                    if (!$isBadRow) {
                                        // 1. Check against DB
                                        $dupStmt->execute([
                                            ':uid'    => $userId,
                                            ':date'   => $cleanDate,
                                            ':amount' => $cleanAmt,
                                            ':title'  => $cleanDesc
                                        ]);
                                        if ($dupStmt->fetch()) {
                                            $isDuplicate = true;
                                            $duplicateReason = "Matches existing transaction on {$cleanDate} for Rs. " . number_format($cleanAmt, 2);
                                        }

                                        // 2. Check intra-file duplicate
                                        $fileKey = $cleanDate . '|' . number_format($cleanAmt, 2, '.', '') . '|' . strtolower($cleanDesc);
                                        if (isset($seenInFile[$fileKey])) {
                                            $isDuplicate = true;
                                            $duplicateReason = "Duplicate within this file (matches Row " . $seenInFile[$fileKey] . ")";
                                        } else {
                                            $seenInFile[$fileKey] = $rowIndex;
                                        }
                                    }

                                    // Run AI Category Suggester
                                    $suggestedCatId = null;
                                    $suggestedCatName = 'Uncategorized';
                                    $suggestedScore = 0.0;
                                    $confidence = 'low';

                                    if (!$isBadRow && $cleanDesc !== '') {
                                        $pred = predictCategory($pdo, $userId, $cleanDesc);
                                        if ($pred) {
                                            $suggestedScore = (float)$pred['score'];
                                            $isUserCorrection = ($pred['source'] === 'user_correction');

                                            if ($isUserCorrection || $suggestedScore >= 2.0) {
                                                $confidence = 'high';
                                                $suggestedCatId = (int)$pred['category_id'];
                                                $suggestedCatName = $pred['name'];
                                            } elseif ($suggestedScore >= 0.8) {
                                                $confidence = 'medium';
                                                $suggestedCatId = (int)$pred['category_id'];
                                                $suggestedCatName = $pred['name'];
                                            } else {
                                                // Low confidence: leave as Uncategorized for manual choice
                                                $confidence = 'low';
                                                $suggestedCatId = null;
                                                $suggestedCatName = 'Uncategorized';
                                            }
                                        }
                                    }

                                    $previewRows[] = [
                                        'row_index'           => $rowIndex,
                                        'include'             => (!$isBadRow && !$isDuplicate) ? 1 : 0,
                                        'date'                => $cleanDate,
                                        'description'         => $cleanDesc,
                                        'amount'              => $cleanAmt,
                                        'type'                => $cleanType,
                                        'suggested_cat_id'    => $suggestedCatId,
                                        'suggested_cat_name'  => $suggestedCatName,
                                        'chosen_cat_id'       => $suggestedCatId, // default chosen = suggested (or null if low)
                                        'confidence'          => $confidence,
                                        'score'               => $suggestedScore,
                                        'is_duplicate'        => $isDuplicate,
                                        'duplicate_reason'    => $duplicateReason,
                                        'is_bad_row'          => $isBadRow,
                                        'errors'              => $rowErrors
                                    ];
                                }

                                fclose($handle);

                                // Delete temp file immediately after processing
                                if (file_exists($randomTempPath)) {
                                    unlink($randomTempPath);
                                }

                                if (empty($previewRows)) {
                                    $error = 'The CSV file was empty or contained no readable transaction rows.';
                                } else {
                                    // Store preview payload in session
                                    $_SESSION['csv_import_data'] = [
                                        'file_name' => $fileName,
                                        'rows'      => $previewRows,
                                        'bad_rows'  => $badRows
                                    ];
                                    header('Location: import.php?step=preview');
                                    exit;
                                }
                            }
                        }
                    }
                }
            }
        }

        // =========================================================
        // 2. CONFIRM AND EXECUTE IMPORT (SINGLE TRANSACTION)
        // =========================================================
        elseif ($action === 'confirm_import') {
            if (!isset($_SESSION['csv_import_data']) || empty($_SESSION['csv_import_data']['rows'])) {
                $error = 'No active import session found. Please upload your CSV again.';
            } else {
                $sessionRows = $_SESSION['csv_import_data']['rows'];
                $submittedRows = $_POST['rows'] ?? [];

                $importedCount = 0;
                $skippedCount = 0;
                $duplicateCount = 0;
                $fallbackExpenseCat = 6; // Food / Misc fallback
                $fallbackIncomeCat  = 1; // Allowance fallback

                try {
                    $pdo->beginTransaction();

                    $insertStmt = $pdo->prepare("
                        INSERT INTO transactions (user_id, category_id, amount, type, title, is_recurring, date, time)
                        VALUES (:uid, :cid, :amount, :type, :title, 0, :date, '12:00:00')
                    ");

                    foreach ($sessionRows as $idx => $sRow) {
                        $input = $submittedRows[$idx] ?? null;
                        $include = isset($input['include']) && (int)$input['include'] === 1;

                        if (!$include || $sRow['is_bad_row']) {
                            if ($sRow['is_duplicate']) {
                                $duplicateCount++;
                            } else {
                                $skippedCount++;
                            }
                            continue;
                        }

                        $chosenCatId = isset($input['category_id']) && (int)$input['category_id'] > 0 
                            ? (int)$input['category_id'] 
                            : ($sRow['type'] === 'income' ? $fallbackIncomeCat : $fallbackExpenseCat);

                        $title = trim($input['description'] ?? $sRow['description']);
                        $amount = (float)($input['amount'] ?? $sRow['amount']);
                        $date = trim($input['date'] ?? $sRow['date']);
                        $type = in_array($input['type'] ?? $sRow['type'], ['income', 'expense']) ? ($input['type'] ?? $sRow['type']) : 'expense';
                        $origSuggestedId = $sRow['suggested_cat_id'];

                        // 1. Insert transaction
                        $insertStmt->execute([
                            ':uid'    => $userId,
                            ':cid'    => $chosenCatId,
                            ':amount' => $amount,
                            ':type'   => $type,
                            ':title'  => $title,
                            ':date'   => $date
                        ]);

                        $transId = (int)$pdo->lastInsertId();

                        // 2. Compare AI suggestion with final category and log to category_suggestion_log
                        // This triggers learn() on overrides or confirmCategorySuggestion() on acceptance!
                        recordCategorySuggestionLog($pdo, $userId, $transId, $title, $origSuggestedId, $chosenCatId);

                        $importedCount++;
                    }

                    $pdo->commit();

                    // Cleanup session data
                    unset($_SESSION['csv_import_data']);

                    $summaryMsg = "Import Complete: Successfully imported {$importedCount} transactions, {$skippedCount} skipped, {$duplicateCount} duplicates excluded.";
                    setFlashMessage('success', $summaryMsg);
                    header('Location: transactions.php');
                    exit;

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('CSV Import failed: ' . $e->getMessage());
                    $error = 'Import failed due to a database error: ' . htmlspecialchars($e->getMessage()) . '. All changes were rolled back safely.';
                }
            }
        }

        // =========================================================
        // 3. CANCEL IMPORT
        // =========================================================
        elseif ($action === 'cancel_import') {
            unset($_SESSION['csv_import_data']);
            setFlashMessage('info', 'CSV import cancelled. No data was saved.');
            header('Location: import.php');
            exit;
        }
    }
}

// Check current view step
$step = $_GET['step'] ?? 'upload';
$previewData = $_SESSION['csv_import_data'] ?? null;
if ($step === 'preview' && !$previewData) {
    $step = 'upload';
}

$pageTitle = "Import Transactions via CSV";
$activePage = "transactions";
include "includes/header.php";
?>

<div class="app-shell">
    <?php include "includes/sidebar.php"; ?>

    <main class="main-content">
        <?php include "includes/topbar.php"; ?>

        <section class="page-content">
            <nav class="breadcrumbs" aria-label="Breadcrumb">
                <a href="index.php">Home</a>
                <span class="bc-sep">/</span>
                <a href="transactions.php">Transactions</a>
                <span class="bc-sep">/</span>
                <span class="bc-current" aria-current="page">Import CSV</span>
            </nav>

            <div class="page-header">
                <div>
                    <p class="eyebrow">Smart Data Ingestion</p>
                    <h1>Import Transactions via CSV</h1>
                    <p class="muted">Upload bank statements or expense sheets. The AI suggester categorizes each row with confidence ratings and duplicate alerts before saving.</p>
                </div>
                <div class="header-action-group">
                    <a href="transactions.php" class="outline-btn" style="text-decoration:none;">&larr; Back to Ledger</a>
                </div>
            </div>

            <?php if (!empty($flashSuccess)): ?>
                <div class="login-success" style="background:#eaf6ee;color:#1e7e34;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;" role="status">
                    <?php echo e($flashSuccess); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error) || !empty($flashError)): ?>
                <div class="login-error" style="margin-bottom:18px;" role="alert">
                    <?php echo e(!empty($error) ? $error : $flashError); ?>
                </div>
            <?php endif; ?>

            <?php if ($step === 'upload'): ?>
                <!-- ========================================== -->
                <!-- STEP 1: CSV UPLOAD ZONE                     -->
                <!-- ========================================== -->
                <div class="panel" style="max-width: 800px; margin: 0 auto; padding: 32px;">
                    <div style="text-align: center; margin-bottom: 24px;">
                        <div style="font-size: 42px; margin-bottom: 8px;">📊</div>
                        <h2 style="margin: 0 0 8px 0; font-size: 22px;">Upload CSV Transaction File</h2>
                        <p class="muted" style="margin: 0; font-size: 14px;">Select or drop your statement. We will parse it and show an AI category preview table before anything is saved.</p>
                    </div>

                    <form id="csvUploadForm" method="POST" action="import.php" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                        <input type="hidden" name="action" value="upload_csv">

                        <div class="csv-upload-dropzone" id="dropzoneBox" onclick="document.getElementById('csvFileInput').click()" style="border: 2px dashed var(--accent); padding: 36px 20px; border-radius: 12px; text-align: center; background: rgba(14, 116, 144, 0.03); cursor: pointer; transition: all 0.2s ease;">
                            <span class="upload-icon" style="font-size: 38px; display: block; margin-bottom: 10px;">📥</span>
                            <p style="font-size: 16px; font-weight: 600; margin: 0 0 6px 0;">
                                Click to choose file <span style="font-weight: 400; color: var(--muted);">or drag & drop here</span>
                            </p>
                            <small class="muted" style="display: block; font-size: 13px;">Accepts <strong>.csv</strong> only &bull; Max <strong>2 MB</strong> &bull; Up to 1,000 rows</small>
                            <input type="file" id="csvFileInput" name="csv_file" accept=".csv,text/csv" style="display:none;" onchange="updateSelectedFileName(this)">
                        </div>

                        <div id="fileSelectedDisplay" style="display:none; margin-top: 14px; background: #eaf6ee; color: #1e7e34; padding: 10px 14px; border-radius: 8px; font-size: 13px; font-weight: 500;">
                            Selected: <strong id="selectedFileName"></strong>
                        </div>

                        <!-- Expected format guide -->
                        <div style="margin-top: 24px; padding: 16px; background: #f8fafc; border: 1px solid var(--line); border-radius: 10px;">
                            <h4 style="margin: 0 0 8px 0; font-size: 14px; font-weight: 600;">Expected CSV Format:</h4>
                            <p class="muted" style="margin: 0 0 8px 0; font-size: 13px;">The parser auto-detects header rows and handles common column names:</p>
                            <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse; font-size: 12px; background: #fff; border-radius: 6px; overflow: hidden; border: 1px solid var(--line);">
                                    <thead>
                                        <tr style="background: #edf2f7; text-align: left;">
                                            <th style="padding: 6px 10px; border-bottom: 1px solid var(--line);">Date</th>
                                            <th style="padding: 6px 10px; border-bottom: 1px solid var(--line);">Description</th>
                                            <th style="padding: 6px 10px; border-bottom: 1px solid var(--line);">Amount</th>
                                            <th style="padding: 6px 10px; border-bottom: 1px solid var(--line);">Type (Optional)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="padding: 6px 10px; border-bottom: 1px solid var(--line);">2026-09-24</td>
                                            <td style="padding: 6px 10px; border-bottom: 1px solid var(--line);">Kabab Roll at Canteen</td>
                                            <td style="padding: 6px 10px; border-bottom: 1px solid var(--line);">350.00</td>
                                            <td style="padding: 6px 10px; border-bottom: 1px solid var(--line);">expense</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 6px 10px;">2026-09-25</td>
                                            <td style="padding: 6px 10px;">Monthly Allowance from Parents</td>
                                            <td style="padding: 6px 10px;">15,000.00</td>
                                            <td style="padding: 6px 10px;">income</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <small class="muted" style="display:block; margin-top:8px; font-size: 12px;">
                                &bull; UTF-8 BOM, negative amounts (<code>-350</code> or <code>(350)</code>), and commas (<code>1,500.00</code>) are handled automatically.
                            </small>
                        </div>

                        <div style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 12px;">
                            <a href="transactions.php" class="outline-btn" style="text-decoration:none;">Cancel</a>
                            <button type="submit" class="primary-btn" id="uploadSubmitBtn" style="padding: 10px 24px;">Upload & Analyze with AI &rarr;</button>
                        </div>
                    </form>
                </div>

            <?php elseif ($step === 'preview' && $previewData): ?>
                <!-- ========================================== -->
                <!-- STEP 2: INTERACTIVE PREVIEW & BULK ACTIONS  -->
                <!-- ========================================== -->
                <?php
                $rows = $previewData['rows'];
                $badRows = $previewData['bad_rows'];
                $totalRows = count($rows);
                $highConfCount = 0;
                $lowConfCount = 0;
                $dupCount = 0;

                // Frequency count of descriptions to enable "Apply to all matching"
                $descFrequencies = [];
                foreach ($rows as $r) {
                    if (!$r['is_bad_row']) {
                        $dKey = strtolower(trim($r['description']));
                        $descFrequencies[$dKey] = ($descFrequencies[$dKey] ?? 0) + 1;
                    }
                    if ($r['confidence'] === 'high') $highConfCount++;
                    if ($r['confidence'] === 'low') $lowConfCount++;
                    if ($r['is_duplicate']) $dupCount++;
                }
                ?>

                <!-- Metrics & Summary Header -->
                <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 20px;">
                    <div class="metric-card">
                        <span class="metric-title">Total Rows</span>
                        <strong class="metric-value"><?php echo $totalRows; ?></strong>
                        <span class="metric-subtitle">Parsed from <?php echo e($previewData['file_name']); ?></span>
                    </div>
                    <div class="metric-card" style="border-left: 4px solid #16a34a;">
                        <span class="metric-title">High Confidence</span>
                        <strong class="metric-value" style="color: #16a34a;"><?php echo $highConfCount; ?></strong>
                        <span class="metric-subtitle">Ready for one-click accept</span>
                    </div>
                    <div class="metric-card" style="border-left: 4px solid #d97706;">
                        <span class="metric-title">Low Confidence / Manual</span>
                        <strong class="metric-value" style="color: #d97706;"><?php echo $lowConfCount; ?></strong>
                        <span class="metric-subtitle">Marked Uncategorized</span>
                    </div>
                    <div class="metric-card" style="border-left: 4px solid #dc2626;">
                        <span class="metric-title">Likely Duplicates</span>
                        <strong class="metric-value" style="color: #dc2626;"><?php echo $dupCount; ?></strong>
                        <span class="metric-subtitle">Unchecked by default</span>
                    </div>
                </div>

                <!-- Bad Rows Notice (Requirement 5) -->
                <?php if (!empty($badRows)): ?>
                    <div class="login-error" style="margin-bottom: 18px; padding: 14px 18px; border-radius: 8px;">
                        <strong>⚠️ Notice: <?php echo count($badRows); ?> row(s) contain invalid formatting and cannot be imported:</strong>
                        <ul style="margin: 6px 0 0 18px; padding: 0; font-size: 13px;">
                            <?php foreach ($badRows as $b): ?>
                                <li>
                                    <strong>Row <?php echo $b['row']; ?>:</strong> <?php echo implode(', ', $b['errors']); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form id="confirmImportForm" method="POST" action="import.php">
                    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                    <input type="hidden" name="action" value="confirm_import">

                    <!-- Bulk Actions Toolbar (Requirement 3) -->
                    <div class="panel" style="padding: 16px 20px; margin-bottom: 18px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px;">
                        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 10px;">
                            <span style="font-size: 13px; font-weight: 700; color: var(--ink);">Bulk Actions:</span>
                            
                            <button type="button" class="outline-btn" id="btnAcceptHighConf" style="font-size: 13px; padding: 6px 12px; display: inline-flex; align-items: center; gap: 6px;">
                                ⚡ Accept all high-confidence suggestions
                            </button>

                            <?php if ($dupCount > 0): ?>
                                <button type="button" class="outline-btn" id="btnExcludeDuplicates" style="font-size: 13px; padding: 6px 12px; color: #dc2626; border-color: #fca5a5;">
                                    🛡️ Exclude all duplicates (<?php echo $dupCount; ?>)
                                </button>
                            <?php endif; ?>

                            <button type="button" class="outline-btn" id="btnSelectAll" style="font-size: 13px; padding: 6px 12px;">
                                Select all
                            </button>
                            <button type="button" class="outline-btn" id="btnDeselectAll" style="font-size: 13px; padding: 6px 12px;">
                                Deselect all
                            </button>
                        </div>

                        <div style="display: flex; align-items: center; gap: 10px;">
                            <button type="submit" class="primary-btn" style="padding: 8px 18px; font-size: 14px;">
                                ✅ Confirm & Import Selected (<span id="selectedCountDisplay">0</span>)
                            </button>
                        </div>
                    </div>

                    <!-- Live Notification Banner for "Apply to all" -->
                    <div id="bulkApplyToast" style="display:none; background: #eaf6ee; color: #1e7e34; padding: 10px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; font-weight: 500;"></div>

                    <!-- Interactive Preview Table (Requirement 2) -->
                    <div class="panel" style="overflow-x: auto; padding: 0; margin-bottom: 24px;">
                        <table class="data-table" id="previewTable" style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f8fafc; border-bottom: 1px solid var(--line);">
                                    <th style="width: 44px; text-align: center; padding: 12px 10px;">
                                        <input type="checkbox" id="masterIncludeCheck" title="Toggle all rows">
                                    </th>
                                    <th style="padding: 12px 10px;">Date</th>
                                    <th style="padding: 12px 10px;">Description</th>
                                    <th style="padding: 12px 10px;">Amount & Type</th>
                                    <th style="padding: 12px 10px;">AI Suggester & Confidence</th>
                                    <th style="padding: 12px 10px; min-width: 240px;">Final Category</th>
                                    <th style="padding: 12px 10px;">Status / Duplicate Alert</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $idx => $row): ?>
                                    <?php
                                    $isBad = $row['is_bad_row'];
                                    $isDup = $row['is_duplicate'];
                                    $isLow = ($row['confidence'] === 'low');
                                    $descKey = strtolower(trim($row['description']));
                                    $hasMultipleSameDesc = ($descFrequencies[$descKey] ?? 0) > 1;

                                    $rowStyle = "";
                                    if ($isBad) {
                                        $rowStyle = "background: #fff5f5; opacity: 0.7;";
                                    } elseif ($isDup) {
                                        $rowStyle = "background: #fef2f2;";
                                    } elseif ($isLow) {
                                        $rowStyle = "background: #fffbeb;";
                                    }
                                    ?>
                                    <tr class="preview-row <?php echo $isLow ? 'row-low-confidence' : ''; ?> <?php echo $isDup ? 'row-duplicate' : ''; ?>" 
                                        style="<?php echo $rowStyle; ?>"
                                        data-row-index="<?php echo $idx; ?>"
                                        data-confidence="<?php echo $row['confidence']; ?>"
                                        data-suggested-id="<?php echo (int)$row['suggested_cat_id']; ?>"
                                        data-desc-clean="<?php echo e($descKey); ?>"
                                        data-duplicate="<?php echo $isDup ? '1' : '0'; ?>">

                                        <!-- 1. Include Checkbox -->
                                        <td style="text-align: center; padding: 12px 10px; vertical-align: middle;">
                                            <input type="checkbox" 
                                                   name="rows[<?php echo $idx; ?>][include]" 
                                                   value="1" 
                                                   class="row-include-check"
                                                   <?php echo ($row['include'] && !$isBad) ? 'checked' : ''; ?> 
                                                   <?php echo $isBad ? 'disabled' : ''; ?>>
                                        </td>

                                        <!-- 2. Date -->
                                        <td style="padding: 12px 10px; vertical-align: middle; white-space: nowrap;">
                                            <input type="date" 
                                                   name="rows[<?php echo $idx; ?>][date]" 
                                                   value="<?php echo e($row['date']); ?>" 
                                                   style="font-size: 13px; padding: 4px 6px; border: 1px solid var(--line); border-radius: 6px;"
                                                   <?php echo $isBad ? 'readonly' : ''; ?>>
                                        </td>

                                        <!-- 3. Description -->
                                        <td style="padding: 12px 10px; vertical-align: middle;">
                                            <input type="text" 
                                                   name="rows[<?php echo $idx; ?>][description]" 
                                                   value="<?php echo e($row['description']); ?>" 
                                                   class="row-desc-input"
                                                   style="font-size: 13px; padding: 4px 8px; width: 100%; min-width: 160px; border: 1px solid var(--line); border-radius: 6px;"
                                                   <?php echo $isBad ? 'readonly' : ''; ?>>
                                        </td>

                                        <!-- 4. Amount & Type -->
                                        <td style="padding: 12px 10px; vertical-align: middle; white-space: nowrap;">
                                            <div style="display: flex; align-items: center; gap: 6px;">
                                                <input type="number" 
                                                       step="0.01" 
                                                       name="rows[<?php echo $idx; ?>][amount]" 
                                                       value="<?php echo $row['amount']; ?>" 
                                                       style="font-size: 13px; padding: 4px 6px; width: 85px; border: 1px solid var(--line); border-radius: 6px;"
                                                       <?php echo $isBad ? 'readonly' : ''; ?>>
                                                <select name="rows[<?php echo $idx; ?>][type]" style="font-size: 12px; padding: 4px 6px; border-radius: 6px; border: 1px solid var(--line);">
                                                    <option value="expense" <?php echo ($row['type'] === 'expense') ? 'selected' : ''; ?>>Expense</option>
                                                    <option value="income" <?php echo ($row['type'] === 'income') ? 'selected' : ''; ?>>Income</option>
                                                </select>
                                            </div>
                                        </td>

                                        <!-- 5. AI Confidence Badge (Requirement 2) -->
                                        <td style="padding: 12px 10px; vertical-align: middle; white-space: nowrap;">
                                            <?php if ($row['confidence'] === 'high'): ?>
                                                <span class="badge" style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-size: 12px; font-weight: 700; padding: 4px 8px; border-radius: 12px;">
                                                    🟢 High (<?php echo e($row['suggested_cat_name']); ?>)
                                                </span>
                                            <?php elseif ($row['confidence'] === 'medium'): ?>
                                                <span class="badge" style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a; font-size: 12px; font-weight: 700; padding: 4px 8px; border-radius: 12px;">
                                                    🟡 Medium (<?php echo e($row['suggested_cat_name']); ?>)
                                                </span>
                                            <?php else: ?>
                                                <span class="badge" style="background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; font-size: 12px; font-weight: 700; padding: 4px 8px; border-radius: 12px;">
                                                    ⚪ Low (Manual choice)
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- 6. Category Dropdown & Bulk Description Match Button -->
                                        <td style="padding: 12px 10px; vertical-align: middle;">
                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                <select name="rows[<?php echo $idx; ?>][category_id]" 
                                                        class="category-select" 
                                                        data-desc="<?php echo e($descKey); ?>"
                                                        style="font-size: 13px; padding: 5px 8px; border-radius: 6px; border: 1px solid <?php echo $isLow ? '#d97706' : 'var(--line)'; ?>;"
                                                        <?php echo $isBad ? 'disabled' : ''; ?>>
                                                    
                                                    <?php if ($isLow || empty($row['chosen_cat_id'])): ?>
                                                        <option value="" selected style="color: #b91c1c; font-weight: bold;">⚠️ Uncategorized (Select Category)</option>
                                                    <?php endif; ?>

                                                    <optgroup label="Expenses">
                                                        <?php foreach ($categories as $cat): ?>
                                                            <?php if ($cat['type'] === 'expense'): ?>
                                                                <option value="<?php echo $cat['category_id']; ?>" 
                                                                    <?php echo ((int)$row['chosen_cat_id'] === (int)$cat['category_id']) ? 'selected' : ''; ?>>
                                                                    <?php echo e($cat['icon'] . ' ' . $cat['name']); ?>
                                                                </option>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </optgroup>

                                                    <optgroup label="Income">
                                                        <?php foreach ($categories as $cat): ?>
                                                            <?php if ($cat['type'] === 'income'): ?>
                                                                <option value="<?php echo $cat['category_id']; ?>" 
                                                                    <?php echo ((int)$row['chosen_cat_id'] === (int)$cat['category_id']) ? 'selected' : ''; ?>>
                                                                    <?php echo e($cat['icon'] . ' ' . $cat['name']); ?>
                                                                </option>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                </select>

                                                <!-- Quick Bulk Action Button for matching descriptions (Requirement 3) -->
                                                <?php if ($hasMultipleSameDesc && !$isBad): ?>
                                                    <button type="button" 
                                                            class="btn-apply-same-desc outline-btn" 
                                                            data-desc="<?php echo e($descKey); ?>"
                                                            data-row="<?php echo $idx; ?>"
                                                            style="font-size: 11px; padding: 2px 6px; align-self: flex-start; background: #fff; border-color: var(--accent); color: var(--accent);">
                                                        ↳ Apply to all "<?php echo e(mb_substr($row['description'], 0, 16)); ?><?php echo mb_strlen($row['description']) > 16 ? '...' : ''; ?>" (<?php echo $descFrequencies[$descKey]; ?>)
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- 7. Status / Alerts -->
                                        <td style="padding: 12px 10px; vertical-align: middle; font-size: 12px;">
                                            <?php if ($isBad): ?>
                                                <span style="color: #dc2626; font-weight: 600;">
                                                    ❌ <?php echo implode('; ', $row['errors']); ?>
                                                </span>
                                            <?php elseif ($isDup): ?>
                                                <span style="color: #dc2626; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;" title="<?php echo e($row['duplicate_reason']); ?>">
                                                    ⚠️ Duplicate: In records
                                                </span>
                                            <?php elseif ($isLow): ?>
                                                <span style="color: #b45309; font-weight: 500;">
                                                    Requires manual category selection
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #16a34a; font-weight: 500;">
                                                    ✓ Ready
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Bottom Action Controls -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px;">
                        <button type="button" class="outline-btn" onclick="document.getElementById('cancelForm').submit();" style="color: #dc2626;">
                            Cancel & Discard CSV
                        </button>

                        <button type="submit" class="primary-btn" style="padding: 12px 28px; font-size: 15px;">
                            Confirm & Save to Ledger &rarr;
                        </button>
                    </div>
                </form>

                <!-- Hidden form to cancel preview -->
                <form id="cancelForm" method="POST" action="import.php" style="display:none;">
                    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                    <input type="hidden" name="action" value="cancel_import">
                </form>

            <?php endif; ?>
        </section>

        <?php include "includes/footer.php"; ?>
    </main>
</div>

<script>
// File name selection indicator
function updateSelectedFileName(input) {
    const display = document.getElementById("fileSelectedDisplay");
    const label = document.getElementById("selectedFileName");
    if (input.files && input.files.length > 0) {
        label.textContent = input.files[0].name + " (" + (input.files[0].size / 1024).toFixed(1) + " KB)";
        display.style.display = "block";
    } else {
        display.style.display = "none";
    }
}

// Drag & drop highlight
const dropzone = document.getElementById("dropzoneBox");
if (dropzone) {
    ['dragenter', 'dragover'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.style.borderColor = "var(--primary)";
            dropzone.style.background = "rgba(14, 116, 144, 0.08)";
        }, false);
    });
    ['dragleave', 'drop'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.style.borderColor = "var(--accent)";
            dropzone.style.background = "rgba(14, 116, 144, 0.03)";
        }, false);
    });
    dropzone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files.length > 0) {
            document.getElementById("csvFileInput").files = files;
            updateSelectedFileName(document.getElementById("csvFileInput"));
        }
    }, false);
}

// -------------------------------------------------------------
// PREVIEW TABLE JAVASCRIPT & BULK ACTIONS
// -------------------------------------------------------------
document.addEventListener("DOMContentLoaded", function() {
    const rowCheckboxes = document.querySelectorAll(".row-include-check");
    const masterCheck = document.getElementById("masterIncludeCheck");
    const selectedCountSpan = document.getElementById("selectedCountDisplay");
    const toast = document.getElementById("bulkApplyToast");

    function updateSelectedCount() {
        if (!selectedCountSpan) return;
        let count = 0;
        rowCheckboxes.forEach(cb => {
            if (cb.checked && !cb.disabled) count++;
        });
        selectedCountSpan.textContent = count;
    }

    if (rowCheckboxes.length > 0) {
        updateSelectedCount();

        rowCheckboxes.forEach(cb => {
            cb.addEventListener("change", updateSelectedCount);
        });

        // Master checkbox toggle
        if (masterCheck) {
            masterCheck.addEventListener("change", function() {
                const checked = masterCheck.checked;
                rowCheckboxes.forEach(cb => {
                    if (!cb.disabled) {
                        cb.checked = checked;
                    }
                });
                updateSelectedCount();
            });
        }

        // Select All button
        const btnSelectAll = document.getElementById("btnSelectAll");
        if (btnSelectAll) {
            btnSelectAll.addEventListener("click", function() {
                rowCheckboxes.forEach(cb => {
                    if (!cb.disabled) cb.checked = true;
                });
                if (masterCheck) masterCheck.checked = true;
                updateSelectedCount();
            });
        }

        // Deselect All button
        const btnDeselectAll = document.getElementById("btnDeselectAll");
        if (btnDeselectAll) {
            btnDeselectAll.addEventListener("click", function() {
                rowCheckboxes.forEach(cb => {
                    cb.checked = false;
                });
                if (masterCheck) masterCheck.checked = false;
                updateSelectedCount();
            });
        }

        // Exclude all duplicates button
        const btnExcludeDuplicates = document.getElementById("btnExcludeDuplicates");
        if (btnExcludeDuplicates) {
            btnExcludeDuplicates.addEventListener("click", function() {
                const dupRows = document.querySelectorAll(".preview-row[data-duplicate='1']");
                let excluded = 0;
                dupRows.forEach(row => {
                    const cb = row.querySelector(".row-include-check");
                    if (cb && cb.checked) {
                        cb.checked = false;
                        excluded++;
                    }
                });
                updateSelectedCount();
                showToast(`Excluded ${excluded} likely duplicate transactions from import.`);
            });
        }

        // Requirement 3: "Accept all high-confidence suggestions"
        const btnAcceptHighConf = document.getElementById("btnAcceptHighConf");
        if (btnAcceptHighConf) {
            btnAcceptHighConf.addEventListener("click", function() {
                const highRows = document.querySelectorAll(".preview-row[data-confidence='high']");
                let updated = 0;

                highRows.forEach(row => {
                    const cb = row.querySelector(".row-include-check");
                    const select = row.querySelector(".category-select");
                    const suggestedId = row.getAttribute("data-suggested-id");

                    if (cb && !cb.disabled) {
                        cb.checked = true;
                    }
                    if (select && suggestedId && suggestedId !== "0") {
                        select.value = suggestedId;
                        updated++;
                    }
                });

                updateSelectedCount();
                showToast(`Accepted all ${updated} high-confidence suggestions and marked them for import.`);
            });
        }

        // Requirement 3: "Apply this category to all rows with the same description"
        document.querySelectorAll(".btn-apply-same-desc").forEach(btn => {
            btn.addEventListener("click", function() {
                const desc = this.getAttribute("data-desc");
                const rowIdx = this.getAttribute("data-row");
                const currentSelect = document.querySelector(`select[name="rows[${rowIdx}][category_id]"]`);
                
                if (!currentSelect || !currentSelect.value) {
                    alert("Please select a category first before applying it to matching rows.");
                    return;
                }

                const chosenCatId = currentSelect.value;
                const chosenCatText = currentSelect.options[currentSelect.selectedIndex].text;
                let appliedCount = 0;

                const matchingSelects = document.querySelectorAll(`.category-select[data-desc="${CSS.escape(desc)}"]`);
                matchingSelects.forEach(sel => {
                    sel.value = chosenCatId;
                    // Also check the include box for matching rows
                    const parentRow = sel.closest(".preview-row");
                    if (parentRow) {
                        const cb = parentRow.querySelector(".row-include-check");
                        if (cb && !cb.disabled) cb.checked = true;
                    }
                    appliedCount++;
                });

                updateSelectedCount();
                showToast(`Applied "${chosenCatText}" to all ${appliedCount} transactions matching this description.`);
            });
        });

        function showToast(message) {
            if (!toast) return;
            toast.textContent = message;
            toast.style.display = "block";
            setTimeout(() => {
                toast.style.display = "none";
            }, 4500);
        }
    }
});
</script>
