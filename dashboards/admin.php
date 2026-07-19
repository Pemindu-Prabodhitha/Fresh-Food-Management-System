<?php
session_start();
require_once '../connection.php';

// --- SECURITY CHECK ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../login.php");
    exit;
}

$admin_id = $_SESSION['user_id'];
$admin_name = $_SESSION['name'];
$message = '';
$error = '';

// --- ADMIN ACTION: CHANGE USER ROLE ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_role'])) {
    $target_user_id = $_POST['user_id'];
    $new_role = $_POST['role'];

    // Ensure we don't accidentally demote ourselves (the logged-in admin) without warning
    if ($target_user_id == $admin_id && $new_role !== 'Admin') {
        $error = "Error: You cannot demote yourself from Admin.";
    } else {
        $role_sql = "UPDATE users SET role = ? WHERE user_id = ?";
        if ($stmt = mysqli_prepare($con, $role_sql)) {
            mysqli_stmt_bind_param($stmt, "si", $new_role, $target_user_id);
            if (mysqli_stmt_execute($stmt)) {
                $message = "User role updated successfully.";
            } else {
                $error = "Error updating user role.";
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// --- ADMIN ACTION: DELETE USER ---
if (isset($_GET['delete_user'])) {
    $target_user_id = $_GET['delete_user'];

    if ($target_user_id == $admin_id) {
        $error = "Error: You cannot delete your own admin account.";
    } else {
        $del_sql = "DELETE FROM users WHERE user_id = ?";
        if ($stmt = mysqli_prepare($con, $del_sql)) {
            mysqli_stmt_bind_param($stmt, "i", $target_user_id);
            if (mysqli_stmt_execute($stmt)) {
                $message = "User account and all related records deleted successfully.";
            } else {
                $error = "Error deleting user account.";
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// --- ADMIN ACTION: DELETE LISTING ---
if (isset($_GET['delete_listing'])) {
    $target_listing_id = $_GET['delete_listing'];

    $del_list_sql = "DELETE FROM food_listings WHERE listing_id = ?";
    if ($stmt = mysqli_prepare($con, $del_list_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $target_listing_id);
        if (mysqli_stmt_execute($stmt)) {
            $message = "Produce listing removed successfully.";
        } else {
            $error = "Error removing produce listing.";
        }
        mysqli_stmt_close($stmt);
    }
}

// --- ADMIN ACTION: CANCEL ORDER ---
if (isset($_GET['cancel_order'])) {
    $target_order_id = $_GET['cancel_order'];

    $cancel_sql = "UPDATE orders SET order_status = 'Cancelled' WHERE order_id = ?";
    if ($stmt = mysqli_prepare($con, $cancel_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $target_order_id);
        if (mysqli_stmt_execute($stmt)) {
            $message = "Order #$target_order_id has been cancelled.";
            
            // Notify buyer
            require_once '../includes/notification_helper.php';
            $lookup_sql = "SELECT sales_id, transporter_id FROM orders WHERE order_id = ?";
            if ($stmt_look = mysqli_prepare($con, $lookup_sql)) {
                mysqli_stmt_bind_param($stmt_look, "i", $target_order_id);
                mysqli_stmt_execute($stmt_look);
                $res_look = mysqli_stmt_get_result($stmt_look);
                if ($row_look = mysqli_fetch_assoc($res_look)) {
                    addNotification($con, $row_look['sales_id'], "Admin Action: Your order #$target_order_id has been cancelled by system administration.");
                    if ($row_look['transporter_id']) {
                        addNotification($con, $row_look['transporter_id'], "Admin Action: Delivery Job for Order #$target_order_id has been cancelled by administration.");
                    }
                }
                mysqli_stmt_close($stmt_look);
            }
        } else {
            $error = "Error cancelling order.";
        }
        mysqli_stmt_close($stmt);
    }
}

// --- ADMIN ACTION: BROADCAST NOTIFICATION ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['broadcast'])) {
    $broadcast_msg = trim($_POST['message']);
    $audience = $_POST['audience'];

    if (empty($broadcast_msg)) {
        $error = "Broadcast message cannot be empty.";
    } else {
        $formatted_msg = "📢 ADMIN BROADCAST: " . $broadcast_msg;
        
        if ($audience === 'all') {
            $broadcast_sql = "INSERT INTO notifications (user_id, message) SELECT user_id, ? FROM users";
            if ($stmt = mysqli_prepare($con, $broadcast_sql)) {
                mysqli_stmt_bind_param($stmt, "s", $formatted_msg);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $message = "Broadcast sent successfully to all registered users.";
            }
        } else {
            $broadcast_sql = "INSERT INTO notifications (user_id, message) SELECT user_id, ? FROM users WHERE role = ?";
            if ($stmt = mysqli_prepare($con, $broadcast_sql)) {
                mysqli_stmt_bind_param($stmt, "ss", $formatted_msg, $audience);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $message = "Broadcast sent successfully to all " . htmlspecialchars($audience) . "s.";
            }
        }
    }
}

// --- FETCH SYSTEM-WIDE STATISTICS ---
// Total users
$stats_users = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as count FROM users"))['count'];
// Total active listings
$stats_listings = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as count FROM food_listings WHERE status='Available'"))['count'];
// Total orders
$stats_orders = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as count FROM orders"))['count'];
// Avg Rating
$stats_avg_rating = round(mysqli_fetch_assoc(mysqli_query($con, "SELECT AVG(score) as avg FROM ratings"))['avg'] ?? 0, 1);

// --- FETCH ALL USERS ---
$users_query = "SELECT user_id, name, email, role, location_city, created_at FROM users ORDER BY created_at DESC";
$users_res = mysqli_query($con, $users_query);
$all_users = mysqli_fetch_all($users_res, MYSQLI_ASSOC);

// --- FETCH ALL LISTINGS ---
$listings_query = "SELECT fl.*, u.name AS farmer_name FROM food_listings fl JOIN users u ON fl.farmer_id = u.user_id ORDER BY fl.created_at DESC";
$listings_res = mysqli_query($con, $listings_query);
$all_listings = mysqli_fetch_all($listings_res, MYSQLI_ASSOC);

// --- FETCH ALL ORDERS ---
$orders_query = "SELECT o.*, fl.food_name, u_buyer.name AS buyer_name, u_trans.name AS transporter_name 
                 FROM orders o 
                 JOIN food_listings fl ON o.listing_id = fl.listing_id 
                 JOIN users u_buyer ON o.sales_id = u_buyer.user_id 
                 LEFT JOIN users u_trans ON o.transporter_id = u_trans.user_id 
                 ORDER BY o.created_at DESC";
$orders_res = mysqli_query($con, $orders_query);
$all_orders = mysqli_fetch_all($orders_res, MYSQLI_ASSOC);

// --- FETCH ALL RATINGS ---
$ratings_query = "SELECT r.*, u_rev.name AS reviewer_name, u_revee.name AS reviewee_name 
                  FROM ratings r 
                  JOIN users u_rev ON r.reviewer_id = u_rev.user_id 
                  JOIN users u_revee ON r.reviewee_id = u_revee.user_id 
                  ORDER BY r.created_at DESC";
$ratings_res = mysqli_query($con, $ratings_query);
$all_ratings = mysqli_fetch_all($ratings_res, MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Fresh Ceylon</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .admin-action-btn {
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            padding: 0;
            text-decoration: underline;
        }
        .admin-action-btn:hover {
            color: white;
        }
    </style>
</head>
<body style="background-color:#1c1c1c;">

    <nav class="top-nav">
        <div class="logo-container">
            <img src="../images/logofinal.png" alt="Fresh Ceylon Logo" class="nav-logo">
            <h1 class="brand-name-dash">Fresh Ceylon Admin</h1>
        </div>
        <div class="nav-links">
            <a href="settings.php" class="btn btn-success" style="padding: 8px 16px;">Settings</a>
            <a href="../logout.php" class="btn btn-danger" style="padding: 8px 16px;">Logout</a>
        </div>
    </nav>

    <div class="dashboard-header">
        <span class="welcome-msg">System Administrator Panel | Welcome back, <strong><?php echo htmlspecialchars($admin_name); ?></strong></span>
        <span style="font-size: 14px; color: var(--text-muted);">Role: Main Administrator</span>
    </div>

    <div style="padding: 40px 5%; max-width: 1400px; width: 100%; margin: 0 auto; display: flex; flex-direction: column; gap: 30px;">
        
        <div>
            <?php if ($message): ?>
                <div style="background-color: rgba(16, 185, 129, 0.15); color: #34d399; padding: 14px; border-radius: var(--radius-sm); margin-bottom: 20px; border: 1px solid rgba(16, 185, 129, 0.3); text-align: center; font-size: 14px;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div style="background: rgba(244, 63, 94, 0.15); border: 1px solid rgba(244, 63, 94, 0.25); padding: 14px; border-radius: var(--radius-sm); margin-bottom: 20px; text-align: center; color: #fb7185; font-size: 14px;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Metric Cards -->
        <div class="admin-stats-grid">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-value"><?php echo $stats_users; ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🌾</div>
                <div class="stat-value"><?php echo $stats_listings; ?></div>
                <div class="stat-label">Active Listings</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📦</div>
                <div class="stat-value"><?php echo $stats_orders; ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⭐</div>
                <div class="stat-value"><?php echo $stats_avg_rating > 0 ? $stats_avg_rating . "/5" : "N/A"; ?></div>
                <div class="stat-label">System Rating Score</div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="admin-tabs">
            <button class="tab-btn active" onclick="showTab('users', this)">Users Directory</button>
            <button class="tab-btn" onclick="showTab('listings', this)">Produce Listings</button>
            <button class="tab-btn" onclick="showTab('orders', this)">Sales Orders</button>
            <button class="tab-btn" onclick="showTab('ratings', this)">Accountability Reviews</button>
            <button class="tab-btn" onclick="showTab('broadcast', this)">System Broadcast</button>
        </div>

        <!-- Tab 1: Users Directory -->
        <div id="users" class="tab-content active">
            <h3 style="font-size: 20px; margin-bottom: 20px; color: var(--primary);">System User Registry</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Full Name</th>
                            <th>Email Address</th>
                            <th>Operating Role</th>
                            <th>Registered City</th>
                            <th>Join Date</th>
                            <th style="text-align: right;">Administrative Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_users as $user): ?>
                            <tr>
                                <td style="font-weight: bold;">#<?php echo $user['user_id']; ?></td>
                                <td style="font-weight: 500;"><?php echo htmlspecialchars($user['name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <form action="admin.php" method="POST" style="display: inline-flex; align-items: center; gap: 8px;">
                                        <input type="hidden" name="change_role" value="1">
                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                        <select name="role" class="form-control" style="padding: 4px 8px; font-size: 12px; width: auto;" onchange="this.form.submit()">
                                            <option value="Farmer" <?php if($user['role'] == 'Farmer') echo 'selected'; ?>>Farmer</option>
                                            <option value="Sales" <?php if($user['role'] == 'Sales') echo 'selected'; ?>>Sales Person</option>
                                            <option value="Transporter" <?php if($user['role'] == 'Transporter') echo 'selected'; ?>>Transporter</option>
                                            <option value="Admin" <?php if($user['role'] == 'Admin') echo 'selected'; ?>>Admin</option>
                                        </select>
                                    </form>
                                </td>
                                <td><?php echo htmlspecialchars($user['location_city']); ?></td>
                                <td style="font-size: 13px; color: var(--text-muted);"><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                <td style="text-align: right;">
                                    <?php if($user['user_id'] != $admin_id): ?>
                                        <a href="admin.php?delete_user=<?php echo $user['user_id']; ?>" 
                                           class="btn btn-danger btn-sm" 
                                           onclick="return confirm('DANGER: Are you sure you want to delete this user? This will delete all of their listings, orders, and related records.');"
                                           style="padding: 6px 12px; font-size: 11px;">Ban Account</a>
                                    <?php else: ?>
                                        <span style="font-style: italic; font-size: 12px; color: var(--text-muted);">Current User</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab 2: Produce Listings -->
        <div id="listings" class="tab-content">
            <h3 style="font-size: 20px; margin-bottom: 20px; color: var(--primary);">Active Crop Marketplace Listings</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Listing ID</th>
                            <th>Produce Item</th>
                            <th>Type</th>
                            <th>Farmer</th>
                        <th style="width:60px;">Photo</th>
                            <th>ID</th>
                            <th>Crop</th>
                            <th>Type</th>
                            <th>Farmer</th>
                            <th>Quantity (kg)</th>
                            <th>Price / kg</th>
                            <th>Status</th>
                            <th>Listed Date</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_listings)): ?>
                            <tr>
                                <td colspan="10" style="text-align: center; color: #666; font-style: italic; padding: 25px;">No marketplace listings found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($all_listings as $listing): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($listing['image_path'])): ?>
                                            <img src="../<?php echo htmlspecialchars($listing['image_path']); ?>" class="listing-thumb" alt="">
                                        <?php else: ?>
                                            <div class="listing-thumb-placeholder">🌿</div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-weight: bold;">#<?php echo $listing['listing_id']; ?></td>
                                    <td style="font-weight: 500;"><?php echo htmlspecialchars($listing['food_name']); ?></td>
                                    <td><?php echo htmlspecialchars($listing['food_type']); ?></td>
                                    <td style="font-weight: 500;"><?php echo htmlspecialchars($listing['farmer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($listing['quantity_kg']); ?> kg</td>
                                    <td style="color: lightgreen; font-weight: 500;">LKR <?php echo htmlspecialchars(number_format($listing['price_per_kg'], 2)); ?></td>
                                    <td>
                                        <?php 
                                        $badge_class = 'badge-available';
                                        if ($listing['status'] == 'Pending') $badge_class = 'badge-pending';
                                        if ($listing['status'] == 'Sold Out') $badge_class = 'badge-sold';
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($listing['status']); ?></span>
                                    </td>
                                    <td style="font-size: 13px; color: #888;"><?php echo date('Y-m-d', strtotime($listing['created_at'])); ?></td>
                                    <td style="text-align: right;">
                                        <a href="admin.php?delete_listing=<?php echo $listing['listing_id']; ?>" 
                                           class="btn btn-danger btn-sm"
                                           onclick="return confirm('Are you sure you want to remove this listing?');"
                                           style="padding: 6px 12px; font-size: 11px;">Remove</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab 3: Sales Orders -->
        <div id="orders" class="tab-content">
            <h3 style="font-size: 20px; margin-bottom: 20px; color: lightgreen;">System Orders Ledger</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Produce Item</th>
                            <th>Buyer</th>
                            <th>Transporter Assigned</th>
                            <th>Order Quantity</th>
                            <th>Total Payout</th>
                            <th>Delivery Status</th>
                            <th>Order Date</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                                      <?php if (empty($all_orders)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; color: #666; font-style: italic; padding: 25px;">No orders found in the database.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($all_orders as $order): ?>
                                <tr>
                                    <td style="font-weight: bold;">#<?php echo $order['order_id']; ?></td>
                                    <td style="color: lightgreen; font-weight: 500;"><?php echo htmlspecialchars($order['food_name']); ?></td>
                                    <td style="font-weight: 500;"><?php echo htmlspecialchars($order['buyer_name']); ?></td>
                                    <td><?php echo $order['transporter_name'] ? htmlspecialchars($order['transporter_name']) : '<span style="color: #555; font-style: italic; font-size: 12px;">Unassigned</span>'; ?></td>
                                    <td><?php echo htmlspecialchars($order['quantity_ordered']); ?> kg</td>
                                    <td style="font-weight: bold; color: white;">LKR <?php echo htmlspecialchars(number_format($order['total_price'], 2)); ?></td>
                                    <td>
                                        <?php
                                        $badge_class = 'badge-available'; // default
                                        if ($order['order_status'] == 'Pending Approval') $badge_class = 'badge-pending';
                                        if ($order['order_status'] == 'Approved') $badge_class = 'badge-pending';
                                        if ($order['order_status'] == 'In Transit') $badge_class = 'badge-pending';
                                        if ($order['order_status'] == 'Delivered') $badge_class = 'badge-available';
                                        if ($order['order_status'] == 'Cancelled') $badge_class = 'badge-sold';
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($order['order_status']); ?></span>
                                    </td>
                                    <td style="font-size: 13px; color: #888;"><?php echo date('Y-m-d', strtotime($order['created_at'])); ?></td>
                                    <td style="text-align: right;">
                                        <?php if($order['order_status'] !== 'Cancelled' && $order['order_status'] !== 'Delivered'): ?>
                                            <a href="admin.php?cancel_order=<?php echo $order['order_id']; ?>" 
                                               class="btn btn-danger btn-sm"
                                               onclick="return confirm('Are you sure you want to cancel this order? It will notify the buyer.')"
                                               style="padding: 6px 12px; font-size: 11px;">Cancel</a>
                                        <?php else: ?>
                                            <span style="font-style: italic; font-size: 12px; color: #555;">Completed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab 4: Accountability Reviews -->
        <div id="ratings" class="tab-content">
            <h3 style="font-size: 20px; margin-bottom: 20px; color: var(--primary);">System Feedback Ratings</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Rating ID</th>
                            <th>Order ID</th>
                            <th>Reviewer (Buyer)</th>
                            <th>Reviewee (Farmer/Trans)</th>
                            <th>Score</th>
                            <th>Review Comment</th>
                            <th>Review Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_ratings)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--text-muted); font-style: italic; padding: 25px;">No feedback reviews left by sales buyers yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($all_ratings as $rating): ?>
                                <tr>
                                    <td style="font-weight: bold;">#<?php echo $rating['rating_id']; ?></td>
                                    <td style="font-weight: 500;">#<?php echo $rating['order_id']; ?></td>
                                    <td style="font-weight: 500;"><?php echo htmlspecialchars($rating['reviewer_name']); ?></td>
                                    <td style="font-weight: 500;"><?php echo htmlspecialchars($rating['reviewee_name']); ?></td>
                                    <td style="color: var(--accent); font-weight: bold;">
                                        <?php
                                        for ($i = 1; $i <= 5; $i++) {
                                            if ($i <= $rating['score']) {
                                                echo '⭐';
                                            }
                                        }
                                        echo ' (' . $rating['score'] . '/5)';
                                        ?>
                                    </td>
                                    <td style="font-style: italic; color: #ddd;">"<?php echo htmlspecialchars($rating['comment']); ?>"</td>
                                    <td style="font-size: 13px; color: var(--text-muted);"><?php echo date('Y-m-d', strtotime($rating['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab 5: System Broadcast -->
        <div id="broadcast" class="tab-content">
            <div class="auth-container" style="margin: 0 auto; max-width: 700px; width: 100%;">
                <h3 style="color: var(--primary); margin-top: 0; margin-bottom: 20px; font-size: 22px;">Broadcast System Notification</h3>
                <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 25px; line-height: 1.5;">
                    Enter a message below to broadcast a notification alert to users. The alert will be published instantly and displayed in their dashboard alerts box.
                </p>
                <form action="admin.php" method="POST">
                    <input type="hidden" name="broadcast" value="1">
                    
                    <div class="form-group">
                        <label>Target Audience</label>
                        <select name="audience" class="form-control">
                            <option value="all">📢 All Registered Users</option>
                            <option value="Farmer">👨‍🌾 All Farmers</option>
                            <option value="Sales">🛒 All Sales Persons (Buyers)</option>
                            <option value="Transporter">🚚 All Transporters</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Broadcast Message</label>
                        <textarea name="message" class="form-control" rows="5" placeholder="Type notification content here..." required style="resize: vertical; font-family: var(--font-body);"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; margin-top: 10px;">Send System Broadcast</button>
                </form>
            </div>
        </div>

    </div>

    <script>
        function showTab(tabId, btn) {
            // Hide all tab content
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            // Remove active class from all buttons
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            
            // Show selected content and set button active
            document.getElementById(tabId).classList.add('active');
            btn.classList.add('active');
        }
    </script>
</body>
</html>
