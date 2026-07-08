<?php
session_start();
require_once '../connection.php';

// --- SECURITY CHECK ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Farmer') {
    header("Location: ../login.php");
    exit;
}

$farmer_id = $_SESSION['user_id'];
$farmer_name = $_SESSION['name'];
$farmer_city = $_SESSION['location_city']; 

// --- BACKEND LOGIC: INSERT NEW LISTING ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_listing'])) {
    $food_name = trim($_POST['food_name']);
    $food_type = $_POST['food_type'];
    $quantity_kg = $_POST['quantity_kg'];
    $price_per_kg = $_POST['price_per_kg'];

    $insert_sql = "INSERT INTO food_listings (farmer_id, food_name, food_type, quantity_kg, price_per_kg, status) VALUES (?, ?, ?, ?, ?, 'Available')";
    if ($stmt = mysqli_prepare($con, $insert_sql)) {
        mysqli_stmt_bind_param($stmt, "issdd", $farmer_id, $food_name, $food_type, $quantity_kg, $price_per_kg);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo "<script>alert('Food item listed successfully!'); window.location.href='farmer.php';</script>";
    }
}

// --- BACKEND LOGIC: DELETE LISTING ---
if (isset($_GET['delete_listing'])) {
    $del_listing_id = $_GET['delete_listing'];
    
    // Secure delete: Ensure the listing belongs to the logged-in farmer
    $del_sql = "DELETE FROM food_listings WHERE listing_id = ? AND farmer_id = ?";
    if ($stmt = mysqli_prepare($con, $del_sql)) {
        mysqli_stmt_bind_param($stmt, "ii", $del_listing_id, $farmer_id);
        
        try {
            // Attempt to execute the deletion
            mysqli_stmt_execute($stmt);
            
            if (mysqli_stmt_affected_rows($stmt) > 0) {
                echo "<script>alert('Listing removed successfully.'); window.location.href='farmer.php';</script>";
            } else {
                 echo "<script>alert('Error: Could not find the listing to delete.'); window.location.href='farmer.php';</script>";
            }
        } catch (mysqli_sql_exception $e) {
            // Catches the foreign key constraint error in newer PHP versions
            echo "<script>alert('Action Blocked: You cannot delete this listing because it has active purchase orders attached to it.'); window.location.href='farmer.php';</script>";
        }
        
        mysqli_stmt_close($stmt);
        exit; // Important: Stops the rest of the HTML from rendering while redirecting
    }
}

