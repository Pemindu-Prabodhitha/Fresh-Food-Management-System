<?php
$servername = "sql206.infinityfree.com";
$username = "if0_42447735";
$password = "yz8AlA3SWOgeqqT";
$dbname = "if0_42447735_fresh_ceylon";

$con = mysqli_connect($servername,$username,$password);

if(!$con){
    die("Connection failed: ".mysqli_connect_error());
}
if(!mysqli_select_db($con,$dbname)){
    die("Database selection failed: ".mysqli_error($con));
}
?>