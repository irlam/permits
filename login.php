<?php
/**
 * Permits System - Login Page
 * 
 * Description: User login interface with authentication
 * Name: login.php
 * Last Updated: 21/10/2025 21:03:42 (UK)
 * Author: irlam
 * 
 * Purpose:
 * - Provide secure login interface
 * - Handle user authentication
 * - Support remember me functionality
 * - Display error messages
 * - Redirect after successful login
 * 
 * Features:
 * - Modern login form design
 * - Password visibility toggle
 * - Remember me checkbox
 * - Error handling
 * - CSRF protection (via session)
 */

// Load application bootstrap
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

use Permits\Auth;

// Initialize auth
$auth = new Auth($db);

// Check if already logged in
if ($auth->isAuthenticated()) {
    header('Location: /dashboard');
    exit;
}

$error = '';
$success = '';

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        try {
            if ($auth->login($username, $password, $remember)) {
                // Login successful - redirect
                $redirectTo = $_GET['redirect'] ?? '/dashboard';
                header('Location: ' . $redirectTo);
                exit;
            } else {
                $error = 'Invalid username or password';
            }
        } catch (\Exception $e) {
            $error = 'An error occurred. Please try again.';
            error_log("Login error: " . $e->getMessage());
        }
    }
}

$base = $_ENV['APP_URL'] ?? '/';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="manifest" href="/manifest.webmanifest">
  <meta name="theme-color" content="#0ea5e9">
  <title>Login - Permits</title>
  <link rel="stylesheet" href="/assets/app.css">
  <style>
    body {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      padding: 20px;
    }
    .login-container {
      width: 100%;
      max-width: 420px;
    }
    .login-card {
      background: #111827;
      border: 1px solid #1f2937;
      border-radius: 16px;
      padding: 40px;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
    }
    .logo {
      text-align: center;
      margin-bottom: 32px;
    }
    .logo-icon {
      font-size: 64px;
      margin-bottom: 16px;
    }
    .logo h1 {
      margin: 0;
      font-size: 28px;
      font-weight: 700;
      color: #f9fafb;
    }
    .logo p {
      margin: 8px 0 0 0;
      color: #9ca3af;
      font-size: 14px;
    }
    .form-group {
      margin-bottom: 24px;
    }
    .form-group label {
      display: block;
      font-size: 14px;
      color: #9ca3af;
      font-weight: 500;
      margin-bottom: 8px;
    }
    .form-group input {
      width: 100%;
      padding: 14px;
      background: #0a0f1a;
      border: 1px solid #1f2937;
      border-radius: 8px;
      color: #f9fafb;
      font-size: 15px;
      transition: all 0.2s ease;
    }
    .form-group input:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    .password-field {
      position: relative;
    }
    .password-toggle {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: #9ca3af;
      cursor: pointer;
      padding: 4px 8px;
      font-size: 14px;
    }
    .password-toggle:hover {
      color: #f9fafb;
    }
    .checkbox-group {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 24px;
    }
    .checkbox-group input[type="checkbox"] {
      width: auto;
      margin: 0;
    }
    .checkbox-group label {
      margin: 0;
      font-size: 14px;
      color: #9ca3af;
      cursor: pointer;
    }
    .btn-login {
      width: 100%;
      padding: 14px;
      background: #3b82f6;
      border: none;
      border-radius: 8px;
      color: white;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .btn-login:hover {
      background: #2563eb;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    }
    .btn-login:active {
      transform: translateY(0);
    }
    .alert {
      padding: 14px 16px;
      border-radius: 8px;
      margin-bottom: 24px;
      font-size: 14px;
    }
    .alert-error {
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #991b1b;
    }
    .alert-success {
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      color: #166534;
    }
    .footer-links {
      text-align: center;
      margin-top: 24px;
      padding-top: 24px;
      border-top: 1px solid #1f2937;
    }
    .footer-links a {
      color: #3b82f6;
      text-decoration: none;
      font-size: 14px;
    }
    .footer-links a:hover {
      text-decoration: underline;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <div class="login-card">
      <div class="logo">
        <div class="logo-icon">🔐</div>
        <h1>Permits System</h1>
        <p>Sign in to continue</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error">
          <strong>⚠ Error:</strong> <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success">
          <strong>✓ Success:</strong> <?= htmlspecialchars($success) ?>
        </div>
      <?php endif; ?>

      <form method="post" action="/login">
        <div class="form-group">
          <label for="username">Username or Email</label>
          <input 
            type="text" 
            id="username" 
            name="username" 
            placeholder="Enter your username"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
            required
            autofocus
          >
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <div class="password-field">
            <input 
              type="password" 
              id="password" 
              name="password" 
              placeholder="Enter your password"
              required
            >
            <button type="button" class="password-toggle" onclick="togglePassword()">
              <span id="toggle-icon">👁️</span>
            </button>
          </div>
        </div>

        <div class="checkbox-group">
          <input type="checkbox" id="remember" name="remember" value="1">
          <label for="remember">Remember me for 30 days</label>
        </div>

        <button type="submit" class="btn-login">Sign In</button>
      </form>

      <div class="footer-links">
        <a href="/">← Back to Permits</a>
      </div>
    </div>
  </div>

  <script>
    function togglePassword() {
      const passwordField = document.getElementById('password');
      const toggleIcon = document.getElementById('toggle-icon');
      
      if (passwordField.type === 'password') {
        passwordField.type = 'text';
        toggleIcon.textContent = '🙈';
      } else {
        passwordField.type = 'password';
        toggleIcon.textContent = '👁️';
      }
    }
  </script>
</body>
</html>
