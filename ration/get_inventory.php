<?php
header('Content-Type: application/json');
require_once 'db_connection.php';

$sql = "SELECT ID, product_id, product_name, price, stock FROM inventory";
$result = $conn->query($sql);

$inventory = [];

while ($row = $result->fetch_assoc()) {
    $inventory[] = [
        "id" => $row['ID'],
        "product_id" => $row['product_id'],
        "product_name" => $row['product_name'],
        "price" => $row['price'],
        "stock" => $row['stock'],
        "status" => (intval(explode(' ', $row['stock'])[0]) <= 20) ? 'Lack' : 'Good' // تحديث حالة المخزون بناءً على الكمية
    ];
}

echo json_encode([
    "status" => "success",
    "data" => $inventory
]);
?>