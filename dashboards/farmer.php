<?php
session_start();
require_once '../connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Farmer') {
    header("Location: ../login.php");
    exit;
}

$farmer_id   = $_SESSION['user_id'];
$farmer_name = $_SESSION['name'];
$farmer_city = $_SESSION['location_city'];

// --- Helper: Handle image upload ---
function handleImageUpload($file_input) {
    if (!isset($file_input) || $file_input['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => true, 'path' => null, 'error' => null]; // No file chosen, that's fine
    }

    if ($file_input['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => 'The file is larger than this server allows (upload_max_filesize in php.ini).',
            UPLOAD_ERR_FORM_SIZE  => 'The file is larger than the form allows.',
            UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server has no temporary folder configured for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write the file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A server extension blocked the upload.',
        ];
        $msg = $upload_errors[$file_input['error']] ?? ('Unknown upload error (code ' . $file_input['error'] . ').');
        return ['success' => false, 'path' => null, 'error' => $msg];
    }

    // Check the file's real content type on disk instead of trusting the
    // browser-supplied MIME type, which can be wrong or missing.
    $allowed_mimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    $real_mime = @mime_content_type($file_input['tmp_name']);
    if ($real_mime === false || !isset($allowed_mimes[$real_mime])) {
        return ['success' => false, 'path' => null, 'error' => "Unsupported file type detected ($real_mime). Use JPG, PNG, WebP, or GIF."];
    }

    if ($file_input['size'] > 3 * 1024 * 1024) {
        return ['success' => false, 'path' => null, 'error' => 'Image is larger than 3MB.'];
    }

    $dest_dir = __DIR__ . '/../images/';
    if (!is_dir($dest_dir)) {
        return ['success' => false, 'path' => null, 'error' => "Upload folder is missing on the server: $dest_dir"];
    }
    if (!is_writable($dest_dir)) {
        return ['success' => false, 'path' => null, 'error' => "Upload folder is not writable by the server: $dest_dir"];
    }

    $ext      = $allowed_mimes[$real_mime];
    $filename = 'crop_' . uniqid() . '.' . $ext;
    $dest     = $dest_dir . $filename;

    if (move_uploaded_file($file_input['tmp_name'], $dest)) {
        return ['success' => true, 'path' => 'images/' . $filename, 'error' => null];
    }

    return ['success' => false, 'path' => null, 'error' => 'The server could not save the uploaded file (move_uploaded_file failed).'];
}

// --- BACKEND: SUBMIT INQUIRY TO ADMIN ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_inquiry'])) {
    $subject     = trim($_POST['subject']);
    $message_txt = trim($_POST['message']);

    if ($subject === '' || $message_txt === '') {
        echo "<script>alert('Please fill in both subject and message.'); window.location.href='farmer.php';</script>";
        exit;
    }

    $inq_sql = "INSERT INTO inquiries (user_id, subject, message) VALUES (?, ?, ?)";
    if ($stmt = mysqli_prepare($con, $inq_sql)) {
        mysqli_stmt_bind_param($stmt, "iss", $farmer_id, $subject, $message_txt);
        if (mysqli_stmt_execute($stmt)) {
            require_once '../includes/notification_helper.php';
            $admins_res = mysqli_query($con, "SELECT user_id FROM users WHERE role = 'Admin'");
            while ($admin_row = mysqli_fetch_assoc($admins_res)) {
                addNotification($con, $admin_row['user_id'], "New inquiry from " . $farmer_name . " (Farmer): \"$subject\"");
            }
            echo "<script>alert('Your message has been sent to the admin team.'); window.location.href='farmer.php';</script>";
        } else {
            echo "<script>alert('Failed to send your inquiry. Please try again.'); window.location.href='farmer.php';</script>";
        }
        mysqli_stmt_close($stmt);
    }
    exit;
}

