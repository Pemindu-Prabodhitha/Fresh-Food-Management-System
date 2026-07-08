<?php
$servername = "localhost";
$username = "root";
$password = "";

$con = mysqli_connect($servername,$username,$password);

if(!$con){
    die("Connection failed: ".mysqli_connect_error());
}
else{
    echo "Connected mysql server successfully.<br>";
}

$dbname = "fresh_food_mgmt";
$sql_createdb = "CREATE DATABASE IF NOT EXISTS $dbname";

if(mysqli_query($con,$sql_createdb)){
    echo "Database created successfully.<br>";
}
else{
    die("Error creating database: ".mysqli_error($con));
}
mysqli_select_db($con,$dbname);

$sql_createtable = "CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('Farmer', 'Sales', 'Transporter', 'Admin') NOT NULL,
    location_city VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
if(mysqli_query($con,$sql_createtable)){
    echo "Table users created successfully.<br>";
}
else{
    die("Error creating table users: ".mysqli_error($con));
}

$sql_createtable_listings = "CREATE TABLE IF NOT EXISTS food_listings (
    listing_id INT AUTO_INCREMENT PRIMARY KEY,
    farmer_id INT,
    food_name VARCHAR(100) NOT NULL,
    food_type VARCHAR(50) NOT NULL, 
    quantity_kg DECIMAL(10, 2) NOT NULL,
    price_per_kg DECIMAL(10, 2) NOT NULL,
    status ENUM('Available', 'Pending', 'Sold Out') DEFAULT 'Available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (farmer_id) REFERENCES users(user_id) ON DELETE CASCADE
)";
if(mysqli_query($con,$sql_createtable_listings)){
    echo "Table listings created successfully.<br>";
}
else{
    die("Error creating listings table: ".mysqli_error($con));
}

$sql_createtable_transporter_service_areas = "CREATE TABLE IF NOT EXISTS transporter_service_areas (
    service_id INT AUTO_INCREMENT PRIMARY KEY,
    transporter_id INT,
    covered_city VARCHAR(100) NOT NULL,
    FOREIGN KEY (transporter_id) REFERENCES users(user_id) ON DELETE CASCADE
)";
if(mysqli_query($con,$sql_createtable_transporter_service_areas)){
    echo "Table transporter_service_areas created successfully.<br>";
}
else{
    die("Error creating transporter_service_areas table: ".mysqli_error($con));
}

$sql_createtable_orders = "CREATE TABLE IF NOT EXISTS orders (
    order_id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT,
    sales_id INT,
    transporter_id INT NULL,
    quantity_ordered DECIMAL(10, 2) NOT NULL,
    total_price DECIMAL(10, 2) NOT NULL,
    order_status ENUM('Pending Approval', 'Approved', 'In Transit', 'Delivered', 'Cancelled') DEFAULT 'Pending Approval',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (listing_id) REFERENCES food_listings(listing_id),
    FOREIGN KEY (sales_id) REFERENCES users(user_id),
    FOREIGN KEY (transporter_id) REFERENCES users(user_id)
)";
if(mysqli_query($con,$sql_createtable_orders)){
    echo "Table orders created successfully.<br>";
}
else{
    die("Error creating orders table: ".mysqli_error($con));
}

$sql_createtable_notifications = "CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
)";
if(mysqli_query($con,$sql_createtable_notifications)){
    echo "Table notifications created successfully.<br>";
}
else{
    die("Error creating table notifications: ".mysqli_error($con));
}

$sql_createtable_ratings = "CREATE TABLE IF NOT EXISTS ratings (
    rating_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    reviewer_id INT NOT NULL, 
    reviewee_id INT NOT NULL,  
    score INT NOT NULL CHECK (score BETWEEN 1 AND 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (reviewee_id) REFERENCES users(user_id) ON DELETE CASCADE
)";
if(mysqli_query($con,$sql_createtable_ratings)){
    echo "Table ratings created successfully.<br>";
}
else{
    die("Error creating table ratings: ".mysqli_error($con));
}
?>