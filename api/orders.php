<?php
/**
 * 订单管理 API
 */
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => '未授权访问'], 401);
}

$db = getDB();
$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'cancel':
            // 取消订单
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['id'])) {
                jsonResponse(['success' => false, 'message' => '订单ID不能为空']);
            }
            
            $stmt = $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND status = 'pending'");
            $stmt->execute([$data['id']]);
            
            if ($stmt->rowCount() == 0) {
                jsonResponse(['success' => false, 'message' => '订单不存在或状态不允许取消']);
            }
            
            logSystem('info', '取消订单', ['order_id' => $data['id']]);
            jsonResponse(['success' => true, 'message' => '订单已取消']);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '不支持的操作'], 400);
    }
} catch (Exception $e) {
    logSystem('error', '订单API错误', ['error' => $e->getMessage()]);
    jsonResponse(['success' => false, 'message' => '操作失败：' . $e->getMessage()], 500);
}