// --- BACKEND LOGIC: APPROVE ORDER & ASSIGN MATCHED TRANSPORTER ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['approve_order'])) {
    $order_id = $_POST['order_id'];
    $transporter_id = $_POST['transporter_id']; 
    $listing_id = $_POST['listing_id'];
    $quantity_ordered = $_POST['quantity_ordered'];

    // FIXED: Added constraint to ensure quantity doesn't drop below zero
    $deduct_sql = "UPDATE food_listings SET quantity_kg = quantity_kg - ? WHERE listing_id = ? AND quantity_kg >= ?";
    
    if ($stmt2 = mysqli_prepare($con, $deduct_sql)) {
        mysqli_stmt_bind_param($stmt2, "did", $quantity_ordered, $listing_id, $quantity_ordered);
        mysqli_stmt_execute($stmt2);
        
        if (mysqli_stmt_affected_rows($stmt2) > 0) {
            // Deducted successfully, now approve the order
            mysqli_stmt_close($stmt2);
            
            $approve_sql = "UPDATE orders SET order_status = 'Approved', transporter_id = ? WHERE order_id = ?";
            if ($stmt1 = mysqli_prepare($con, $approve_sql)) {
                mysqli_stmt_bind_param($stmt1, "ii", $transporter_id, $order_id);
                mysqli_stmt_execute($stmt1);
                mysqli_stmt_close($stmt1);
                
                require_once '../includes/notification_helper.php';
                
                addNotification($con, $transporter_id, "New Job Assigned! You have been auto-matched to haul a crop batch order (#$order_id).");
                
                $sales_lookup_sql = "SELECT sales_id FROM orders WHERE order_id = ?";
                if ($stmt_lookup = mysqli_prepare($con, $sales_lookup_sql)) {
                    mysqli_stmt_bind_param($stmt_lookup, "i", $order_id);
                    mysqli_stmt_execute($stmt_lookup);
                    $res_lookup = mysqli_stmt_get_result($stmt_lookup);
                    if ($row_lookup = mysqli_fetch_assoc($res_lookup)) {
                        $sales_id = $row_lookup['sales_id'];
                        addNotification($con, $sales_id, "Your pending order (#$order_id) has been approved by the farmer and a transporter has been dispatched!");
                    }
                    mysqli_stmt_close($stmt_lookup);
                }
            }
            
            // Mark as 'Sold Out' if quantity drops to 0 or below
            $status_check_sql = "UPDATE food_listings SET status = 'Sold Out' WHERE listing_id = ? AND quantity_kg <= 0";
            if ($stmt3 = mysqli_prepare($con, $status_check_sql)) {
                mysqli_stmt_bind_param($stmt3, "i", $listing_id);
                mysqli_stmt_execute($stmt3);
                mysqli_stmt_close($stmt3);
            }
            echo "<script>alert('Order approved and Transporter assigned successfully!'); window.location.href='farmer.php';</script>";
        } else {
            // Revert or block if stock is insufficient
            mysqli_stmt_close($stmt2);
            echo "<script>alert('Error: Not enough stock to fulfill this order!'); window.location.href='farmer.php';</script>";
        }
    }
}

// --- FETCH CURRENT FARMER'S LISTINGS ---
$query = "SELECT * FROM food_listings WHERE farmer_id = ? ORDER BY created_at DESC";
$listings_stmt = mysqli_prepare($con, $query);
mysqli_stmt_bind_param($listings_stmt, "i", $farmer_id);
mysqli_stmt_execute($listings_stmt);
$listings_res = mysqli_stmt_get_result($listings_stmt);
$listings = mysqli_fetch_all($listings_res, MYSQLI_ASSOC);
mysqli_stmt_close($listings_stmt);

// --- FETCH INCOMING PENDING ORDERS FOR THIS FARMER ---
$order_query = "SELECT o.*, fl.food_name, u.name AS buyer_name 
                FROM orders o
                JOIN food_listings fl ON o.listing_id = fl.listing_id
                JOIN users u ON o.sales_id = u.user_id
                WHERE fl.farmer_id = ? AND o.order_status = 'Pending Approval'";
$orders_stmt = mysqli_prepare($con, $order_query);
mysqli_stmt_bind_param($orders_stmt, "i", $farmer_id);
mysqli_stmt_execute($orders_stmt);
$orders_res = mysqli_stmt_get_result($orders_stmt);
$incoming_orders = mysqli_fetch_all($orders_res, MYSQLI_ASSOC);
mysqli_stmt_close($orders_stmt);

// --- MATCHING ENGINE QUERY ---
$matching_sql = "SELECT t_area.transporter_id, u.name AS transporter_name 
                 FROM transporter_service_areas t_area
                 JOIN users u ON t_area.transporter_id = u.user_id
                 WHERE LOWER(t_area.covered_city) = LOWER(?)";
$match_stmt = mysqli_prepare($con, $matching_sql);
mysqli_stmt_bind_param($match_stmt, "s", $farmer_city);
mysqli_stmt_execute($match_stmt);
$match_res = mysqli_stmt_get_result($match_stmt);
$available_transporters = mysqli_fetch_all($match_res, MYSQLI_ASSOC);
mysqli_stmt_close($match_stmt);

// --- FETCH RECENT NOTIFICATIONS FOR THE LOGGED-IN FARMER ---
$my_notifications = [];
$noti_query = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5";
if ($stmt_noti = mysqli_prepare($con, $noti_query)) {
    mysqli_stmt_bind_param($stmt_noti, "i", $farmer_id);
    mysqli_stmt_execute($stmt_noti);
    $noti_res = mysqli_stmt_get_result($stmt_noti);
    while ($row = mysqli_fetch_assoc($noti_res)) {
        $my_notifications[] = $row;
    }
    mysqli_stmt_close($stmt_noti);
}

