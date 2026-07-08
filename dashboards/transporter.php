<?php
session_start();
require_once '../connection.php';

// --- SECURITY CHECK ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Transporter') {
    header("Location: ../login.php");
    exit;
}

$transporter_id = $_SESSION['user_id'];
$transporter_name = $_SESSION['name'];

// --- BACKEND LOGIC: ADD SERVICE AREA ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_area'])) {
    $covered_city = trim($_POST['covered_city']);

    $area_sql = "INSERT INTO transporter_service_areas (transporter_id, covered_city) VALUES (?, ?)";
    if ($stmt = mysqli_prepare($con, $area_sql)) {
        mysqli_stmt_bind_param($stmt, "is", $transporter_id, $covered_city);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo "<script>alert('Service area added successfully!'); window.location.href='transporter.php';</script>";
    }
}

// --- BACKEND LOGIC: REMOVE SERVICE AREA ---
if (isset($_GET['delete_area'])) {
    $service_id = $_GET['delete_area'];
    
    $delete_sql = "DELETE FROM transporter_service_areas WHERE service_id = ? AND transporter_id = ?";
    if ($stmt = mysqli_prepare($con, $delete_sql)) {
        mysqli_stmt_bind_param($stmt, "ii", $service_id, $transporter_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header("Location: transporter.php");
        exit;
    }
}

// --- BACKEND LOGIC: UPDATE DELIVERY STATUS ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_status'])) {
    $order_id = $_POST['order_id'];
    $new_status = $_POST['new_status'];

    $status_sql = "UPDATE orders SET order_status = ? WHERE order_id = ? AND transporter_id = ?";
    if ($stmt = mysqli_prepare($con, $status_sql)) {
        mysqli_stmt_bind_param($stmt, "sii", $new_status, $order_id, $transporter_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        
        // --- NOTIFICATION HOOK ---
        require_once '../includes/notification_helper.php';
        
        $order_lookup_sql = "SELECT sales_id FROM orders WHERE order_id = ?";
        if ($stmt_order = mysqli_prepare($con, $order_lookup_sql)) {
            mysqli_stmt_bind_param($stmt_order, "i", $order_id);
            mysqli_stmt_execute($stmt_order);
            $order_res = mysqli_stmt_get_result($stmt_order);
            if ($order_row = mysqli_fetch_assoc($order_res)) {
                $s_id = $order_row['sales_id'];
                addNotification($con, $s_id, "Delivery Update: Your order #$order_id status has shifted to '$new_status'.");
            }
            mysqli_stmt_close($stmt_order);
        }
    }
}

// --- FETCH TRANSPORTER'S COVERED CITIES ---
$area_query = "SELECT * FROM transporter_service_areas WHERE transporter_id = ?";
$areas_stmt = mysqli_prepare($con, $area_query);
mysqli_stmt_bind_param($areas_stmt, "i", $transporter_id);
mysqli_stmt_execute($areas_stmt);
$areas_result = mysqli_stmt_get_result($areas_stmt);
$my_areas = mysqli_fetch_all($areas_result, MYSQLI_ASSOC);
mysqli_stmt_close($areas_stmt);

// --- FETCH TRANSPORTER'S DELIVERY QUEUE WITH LOCATIONS ---
$queue_query = "SELECT o.*, fl.food_name, 
                u_farmer.location_city AS pickup_location, 
                u_sales.name AS buyer_name, 
                u_sales.location_city AS drop_point 
                FROM orders o
                JOIN food_listings fl ON o.listing_id = fl.listing_id
                JOIN users u_farmer ON fl.farmer_id = u_farmer.user_id
                JOIN users u_sales ON o.sales_id = u_sales.user_id
                WHERE o.transporter_id = ? 
                ORDER BY o.created_at DESC";

$queue_stmt = mysqli_prepare($con, $queue_query);
mysqli_stmt_bind_param($queue_stmt, "i", $transporter_id);
mysqli_stmt_execute($queue_stmt);
$queue_result = mysqli_stmt_get_result($queue_stmt);
$my_deliveries = mysqli_fetch_all($queue_result, MYSQLI_ASSOC);
mysqli_stmt_close($queue_stmt);

// --- FETCH RECENT NOTIFICATIONS ---
$my_notifications = [];
$noti_query = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5";
if ($stmt_noti = mysqli_prepare($con, $noti_query)) {
    mysqli_stmt_bind_param($stmt_noti, "i", $transporter_id);
    mysqli_stmt_execute($stmt_noti);
    $noti_res = mysqli_stmt_get_result($stmt_noti);
    while ($row = mysqli_fetch_assoc($noti_res)) {
        $my_notifications[] = $row;
    }
    mysqli_stmt_close($stmt_noti);
}

// --- FETCH TRANSPORTER'S AVERAGE RATING ---
$rating_query = "SELECT AVG(score) as avg_score, COUNT(*) as review_count FROM ratings WHERE reviewee_id = ?";
$avg_score = 0;
$review_count = 0;
if ($stmt_rating = mysqli_prepare($con, $rating_query)) {
    mysqli_stmt_bind_param($stmt_rating, "i", $transporter_id);
    mysqli_stmt_execute($stmt_rating);
    $rating_res = mysqli_stmt_get_result($stmt_rating);
    if ($rating_row = mysqli_fetch_assoc($rating_res)) {
        $avg_score = round($rating_row['avg_score'], 1);
        $review_count = $rating_row['review_count'];
    }
    mysqli_stmt_close($stmt_rating);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transporter Dashboard - Fresh Ceylon</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>

    <nav class="top-nav">
        <div class="logo-container">
            <img src="../images/logofinal.png" alt="Fresh Ceylon Logo" class="nav-logo" style="height: 80px; margin-bottom: 15px; border-radius: 8px;">
            <h1 class="brand-name-dash">Fresh Ceylon</h1>
        </div>
        <div class="nav-links">
            <a href="settings.php" class="btn btn-primary" style="width: auto; text-decoration: none; margin-right: 10px;">Settings</a>
            <a href="../logout.php" class="btn btn-danger" style="width: auto; text-decoration: none;">Logout</a>
        </div>
    </nav>
    <div class="dashboard-header">
        <span class="welcome-msg">
            Welcome, <strong><?php echo htmlspecialchars($transporter_name); ?></strong> (Transporter)
            <span style="color: gold; margin-left: 15px; font-size: 16px;">
                ⭐ Rating: <?php echo $review_count > 0 ? $avg_score . "/5 (" . $review_count . " reviews)" : "No reviews yet"; ?>
            </span>
        </span>
    </div>

    <div class="alert-box">
        <h4>🔔 Recent Alerts & Updates</h4>
        <?php if (empty($my_notifications)): ?>
            <p style="font-style: italic;">No new alerts at the moment.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($my_notifications as $notif): ?>
                    <li>
                        <?php echo htmlspecialchars($notif['message']); ?> 
                        <span class="alert-date">Received: <?php echo date('Y-m-d h:i A', strtotime($notif['created_at'])); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="dashboard-layout">
        
        <div class="dashboard-sidebar">
            <h3>Manage Service Areas</h3>
            <form action="transporter.php" method="POST" style="margin-bottom: 25px;">
                <input type="hidden" name="add_area" value="1">
                <div class="form-group">
                    <label for="covered_city">Add Covered City / Town:</label>
                    <div style="display: flex; gap: 10px;">
                        <input type="text" name="covered_city" class="form-control" required>
                        <button type="submit" class="btn btn-primary" style="width: auto;">Add</button>
                    </div>
                </div>
            </form>

            <h4>Your Coverage Areas:</h4>
            <?php if (empty($my_areas)): ?>
                <p>No service cities added yet.</p>
            <?php else: ?>
                <ul style="padding-left: 20px;">
                    <?php foreach ($my_areas as $area): ?>
                        <li style="margin-bottom: 10px;">
                            <?php echo htmlspecialchars($area['covered_city']); ?> 
                            <a href="transporter.php?delete_area=<?php echo $area['service_id']; ?>" style="color: #dc3545; font-size: 12px; margin-left: 10px; text-decoration: none;">[Remove]</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="dashboard-main">
            <h3>Your Delivery Queue</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Item Name</th>
                            <th>Pickup Location</th>
                            <th>Drop Point (Buyer)</th>
                            <th>Status</th>
                            <th>Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($my_deliveries)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center;">No active delivery assignments found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($my_deliveries as $job): ?>
                                <tr>
                                    <td>#<?php echo $job['order_id']; ?></td>
                                    <td><?php echo htmlspecialchars($job['food_name']); ?></td>
                                    <td><?php echo htmlspecialchars($job['pickup_location']); ?></td>
                                    <td style="color: lightgreen; font-weight: bold;"><?php echo htmlspecialchars($job['drop_point']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($job['order_status']); ?></strong></td>
                                    <td>
                                        <form action="transporter.php" method="POST" style="display: flex; gap: 5px; align-items: center;">
                                            <input type="hidden" name="update_status" value="1">
                                            <input type="hidden" name="order_id" value="<?php echo $job['order_id']; ?>">
                                            <select name="new_status" class="form-control" style="width: auto; padding: 5px;">
                                                <option value="Approved" <?php if($job['order_status'] == 'Approved') echo 'selected'; ?>>Approved</option>
                                                <option value="In Transit" <?php if($job['order_status'] == 'In Transit') echo 'selected'; ?>>In Transit</option>
                                                <option value="Delivered" <?php if($job['order_status'] == 'Delivered') echo 'selected'; ?>>Delivered</option>
                                            </select>
                                            <button type="submit" class="btn btn-success btn-sm">Save</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>