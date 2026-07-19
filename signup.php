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
    <title>Register - Fresh Ceylon</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="landing-body">

    <nav class="top-nav">
        <div class="logo-container">
            <img src="images/logofinal.png" alt="Fresh Ceylon Logo" class="nav-logo" style="height:55px;">
            <h1 class="brand-name-dash">Fresh Ceylon</h1>
        </div>
        <div class="nav-links">
            <a href="index.php" class="btn btn-primary" style="width:auto;">Home</a>
            <a href="login.php" class="btn btn-primary" style="width:auto;">Login</a>
        </div>
    </nav>

    <div style="flex-grow:1; display:flex; align-items:center; justify-content:center; padding:40px 20px;">
        <div class="auth-container auth-wide">
            <h2>Create an Account</h2>
            <?php
            if (isset($_SESSION['signup_success'])) {
                echo '<div style="background-color:#0a2a10; color:#8ce67c; padding:12px; border-radius:6px; margin-bottom:15px; border:1px solid #2e4a29; text-align:center; font-size:14px;">' . $_SESSION['signup_success'] . '<br><a href="login.php" style="color:white; font-weight:bold; text-decoration:underline;">Click here to Login</a></div>';
                unset($_SESSION['signup_success']);
            }
            if (isset($_SESSION['signup_error'])) {
                echo '<div style="background-color:#2a1010; color:#ff6b6b; padding:12px; border-radius:6px; margin-bottom:15px; border:1px solid #ff6b6b; text-align:center; font-size:14px;">' . $_SESSION['signup_error'] . '</div>';
                unset($_SESSION['signup_error']);
            }
            ?>
            <form id="signup-form" action="signup_action.php" method="POST" novalidate autocomplete="off">
                <div class="form-row">
                    <div class="form-group">
                        <label for="signup-name">Full Name:</label>
                        <input type="text" id="signup-name" name="name" class="form-control" placeholder="John Doe">
                    </div>
                    <div class="form-group">
                        <label for="signup-email">Email Address:</label>
                        <input type="text" id="signup-email" name="email" class="form-control" placeholder="john@example.com">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="signup-password">Password:</label>
                        <input type="password" id="signup-password" name="password" class="form-control" placeholder="Min. 6 characters">
                    </div>
                    <div class="form-group">
                        <label for="signup-role">Select Your Role:</label>
                        <select id="signup-role" name="role" class="form-control">
                            <option value="Farmer">Farmer</option>
                            <option value="Sales">Sales Person</option>
                            <option value="Transporter">Transporter</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="signup-location">City / Location:</label>
                    <input type="text" id="signup-location" name="location_city" class="form-control" placeholder="Colombo, Kandy, Galle...">
                </div>
                <button type="submit" class="btn btn-success" style="margin-top:10px;">Register Account</button>
            </form>
            <p style="text-align:center; margin-top:20px; color:#888; font-size:14px;">Already have an account? <a href="login.php" style="color:lightgreen; text-decoration:none;">Login here</a></p>
        </div>
    </div>

    <script src="validation.js"></script>
</body>
</html>