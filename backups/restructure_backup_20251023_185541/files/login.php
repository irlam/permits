<?php
/**
 * Login Page
 * 
 * File Path: /login.php
 * Description: User authentication with activity logging
 * Created: 22/10/2025
 * Last Modified: 22/10/2025
 * 
 * Features:
 * - User login with email/password
 * - Remember me functionality
 * - Activity logging for all login attempts
 * - Failed login tracking
 * - Redirect after successful login
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/Auth.php';
require __DIR__ . '/src/ActivityLogger.php';

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
$auth = new Auth($db);
$logger = new \Permits\ActivityLogger($db);

// If already logged in, redirect to dashboard
if ($auth->isLoggedIn()) {
    header('Location: /');
    exit;
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    // Attempt login
    $user = $auth->login($email, $password, $remember);
    
    if ($user) {
        // Successful login - log it
        $logger->setUser($user['id'], $user['email']);
        $logger->logLogin($user['id'], $user['email'], true);
        
        // Redirect to intended page or dashboard
        $redirect = $_GET['redirect'] ?? '/';
        header('Location: ' . $redirect);
        exit;
    } else {
        // Failed login - log it
        $logger->log(
            'login_failed',
            'auth',
            'user',
            $email,
            "Failed login attempt for: {$email}",
            null,
            null,
            'failed'
        );
        
        $error = 'Invalid email or password';
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Permits System</title>
    <link rel="stylesheet" href="/assets/app.css">
    <style>
        body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#0a101a}
        .login-box{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:40px;width:100%;max-width:400px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.3)}
        .logo{text-align:center;margin-bottom:30px}
        .logo h1{color:#0ea5e9;font-size:28px;margin:0}
        .logo p{color:#94a3b8;font-size:14px;margin:8px 0 0 0}
        .form-group{margin-bottom:20px}
        .form-group label{display:block;color:#94a3b8;margin-bottom:8px;font-size:14px}
        .form-group input{width:100%;padding:12px;background:#0a101a;border:1px solid #1f2937;border-radius:6px;color:#e5e7eb;font-size:14px}
        .form-group input:focus{outline:none;border-color:#0ea5e9}
        .error{background:#ef4444;color:#fff;padding:12px;border-radius:6px;margin-bottom:20px;font-size:14px}
        .remember{display:flex;align-items:center;margin-bottom:20px}
        .remember input{width:auto;margin-right:8px}
        .remember label{margin:0;color:#94a3b8;font-size:14px}
        .btn-login{width:100%;background:#0ea5e9;color:#fff;padding:12px;border:none;border-radius:6px;font-size:16px;font-weight:600;cursor:pointer;transition:background 0.2s}
        .btn-login:hover{background:#0284c7}
        .footer{text-align:center;margin-top:20px;padding-top:20px;border-top:1px solid #1f2937}
        .footer a{color:#0ea5e9;text-decoration:none;font-size:14px}
        .footer a:hover{text-decoration:underline}
    </style>
</head>
<body>
    <div class="login-box">
        <div class="logo">
            <h1>🛡️ Permits System</h1>
            <p>Sign in to continue</p>
        </div>
        
        <?php if($error): ?>
            <div class="error"><?=htmlspecialchars($error)?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" value="<?=htmlspecialchars($_POST['email'] ?? '')?>" placeholder="admin@permits.local" required autofocus>
            </div>
            
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            
            <div class="remember">
                <input type="checkbox" name="remember" id="remember">
                <label for="remember">Remember me for 30 days</label>
            </div>
            
            <button type="submit" class="btn-login">Sign In</button>
        </form>
        
        <div class="footer">
            <a href="/forgot-password.php">Forgot password?</a>
        </div>
    </div>
</body>
</html>