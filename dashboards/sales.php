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
    <link rel="stylesheet" href="../style.css">
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
        <span class="welcome-msg">Welcome back, <strong><?php echo htmlspecialchars($sales_name); ?></strong>.</span>
    </div>

    <div style="max-width: 1400px; width: 100%; margin: 0 auto; display: flex; flex-direction: column; gap: 30px;">

        <!-- Dashboard Sub-Navigation -->
        <div class="dashboard-tabs">
            <button type="button" class="tab-btn active" data-tab-target="tab-overview" onclick="showDashboardTab('tab-overview', this)">
                🔔 Overview
                <?php if (!empty($my_notifications)): ?><span class="tab-count"><?php echo count($my_notifications); ?></span><?php endif; ?>
            </button>
            <button type="button" class="tab-btn" data-tab-target="tab-marketplace" onclick="showDashboardTab('tab-marketplace', this)">🛒 Marketplace</button>
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