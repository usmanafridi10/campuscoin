<?php
$pageTitle = "Create account";
include "includes/header.php";
?>
<div class="auth-page">
    <div class="auth-art">
        <div class="auth-brand"><div class="brand-mark">C</div><strong>Campus Coin</strong></div>
        <div class="auth-copy">
            <p class="eyebrow">Start with clarity</p>
            <h1>Build better money habits, one month at a time.</h1>
            <p>Create a student-friendly finance space for your allowance, budgets and savings.</p>
        </div>
        <div class="auth-stat-row"><div><strong>5</strong><span>Categories</span></div><div><strong>6</strong><span>Months</span></div><div><strong>1</strong><span>Goal</span></div></div>
    </div>
    <div class="auth-form-wrap">
        <div class="auth-form">
            <div class="mobile-brand auth-mobile"><div class="brand-mark">C</div><strong>Campus Coin</strong></div>
            <p class="eyebrow">Get started</p>
            <h2>Create your account</h2>
            <p class="muted">It only takes a minute.</p>
            <form onsubmit="return demoSignup(event)">
                <label>Full name<input type="text" placeholder="Your name" required></label>
                <label>Email address<input type="email" placeholder="you@example.com" required></label>
                <label>Password<input type="password" placeholder="Create a password" required></label>
                <button class="primary-btn full-btn" type="submit">Create account</button>
            </form>
            <p class="auth-switch">Already have an account? <a href="login.php">Sign in</a></p>
        </div>
    </div>
</div>
<script>
function demoSignup(event) {
    event.preventDefault();

    const name = document.getElementById("signupName").value.trim();
    const email = document.getElementById("signupEmail").value.trim();

    localStorage.setItem("campusCoinUser", JSON.stringify({
        name: name,
        email: email
    }));

    showDemoMessage("Account profile saved. You can now sign in with the demo login.");

    setTimeout(function () {
        window.location.href = "login.php";
    }, 800);

    return false;
}
</script>
