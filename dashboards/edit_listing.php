<?php
session_start();
require_once '../connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Farmer') {
    header("Location: ../login.php");
    exit;
}

$farmer_id = $_SESSION['user_id'];
$listing_id = intval($_GET['id'] ?? 0);

if (!$listing_id) {
    header("Location: farmer.php");
    exit;
}

// Verify ownership
$ownership_sql = "SELECT * FROM food_listings WHERE listing_id = ? AND farmer_id = ?";
$own_stmt = mysqli_prepare($con, $ownership_sql);
mysqli_stmt_bind_param($own_stmt, "ii", $listing_id, $farmer_id);
mysqli_stmt_execute($own_stmt);
$listing = mysqli_fetch_assoc(mysqli_stmt_get_result($own_stmt));
mysqli_stmt_close($own_stmt);

if (!$listing) {
    echo "<script>alert('Listing not found or access denied.'); window.location.href='farmer.php';</script>";
    exit;
}

$success = '';
$error   = '';

// --- HANDLE UPDATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_listing'])) {
    $food_name    = trim($_POST['food_name']);
    $food_type    = $_POST['food_type'];
    $quantity_kg  = $_POST['quantity_kg'];
    $price_per_kg = $_POST['price_per_kg'];
    $status       = $_POST['status'];

    // Handle new image upload (optional)
    $new_image_path = $listing['image_path']; // Keep old image by default

    if (isset($_FILES['food_image']) && $_FILES['food_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['food_image'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE   => 'The file is larger than this server allows (upload_max_filesize in php.ini).',
                UPLOAD_ERR_FORM_SIZE  => 'The file is larger than the form allows.',
                UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server has no temporary folder configured for uploads.',
                UPLOAD_ERR_CANT_WRITE => 'Server failed to write the file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A server extension blocked the upload.',
            ];
            $error = $upload_errors[$file['error']] ?? ('Unknown upload error (code ' . $file['error'] . ').');
        } else {
            // Check the file's real content type on disk instead of trusting the
            // browser-supplied MIME type, which can be wrong or missing.
            $allowed_mimes = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/gif'  => 'gif',
            ];
            $real_mime = @mime_content_type($file['tmp_name']);

            if ($real_mime === false || !isset($allowed_mimes[$real_mime])) {
                $error = "Unsupported file type detected ($real_mime). Use JPG, PNG, WebP, or GIF.";
            } elseif ($file['size'] > 3 * 1024 * 1024) {
                $error = "Image is larger than 3MB.";
            } else {
                $dest_dir = __DIR__ . '/../images/';
                if (!is_dir($dest_dir)) {
                    $error = "Upload folder is missing on the server: $dest_dir";
                } elseif (!is_writable($dest_dir)) {
                    $error = "Upload folder is not writable by the server: $dest_dir";
                } else {
                    $ext      = $allowed_mimes[$real_mime];
                    $filename = 'crop_' . uniqid() . '.' . $ext;
                    $dest     = $dest_dir . $filename;

                    if (move_uploaded_file($file['tmp_name'], $dest)) {
                        // Delete old image if it exists
                        if (!empty($listing['image_path'])) {
                            $old_path = __DIR__ . '/../' . $listing['image_path'];
                            if (file_exists($old_path)) @unlink($old_path);
                        }
                        $new_image_path = 'images/' . $filename;
                    } else {
                        $error = "The server could not save the uploaded file (move_uploaded_file failed).";
                    }
                }
            }
        }
    }

    if (!$error) {
        $update_sql = "UPDATE food_listings SET food_name=?, food_type=?, quantity_kg=?, price_per_kg=?, status=?, image_path=? WHERE listing_id=? AND farmer_id=?";
        if ($stmt = mysqli_prepare($con, $update_sql)) {
            mysqli_stmt_bind_param($stmt, "ssddssii", $food_name, $food_type, $quantity_kg, $price_per_kg, $status, $new_image_path, $listing_id, $farmer_id);
            if (mysqli_stmt_execute($stmt)) {
                $listing['food_name']    = $food_name;
                $listing['food_type']    = $food_type;
                $listing['quantity_kg']  = $quantity_kg;
                $listing['price_per_kg'] = $price_per_kg;
                $listing['status']       = $status;
                $listing['image_path']   = $new_image_path;
                $success = "Listing updated successfully!";
            } else {
                $error = "Failed to update the listing: " . mysqli_error($con);
            }
            mysqli_stmt_close($stmt);
        } else {
            $error = "Database error: " . mysqli_error($con);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Listing - Fresh Ceylon</title>
    <link rel="stylesheet" href="../style.css?v=3">
</head>
<body>

    <nav class="top-nav">
        <div class="logo-container">
            <img src="../images/logofinal.png" alt="Fresh Ceylon Logo" class="nav-logo" style="height:55px;">
            <h1 class="brand-name-dash">Fresh Ceylon</h1>
        </div>
        <div class="nav-links">
            <a href="farmer.php" class="btn btn-primary" style="width:auto; text-decoration:none; margin-right:10px;">← Back to Dashboard</a>
            <a href="../logout.php" class="btn btn-danger" style="width:auto; text-decoration:none;">Logout</a>
        </div>
    </nav>

    <div style="max-width: 600px; margin: 40px auto; padding: 0 20px;">

        <h2 style="color:lightgreen; margin-bottom:8px;">✏️ Edit Produce Listing</h2>
        <p style="color:#888; margin-top:0; margin-bottom:25px;">Update the details for: <strong style="color:#ddd;"><?php echo htmlspecialchars($listing['food_name']); ?></strong></p>

        <?php if ($success): ?>
            <div style="background:#0a2a10; color:#8ce67c; padding:12px 16px; border-radius:6px; margin-bottom:22px; border:1px solid #2e4a29; font-size:14px;">
                ✅ <?php echo htmlspecialchars($success); ?> <a href="farmer.php" style="color:lightgreen; margin-left:12px;">← Return to Dashboard</a>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div style="background:#2a1010; color:#ff6b6b; padding:12px 16px; border-radius:6px; margin-bottom:22px; border:1px solid #ff6b6b; font-size:14px;">
                ⚠️ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div style="background:#000; border:1px solid #2a2a2a; border-radius:10px; padding:30px;">

            <!-- Image Preview -->
            <?php if (!empty($listing['image_path'])): ?>
                <div style="margin-bottom:20px; text-align:center;">
                    <p style="color:#888; font-size:13px; margin-bottom:8px;">Current Photo:</p>
                    <img src="../<?php echo htmlspecialchars($listing['image_path']); ?>"
                         alt="Current crop image"
                         style="max-width:100%; max-height:220px; object-fit:cover; border-radius:8px; border:1px solid #2a2a2a;">
                </div>
            <?php else: ?>
                <div style="margin-bottom:20px; text-align:center; padding:25px; background:#0a200f; border-radius:8px; border:1px dashed #2a2a2a;">
                    <p style="color:#2e4a29; font-size:36px; margin:0;">🌿</p>
                    <p style="color:#555; font-size:13px; margin:6px 0 0 0;">No photo uploaded yet</p>
                </div>
            <?php endif; ?>

            <form action="edit_listing.php?id=<?php echo $listing_id; ?>" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="update_listing" value="1">

                <div class="form-group">
                    <label>Food Name:</label>
                    <input type="text" name="food_name" class="form-control" value="<?php echo htmlspecialchars($listing['food_name']); ?>" required>
                </div>

                <div class="form-group">
                    <label>Food Type:</label>
                    <select name="food_type" class="form-control" required>
                        <?php foreach (['Vegetables','Fruits','Grains'] as $ft): ?>
                            <option value="<?php echo $ft; ?>" <?php if ($listing['food_type'] == $ft) echo 'selected'; ?>><?php echo $ft; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Quantity (kg):</label>
                    <input type="number" step="0.01" min="0" name="quantity_kg" class="form-control" value="<?php echo htmlspecialchars($listing['quantity_kg']); ?>" required>
                </div>

                <div class="form-group">
                    <label>Price per kg (LKR):</label>
                    <input type="number" step="0.01" min="0.01" name="price_per_kg" class="form-control" value="<?php echo htmlspecialchars($listing['price_per_kg']); ?>" required>
                </div>

                <div class="form-group">
                    <label>Listing Status:</label>
                    <select name="status" class="form-control" required>
                        <?php foreach (['Available','Pending','Sold Out'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php if ($listing['status'] == $s) echo 'selected'; ?>><?php echo $s; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Replace Produce Photo <small style="color:#666;">(optional — JPG/PNG/WebP, max 3MB)</small>:</label>
                    <div class="file-input-wrapper">
                        <input type="file" name="food_image" accept="image/jpeg,image/png,image/webp,image/gif">
                    </div>
                </div>

                <div style="display:flex; gap:12px; margin-top:15px;">
                    <button type="submit" class="btn btn-success" style="flex:1;">💾 Save Changes</button>
                    <a href="farmer.php" class="btn btn-primary" style="flex:1; text-decoration:none; text-align:center; line-height:1.6;">✕ Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>