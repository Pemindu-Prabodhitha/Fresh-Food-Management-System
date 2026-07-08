<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "fresh_food_mgmt";

$con = mysqli_connect($servername,$username,$password);

if(!$con){
    die("Connection failed: ".mysqli_connect_error());
}
if(!mysqli_select_db($con,$dbname)){
    die("Database selection failed: ".mysqli_error($con));
}
?>