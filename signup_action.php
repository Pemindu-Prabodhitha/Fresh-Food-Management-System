<?php
session_start();
require_once 'connection.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name             = trim($_POST['name']);
    $email            = trim($_POST['email']);
    $password         = $_POST['password'];
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role             = $_POST['role'];
    $location_city    = trim($_POST['location_city']);
    $mobile_number    = trim($_POST['mobile_number'] ?? '');

    // Remember what they typed (not the passwords) so the form can refill on error
    $_SESSION['signup_old'] = [
        'name'          => $name,
        'email'         => $email,
        'role'          => $role,
        'location_city' => $location_city,
        'mobile_number' => $mobile_number,
    ];

    // --- SERVER-SIDE VALIDATION ---
    if ($name === '' || $email === '' || $location_city === '' || $mobile_number === '') {
        $_SESSION['signup_error'] = "Please fill in all required fields.";
        header("Location: signup.php");
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['signup_error'] = "Please enter a valid email address.";
        header("Location: signup.php");
        exit;
    }

    // Accept digits, spaces, +, - so both local (07XXXXXXXX) and international formats work
    if (!preg_match('/^[0-9+\-\s]{7,15}$/', $mobile_number)) {
        $_SESSION['signup_error'] = "Please enter a valid mobile number.";
        header("Location: signup.php");
        exit;
    }

    if (strlen($password) < 6) {
        $_SESSION['signup_error'] = "Password must be at least 6 characters long.";
        header("Location: signup.php");
        exit;
    }

    if ($password !== $confirm_password) {
        $_SESSION['signup_error'] = "Passwords do not match. Please re-type them.";
        header("Location: signup.php");
        exit;
    }

    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    $sql = "INSERT INTO users (name, email, password_hash, role, location_city, phone) VALUES (?, ?, ?, ?, ?, ?)";

    if ($stmt = mysqli_prepare($con, $sql)) {
        mysqli_stmt_bind_param($stmt, "ssssss", $name, $email, $password_hash, $role, $location_city, $mobile_number);

        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['signup_success'] = "Registration successful!";
            unset($_SESSION['signup_old']); // clear saved values on success
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