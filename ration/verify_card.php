<?php
header('Content-Type: application/json');

// الاتصال بقاعدة البيانات
require_once 'db_connection.php';

// التأكد من أن الطريقة POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);

    // الحالة الأولى: جلب بيانات المستخدم الأولية والمنتجات (يتم إرسال card_number فقط)
    if (isset($input['card_number']) && !isset($input['products'])) {
        $card_number = $input['card_number'];

        $stmt = $conn->prepare("SELECT
            c.name,
            c.monthQuotaPoints AS month_quota_points,
            (SELECT COUNT(*) FROM FamilyMember fm WHERE fm.citizenID = rc.citizenID) AS family_members
        FROM RationCard rc
        JOIN Citizen c ON rc.citizenID = c.citizenID
        WHERE rc.cardNumber = ?");
        $stmt->bind_param("s", $card_number);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $userData = $result->fetch_assoc();

            $sql_products = "SELECT product_id, product_name FROM Inventory";
            $result_products = $conn->query($sql_products);
            $products = [];
            if ($result_products->num_rows > 0) {
                while ($row_product = $result_products->fetch_assoc()) {
                    $products[] = [
                        "product_id" => $row_product['product_id'],
                        "product_name" => $row_product['product_name']
                    ];
                }
                echo json_encode(["status" => "success", "userData" => $userData, "products" => $products]);
            } else {
                echo json_encode(["status" => "success", "userData" => $userData, "products" => []]); // لا توجد منتجات
            }
        } else {
            echo json_encode(["status" => "error", "message" => "Card not found."]);
        }
        $stmt->close();
    }
    // الحالة الثانية: معالجة وتأكيد الطلب (يتم إرسال card_number ومصفوفة products)
    elseif (isset($input['card_number']) && isset($input['products'])) {
        $card_number = $input['card_number'];
        $products = $input['products'];
        $total_price = 0;

        // التحقق من وجود البطاقة
        $stmt_check_card = $conn->prepare("SELECT citizenID FROM rationcard WHERE card_number = ?");
        $stmt_check_card->bind_param("s", $card_number);
        $stmt_check_card->execute();
        $result_check_card = $stmt_check_card->get_result();

        if ($result_check_card->num_rows === 0) {
            echo json_encode(["status" => "error", "message" => "Card not found."]);
            exit;
        }
        $citizenID = $result_check_card->fetch_assoc()['citizenID'];
        $stmt_check_card->close();

        // بدء عملية المعاملة
        $conn->begin_transaction();
        $transaction_successful = true;

        // حساب إجمالي السعر والتحقق من المخزون وإضافة المنتجات للمعاملة
        foreach ($products as $product) {
            $product_id = $product['product_id'];
            $qty = $product['qty'];

            // جلب معلومات المنتج من جدول Inventory
            $stmt_item = $conn->prepare("SELECT price, stock FROM Inventory WHERE product_id = ?");
            $stmt_item->bind_param("s", $product_id);
            $stmt_item->execute();
            $result_item = $stmt_item->get_result();

            if ($result_item->num_rows === 1) {
                $item_data = $result_item->fetch_assoc();
                $price_per_unit = $item_data['price'];
                $current_stock = intval(explode(' ', $item_data['stock'])[0]);
                $unit = trim(explode(' ', $item_data['stock'])[1]);

                if ($current_stock >= $qty) {
                    $total_price += $qty * $price_per_unit;
                    $new_stock = $current_stock - $qty;
                    $stmt_update_stock = $conn->prepare("UPDATE Inventory SET stock = ? WHERE product_id = ?");
                    $stmt_update_stock->bind_param("ss", $new_stock . ' ' . $unit, $product_id);
                    if (!$stmt_update_stock->execute()) {
                        $transaction_successful = false;
                        break;
                    }
                    $stmt_update_stock->close();
                } else {
                    $transaction_successful = false;
                    $conn->rollback();
                    echo json_encode(["status" => "error", "message" => "Insufficient stock for product: " . $product['product_name']]);
                    exit;
                }
            } else {
                $transaction_successful = false;
                $conn->rollback();
                echo json_encode(["status" => "error", "message" => "Product not found: " . $product_id]);
                exit;
            }
            $stmt_item->close();
        }

        if ($transaction_successful) {
            $stmt_transaction = $conn->prepare("INSERT INTO transactions (transactionDate, totalAmount, citizenID) VALUES (NOW(), ?, ?)");
            $stmt_transaction->bind_param("ds", $total_price, $citizenID);
            if ($stmt_transaction->execute()) {
                $transactionID = $conn->insert_id;
                $conn->commit();
                echo json_encode(["status" => "success", "message" => "Order confirmed.", "transaction_id" => $transactionID]);
            } else {
                $conn->rollback();
                echo json_encode(["status" => "error", "message" => "Failed to record transaction."]);
            }
            $stmt_transaction->close();
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid request data."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}

$conn->close();
?>