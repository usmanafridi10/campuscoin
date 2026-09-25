<?php
require_once '../config.php';

if (isset($_SESSION['campus_coin_admin']) && $_SESSION['campus_coin_admin'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
$adminEmail = 'admin@campuscoin.com';
$adminPassword = 'admin123';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === $adminEmail && $password === $adminPassword) {
        $_SESSION['campus_coin_admin'] = true;
        $_SESSION['campus_coin_admin_name'] = 'Campus Coin Admin';
        header('Location: index.php');
        exit;
    }

    $error = 'Invalid admin email or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | Campus Coin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="admin-login-page">
    <main class="admin-login-shell">
        <section class="admin-login-card">
            <div class="brand admin-brand">
                <div class="brand-mark">C</div>
                <div>
                    <strong>Campus Coin</strong>
                    <span>Administration</span>
                </div>
            </div>

            <p class="eyebrow">Secure admin area</p>
            <h1>Admin sign in</h1>
            <p class="muted">Manage and review the Campus Coin experience from one dashboard.</p>

            <?php if ($error !== ''): ?>
                <div class="login-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="demo-login-box">
                <strong>Demo Admin</strong>
                <span>Email: admin@campuscoin.com</span>
                <span>Password: admin123</span>
            </div>

            <form method="POST" action="login.php">
                <label>
                    Admin email
                    <input type="email" name="email" placeholder="admin@campuscoin.com" required>
                </label>
                <label>
                    Password
                    <input type="password" name="password" placeholder="Enter password" required>
                </label>
                <button class="primary-btn full-btn" type="submit">Enter admin dashboard</button>
            </form>

            <a class="admin-back-link" href="../login.php">← Back to student login</a>
        </section>
    </main>
</body>
</html>
