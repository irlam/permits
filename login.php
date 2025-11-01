<?php
/**
 * Login Page - Simple Version
 * 
 * File Path: /login.php
 * Description: User login without Auth class dependencies
 * Created: 24/10/2025
 * Last Modified: 24/10/2025
 * 
 * Features:
 * - Simple login form
 * - Works with bootstrap.php only
 * - No Auth class needed
 * - Session-based authentication
 */

// Load bootstrap
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

// Start session
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// DEBUG: Output session and cookie info if requested, even if already logged in
if (isset($_GET['debug'])) {
  echo '<pre style="background:#222;color:#fff;padding:12px;">';
  echo 'Session Name: ' . session_name() . "\n";
  echo 'Session ID: ' . session_id() . "\n";
  echo 'Session Data: ' . print_r($_SESSION, true) . "\n";
  echo 'Cookies: ' . print_r($_COOKIE, true) . "\n";
  echo '</pre>';
  exit;
}
// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
  header('Location: ' . $app->url('dashboard.php'));
  exit;
}

// Handle login submission
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password";
    } else {
        try {
            // Get user by email
            $stmt = $db->pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Login successful
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                
                // Log activity if function exists
        if (function_exists('logActivity')) {
          logActivity(
            'user_login',
            'auth',
            'user',
            $user['id'],
            "User logged in: {$user['email']}"
          );
        }

        // DEBUG: Output session and cookie info after login
        if (isset($_GET['debug'])) {
          echo '<pre style="background:#222;color:#fff;padding:12px;">';
          echo 'Session ID: ' . session_id() . "\n";
          echo 'Session Data: ' . print_r($_SESSION, true) . "\n";
          echo 'Cookies: ' . print_r($_COOKIE, true) . "\n";
          echo '</pre>';
          exit;
        }

        // Redirect to dashboard
        header('Location: ' . $app->url('dashboard.php'));
        exit;
            } else {
                $error = "Invalid email or password";
            }
        } catch (Exception $e) {
            $error = "An error occurred. Please try again.";
            error_log("Login error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Permit System</title>
    <link rel="stylesheet" href="<?=asset('/assets/app.css')?>">
    <meta name="theme-color" content="#0f172a">
    <style>
      /* Minimal input styling to blend with dark theme */
      .form-group label{display:block;font-weight:600;margin:0 0 6px;color:#cbd5e1}
      .form-group input{width:100%;padding:10px 12px;background:#0b1220;border:1px solid #334155;border-radius:8px;color:#e5e7eb}
      .form-group input:focus{outline:none;border-color:#3b82f6}
      .login-card{max-width:420px;margin:0 auto}
    </style>
    </head>
<body class="theme-dark">
  <header class="site-header">
    <h1 class="site-header__title">🛡️ Permit System</h1>
    <div class="site-header__actions">
      <a class="btn btn-secondary" href="<?=htmlspecialchars($app->url('/'))?>">🏠 Home</a>
    </div>
  </header>
  <main class="site-container">
    <section class="surface-card login-card">
      <div class="card-header">
        <h3>🔐 Manager Login</h3>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error" role="alert">
          <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="<?php echo htmlspecialchars($app->url('login.php')); ?>">
        <div class="form-group" style="margin-bottom:14px;">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email" required autofocus value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
        </div>
        <div class="form-group" style="margin-bottom:18px;">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%">Login</button>
      </form>
    </section>

    <div style="text-align:center;color:#94a3b8;margin-top:16px;">
      <a class="btn btn-ghost" href="<?=htmlspecialchars($app->url('/'))?>">← Back to Homepage</a>
    </div>
  </main>
</body>
</html>