<?php
session_start();
require_once '../connection.php';

// --- SECURITY CHECK ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Sales') {
    header("Location: ../login.php");
    exit;
}

$sales_id = $_SESSION['user_id'];
$sales_name = $_SESSION['name'];

// --- BACKEND LOGIC: SUBMIT AN ORDER ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['place_order'])) {
    $listing_id = $_POST['listing_id'];
    $quantity_ordered = $_POST['quantity_ordered'];
    
    // SECURITY FIX: Fetch genuine price and quantity from DB
    $price_sql = "SELECT price_per_kg, quantity_kg FROM food_listings WHERE listing_id = ?";
    if ($stmt_price = mysqli_prepare($con, $price_sql)) {
        mysqli_stmt_bind_param($stmt_price, "i", $listing_id);
        mysqli_stmt_execute($stmt_price);
        $res_price = mysqli_stmt_get_result($stmt_price);
        
        if ($row_price = mysqli_fetch_assoc($res_price)) {
            $true_price_per_kg = $row_price['price_per_kg'];
            $available_stock = $row_price['quantity_kg'];
            
            // FIXED: Verify requested quantity against DB stock
            if ($quantity_ordered > $available_stock) {
                 echo "<script>alert('Error: Requested quantity exceeds available stock.'); window.location.href='sales.php';</script>";
            } else {
                $total_price = $quantity_ordered * $true_price_per_kg;

                // Secure prepared statement to insert into orders table
                $order_sql = "INSERT INTO orders (listing_id, sales_id, quantity_ordered, total_price, order_status) VALUES (?, ?, ?, ?, 'Pending Approval')";
                
                if ($stmt = mysqli_prepare($con, $order_sql)) {
                    mysqli_stmt_bind_param($stmt, "iidd", $listing_id, $sales_id, $quantity_ordered, $total_price);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        // --- NOTIFICATION HOOK ---
                        require_once '../includes/notification_helper.php';
                        
                        // Securely find who the farmer is for this specific crop listing
                        $farmer_lookup_sql = "SELECT farmer_id FROM food_listings WHERE listing_id = ?";
                        if ($stmt_farmer = mysqli_prepare($con, $farmer_lookup_sql)) {
                            mysqli_stmt_bind_param($stmt_farmer, "i", $listing_id);
                            mysqli_stmt_execute($stmt_farmer);
                            $farmer_res = mysqli_stmt_get_result($stmt_farmer);
                            if ($farmer_row = mysqli_fetch_assoc($farmer_res)) {
                                $f_id = $farmer_row['farmer_id'];
                                addNotification($con, $f_id, "New Order Pending! A Sales Person requested $quantity_ordered kg of your listing.");
                            }
                            mysqli_stmt_close($stmt_farmer);
                        }
                        echo "<script>alert('Order submitted successfully! Awaiting farmer approval.'); window.location.href='sales.php';</script>";
                    } else {
                        echo "<script>alert('Failed to place order. Try again.');</script>";
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
        mysqli_stmt_close($stmt_price);
    }
}

// --- BACKEND LOGIC: SUBMIT RATING ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_rating'])) {
    $order_id = $_POST['order_id'];
    $reviewee_id = $_POST['reviewee_id'];
    $score = $_POST['score'];
    $comment = trim($_POST['comment']);

    $rating_sql = "INSERT INTO ratings (order_id, reviewer_id, reviewee_id, score, comment) VALUES (?, ?, ?, ?, ?)";
    if ($stmt = mysqli_prepare($con, $rating_sql)) {
        mysqli_stmt_bind_param($stmt, "iiiis", $order_id, $sales_id, $reviewee_id, $score, $comment);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo "<script>alert('Thank you for your feedback!'); window.location.href='sales.php';</script>";
    }
}

// --- BACKEND LOGIC: SELECT TRANSPORTER (after farmer approval) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['select_transporter'])) {
    $order_id        = $_POST['order_id'];
    $transporter_id  = $_POST['transporter_id'];
    $contact_name    = trim($_POST['contact_name'] ?? '');
    $contact_email   = trim($_POST['contact_email'] ?? '');
    $contact_mobile  = trim($_POST['contact_mobile'] ?? '');
    $pickup_location = trim($_POST['pickup_location'] ?? '');

    if ($contact_name === '' || $contact_email === '' || $contact_mobile === '' || $pickup_location === '') {
        echo "<script>alert('Please fill in your full name, email, mobile number, and pickup location.'); window.location.href='sales.php';</script>";
        exit;
    }

    // Only allow this on the buyer's own order, and only while it's awaiting a transporter
    $verify_sql = "SELECT order_id FROM orders WHERE order_id = ? AND sales_id = ? AND order_status = 'Approved' AND transporter_id IS NULL";
    if ($stmt_v = mysqli_prepare($con, $verify_sql)) {
        mysqli_stmt_bind_param($stmt_v, "ii", $order_id, $sales_id);
        mysqli_stmt_execute($stmt_v);
        $res_v = mysqli_stmt_get_result($stmt_v);
        $is_valid = mysqli_fetch_assoc($res_v) ? true : false;
        mysqli_stmt_close($stmt_v);

        if ($is_valid) {
            $assign_sql = "UPDATE orders
                           SET transporter_id = ?, transporter_confirmed = 0,
                               contact_name = ?, contact_email = ?, contact_mobile = ?, pickup_location = ?
                           WHERE order_id = ?";
            if ($stmt_a = mysqli_prepare($con, $assign_sql)) {
                mysqli_stmt_bind_param($stmt_a, "issssi", $transporter_id, $contact_name, $contact_email, $contact_mobile, $pickup_location, $order_id);
                if (mysqli_stmt_execute($stmt_a)) {
                    require_once '../includes/notification_helper.php';
                    addNotification($con, $transporter_id, "New Delivery Job! Order #$order_id needs your review — download the details and Approve or Reject.");

                    $farmer_lookup_sql = "SELECT fl.farmer_id FROM orders o JOIN food_listings fl ON o.listing_id = fl.listing_id WHERE o.order_id = ?";
                    if ($stmt_f = mysqli_prepare($con, $farmer_lookup_sql)) {
                        mysqli_stmt_bind_param($stmt_f, "i", $order_id);
                        mysqli_stmt_execute($stmt_f);
                        $res_f = mysqli_stmt_get_result($stmt_f);
                        if ($row_f = mysqli_fetch_assoc($res_f)) {
                            addNotification($con, $row_f['farmer_id'], "Order #$order_id: the buyer has selected a transporter, awaiting their confirmation.");
                        }
                        mysqli_stmt_close($stmt_f);
                    }
                    echo "<script>alert('Transporter selected! Waiting for their confirmation.'); window.location.href='sales.php';</script>";
                } else {
                    echo "<script>alert('Failed to assign transporter. Please try again.'); window.location.href='sales.php';</script>";
                }
                mysqli_stmt_close($stmt_a);
            }
        } else {
            echo "<script>alert('This order is not currently awaiting a transporter.'); window.location.href='sales.php';</script>";
        }
    }
    exit;
}