// --- BACKEND: INSERT NEW LISTING ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_listing'])) {
    $food_name   = trim($_POST['food_name']);
    $food_type   = $_POST['food_type'];
    $quantity_kg = $_POST['quantity_kg'];
    $price_per_kg = $_POST['price_per_kg'];

    $image_path = null;
    if (isset($_FILES['food_image'])) {
        $upload_result = handleImageUpload($_FILES['food_image']);
        if (!$upload_result['success']) {
            $safe_msg = addslashes($upload_result['error']);
            echo "<script>alert('Image upload failed: $safe_msg'); window.location.href='farmer.php';</script>";
            exit;
        }
        $image_path = $upload_result['path'];
    }

    $insert_sql = "INSERT INTO food_listings (farmer_id, food_name, food_type, quantity_kg, price_per_kg, status, image_path) VALUES (?, ?, ?, ?, ?, 'Available', ?)";
    if ($stmt = mysqli_prepare($con, $insert_sql)) {
        mysqli_stmt_bind_param($stmt, "issdds", $farmer_id, $food_name, $food_type, $quantity_kg, $price_per_kg, $image_path);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo "<script>alert('Food item listed successfully!'); window.location.href='farmer.php';</script>";
    } else {
        echo "<script>alert('Database error: " . addslashes(mysqli_error($con)) . "'); window.location.href='farmer.php';</script>";
    }
    exit;
}

// --- BACKEND: DELETE LISTING ---
if (isset($_GET['delete_listing'])) {
    $del_listing_id = intval($_GET['delete_listing']);
    $del_sql = "DELETE FROM food_listings WHERE listing_id = ? AND farmer_id = ?";
    if ($stmt = mysqli_prepare($con, $del_sql)) {
        mysqli_stmt_bind_param($stmt, "ii", $del_listing_id, $farmer_id);
        try {
            mysqli_stmt_execute($stmt);
            if (mysqli_stmt_affected_rows($stmt) > 0) {
                echo "<script>alert('Listing removed successfully.'); window.location.href='farmer.php';</script>";
            } else {
                echo "<script>alert('Error: Could not find the listing.'); window.location.href='farmer.php';</script>";
            }
        } catch (mysqli_sql_exception $e) {
            echo "<script>alert('Cannot delete: this listing has active orders attached.'); window.location.href='farmer.php';</script>";
        }
        mysqli_stmt_close($stmt);
        exit;
    }
}

// --- BACKEND: APPROVE ORDER (buyer selects transporter afterwards) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['approve_order'])) {
    $order_id        = $_POST['order_id'];
    $listing_id      = $_POST['listing_id'];
    $quantity_ordered = $_POST['quantity_ordered'];

    $deduct_sql = "UPDATE food_listings SET quantity_kg = quantity_kg - ? WHERE listing_id = ? AND quantity_kg >= ?";
    if ($stmt2 = mysqli_prepare($con, $deduct_sql)) {
        mysqli_stmt_bind_param($stmt2, "did", $quantity_ordered, $listing_id, $quantity_ordered);
        mysqli_stmt_execute($stmt2);
        if (mysqli_stmt_affected_rows($stmt2) > 0) {
            mysqli_stmt_close($stmt2);
            $approve_sql = "UPDATE orders SET order_status = 'Approved' WHERE order_id = ?";
            if ($stmt1 = mysqli_prepare($con, $approve_sql)) {
                mysqli_stmt_bind_param($stmt1, "i", $order_id);
                mysqli_stmt_execute($stmt1);
                mysqli_stmt_close($stmt1);
                require_once '../includes/notification_helper.php';
                $sales_lookup_sql = "SELECT sales_id FROM orders WHERE order_id = ?";
                if ($stmt_lookup = mysqli_prepare($con, $sales_lookup_sql)) {
                    mysqli_stmt_bind_param($stmt_lookup, "i", $order_id);
                    mysqli_stmt_execute($stmt_lookup);
                    $res_lookup = mysqli_stmt_get_result($stmt_lookup);
                    if ($row_lookup = mysqli_fetch_assoc($res_lookup)) {
                        addNotification($con, $row_lookup['sales_id'], "Your order (#$order_id) has been approved by the farmer! Please select a transporter to schedule pickup.");
                    }
                    mysqli_stmt_close($stmt_lookup);
                }
            }
            $status_check_sql = "UPDATE food_listings SET status = 'Sold Out' WHERE listing_id = ? AND quantity_kg <= 0";
            if ($stmt3 = mysqli_prepare($con, $status_check_sql)) {
                mysqli_stmt_bind_param($stmt3, "i", $listing_id);
                mysqli_stmt_execute($stmt3);
                mysqli_stmt_close($stmt3);
            }
            echo "<script>alert('Order approved! The buyer can now choose a transporter.'); window.location.href='farmer.php';</script>";
        } else {
            mysqli_stmt_close($stmt2);
            echo "<script>alert('Error: Not enough stock!'); window.location.href='farmer.php';</script>";
        }
    }
    exit;
}

