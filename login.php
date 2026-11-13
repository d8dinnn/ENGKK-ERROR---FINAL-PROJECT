<?php
require_once 'config.php';
if (!empty($_SESSION['user_id'])) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username)) {
        $error = 'Please enter a username.';
    } elseif (!preg_match('/^[a-zA-Z\s]+$/', $username)) {
        // Fail login if username contains any numbers or symbols
        $error = 'Invalid username or password.';
    } else {
        // Check if user already exists
        $stmt = $pdo->prepare("SELECT * FROM users WHERE name = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user) {
            // If user exists, log them in
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            redirect();
        } else {
            // If user doesn't exist, automatically create them on the fly
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $insert = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
            
            if ($insert->execute([$username, $username . '@local.test', $hashedPassword])) {
                $newUserId = $pdo->lastInsertId();
                session_regenerate_id(true);
                $_SESSION['user_id'] = $newUserId;
                $_SESSION['name'] = $username;
                redirect();
            } else {
                $error = 'Login failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | 4M's Express</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
<div class="login-card">
    <div class="brand-mark">4M's</div>
    <p class="eyebrow">INVENTORY & SALES ANALYTICS</p>
    <h1>4M's Express</h1>
    <p class="muted">Log In to start manage your stall.</p>

    <?php if ($error): ?>
        <div class="alert danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <label>Username</label>
        <input type="text" name="username" required autocomplete="username">
        
        <label>Password</label>
        <input type="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
        
        <button class="btn primary full" type="submit">Log In</button>
    </form>
</div>
</body>
</html>