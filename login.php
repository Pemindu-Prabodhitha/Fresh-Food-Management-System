<?php
session_start();
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'Farmer') { header("Location: dashboards/farmer.php"); exit; }
    elseif ($_SESSION['role'] === 'Sales') { header("Location: dashboards/sales.php"); exit; }
    elseif ($_SESSION['role'] === 'Transporter') { header("Location: dashboards/transporter.php"); exit; }
    elseif ($_SESSION['role'] === 'Admin') { header("Location: dashboards/admin.php"); exit; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Fresh Ceylon</title>
    <link rel="stylesheet" href="style.css?v=2">
</head>
<body class="landing-body">
    
    <nav class="top-nav">
        <div class="logo-container">
            <img src="images/logofinal.png" alt="Fresh Ceylon Logo" class="nav-logo" style="height:100px;">
            <h1 class="brand-name-dash">Fresh Ceylon</h1>
        </div>
        <div class="nav-links">
            <a href="index.php" class="btn btn-primary" style="width:auto;">Home</a>
            <a href="signup.php" class="btn btn-success" style="width:auto;">Registration</a>
        </div>
    </nav>

    <div style="flex-grow:1; display:flex; align-items:center; justify-content:center; padding:40px 20px;">
        <div class="auth-container">
            <h2>Login to Dashboard</h2>
            <?php
            if (isset($_SESSION['login_error'])) {
                echo '<div style="background-color:#2a1010; color:#ff6b6b; padding:12px; border-radius:6px; margin-bottom:15px; border:1px solid #ff6b6b; text-align:center; font-size:14px;">' . $_SESSION['login_error'] . '</div>';
                unset($_SESSION['login_error']);
            }
            ?>
            <form id="login-form" action="login_action.php" method="POST" novalidate autocomplete="off">
                <div class="form-group">
                    <label for="login-email">Email Address:</label>
                    <input type="text" id="login-email" name="email" class="form-control">
                </div>
                <div class="form-group">
                    <label for="login-password">Password:</label>
                    <input type="password" id="login-password" name="password" class="form-control">
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:10px;">Login</button>
            </form>
            <p style="text-align:center; margin-top:20px; color:#888; font-size:14px;">Don't have an account? <a href="signup.php" style="color:lightgreen; text-decoration:none;">Register here</a></p>
        </div>
    </div>

    <script src="validation.js"></script>
</body>
</html>