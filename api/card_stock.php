<?php
/**
 * 卡密库存管理 API
 */
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => '未授权访问'], 401);
}

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            
            // 检查是否是批量导入
            if (isset($_GET['action']) && $_GET['action'] == 'batch') {
                if (empty($data['product_id']) || empty($data['cards'])) {
                    jsonResponse(['success' => false, 'message' => '商品ID和卡密列表不能为空']);
                }
                
                $product_id = $data['product_id'];
                $cards = $data['cards'];
                $count = 0;
                
                $stmt = $db->prepare("INSERT INTO card_stock (product_id, card_content, status) VALUES (?, ?, 'available')");
                
                foreach ($cards as $card) {
                    $card = trim($card);
                    if ($card) {
                        $stmt->execute([$product_id, $card]);
                        $count++;
                    }
                }
                
                // 更新商品库存
                $update_stmt = $db->prepare("
                    UPDATE products SET stock = (
                        SELECT COUNT(*) FROM card_stock WHERE product_id = ? AND status = 'available'
                    ) WHERE id = ?
                ");
                $update_stmt->execute([$product_id, $product_id]);
                
                logSystem('info', '批量导入卡密', ['product_id' => $product_id, 'count' => $count]);
                jsonResponse(['success' => true, 'message' => "成功导入 {$count} 个卡密", 'count' => $count]);
            } else {
                // 单个添加
                if (empty($data['product_id']) || empty($data['card_content'])) {
                    jsonResponse(['success' => false, 'message' => '商品ID和卡密内容不能为空']);
                }
                
                $stmt = $db->prepare("INSERT INTO card_stock (product_id, card_content, status) VALUES (?, ?, 'available')");
                $stmt->execute([$data['product_id'], $data['card_content']]);
                
                // 更新商品库存
                $update_stmt = $db->prepare("
                    UPDATE products SET stock = (
                        SELECT COUNT(*) FROM card_stock WHERE product_id = ? AND status = 'available'
                    ) WHERE id = ?
                ");
                $update_stmt->execute([$data['product_id'], $data['product_id']]);
                
                logSystem('info', '添加卡密', ['product_id' => $data['product_id']]);
                jsonResponse(['success' => true, 'message' => '卡密添加成功', 'id' => $db->lastInsertId()]);
            }
            break;
            
        case 'DELETE':
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['id'])) {
                jsonResponse(['success' => false, 'message' => 'ID不能为空']);
            }
            
            // 获取卡密信息
            $stmt = $db->prepare("SELECT product_id, status FROM card_stock WHERE id = ?");
            $stmt->execute([$data['id']]);
            $card = $stmt->fetch();
            
            if (!$card) {
                jsonResponse(['success' => false, 'message' => '卡密不存在']);
            }
            
            if ($card['status'] != 'available') {
                jsonResponse(['success' => false, 'message' => '只能删除可用状态的卡密']);
            }
            
            // 删除卡密
            $stmt = $db->prepare("DELETE FROM card_stock WHERE id = ?");
            $stmt->execute([$data['id']]);
            
            // 更新商品库存
            $update_stmt = $db->prepare("
                UPDATE products SET stock = (
                    SELECT COUNT(*) FROM card_stock WHERE product_id = ? AND status = 'available'
                ) WHERE id = ?
            ");
            $update_stmt->execute([$card['product_id'], $card['product_id']]);
            
            logSystem('info', '删除卡密', ['id' => $data['id']]);
            jsonResponse(['success' => true, 'message' => '卡密删除成功']);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '不支持的请求方法'], 405);
    }
} catch (Exception $e) {
    logSystem('error', '卡密库存API错误', ['error' => $e->getMessage()]);
    jsonResponse(['success' => false, 'message' => '操作失败：' . $e->getMessage()], 500);
}