// --- BACKEND: REJECT ORDER ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reject_order'])) {
    $order_id = $_POST['order_id'];

    $reject_sql = "UPDATE orders SET order_status = 'Cancelled' WHERE order_id = ? AND order_status = 'Pending Approval'";
    if ($stmt = mysqli_prepare($con, $reject_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $order_id);
        mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($affected > 0) {
            require_once '../includes/notification_helper.php';
            $sales_lookup_sql = "SELECT sales_id FROM orders WHERE order_id = ?";
            if ($stmt_lookup = mysqli_prepare($con, $sales_lookup_sql)) {
                mysqli_stmt_bind_param($stmt_lookup, "i", $order_id);
                mysqli_stmt_execute($stmt_lookup);
                $res_lookup = mysqli_stmt_get_result($stmt_lookup);
                if ($row_lookup = mysqli_fetch_assoc($res_lookup)) {
                    addNotification($con, $row_lookup['sales_id'], "Your order (#$order_id) was declined by the farmer.");
                }
                mysqli_stmt_close($stmt_lookup);
            }
            echo "<script>alert('Order rejected.'); window.location.href='farmer.php';</script>";
        } else {
            echo "<script>alert('Could not reject this order (it may already be processed).'); window.location.href='farmer.php';</script>";
        }
    }
    exit;
}

// --- FETCH FARMER'S LISTINGS ---
$query = "SELECT * FROM food_listings WHERE farmer_id = ? ORDER BY created_at DESC";
$listings_stmt = mysqli_prepare($con, $query);
mysqli_stmt_bind_param($listings_stmt, "i", $farmer_id);
mysqli_stmt_execute($listings_stmt);
$listings = mysqli_fetch_all(mysqli_stmt_get_result($listings_stmt), MYSQLI_ASSOC);
mysqli_stmt_close($listings_stmt);

// --- FETCH INCOMING ORDERS ---
$order_query = "SELECT o.*, fl.food_name, u.name AS buyer_name
                FROM orders o
                JOIN food_listings fl ON o.listing_id = fl.listing_id
                JOIN users u ON o.sales_id = u.user_id
                WHERE fl.farmer_id = ? AND o.order_status = 'Pending Approval'";
$orders_stmt = mysqli_prepare($con, $order_query);
mysqli_stmt_bind_param($orders_stmt, "i", $farmer_id);
mysqli_stmt_execute($orders_stmt);
$incoming_orders = mysqli_fetch_all(mysqli_stmt_get_result($orders_stmt), MYSQLI_ASSOC);
mysqli_stmt_close($orders_stmt);

// --- FETCH ALL ORDERS FOR THIS FARMER'S LISTINGS (full lifecycle tracking) ---
$tracking_query = "SELECT o.*, fl.food_name, u_buyer.name AS buyer_name, u_trans.name AS transporter_name
                    FROM orders o
                    JOIN food_listings fl ON o.listing_id = fl.listing_id
                    JOIN users u_buyer ON o.sales_id = u_buyer.user_id
                    LEFT JOIN users u_trans ON o.transporter_id = u_trans.user_id
                    WHERE fl.farmer_id = ?
                    ORDER BY o.created_at DESC";
$farmer_orders = [];
if ($stmt_track = mysqli_prepare($con, $tracking_query)) {
    mysqli_stmt_bind_param($stmt_track, "i", $farmer_id);
    mysqli_stmt_execute($stmt_track);
    $track_res = mysqli_stmt_get_result($stmt_track);
    while ($row = mysqli_fetch_assoc($track_res)) { $farmer_orders[] = $row; }
    mysqli_stmt_close($stmt_track);
}
$active_order_count = 0;
foreach ($farmer_orders as $fo) {
    if (!in_array($fo['order_status'], ['Delivered', 'Cancelled'])) $active_order_count++;
}

