<?php
session_start();
require_once 'connection.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    $location_city = trim($_POST['location_city']);

    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    $sql = "INSERT INTO users (name, email, password_hash, role, location_city) VALUES (?, ?, ?, ?, ?)";
    
    if ($stmt = mysqli_prepare($con, $sql)) {
        mysqli_stmt_bind_param($stmt, "sssss", $name, $email, $password_hash, $role, $location_city);
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['signup_success'] = "Registration successful!";
        } else {
            if (mysqli_errno($con) == 1062) {
                $_SESSION['signup_error'] = "Error: An account with this email already exists.";
            } else {
                $_SESSION['signup_error'] = "Something went wrong. Please try again later.";
            }
        }
        mysqli_stmt_close($stmt);
    } else {
        $_SESSION['signup_error'] = "Database error: Could not prepare statement.";
    }
    
    mysqli_close($con);
    
    header("Location: signup.php");
    exit;
}
?>