// --- FETCH ALL AVAILABLE FOOD LISTINGS WITH FARMER DETAILS ---
$query = "SELECT fl.*, u.name AS farmer_name, u.location_city 
          FROM food_listings fl 
          JOIN users u ON fl.farmer_id = u.user_id 
          WHERE fl.status = 'Available' AND fl.quantity_kg > 0 
          ORDER BY fl.created_at DESC";

$result = mysqli_query($con, $query);
$listings = mysqli_fetch_all($result, MYSQLI_ASSOC);

// --- FETCH RECENT NOTIFICATIONS FOR THE LOGGED-IN SALES PERSON ---
$my_notifications = [];
$noti_query = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5";
if ($stmt_noti = mysqli_prepare($con, $noti_query)) {
    mysqli_stmt_bind_param($stmt_noti, "i", $sales_id);
    mysqli_stmt_execute($stmt_noti);
    $noti_res = mysqli_stmt_get_result($stmt_noti);
    while ($row = mysqli_fetch_assoc($noti_res)) {
        $my_notifications[] = $row;
    }
    mysqli_stmt_close($stmt_noti);
}

// --- FETCH COMPLETED DELIVERED ORDERS AWAITING ACCOUNTABILITY REVIEW (OPTIMIZED) ---
$completed_query = "SELECT o.*, fl.food_name, fl.farmer_id, u.name AS farmer_name 
                    FROM orders o 
                    JOIN food_listings fl ON o.listing_id = fl.listing_id 
                    JOIN users u ON fl.farmer_id = u.user_id
                    LEFT JOIN ratings r ON o.order_id = r.order_id AND r.reviewer_id = ?
                    WHERE o.sales_id = ? AND o.order_status = 'Delivered' AND r.rating_id IS NULL";
