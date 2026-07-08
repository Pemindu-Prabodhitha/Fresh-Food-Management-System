<?php
session_start();
require_once 'connection.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $sql = "SELECT user_id, name, password_hash, role, location_city FROM users WHERE email = ?";
    
    if ($stmt = mysqli_prepare($con, $sql)) {
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($user = mysqli_fetch_assoc($result)) {
            if (password_verify($password, $user['password_hash'])) {
                
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['location_city'] = $user['location_city'];

                if ($user['role'] == 'Farmer') { header("Location: dashboards/farmer.php"); } 
                elseif ($user['role'] == 'Sales') { header("Location: dashboards/sales.php"); } 
                elseif ($user['role'] == 'Transporter') { header("Location: dashboards/transporter.php"); } 
                elseif ($user['role'] == 'Admin') { header("Location: dashboards/admin.php"); }
                exit; 
                
            } else {
                $_SESSION['login_error'] = "Invalid email or password.";
            }
        } else {
            $_SESSION['login_error'] = "Invalid email or password.";
        }
        mysqli_stmt_close($stmt);
    } else {
        $_SESSION['login_error'] = "Database configuration error.";
    }
    mysqli_close($con);
    
    header("Location: login.php");
    exit;
}
?>