// --- FETCH FARMER'S AVERAGE RATING ---
$rating_query = "SELECT AVG(score) as avg_score, COUNT(*) as review_count FROM ratings WHERE reviewee_id = ?";
$avg_score = 0;
$review_count = 0;
if ($stmt_rating = mysqli_prepare($con, $rating_query)) {
    mysqli_stmt_bind_param($stmt_rating, "i", $farmer_id);
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
    <title>Farmer Dashboard - Fresh Food System</title>
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
            Welcome, <strong><?php echo htmlspecialchars($farmer_name); ?></strong> (Location: <?php echo htmlspecialchars($farmer_city); ?>)
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

    <h3>📥 Incoming Requests</h3>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Buyer</th>
                    <th>Crop Ordered</th>
                    <th>Qty Requested</th>
                    <th>Total Payout</th>
                    <th>Auto-Matched Transporters</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($incoming_orders)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center;">No pending purchase orders right now.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($incoming_orders as $order): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($order['buyer_name']); ?></td>
                            <td><?php echo htmlspecialchars($order['food_name']); ?></td>
                            <td><?php echo htmlspecialchars($order['quantity_ordered']); ?> kg</td>
                            <td>LKR <?php echo htmlspecialchars($order['total_price']); ?></td>
                            <td>
                                <form action="farmer.php" method="POST" style="display:flex; gap:10px;">
                                    <input type="hidden" name="approve_order" value="1">
                                    <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                    <input type="hidden" name="listing_id" value="<?php echo $order['listing_id']; ?>">
                                    <input type="hidden" name="quantity_ordered" value="<?php echo $order['quantity_ordered']; ?>">
                                    
                                    <?php if (empty($available_transporters)): ?>
                                        <span style="color: red; font-size: 13px;">⚠️ No transporters found covering <strong><?php echo htmlspecialchars($farmer_city); ?></strong></span>
                                    <?php else: ?>
                                        <select name="transporter_id" class="form-control" style="width: auto;" required>
                                            <option value="">-- Choose Transporter --</option>
                                            <?php foreach ($available_transporters as $transporter): ?>
                                                <option value="<?php echo $transporter['transporter_id']; ?>">
                                                    <?php echo htmlspecialchars($transporter['transporter_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-success btn-sm">Approve & Assign</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="dashboard-layout">
        <div class="dashboard-sidebar">
            <h3>List New Produce</h3>
            <form action="farmer.php" method="POST">
                <input type="hidden" name="add_listing" value="1">
                
                <div class="form-group">
                    <label>Food Name:</label>
                    <input type="text" name="food_name" class="form-control" required>
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
                    <input type="number" step="0.01" name="quantity_kg" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Price per kg (LKR):</label>
                    <input type="number" step="0.01" name="price_per_kg" class="form-control" required>
                </div>

                <button type="submit" class="btn btn-primary">Submit Listing</button>
            </form>
        </div>

        <div class="dashboard-main">
            <h3>Your Crop Inventory</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Crop</th>
                            <th>Type</th>
                            <th>Stock Qty</th>
                            <th>Price/kg</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($listings)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center;">You haven't listed any crops yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($listings as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['food_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['food_type']); ?></td>
                                    <td><?php echo htmlspecialchars($item['quantity_kg']); ?> kg</td>
                                    <td>LKR <?php echo htmlspecialchars($item['price_per_kg']); ?></td>
                                    <td><?php echo htmlspecialchars($item['status']); ?></td>
                                    <td>
                                        <a href="farmer.php?delete_listing=<?php echo $item['listing_id']; ?>" 
                                           class="btn btn-danger btn-sm" 
                                           onclick="return confirm('Are you sure you want to delete this listing?');"
                                           style="text-decoration: none;">Delete</a>
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