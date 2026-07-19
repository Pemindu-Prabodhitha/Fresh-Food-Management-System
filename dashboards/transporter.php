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

    require_once '../includes/notification_helper.php';

    if ($new_status === 'Cancelled') {
        // Transporter is releasing this job. Send it back to "Approved" with no
        // transporter so the buyer can pick someone else, instead of dead-ending it.
        $status_sql = "UPDATE orders SET order_status = 'Approved', transporter_id = NULL WHERE order_id = ? AND transporter_id = ?";
        if ($stmt = mysqli_prepare($con, $status_sql)) {
            mysqli_stmt_bind_param($stmt, "ii", $order_id, $transporter_id);
            mysqli_stmt_execute($stmt);
            $affected = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);

            if ($affected > 0) {
                $order_lookup_sql = "SELECT sales_id FROM orders WHERE order_id = ?";
                if ($stmt_order = mysqli_prepare($con, $order_lookup_sql)) {
                    mysqli_stmt_bind_param($stmt_order, "i", $order_id);
                    mysqli_stmt_execute($stmt_order);
                    $order_res = mysqli_stmt_get_result($stmt_order);
                    if ($order_row = mysqli_fetch_assoc($order_res)) {
                        addNotification($con, $order_row['sales_id'], "Your transporter cancelled order #$order_id. Please select another transporter to continue.");
                    }
                    mysqli_stmt_close($stmt_order);
                }
            }
        }
    } else {
        $status_sql = "UPDATE orders SET order_status = ? WHERE order_id = ? AND transporter_id = ?";
        if ($stmt = mysqli_prepare($con, $status_sql)) {
            mysqli_stmt_bind_param($stmt, "sii", $new_status, $order_id, $transporter_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

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
    <script>
    (function(){try{var t=localStorage.getItem('fc-theme');document.documentElement.setAttribute('data-theme', t === 'light' ? 'light' : 'dark');}catch(e){}})();
    </script>
    <title>Transporter Dashboard - Fresh Ceylon</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body class="has-sidenav">

    <div class="app-shell">
        <aside class="side-nav" id="sideNav">
            <div class="side-nav-brand">
                <img src="../images/logofinal.png" alt="Fresh Ceylon Logo" class="side-nav-logo">
                <div class="side-nav-brand-text">
                    <span class="brand-name-side">Fresh Ceylon</span>
                    <span class="brand-role">Transporter</span>
                </div>
            </div>
            <nav class="side-nav-links">
                <a href="transporter.php" class="side-nav-link active"><span class="side-nav-icon">🚚</span>Dashboard</a>
                <a href="settings.php" class="side-nav-link"><span class="side-nav-icon">⚙️</span>Settings</a>
            </nav>
            <div class="side-nav-footer">
                <button type="button" class="theme-toggle" data-theme-toggle>
                    <span class="theme-toggle-icon" data-theme-icon>☀️</span>
                    <span data-theme-label>Light mode</span>
                </button>
                <a href="../logout.php" class="side-nav-link side-nav-logout"><span class="side-nav-icon">↩</span>Logout</a>
            </div>
        </aside>
        <div class="side-nav-backdrop" id="sideNavBackdrop"></div>

        <div class="mobile-topbar">
            <button type="button" class="mobile-nav-toggle" id="mobileNavToggle" aria-label="Open menu">☰</button>
            <span class="mobile-topbar-brand">Fresh Ceylon — Transporter</span>
        </div>

        <main class="app-main">

    <div class="dashboard-header">
        <span class="welcome-msg">
            Welcome back, <strong><?php echo htmlspecialchars($transporter_name); ?></strong> (Transporter)
        </span>
        <span style="background: var(--accent-soft); border: 1px solid var(--accent); padding: 8px 16px; border-radius: var(--radius-md); color: var(--accent); font-weight: 600; display: inline-flex; align-items: center; gap: 8px; font-family: var(--font-mono);">
            ⭐ Rating: <?php echo $review_count > 0 ? $avg_score . "/5 (" . $review_count . " reviews)" : "No reviews yet"; ?>
        </span>
    </div>

    <div style="max-width: 1400px; width: 100%; margin: 0 auto; display: flex; flex-direction: column; gap: 30px;">

        <!-- Dashboard Sub-Navigation -->
        <div class="dashboard-tabs">
            <button type="button" class="tab-btn active" data-tab-target="tab-overview" onclick="showDashboardTab('tab-overview', this)">
                🔔 Overview
                <?php if (!empty($my_notifications)): ?><span class="tab-count"><?php echo count($my_notifications); ?></span><?php endif; ?>
            </button>
            <button type="button" class="tab-btn" data-tab-target="tab-delivery-queue" onclick="showDashboardTab('tab-delivery-queue', this)">
                🚚 Delivery Queue
                <?php if (!empty($my_deliveries)): ?><span class="tab-count"><?php echo count($my_deliveries); ?></span><?php endif; ?>
            </button>
            <button type="button" class="tab-btn" data-tab-target="tab-service-areas" onclick="showDashboardTab('tab-service-areas', this)">
                🗺️ Service Areas
                <?php if (!empty($my_areas)): ?><span class="tab-count"><?php echo count($my_areas); ?></span><?php endif; ?>
            </button>
        </div>

        <!-- Tab: Overview -->
        <div id="tab-overview" class="tab-content active">
        <div class="alert-box">
            <h4>🔔 Recent Alerts & Updates</h4>
            <?php if (empty($my_notifications)): ?>
                <p style="font-style: italic; color: var(--text-muted); font-size: 14px;">No new alerts at the moment.</p>
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
        </div><!-- /tab-overview -->

        <!-- Tab: Delivery Queue -->
        <div id="tab-delivery-queue" class="tab-content">
            <div class="dashboard-main" style="max-width: 100%;">
                <h3 style="font-size: 22px; color: var(--primary);">Your Active Delivery Queue</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Item Name</th>
                                <th>Pickup City</th>
                                <th>Drop Point (Buyer)</th>
                                <th>Status</th>
                                <th style="text-align: right;">Update Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($my_deliveries)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: var(--text-muted); font-style: italic; padding: 25px;">No active delivery assignments found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($my_deliveries as $job): ?>
                                    <tr>
                                        <td style="font-weight: bold;">#<?php echo $job['order_id']; ?></td>
                                        <td style="font-weight: 500;"><?php echo htmlspecialchars($job['food_name']); ?></td>
                                        <td><?php echo htmlspecialchars($job['pickup_location']); ?></td>
                                        <td style="color: var(--primary); font-weight: 500;"><?php echo htmlspecialchars($job['drop_point']); ?></td>
                                        <td>
                                            <?php
                                            $badge_class = 'badge-available'; // default
                                            if ($job['order_status'] == 'Approved') $badge_class = 'badge-pending';
                                            if ($job['order_status'] == 'In Transit') $badge_class = 'badge-pending';
                                            if ($job['order_status'] == 'Delivered') $badge_class = 'badge-available';
                                            if ($job['order_status'] == 'Cancelled') $badge_class = 'badge-sold';
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($job['order_status']); ?></span>
                                        </td>
                                        <td>
                                            <form action="transporter.php" method="POST" style="display: flex; gap: 8px; align-items: center; justify-content: flex-end;"
                                                onsubmit="var sel=this.querySelector('select[name=new_status]'); if(sel.value==='Cancelled'){ return confirm('Cancel this delivery job? It will be released back to the buyer so they can pick another transporter.'); }">
                                                <input type="hidden" name="update_status" value="1">
                                                <input type="hidden" name="order_id" value="<?php echo $job['order_id']; ?>">
                                                <select name="new_status" class="form-control" style="width: auto; padding: 6px 12px; font-size: 13px;">
                                                    <option value="Approved" <?php if($job['order_status'] == 'Approved') echo 'selected'; ?>>Approved</option>
                                                    <option value="In Transit" <?php if($job['order_status'] == 'In Transit') echo 'selected'; ?>>In Transit</option>
                                                    <option value="Delivered" <?php if($job['order_status'] == 'Delivered') echo 'selected'; ?>>Delivered</option>
                                                    <option value="Cancelled">Cancel &amp; Release Job</option>
                                                </select>
                                                <button type="submit" class="btn btn-primary btn-sm" style="padding: 8px 12px;">Save</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div><!-- /tab-delivery-queue -->

        <!-- Tab: Service Areas -->
        <div id="tab-service-areas" class="tab-content">
            <div class="dashboard-sidebar" style="max-width: 480px;">
                <h3>Manage Service Areas</h3>
                <form action="transporter.php" method="POST" style="margin-bottom: 25px;">
                    <input type="hidden" name="add_area" value="1">
                    <div class="form-group">
                        <label for="covered_city">Covered City / Town</label>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <input type="text" name="covered_city" class="form-control" placeholder="e.g. Colombo, Kandy" required>
                            <button type="submit" class="btn btn-success" style="width: 100%;">Add Area</button>
                        </div>
                    </div>
                </form>

                <h4 style="margin-bottom: 15px; font-size: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">Your Coverage Areas</h4>
                <?php if (empty($my_areas)): ?>
                    <p style="color: var(--text-muted); font-style: italic; font-size: 13px;">No service cities added yet.</p>
                <?php else: ?>
                    <ul style="padding-left: 15px; font-size: 14px; list-style: square;">
                        <?php foreach ($my_areas as $area): ?>
                            <li style="margin-bottom: 12px; color: white;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span><?php echo htmlspecialchars($area['covered_city']); ?></span>
                                    <a href="transporter.php?delete_area=<?php echo $area['service_id']; ?>" style="color: var(--danger); font-size: 12px; text-decoration: none; font-weight: bold;">[Remove]</a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div><!-- /tab-service-areas -->

    </div>

        </main>
    </div><!-- /app-shell -->

    <script src="../theme.js"></script>
</body>
</html>