$unreviewed_orders = [];
if ($stmt_comp = mysqli_prepare($con, $completed_query)) {
    mysqli_stmt_bind_param($stmt_comp, "ii", $sales_id, $sales_id);
    mysqli_stmt_execute($stmt_comp);
    $comp_res = mysqli_stmt_get_result($stmt_comp);
    while ($row = mysqli_fetch_assoc($comp_res)) {
        $unreviewed_orders[] = $row;
    }
    mysqli_stmt_close($stmt_comp);
}

// --- FETCH ALL OF THIS BUYER'S ORDERS (for the My Orders tab) ---
$my_orders_query = "SELECT o.*, fl.food_name, fl.farmer_id,
                            u_farmer.name AS farmer_name, u_farmer.location_city AS farmer_city,
                            u_trans.name AS transporter_name
                     FROM orders o
                     JOIN food_listings fl ON o.listing_id = fl.listing_id
                     JOIN users u_farmer ON fl.farmer_id = u_farmer.user_id
                     LEFT JOIN users u_trans ON o.transporter_id = u_trans.user_id
                     WHERE o.sales_id = ?
                     ORDER BY o.created_at DESC";
$my_orders = [];
if ($stmt_mo = mysqli_prepare($con, $my_orders_query)) {
    mysqli_stmt_bind_param($stmt_mo, "i", $sales_id);
    mysqli_stmt_execute($stmt_mo);
    $mo_res = mysqli_stmt_get_result($stmt_mo);
    while ($row = mysqli_fetch_assoc($mo_res)) { $my_orders[] = $row; }
    mysqli_stmt_close($stmt_mo);
}
$my_orders_by_id = [];
foreach ($my_orders as $mo_row) { $my_orders_by_id[$mo_row['order_id']] = $mo_row; }

