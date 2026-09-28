<?php
/**
 * REST API Endpoint: /import-csv
 *
 * Accepts a CSV file via POST, parses rows, runs AI category suggester,
 * flags duplicates and low confidence rows, and returns a JSON preview payload.
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

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please provide a valid CSV file upload under key csv_file.']);
    exit;
}

$file = $_FILES['csv_file'];
$fileName = $file['name'];
$fileTmp = $file['tmp_name'];
$fileSize = (int)$file['size'];

$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if ($fileExt !== 'csv') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Only .csv files are supported.']);
    exit;
}

if ($fileSize > (2 * 1024 * 1024)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File exceeds maximum 2 MB limit.']);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $fileTmp);
finfo_close($finfo);

$allowedMimes = [
    'text/plain', 'text/csv', 'text/x-csv', 'application/vnd.ms-excel',
    'text/comma-separated-values', 'application/csv', 'application/octet-stream'
];

if (!in_array($mimeType, $allowedMimes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid CSV MIME type (' . $mimeType . ').']);
    exit;
}

$pdo = getDbConnection();
$userId = currentUserId();

$handle = fopen($fileTmp, 'r');
if (!$handle) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to read uploaded CSV.']);
    exit;
}

// Strip BOM
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($handle);
}

$dupStmt = $pdo->prepare("
    SELECT transaction_id 
    FROM transactions 
    WHERE user_id = :uid AND date = :date AND amount = :amount AND LOWER(TRIM(title)) = LOWER(TRIM(:title))
    LIMIT 1
");

$rows = [];
$badRows = [];
$seenInFile = [];
$headerMap = ['date' => null, 'description' => null, 'amount' => null, 'type' => null];
$rowIndex = 0;
$dataRowCount = 0;

while (($data = fgetcsv($handle, 4096, ',')) !== false) {
    $rowIndex++;
    if (empty(array_filter($data, fn($v) => trim($v) !== ''))) continue;

    if ($rowIndex === 1) {
        $isHeader = false;
        foreach ($data as $colIdx => $colVal) {
            $colClean = strtolower(trim(preg_replace('/[^a-zA-Z]/', '', $colVal)));
            if (in_array($colClean, ['date', 'txdate', 'time', 'posted', 'day'])) {
                $headerMap['date'] = $colIdx;
                $isHeader = true;
            } elseif (in_array($colClean, ['desc', 'description', 'title', 'memo', 'details', 'narration'])) {
                $headerMap['description'] = $colIdx;
                $isHeader = true;
            } elseif (in_array($colClean, ['amount', 'total', 'cost', 'price', 'sum', 'value', 'net', 'debit'])) {
                $headerMap['amount'] = $colIdx;
                $isHeader = true;
            } elseif (in_array($colClean, ['type', 'txtype', 'direction'])) {
                $headerMap['type'] = $colIdx;
                $isHeader = true;
            }
        }
        if ($isHeader) continue;
    }

    $dataRowCount++;
    if ($dataRowCount > 1000) break;

    $dIdx = $headerMap['date'] ?? 0;
    $descIdx = $headerMap['description'] ?? 1;
    $amtIdx = $headerMap['amount'] ?? 2;
    $typeIdx = $headerMap['type'] ?? 3;

    $dInfo = parseImportDate($data[$dIdx] ?? '');
    $aInfo = parseImportAmount($data[$amtIdx] ?? '', $data[$typeIdx] ?? null);
    $desc = trim($data[$descIdx] ?? '');

    $errors = [];
    if (!$dInfo['valid']) $errors[] = $dInfo['error'];
    if (!$aInfo['valid']) $errors[] = $aInfo['error'];
    if ($desc === '') $errors[] = 'Description is empty';

    $isBad = !empty($errors);
    if ($isBad) {
        $badRows[] = ['row' => $rowIndex, 'errors' => $errors];
    }

    $cDate = $dInfo['valid'] ? $dInfo['date'] : date('Y-m-d');
    $cAmt = $aInfo['valid'] ? $aInfo['amount'] : 0.0;
    $cType = $aInfo['type'];

    $isDuplicate = false;
    if (!$isBad) {
        $dupStmt->execute([':uid' => $userId, ':date' => $cDate, ':amount' => $cAmt, ':title' => $desc]);
        if ($dupStmt->fetch()) {
            $isDuplicate = true;
        }
        $fKey = $cDate . '|' . number_format($cAmt, 2, '.', '') . '|' . strtolower($desc);
        if (isset($seenInFile[$fKey])) {
            $isDuplicate = true;
        } else {
            $seenInFile[$fKey] = $rowIndex;
        }
    }

    $pred = predictCategory($pdo, $userId, $desc);
    $confidence = 'low';
    $suggestedCatId = null;
    $suggestedCatName = 'Uncategorized';
    if ($pred && !$isBad) {
        $score = (float)$pred['score'];
        if ($pred['source'] === 'user_correction' || $score >= 2.0) {
            $confidence = 'high';
            $suggestedCatId = (int)$pred['category_id'];
            $suggestedCatName = $pred['name'];
        } elseif ($score >= 0.8) {
            $confidence = 'medium';
            $suggestedCatId = (int)$pred['category_id'];
            $suggestedCatName = $pred['name'];
        }
    }

    $rows[] = [
        'row'                => $rowIndex,
        'date'               => $cDate,
        'description'        => $desc,
        'amount'             => $cAmt,
        'type'               => $cType,
        'confidence'         => $confidence,
        'suggested_cat_id'   => $suggestedCatId,
        'suggested_cat_name' => $suggestedCatName,
        'is_duplicate'       => $isDuplicate,
        'is_bad'             => $isBad,
        'errors'             => $errors
    ];
}
fclose($handle);

echo json_encode([
    'success'    => true,
    'file_name'  => $fileName,
    'total_rows' => count($rows),
    'bad_rows'   => $badRows,
    'rows'       => $rows
]);
