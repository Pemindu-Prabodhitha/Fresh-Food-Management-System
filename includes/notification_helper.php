<?php
function addNotification($con, $user_id, $message) {
    $sql = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
    if ($stmt = mysqli_prepare($con, $sql)) {
        mysqli_stmt_bind_param($stmt, "is", $user_id, $message);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}
?>