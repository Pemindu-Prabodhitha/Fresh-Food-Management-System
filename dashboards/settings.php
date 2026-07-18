<?php
session_start();
require_once '../connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'];
$message = '';
$error   = '';

$dashboard_link = "farmer.php";
if ($role === 'Sales')       $dashboard_link = "sales.php";
if ($role === 'Transporter') $dashboard_link = "transporter.php";
if ($role === 'Admin')       $dashboard_link = "admin.php";

// --- HANDLE PROFILE UPDATE ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $new_name = trim($_POST['name']);
    $new_city = trim($_POST['location_city']);
    $update_sql = "UPDATE users SET name = ?, location_city = ? WHERE user_id = ?";
    if ($stmt = mysqli_prepare($con, $update_sql)) {
        mysqli_stmt_bind_param($stmt, "ssi", $new_name, $new_city, $user_id);
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['name'] = $new_name;
            $_SESSION['location_city'] = $new_city;
            $message = "Profile updated successfully!";
        } else { $error = "Error updating profile."; }
        mysqli_stmt_close($stmt);
    }
}

// --- HANDLE PASSWORD UPDATE ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_password'])) {
    $current_password  = $_POST['current_password'];
    $new_password      = $_POST['new_password'];
    $confirm_password  = $_POST['confirm_password'];

    if ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters long.";
    } else {
        $verify_sql = "SELECT password_hash FROM users WHERE user_id = ?";
        if ($stmt = mysqli_prepare($con, $verify_sql)) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) {
                if (password_verify($current_password, $row['password_hash'])) {
                    $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
                    $update_pass_sql = "UPDATE users SET password_hash = ? WHERE user_id = ?";
                    if ($stmt2 = mysqli_prepare($con, $update_pass_sql)) {
                        mysqli_stmt_bind_param($stmt2, "si", $new_hash, $user_id);
                        if (mysqli_stmt_execute($stmt2)) { $message = "Password changed successfully!"; }
                        else { $error = "Error updating password."; }
                        mysqli_stmt_close($stmt2);
                    }
                } else { $error = "Incorrect current password."; }
            }
            mysqli_stmt_close($stmt);
        }
    }
}

$query = "SELECT name, email, location_city FROM users WHERE user_id = ?";
if ($stmt = mysqli_prepare($con, $query)) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result    = mysqli_stmt_get_result($stmt);
    $user_data = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - Fresh Ceylon</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>

    <nav class="top-nav">
        <div class="logo-container">
            <img src="../images/logofinal.png" alt="Fresh Ceylon Logo" class="nav-logo" style="height:55px;">
            <h1 class="brand-name-dash">Fresh Ceylon</h1>
        </div>
        <div class="nav-links">
            <a href="<?php echo $dashboard_link; ?>" class="btn btn-primary" style="width:auto; text-decoration:none; margin-right:10px;">Back to Dashboard</a>
            <a href="../logout.php" class="btn btn-danger" style="width:auto; text-decoration:none;">Logout</a>
        </div>
    </nav>

    <div style="max-width:900px; margin:40px auto; padding:0 20px;">
        <h2 style="color:lightgreen; margin-bottom:25px;">Account Settings</h2>

        <?php if ($message): ?>
            <div style="background:#0a2a10; color:#8ce67c; padding:12px; border-radius:6px; margin-bottom:20px; border:1px solid #2e4a29; text-align:center; font-size:14px;"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div style="background:#2a1010; color:#ff6b6b; padding:12px; border-radius:6px; margin-bottom:20px; border:1px solid #ff6b6b; text-align:center; font-size:14px;"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="settings-split">
            <div style="padding:25px; background:#000; border:1px solid #2a2a2a; border-radius:10px;">
                <h3 style="color:lightgreen; margin-top:0; margin-bottom:20px;">Update Profile Details</h3>
                <form action="settings.php" method="POST">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="form-group">
                        <label>Email Address (Cannot be changed):</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_data['email']); ?>" disabled style="background:#111; color:#555; cursor:not-allowed;">
                    </div>
                    <div class="form-group">
                        <label>Full Name:</label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($user_data['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Location / City:</label>
                        <input type="text" name="location_city" class="form-control" value="<?php echo htmlspecialchars($user_data['location_city']); ?>" required>
                    </div>
                    <button type="submit" class="btn btn-success" style="margin-top:10px;">Save Profile Updates</button>
                </form>
            </div>

            <div style="padding:25px; background:#000; border:1px solid #2a2a2a; border-radius:10px;">
                <h3 style="color:lightgreen; margin-top:0; margin-bottom:20px;">Change Password</h3>
                <form action="settings.php" method="POST">
                    <input type="hidden" name="update_password" value="1">
                    <div class="form-group">
                        <label>Current Password:</label>
                        <input type="password" name="current_password" class="form-control" required placeholder="••••••••">
                    </div>
                    <div class="form-group">
                        <label>New Password (Min. 6 characters):</label>
                        <input type="password" name="new_password" class="form-control" required minlength="6" placeholder="New password">
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password:</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="6" placeholder="Re-type new password">
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top:10px;">Change Password</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>