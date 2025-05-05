<?php
$host = "localhost";
$username = "root";
$password = "";
$database = "rationsystem";

$conn = new mysqli($host, $username, $password, $database);

// التحقق من الاتصال
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