// For every order approved by the farmer but still waiting on a transporter,
// look up which transporters cover that specific farmer's city.
$transporter_options_by_order = [];
$awaiting_transporter_count = 0;
foreach ($my_orders as $ord) {
    if ($ord['order_status'] === 'Approved' && empty($ord['transporter_id'])) {
        $awaiting_transporter_count++;
        $tsql = "SELECT t_area.transporter_id, u.name AS transporter_name,
                        ROUND(AVG(r.score), 1) AS avg_score, COUNT(r.rating_id) AS review_count
                 FROM transporter_service_areas t_area
                 JOIN users u ON t_area.transporter_id = u.user_id
                 LEFT JOIN ratings r ON r.reviewee_id = u.user_id
                 WHERE LOWER(t_area.covered_city) = LOWER(?)
                 GROUP BY t_area.transporter_id, u.name
                 ORDER BY avg_score DESC";
        if ($tstmt = mysqli_prepare($con, $tsql)) {
            mysqli_stmt_bind_param($tstmt, "s", $ord['farmer_city']);
            mysqli_stmt_execute($tstmt);
            $transporter_options_by_order[$ord['order_id']] = mysqli_fetch_all(mysqli_stmt_get_result($tstmt), MYSQLI_ASSOC);
            mysqli_stmt_close($tstmt);
        }
    }
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
    <title>Sales Dashboard - Fresh Ceylon</title>
    <link rel="stylesheet" href="../style.css?v=2">
</head>
<body class="has-sidenav">

    <div class="app-shell">
        <aside class="side-nav" id="sideNav">
            <div class="side-nav-brand">
                <img src="../images/logofinal.png" alt="Fresh Ceylon Logo" class="side-nav-logo">
                <div class="side-nav-brand-text">
                    <span class="brand-name-side">Fresh Ceylon</span>
                    <span class="brand-role">Sales Buyer</span>
                </div>
            </div>
            <nav class="side-nav-links">
                <a href="sales.php" class="side-nav-link active"><span class="side-nav-icon">🛒</span>Dashboard</a>
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
            <span class="mobile-topbar-brand">Fresh Ceylon — Sales</span>
        </div>

        <main class="app-main">

    <div class="dashboard-header">
        <span class="welcome-msg">Welcome back, <strong><?php echo htmlspecialchars($sales_name); ?></strong> (Sales Buyer)</span>
    </div>

    <div class="dash-container">

        <!-- Dashboard Sub-Navigation -->
        <div class="dashboard-tabs">
            <button type="button" class="tab-btn active" data-tab-target="tab-overview" onclick="showDashboardTab('tab-overview', this)">
                🔔 Overview
                <?php if (!empty($my_notifications)): ?><span class="tab-count"><?php echo count($my_notifications); ?></span><?php endif; ?>
            </button>
            <button type="button" class="tab-btn" data-tab-target="tab-marketplace" onclick="showDashboardTab('tab-marketplace', this)">🛒 Marketplace</button>
            <button type="button" class="tab-btn" data-tab-target="tab-my-orders" onclick="showDashboardTab('tab-my-orders', this)">
                📦 My Orders
                <?php if ($awaiting_transporter_count > 0): ?><span class="tab-count"><?php echo $awaiting_transporter_count; ?></span><?php endif; ?>
            </button>
            <button type="button" class="tab-btn" data-tab-target="tab-feedback" onclick="showDashboardTab('tab-feedback', this)">
                ⭐ Feedback &amp; Reviews
                <?php if (!empty($unreviewed_orders)): ?><span class="tab-count"><?php echo count($unreviewed_orders); ?></span><?php endif; ?>
            </button>
        </div>

        <!-- Tab: Overview -->
        <div id="tab-overview" class="tab-content active">
        <div class="alert-box">
            <h4>🔔 Recent Alerts &amp; Updates</h4>
            <?php if (empty($my_notifications)): ?>
                <p style="font-style: italic; color: #666; font-size: 14px;">No new alerts at the moment.</p>
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

        <!-- Tab: Marketplace -->
        <div id="tab-marketplace" class="tab-content">

        <div class="filter-bar">
            <strong>Filters:</strong>
            <div style="flex: 1; min-width: 200px;">
                <input type="text" id="search_name" class="form-control" onkeyup="filterCatalog()" placeholder="Search crop name...">
            </div>
            <div>
                <select id="filter_type" class="form-control" onchange="filterCatalog()">
                    <option value="">All Categories</option>
                    <option value="Vegetables">Vegetables</option>
                    <option value="Fruits">Fruits</option>
                    <option value="Grains">Grains</option>
                </select>
            </div>
            <div style="flex: 1; min-width: 200px;">
                <input type="text" id="search_location" class="form-control" onkeyup="filterCatalog()" placeholder="Search city...">
            </div>
        </div>

        <h3 style="font-size: 22px; color: lightgreen; margin-top: 10px;">🌿 Available Marketplace Produce</h3>
        
        <div id="catalog_container" class="catalog-grid">
            <?php if (empty($listings)): ?>
                <p style="color: #666; font-style: italic;">No fresh food listings are currently available in the marketplace.</p>
            <?php else: ?>
                <?php foreach ($listings as $item): ?>
                    <div class="product-card" 
                         data-name="<?php echo strtolower($item['food_name']); ?>"
                         data-type="<?php echo $item['food_type']; ?>"
                         data-location="<?php echo strtolower($item['location_city']); ?>">

                        <!-- Crop Image -->
                        <div class="product-img-wrap">
                            <?php if (!empty($item['image_path'])): ?>
                                <img src="../<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['food_name']); ?>">
                            <?php else: ?>
                                <div class="product-img-placeholder">
                                    <?php
                                    $icon = '🌾';
                                    if ($item['food_type'] === 'Fruits')    $icon = '🍎';
                                    if ($item['food_type'] === 'Vegetables') $icon = '🥦';
                                    echo $icon;
                                    ?>
                                    <span><?php echo htmlspecialchars($item['food_type']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Card Body -->
                        <div class="product-card-body">
                            <h4><?php echo htmlspecialchars($item['food_name']); ?></h4>
                            <span class="badge badge-available" style="align-self:flex-start; margin-bottom:10px;"><?php echo htmlspecialchars($item['food_type']); ?></span>

                            <p><strong>Farmer:</strong> <span><?php echo htmlspecialchars($item['farmer_name']); ?></span></p>
                            <p><strong>📍 Location:</strong> <span><?php echo htmlspecialchars($item['location_city']); ?></span></p>
                            <p><strong>📦 Stock:</strong> <span><?php echo htmlspecialchars($item['quantity_kg']); ?> kg available</span></p>

                            <div class="product-price">LKR <?php echo number_format($item['price_per_kg'], 2); ?> / kg</div>
                        </div>

                        <!-- Card Footer: Order Form -->
                        <div class="product-card-footer">
                            <form action="sales.php" method="POST">
                                <input type="hidden" name="place_order" value="1">
                                <input type="hidden" name="listing_id" value="<?php echo $item['listing_id']; ?>">
                                
                                <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                                    <label style="margin:0; font-size:13px; color:#aaa; white-space:nowrap;">Qty (kg):</label>
                                    <input type="number" name="quantity_ordered" class="form-control" min="0.1" max="<?php echo $item['quantity_kg']; ?>" step="0.1" required style="flex:1; padding:7px 10px; font-size:13px;">
                                </div>
                                <button type="submit" class="btn btn-success" style="width:100%; padding:9px;">🛒 Book Order</button>
                            </form>
                        </div>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        </div><!-- /tab-marketplace -->

        <!-- Tab: My Orders -->
        <div id="tab-my-orders" class="tab-content">
        <h3 style="color:lightgreen; margin-bottom:12px;">📦 My Orders</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Crop</th>
                        <th>Farmer</th>
                        <th>Qty</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Transporter</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($my_orders)): ?>
                        <tr><td colspan="8" style="text-align:center; color:#666; font-style:italic; padding:20px;">You haven't placed any orders yet. Browse the Marketplace to get started!</td></tr>
                    <?php else: ?>
                        <?php foreach ($my_orders as $mord): ?>
                            <tr>
                                <td style="font-weight:bold;">#<?php echo $mord['order_id']; ?></td>
                                <td style="color:lightgreen; font-weight:bold;"><?php echo htmlspecialchars($mord['food_name']); ?></td>
                                <td><?php echo htmlspecialchars($mord['farmer_name']); ?></td>
                                <td><?php echo htmlspecialchars($mord['quantity_ordered']); ?> kg</td>
                                <td style="color:white; font-weight:bold;">LKR <?php echo number_format($mord['total_price'], 2); ?></td>
                                <td>
                                    <?php
                                    $mbc = 'badge-pending';
                                    if ($mord['order_status'] == 'Delivered') $mbc = 'badge-available';
                                    if ($mord['order_status'] == 'Cancelled') $mbc = 'badge-sold';
                                    ?>
                                    <span class="badge <?php echo $mbc; ?>"><?php echo htmlspecialchars($mord['order_status']); ?></span>
                                </td>
                                <td>
                                    <?php if ($mord['transporter_name']): ?>
                                        <?php echo htmlspecialchars($mord['transporter_name']); ?>
                                        <?php if (empty($mord['transporter_confirmed'])): ?>
                                            <span style="display:block; color:var(--warning); font-size:11px; font-style:italic;">Awaiting confirmation</span>
                                        <?php else: ?>
                                            <span style="display:block; color:var(--accent); font-size:11px; font-style:italic;">Confirmed</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted); font-style:italic; font-size:12px;">Not yet assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <?php if ($mord['order_status'] === 'Approved' && empty($mord['transporter_id'])): ?>
                                        <button type="button" class="btn btn-success btn-sm" data-modal-open="transporterModal<?php echo $mord['order_id']; ?>">🚚 Select Transporter</button>
                                    <?php elseif (!empty($mord['transporter_id']) && empty($mord['transporter_confirmed'])): ?>
                                        <span style="color:var(--warning); font-size:12px; font-style:italic;">Waiting on transporter</span>
                                    <?php elseif (!empty($mord['transporter_id']) && !empty($mord['transporter_confirmed'])): ?>
                                        <a href="transporter_details_pdf.php?order_id=<?php echo $mord['order_id']; ?>" target="_blank" class="btn btn-primary btn-sm" style="text-decoration:none; display:inline-block;">📄 Download Transporter Details</a>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted); font-size:12px; font-style:italic;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        </div><!-- /tab-my-orders -->

        <!-- Transporter selection modals: one per order awaiting a transporter -->
        <?php foreach ($transporter_options_by_order as $ord_id => $t_options): ?>
        <div class="modal-overlay" id="transporterModal<?php echo $ord_id; ?>">
            <div class="modal-box">
                <div class="modal-header">
                    <h3>🚚 Select Transporter — Order #<?php echo $ord_id; ?></h3>
                    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <?php if (empty($t_options)): ?>
                        <p style="color:var(--danger); font-size:14px;">⚠️ No transporters currently cover the farmer's area for this order. Please check back later.</p>
                    <?php else: ?>
                        <?php $mo = $my_orders_by_id[$ord_id] ?? []; ?>
                        <form action="sales.php" method="POST">
                            <input type="hidden" name="select_transporter" value="1">
                            <input type="hidden" name="order_id" value="<?php echo $ord_id; ?>">

                            <p style="color:var(--text-muted); font-size:13px; margin-top:0;">Please provide your contact and pickup details so the transporter can reach you.</p>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Full Name:</label>
                                    <input type="text" name="contact_name" class="form-control" placeholder="Your full name" value="<?php echo htmlspecialchars($mo['contact_name'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Email Address:</label>
                                    <input type="email" name="contact_email" class="form-control" placeholder="you@example.com" value="<?php echo htmlspecialchars($mo['contact_email'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Mobile Number:</label>
                                    <input type="text" name="contact_mobile" class="form-control" placeholder="07X XXXXXXX" value="<?php echo htmlspecialchars($mo['contact_mobile'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Pickup Location:</label>
                                    <input type="text" name="pickup_location" class="form-control" placeholder="Address for pickup" value="<?php echo htmlspecialchars($mo['pickup_location'] ?? ''); ?>" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Available transporters:</label>
                                <select name="transporter_id" class="form-control" required>
                                    <option value="">-- Select Transporter --</option>
                                    <?php foreach ($t_options as $t): ?>
                                        <option value="<?php echo $t['transporter_id']; ?>">
                                            <?php echo htmlspecialchars($t['transporter_name']); ?>
                                            <?php if ($t['review_count'] > 0): ?>
                                                — ⭐ <?php echo $t['avg_score']; ?>/5 (<?php echo $t['review_count']; ?> reviews)
                                            <?php else: ?>
                                                — No reviews yet
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-success">Confirm Transporter</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div><!-- /transporterModal<?php echo $ord_id; ?> -->
        <?php endforeach; ?>

        <!-- Tab: Feedback & Reviews -->
        <div id="tab-feedback" class="tab-content">
        <div class="feedback-box">
            <h3>⭐ Leave Feedback on Completed Deliveries</h3>
            <div class="feedback-scroll-area">
                <?php if (empty($unreviewed_orders)): ?>
                    <p style="font-style: italic; color: #666; font-size: 14px;">No deliveries awaiting feedback reviews right now.</p>
                <?php else: ?>
                    <?php foreach ($unreviewed_orders as $order): ?>
                        <form action="sales.php" method="POST" class="feedback-form">
                            <input type="hidden" name="submit_rating" value="1">
                            <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                            <input type="hidden" name="reviewee_id" value="<?php echo $order['farmer_id']; ?>">
                            
                            <p style="margin: 0 0 15px 0; color: #ddd; font-size: 14px;">
                                Rate your experience for <strong>Order #<?php echo $order['order_id']; ?></strong> 
                                (<?php echo htmlspecialchars($order['food_name']); ?> from Farmer <strong><?php echo htmlspecialchars($order['farmer_name']); ?></strong>):
                            </p>
                            
                            <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                                <select name="score" class="form-control" style="width: 180px;" required>
                                    <option value="5">⭐⭐⭐⭐⭐ Excellent</option>
                                    <option value="4">⭐⭐⭐⭐ Good</option>
                                    <option value="3">⭐⭐⭐ Average</option>
                                    <option value="2">⭐⭐ Poor</option>
                                    <option value="1">⭐ Terrible</option>
                                </select>
                                <input type="text" name="comment" class="form-control" placeholder="Write a brief comment..." required style="flex: 1; min-width: 250px;">
                                <button type="submit" class="btn btn-success" style="width: auto; white-space: nowrap; padding: 12px 25px;">Submit Review</button>
                            </div>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        </div><!-- /tab-feedback -->

    </div>

    <script>
    function filterCatalog() {
        let searchName = document.getElementById('search_name').value.toLowerCase();
        let filterType = document.getElementById('filter_type').value;
        let searchLocation = document.getElementById('search_location').value.toLowerCase();
        
        let cards = document.getElementsByClassName('product-card');

        for (let i = 0; i < cards.length; i++) {
            let card = cards[i];
            let name = card.getAttribute('data-name');
            let type = card.getAttribute('data-type');
            let location = card.getAttribute('data-location');

            let matchesName = name.includes(searchName);
            let matchesType = filterType === "" || type === filterType;
            let matchesLocation = location.includes(searchLocation);

            if (matchesName && matchesType && matchesLocation) {
                card.style.display = "flex";
            } else {
                card.style.display = "none";
            }
        }
    }
    </script>

        </main>
    </div><!-- /app-shell -->

    <script src="../theme.js"></script>
</body>
</html>