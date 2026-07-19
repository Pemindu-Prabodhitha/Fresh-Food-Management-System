<?php
session_start();
require_once '../connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Transporter') {
    header("Location: ../login.php");
    exit;
}

$transporter_id = $_SESSION['user_id'];
$order_id = intval($_GET['order_id'] ?? 0);

$sql = "SELECT o.*, fl.food_name, fl.food_type, fl.price_per_kg,
               u_farmer.name AS farmer_name, u_farmer.location_city AS farmer_city, u_farmer.email AS farmer_email, u_farmer.phone AS farmer_phone,
               u_buyer.name AS buyer_account_name
        FROM orders o
        JOIN food_listings fl ON o.listing_id = fl.listing_id
        JOIN users u_farmer ON fl.farmer_id = u_farmer.user_id
        JOIN users u_buyer ON o.sales_id = u_buyer.user_id
        WHERE o.order_id = ? AND o.transporter_id = ?";

$order = null;
if ($stmt = mysqli_prepare($con, $sql)) {
    mysqli_stmt_bind_param($stmt, "ii", $order_id, $transporter_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $order = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
}

if (!$order) {
    die("Order not found, or this job is not assigned to you.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Job Sheet - Order #<?php echo $order['order_id']; ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f2f2f2;
            color: #1a1a1a;
            margin: 0;
            padding: 30px;
        }
        .sheet {
            max-width: 780px;
            margin: 0 auto;
            background: #fff;
            padding: 45px 50px;
            border-radius: 6px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.12);
        }
        .doc-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 3px solid #1f7a3d;
            padding-bottom: 18px;
            margin-bottom: 25px;
        }
        .brand { font-size: 22px; font-weight: 700; color: #1f7a3d; margin: 0; }
        .doc-title { font-size: 14px; color: #666; margin: 4px 0 0 0; }
        .meta { text-align: right; font-size: 12px; color: #666; }
        .meta strong { color: #1a1a1a; }
        h2.section-title {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #1f7a3d;
            border-bottom: 1px solid #ddd;
            padding-bottom: 6px;
            margin: 28px 0 14px 0;
        }
        table.kv { width: 100%; border-collapse: collapse; font-size: 14px; }
        table.kv td { padding: 7px 4px; vertical-align: top; }
        table.kv td.label { width: 200px; color: #666; font-weight: 600; }
        table.kv td.value { color: #111; }
        .highlight-box {
            background: #f0f9f1;
            border: 1px solid #cbe8cf;
            border-radius: 6px;
            padding: 16px 20px;
            margin-top: 8px;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            background: #fff3cd;
            color: #8a6300;
        }
        .print-bar { text-align: center; margin-top: 32px; }
        .print-btn {
            background: #1f7a3d;
            color: #fff;
            border: none;
            padding: 12px 28px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .print-btn:hover { background: #17602f; }
        .footer-note { margin-top: 30px; font-size: 11px; color: #999; text-align: center; }

        @media print {
            body { background: #fff; padding: 0; }
            .sheet { box-shadow: none; border-radius: 0; max-width: 100%; padding: 20px; }
            .print-bar { display: none; }
        }

        @media (max-width: 600px) {
            body { padding: 12px; }
            .sheet { padding: 22px 18px; }
            .doc-header { flex-direction: column; gap: 10px; }
            .meta { text-align: left; }
            table.kv td.label { display: block; width: 100%; padding-bottom: 0; }
            table.kv td.value { display: block; width: 100%; padding-top: 2px; padding-bottom: 12px; }
            table.kv tr { display: block; }
        }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="doc-header">
            <div>
                <p class="brand">🌿 Fresh Ceylon</p>
                <p class="doc-title">Delivery Job Sheet</p>
            </div>
            <div class="meta">
                <div>Order <strong>#<?php echo $order['order_id']; ?></strong></div>
                <div>Generated: <strong><?php echo date('Y-m-d H:i'); ?></strong></div>
                <div>Status: <span class="status-badge"><?php echo htmlspecialchars($order['order_status']); ?></span></div>
            </div>
        </div>

        <h2 class="section-title">Produce Details</h2>
        <table class="kv">
            <tr><td class="label">Crop</td><td class="value"><?php echo htmlspecialchars($order['food_name']); ?> (<?php echo htmlspecialchars($order['food_type']); ?>)</td></tr>
            <tr><td class="label">Quantity Ordered</td><td class="value"><?php echo htmlspecialchars($order['quantity_ordered']); ?> kg</td></tr>
            <tr><td class="label">Price per kg</td><td class="value">LKR <?php echo number_format($order['price_per_kg'], 2); ?></td></tr>
            <tr><td class="label">Total Value</td><td class="value">LKR <?php echo number_format($order['total_price'], 2); ?></td></tr>
        </table>

        <h2 class="section-title">Pickup From (Farmer)</h2>
        <table class="kv">
            <tr><td class="label">Farmer Name</td><td class="value"><?php echo htmlspecialchars($order['farmer_name']); ?></td></tr>
            <tr><td class="label">Farmer City</td><td class="value"><?php echo htmlspecialchars($order['farmer_city']); ?></td></tr>
            <tr><td class="label">Farmer Email</td><td class="value"><?php echo htmlspecialchars($order['farmer_email']); ?></td></tr>
            <tr><td class="label">Farmer Mobile</td><td class="value"><?php echo htmlspecialchars($order['farmer_phone'] ?? 'Not provided'); ?></td></tr>
        </table>

        <h2 class="section-title">Deliver To (Buyer Contact Details)</h2>
        <div class="highlight-box">
            <table class="kv">
                <tr><td class="label">Full Name</td><td class="value"><?php echo htmlspecialchars($order['contact_name'] ?? 'Not provided'); ?></td></tr>
                <tr><td class="label">Email</td><td class="value"><?php echo htmlspecialchars($order['contact_email'] ?? 'Not provided'); ?></td></tr>
                <tr><td class="label">Mobile Number</td><td class="value"><?php echo htmlspecialchars($order['contact_mobile'] ?? 'Not provided'); ?></td></tr>
                <tr><td class="label">Pickup Location</td><td class="value"><?php echo htmlspecialchars($order['pickup_location'] ?? 'Not provided'); ?></td></tr>
            </table>
        </div>

        <p class="footer-note">This document was generated by Fresh Ceylon for delivery coordination purposes. Please verify all details with the buyer before starting the job.</p>

        <div class="print-bar">
            <button class="print-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
        </div>
    </div>
</body>
</html>