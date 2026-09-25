<?php
require_once 'config.php';

if (isset($_SESSION['campus_coin_logged_in']) && $_SESSION['campus_coin_logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === $demoEmail && $password === $demoPassword) {
        $_SESSION['campus_coin_logged_in'] = true;
        $_SESSION['campus_coin_user'] = 'Campus Coin Student';
        $_SESSION['campus_coin_email'] = $email;

        header('Location: dashboard.php');
        exit;
    }

    $error = 'Invalid email or password. Please use the demo login shown below.';
}

$pageTitle = 'Login';
include 'includes/header.php';
?>
<div class="auth-page">
    <div class="auth-art">
        <div class="auth-brand"><div class="brand-mark">C</div><strong>Campus Coin</strong></div>
        <div class="auth-copy">
            <p class="eyebrow">Student finance made simple</p>
            <h1>Know your money. Plan your month.</h1>
            <p>Keep your allowance, expenses and savings goals in one calm, simple space.</p>
        </div>
        <div class="auth-mini-card">
            <span>September balance</span>
            <strong>Rs. 248.50</strong>
            <small>On track for your savings goal</small>
        </div>
    </div>

    <div class="auth-form-wrap">
        <div class="auth-form">
            <div class="mobile-brand auth-mobile"><div class="brand-mark">C</div><strong>Campus Coin</strong></div>
            <p class="eyebrow">Welcome back</p>
            <h2>Sign in to your account</h2>
            <p class="muted">Enter your details to continue.</p>

            <?php if ($error !== ''): ?>
                <div class="login-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="demo-login-box">
                <strong>Demo Login</strong>
                <span>Email: student@campuscoin.com</span>
                <span>Password: 123456</span>
            </div>

            <form method="POST" action="login.php">
                <label>
                    Email address
                    <input type="email" name="email" placeholder="you@example.com" required>
                </label>

                <label>
                    Password
                    <input type="password" name="password" placeholder="Enter password" required>
                </label>

                <div class="form-row-between">
                    <label class="check-label">
                        <input type="checkbox" name="remember">
                        Remember me
                    </label>
                    <a href="#">Forgot password?</a>
                </div>

                <button class="primary-btn full-btn" type="submit">Sign in</button>
            </form>

            <p class="auth-switch">Don't have an account? <a href="signup.php">Create one</a></p>
            <p class="auth-switch"><a href="admin/login.php">Admin portal →</a></p>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
