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
session_start();

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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 400px;
            overflow: hidden;
        }
        
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 32px;
            text-align: center;
        }
        
        .login-header h1 {
            font-size: 24px;
            margin-bottom: 8px;
        }
        
        .login-header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .login-body {
            padding: 32px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #374151;
        }
        
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 15px;
            transition: border-color 0.2s;
        }
        
        input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .error-message {
            background: #fee2e2;
            border: 1px solid #ef4444;
            color: #991b1b;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .login-footer {
            text-align: center;
            padding: 20px 32px 32px;
            color: #6b7280;
            font-size: 14px;
        }
        
        .login-footer a {
            color: #667eea;
            text-decoration: none;
        }
        
        .login-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>🛡️ Permit System</h1>
            <p>Manager Login</p>
        </div>
        
        <div class="login-body">
            <?php if ($error): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="<?php echo htmlspecialchars($app->url('login.php')); ?>">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        required 
                        autofocus
                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                    >
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        required
                    >
                </div>
                
                <button type="submit" class="btn">
                    Login
                </button>
            </form>
        </div>
        
        <div class="login-footer">
            <a href="<?php echo htmlspecialchars($app->url('/')); ?>">← Back to Homepage</a>
        </div>
    </div>
</body>
</html>