// --- NOTIFICATIONS ---
$my_notifications = [];
$noti_query = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5";
if ($stmt_noti = mysqli_prepare($con, $noti_query)) {
    mysqli_stmt_bind_param($stmt_noti, "i", $farmer_id);
    mysqli_stmt_execute($stmt_noti);
    $noti_res = mysqli_stmt_get_result($stmt_noti);
    while ($row = mysqli_fetch_assoc($noti_res)) { $my_notifications[] = $row; }
    mysqli_stmt_close($stmt_noti);
}

// --- AVERAGE RATING ---
$avg_score = 0; $review_count = 0;
$rating_query = "SELECT AVG(score) as avg_score, COUNT(*) as review_count FROM ratings WHERE reviewee_id = ?";
if ($stmt_rating = mysqli_prepare($con, $rating_query)) {
    mysqli_stmt_bind_param($stmt_rating, "i", $farmer_id);
    mysqli_stmt_execute($stmt_rating);
    $rating_res = mysqli_stmt_get_result($stmt_rating);
    if ($rating_row = mysqli_fetch_assoc($rating_res)) {
        $avg_score    = round($rating_row['avg_score'], 1);
        $review_count = $rating_row['review_count'];
    }
    mysqli_stmt_close($stmt_rating);
}
// --- FETCH MY INQUIRIES TO ADMIN ---
$my_inquiries = [];
$inq_query = "SELECT * FROM inquiries WHERE user_id = ? ORDER BY created_at DESC";
if ($stmt_inq = mysqli_prepare($con, $inq_query)) {
    mysqli_stmt_bind_param($stmt_inq, "i", $farmer_id);
    mysqli_stmt_execute($stmt_inq);
    $inq_res = mysqli_stmt_get_result($stmt_inq);
    while ($row = mysqli_fetch_assoc($inq_res)) { $my_inquiries[] = $row; }
    mysqli_stmt_close($stmt_inq);
}
$open_inquiry_count = 0;
foreach ($my_inquiries as $iq) { if ($iq['status'] === 'Open') $open_inquiry_count++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
    (function(){try{var t=localStorage.getItem('fc-theme');document.documentElement.setAttribute('data-theme', t === 'light' ? 'light' : 'dark');}catch(e){}})();
    </script>
    <title>Farmer Dashboard - Fresh Ceylon</title>
    <link rel="stylesheet" href="../style.css?v=4">
</head>
<body class="has-sidenav">

    <div class="app-shell">
        <aside class="side-nav" id="sideNav">
            <div class="side-nav-brand">
                <img src="../images/logofinal.png" alt="Fresh Ceylon Logo" class="side-nav-logo">
                <div class="side-nav-brand-text">
                    <span class="brand-name-side">Fresh Ceylon</span>
                    <span class="brand-role">Farmer</span>
                </div>
            </div>
            <nav class="side-nav-links">
                <a href="farmer.php" class="side-nav-link active"><span class="side-nav-icon">🏡</span>Dashboard</a>
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
            <span class="mobile-topbar-brand">Fresh Ceylon — Farmer</span>
        </div>

        <main class="app-main">

    <div class="dashboard-header">
        <span class="welcome-msg">
            Welcome, <strong><?php echo htmlspecialchars($farmer_name); ?></strong>
            <small style="font-size:14px; color:#888;"> — 📍 <?php echo htmlspecialchars($farmer_city); ?></small>
            <span style="color:gold; margin-left:15px; font-size:15px;">
                ⭐ Rating: <?php echo $review_count > 0 ? $avg_score . "/5 (" . $review_count . " reviews)" : "No reviews yet"; ?>
            </span>
        </span>
    </div>

    <div class="dash-content-pad">

        <!-- Dashboard Sub-Navigation -->
        <div class="dashboard-tabs">
            <button type="button" class="tab-btn active" data-tab-target="tab-overview" onclick="showDashboardTab('tab-overview', this)">🔔 Overview</button>
            <button type="button" class="tab-btn" data-tab-target="tab-incoming-orders" onclick="showDashboardTab('tab-incoming-orders', this)">
                📥 Incoming Orders
                <?php if (!empty($incoming_orders)): ?><span class="tab-count"><?php echo count($incoming_orders); ?></span><?php endif; ?>
            </button>
            <button type="button" class="tab-btn" data-tab-target="tab-order-tracking" onclick="showDashboardTab('tab-order-tracking', this)">
                🚚 Order Tracking
                <?php if ($active_order_count > 0): ?><span class="tab-count"><?php echo $active_order_count; ?></span><?php endif; ?>
            </button>
            <button type="button" class="tab-btn" data-tab-target="tab-my-listings" onclick="showDashboardTab('tab-my-listings', this)">📦 My Listings</button>
            <button type="button" class="tab-btn" data-tab-target="tab-contact-admin" onclick="showDashboardTab('tab-contact-admin', this)">
                📨 Contact Admin
                <?php if ($open_inquiry_count > 0): ?><span class="tab-count"><?php echo $open_inquiry_count; ?></span><?php endif; ?>
            </button>
        </div>

        <!-- Tab: Overview -->
        <div id="tab-overview" class="tab-content active">
        <div class="alert-box">
            <h4>🔔 Recent Alerts &amp; Updates</h4>
            <?php if (empty($my_notifications)): ?>
                <p style="font-style:italic; color:#666;">No new alerts at the moment.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($my_notifications as $notif): ?>
                        <li><?php echo htmlspecialchars($notif['message']); ?>
                            <span class="alert-date">Received: <?php echo date('Y-m-d h:i A', strtotime($notif['created_at'])); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        </div><!-- /tab-overview -->

        <!-- Tab: Incoming Orders -->
        <div id="tab-incoming-orders" class="tab-content">
        <h3 style="color:lightgreen; margin-bottom:12px;">📥 Incoming Purchase Requests</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Buyer</th>
                        <th>Crop Ordered</th>
                        <th>Qty Requested</th>
                        <th>Total Payout</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($incoming_orders)): ?>
                        <tr><td colspan="5" style="text-align:center; color:#666; font-style:italic; padding:20px;">No pending purchase orders right now.</td></tr>
                    <?php else: ?>
                        <?php foreach ($incoming_orders as $order): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($order['buyer_name']); ?></td>
                                <td style="color:lightgreen; font-weight:bold;"><?php echo htmlspecialchars($order['food_name']); ?></td>
                                <td><?php echo htmlspecialchars($order['quantity_ordered']); ?> kg</td>
                                <td style="color:white; font-weight:bold;">LKR <?php echo number_format($order['total_price'], 2); ?></td>
                                <td style="text-align:right;">
                                    <form action="farmer.php" method="POST" style="display:flex; gap:8px; justify-content:flex-end;">
                                        <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                        <input type="hidden" name="listing_id" value="<?php echo $order['listing_id']; ?>">
                                        <input type="hidden" name="quantity_ordered" value="<?php echo $order['quantity_ordered']; ?>">
                                        <button type="submit" name="approve_order" value="1" class="btn btn-success btn-sm">✅ Approve</button>
                                        <button type="submit" name="reject_order" value="1" class="btn btn-danger btn-sm"
                                            onclick="return confirm('Reject this order from <?php echo htmlspecialchars(addslashes($order['buyer_name'])); ?>?');">❌ Reject</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        </div><!-- /tab-incoming-orders -->

        <!-- Tab: Order Tracking -->
        <div id="tab-order-tracking" class="tab-content">
        <h3 style="color:lightgreen; margin-bottom:12px;">🚚 Order Tracking</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Buyer</th>
                        <th>Crop</th>
                        <th>Qty</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Transporter</th>
                        <th>Placed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($farmer_orders)): ?>
                        <tr><td colspan="8" style="text-align:center; color:#666; font-style:italic; padding:20px;">No orders yet for your listings.</td></tr>
                    <?php else: ?>
                        <?php foreach ($farmer_orders as $fo): ?>
                            <tr>
                                <td style="font-weight:bold;">#<?php echo $fo['order_id']; ?></td>
                                <td><?php echo htmlspecialchars($fo['buyer_name']); ?></td>
                                <td style="color:lightgreen; font-weight:bold;"><?php echo htmlspecialchars($fo['food_name']); ?></td>
                                <td><?php echo htmlspecialchars($fo['quantity_ordered']); ?> kg</td>
                                <td style="color:white; font-weight:bold;">LKR <?php echo number_format($fo['total_price'], 2); ?></td>
                                <td>
                                    <?php
                                    $fbc = 'badge-pending';
                                    if ($fo['order_status'] == 'Delivered') $fbc = 'badge-available';
                                    if ($fo['order_status'] == 'Cancelled') $fbc = 'badge-sold';
                                    ?>
                                    <span class="badge <?php echo $fbc; ?>"><?php echo htmlspecialchars($fo['order_status']); ?></span>
                                </td>
                                <td>
                                    <?php if ($fo['transporter_name']): ?>
                                        <?php echo htmlspecialchars($fo['transporter_name']); ?>
                                        <?php if (empty($fo['transporter_confirmed'])): ?>
                                            <span style="display:block; color:var(--warning); font-size:11px; font-style:italic;">Awaiting their confirmation</span>
                                        <?php else: ?>
                                            <span style="display:block; color:var(--accent); font-size:11px; font-style:italic;">Confirmed</span>
                                        <?php endif; ?>
                                    <?php elseif ($fo['order_status'] === 'Approved'): ?>
                                        <span style="color:var(--warning); font-style:italic; font-size:12px;">Awaiting buyer's pick</span>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted); font-style:italic; font-size:12px;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:13px; color:#888;"><?php echo date('Y-m-d', strtotime($fo['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        </div><!-- /tab-order-tracking -->

        <!-- Tab: My Listings -->
        <div id="tab-my-listings" class="tab-content">

            <div class="section-toolbar">
                <h3>📦 Your Crop Inventory</h3>
                <button type="button" class="btn btn-success" style="width:auto; padding:11px 24px;" data-modal-open="addProduceModal">+ Add New Produce</button>
            </div>

            <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:60px;">Photo</th>
                                <th>Crop</th>
                                <th>Type</th>
                                <th>Stock (kg)</th>
                                <th>Price/kg</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($listings)): ?>
                                <tr><td colspan="7" style="text-align:center; color:#666; font-style:italic; padding:20px;">You haven't listed any crops yet. Click "+ Add New Produce" to get started!</td></tr>
                            <?php else: ?>
                                <?php foreach ($listings as $item): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($item['image_path'])): ?>
                                                <img src="../<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['food_name']); ?>" style="width:55px; height:45px; object-fit:cover; border-radius:5px; border:1px solid #2a2a2a;">
                                            <?php else: ?>
                                                <div style="width:55px; height:45px; background:#0a200f; border-radius:5px; border:1px dashed #333; display:flex; align-items:center; justify-content:center; font-size:20px;">🌿</div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-weight:bold;"><?php echo htmlspecialchars($item['food_name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['food_type']); ?></td>
                                        <td><?php echo htmlspecialchars($item['quantity_kg']); ?> kg</td>
                                        <td style="color:lightgreen; font-weight:bold;">LKR <?php echo number_format($item['price_per_kg'], 2); ?></td>
                                        <td>
                                            <?php
                                            $bc = 'badge-available';
                                            if ($item['status'] == 'Pending')  $bc = 'badge-pending';
                                            if ($item['status'] == 'Sold Out') $bc = 'badge-sold';
                                            ?>
                                            <span class="badge <?php echo $bc; ?>"><?php echo htmlspecialchars($item['status']); ?></span>
                                        </td>
                                        <td>
                                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                                <a href="edit_listing.php?id=<?php echo $item['listing_id']; ?>"
                                                   class="btn btn-warning btn-sm"
                                                   style="text-decoration:none;">✏️ Edit</a>
                                                <a href="farmer.php?delete_listing=<?php echo $item['listing_id']; ?>"
                                                   class="btn btn-danger btn-sm"
                                                   onclick="return confirm('Delete this listing?');"
                                                   style="text-decoration:none;">🗑 Delete</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
            </div>

        </div><!-- /tab-my-listings -->

        <!-- Tab: Contact Admin -->
        <div id="tab-contact-admin" class="tab-content">
            <div class="feedback-box" style="margin-top:0;">
                <h3>📨 Send a New Inquiry</h3>
                <form action="farmer.php" method="POST" class="feedback-form">
                    <input type="hidden" name="submit_inquiry" value="1">
                    <div class="form-group">
                        <label>Subject:</label>
                        <input type="text" name="subject" class="form-control" placeholder="e.g. Issue with my listing" maxlength="150" required>
                    </div>
                    <div class="form-group">
                        <label>Message:</label>
                        <textarea name="message" class="form-control" rows="4" placeholder="Describe your issue or question for the admin team..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:auto; padding:11px 26px;">Send to Admin</button>
                </form>
            </div>

            <h3 style="color:lightgreen; margin:25px 0 14px;">📋 Your Inquiry History</h3>
            <?php if (empty($my_inquiries)): ?>
                <p style="color:var(--text-muted); font-style:italic;">You haven't contacted admin yet.</p>
            <?php else: ?>
                <div class="inquiry-list">
                    <?php foreach ($my_inquiries as $inq): ?>
                        <div class="inquiry-card">
                            <div class="inquiry-header">
                                <div>
                                    <strong class="inquiry-subject"><?php echo htmlspecialchars($inq['subject']); ?></strong>
                                    <div class="inquiry-meta">Sent: <?php echo date('Y-m-d h:i A', strtotime($inq['created_at'])); ?></div>
                                </div>
                                <span class="badge <?php echo $inq['status'] === 'Open' ? 'badge-pending' : 'badge-available'; ?>">
                                    <?php echo $inq['status'] === 'Open' ? 'Awaiting Reply' : 'Resolved'; ?>
                                </span>
                            </div>
                            <p class="inquiry-message"><?php echo nl2br(htmlspecialchars($inq['message'])); ?></p>
                            <?php if (!empty($inq['admin_reply'])): ?>
                                <div class="inquiry-reply">
                                    <span class="inquiry-reply-label">Admin Reply</span>
                                    <p class="inquiry-reply-text"><?php echo nl2br(htmlspecialchars($inq['admin_reply'])); ?></p>
                                    <span class="inquiry-date">Replied: <?php echo date('Y-m-d h:i A', strtotime($inq['replied_at'])); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div><!-- /tab-contact-admin -->

        <!-- Add New Produce Modal -->
        <div class="modal-overlay" id="addProduceModal">
            <div class="modal-box">
                <div class="modal-header">
                    <h3>🌾 List New Produce</h3>
                    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <form action="farmer.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="add_listing" value="1">

                        <div class="form-group">
                            <label>Food Name:</label>
                            <input type="text" name="food_name" class="form-control" placeholder="e.g. Carrots, Mangoes" required>
                        </div>

                        <div class="form-group">
                            <label>Food Type:</label>
                            <select name="food_type" class="form-control" required>
                                <option value="Vegetables">Vegetables</option>
                                <option value="Fruits">Fruits</option>
                                <option value="Grains">Grains</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Quantity (kg):</label>
                            <input type="number" step="0.01" name="quantity_kg" class="form-control" placeholder="0.00" min="0.01" required>
                        </div>

                        <div class="form-group">
                            <label>Price per kg (LKR):</label>
                            <input type="number" step="0.01" name="price_per_kg" class="form-control" placeholder="0.00" min="0.01" required>
                        </div>

                        <div class="form-group">
                            <label>Produce Photo <small style="color:var(--text-muted);">(JPG/PNG/WebP, max 3MB)</small>:</label>
                            <div class="file-input-wrapper">
                                <input type="file" name="food_image" accept="image/jpeg,image/png,image/webp,image/gif">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">Submit Listing</button>
                    </form>
                </div>
            </div>
        </div><!-- /addProduceModal -->

    </div><!-- /padding wrapper -->

        </main>
    </div><!-- /app-shell -->

    <script src="../theme.js"></script>
</body>
</html>