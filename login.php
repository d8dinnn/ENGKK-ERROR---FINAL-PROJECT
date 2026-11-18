<?php
require_once 'config.php';
if (!empty($_SESSION['user_id'])) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email)) {
        $error = 'Please enter an email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Extract the name part before the '@' symbol
        $localPart = explode('@', $email)[0];
        
        $cleanName = preg_replace('/[0-9]+/', '', $localPart);
        $cleanName = str_replace(['.', '_', '-'], ' ', $cleanName);
        $name = ucwords(trim(preg_replace('/\s+/', ' ', $cleanName)));
        
        if (empty($name)) {
            $name = 'Stall Owner';
        }

        // Check if user already exists by email
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // If user exists, log them in
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            redirect();
        } else {
            // If user doesn't exist, automatically create them on the fly without registration
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $insert = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
            
            if ($insert->execute([$name, $email, $hashedPassword])) {
                $newUserId = $pdo->lastInsertId();
                session_regenerate_id(true);
                $_SESSION['user_id'] = $newUserId;
                $_SESSION['name'] = $name;
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
        <label>Email Address</label>
        <input type="email" name="email" required autocomplete="email" placeholder="juan.delacruz123@gmail.com">
        
        <label>Password</label>
        <input type="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
        
        <button class="btn primary full" type="submit">Log In</button>
    </form>
</div>
</body>
</html>