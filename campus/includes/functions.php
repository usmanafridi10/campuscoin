<?php
if (!defined('CAMPUS_COIN_BOOTSTRAP')) {
    define('CAMPUS_COIN_BOOTSTRAP', true);
}

/**
 * Escapes HTML output safely to prevent XSS (Cross-Site Scripting).
 */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Generates or retrieves existing CSRF token for the active session.
 */
function generateCsrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Validates submitted CSRF token against current session token using timing-safe comparison.
 */
function validateCsrfToken(?string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sets a flash message to display on subsequent page render.
 */
function setFlashMessage(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_' . $type] = $message;
}

/**
 * Retrieves and clears a flash message.
 */
function getFlashMessage(string $type): ?string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $key = 'flash_' . $type;
    if (isset($_SESSION[$key])) {
        $msg = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $msg;
    }
    return null;
}

/**
 * Checks if a student or user is currently logged in.
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['campus_coin_logged_in']) && 
           $_SESSION['campus_coin_logged_in'] === true && 
           !empty($_SESSION['user_id']);
}

/**
 * Enforces student/user login. Redirects to login.php if unauthenticated.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Checks if the logged-in user is an administrator.
 */
function isAdmin(): bool
{
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Enforces admin authorization.
 */
function requireAdmin(): void
{
    if (!isAdmin()) {
        $inAdminDir = (strpos($_SERVER['PHP_SELF'] ?? '', '/admin/') !== false);
        $redirectUrl = $inAdminDir ? 'login.php' : 'admin/login.php';
        header('Location: ' . $redirectUrl);
        exit;
    }
}

/**
 * Returns current authenticated user's ID.
 */
function currentUserId(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

/**
 * Returns current authenticated user's display name.
 */
function currentUserName(): string
{
    return (string)($_SESSION['user_name'] ?? 'Student');
}

/**
 * Fetches the complete profile record of the currently authenticated user.
 */
function currentUser(): ?array
{
    $userId = currentUserId();
    if ($userId <= 0) {
        return null;
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT user_id, name, email, role, academic_year, monthly_allowance, monthly_savings_goal, ai_suggestions_enabled, is_active, created_at FROM users WHERE user_id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}

/**
 * Parses and sanitizes a raw CSV amount string (handles commas, currency prefixes, negative formats).
 */
function parseImportAmount(string $rawAmount, ?string $rawType = null): array
{
    $clean = trim($rawAmount);
    if ($clean === '') {
        return ['amount' => 0.0, 'type' => 'expense', 'valid' => false, 'error' => 'Amount is empty'];
    }

    $isNegative = false;
    if (preg_match('/^\((.+)\)$/', $clean, $m)) {
        $isNegative = true;
        $clean = $m[1];
    } elseif (strpos($clean, '-') !== false) {
        $isNegative = true;
    }

    // Strip currency prefixes/suffixes and symbols (e.g. "Rs.", "PKR", "$", "USD", etc.)
    $clean = preg_replace('/^\s*(?:Rs\.?|PKR|USD|EUR|GBP|\$|€|£|¥)\s*/i', '', $clean);
    // Strip remaining letters
    $clean = preg_replace('/[a-zA-Z]+/', '', $clean);
    // Remove comma thousands separators
    $clean = str_replace(',', '', $clean);
    // Trim remaining whitespace or extra characters
    $numStr = trim($clean, " ()-\t\n\r\0\x0B");

    if ($numStr === '' || !is_numeric($numStr)) {
        return ['amount' => 0.0, 'type' => 'expense', 'valid' => false, 'error' => "Invalid amount format: '{$rawAmount}'"];
    }

    $amount = (float)$numStr;
    if ($amount <= 0) {
        return ['amount' => 0.0, 'type' => 'expense', 'valid' => false, 'error' => 'Amount must be greater than zero'];
    }

    $type = 'expense';
    if ($rawType !== null && trim($rawType) !== '') {
        $tLower = strtolower(trim($rawType));
        if (strpos($tLower, 'inc') !== false || strpos($tLower, 'credit') !== false || strpos($tLower, 'deposit') !== false || $tLower === 'cr') {
            $type = 'income';
        } else {
            $type = 'expense';
        }
    } else {
        // If negative in single amount column, it is an expense
        $type = 'expense';
    }

    return ['amount' => $amount, 'type' => $type, 'valid' => true, 'error' => null];
}

/**
 * Parses and validates common CSV date formats.
 */
function parseImportDate(string $rawDate): array
{
    $clean = trim($rawDate);
    if ($clean === '') {
        return ['date' => '', 'valid' => false, 'error' => 'Date is empty'];
    }

    $formats = [
        'Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y', 'Y/m/d',
        'd.m.Y', 'Y.m.d', 'j M Y', 'd M Y', 'j F Y', 'd F Y',
        'M j, Y', 'F j, Y', 'Y-m-d H:i:s', 'd/m/Y H:i', 'm/d/Y H:i'
    ];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $clean);
        if ($dt !== false) {
            $errors = DateTime::getLastErrors();
            if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                $year = (int)$dt->format('Y');
                if ($year >= 1990 && $year <= 2099) {
                    return ['date' => $dt->format('Y-m-d'), 'valid' => true, 'error' => null];
                }
            }
        }
    }

    $ts = strtotime($clean);
    if ($ts !== false && $ts > 0) {
        $year = (int)date('Y', $ts);
        if ($year >= 1990 && $year <= 2099) {
            return ['date' => date('Y-m-d', $ts), 'valid' => true, 'error' => null];
        }
    }

    return ['date' => $clean, 'valid' => false, 'error' => "Invalid date format: '{$clean}'"];
}


