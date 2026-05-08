<?php
/**
 * 支付方式管理 API
 */
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// 获取支付方式列表不需要登录（机器人使用）
if ($method != 'GET') {
    session_start();
    if (!isset($_SESSION['admin_id'])) {
        jsonResponse(['success' => false, 'message' => '未授权访问'], 401);
    }
}

try {
    switch ($method) {
        case 'GET':
            // 获取支付方式列表
            $active_only = isset($_GET['active_only']) && $_GET['active_only'] == '1';
            $sql = "SELECT * FROM payment_methods";
            if ($active_only) {
                $sql .= " WHERE is_active = 1";
            }
            $sql .= " ORDER BY sort_order ASC, id ASC";
            
            $stmt = $db->query($sql);
            $methods = $stmt->fetchAll();
            jsonResponse(['success' => true, 'data' => $methods]);
            break;
            
        case 'POST':
            // 添加支付方式
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['name']) || empty($data['type']) || empty($data['wallet_address'])) {
                jsonResponse(['success' => false, 'message' => '名称、类型和收款地址不能为空']);
            }
            
            $stmt = $db->prepare("
                INSERT INTO payment_methods 
                (name, type, icon, wallet_address, qr_code_url, network, min_amount, exchange_rate, instructions, sort_order, is_active, 
                 auto_verify, api_type, api_key, check_interval, min_confirmations) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['name'],
                $data['type'],
                $data['icon'] ?? '💰',
                $data['wallet_address'],
                $data['qr_code_url'] ?? null,
                $data['network'] ?? null,
                $data['min_amount'] ?? 1.00,
                $data['exchange_rate'] ?? 1.0000,
                $data['instructions'] ?? null,
                $data['sort_order'] ?? 0,
                $data['is_active'] ?? 1,
                $data['auto_verify'] ?? 0,
                $data['api_type'] ?? null,
                $data['api_key'] ?? null,
                $data['check_interval'] ?? 300,
                $data['min_confirmations'] ?? 1
            ]);
            
            logSystem('info', '添加支付方式', ['name' => $data['name']]);
            jsonResponse(['success' => true, 'message' => '支付方式添加成功', 'id' => $db->lastInsertId()]);
            break;
            
        case 'PUT':
            // 更新支付方式
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['id'])) {
                jsonResponse(['success' => false, 'message' => 'ID不能为空']);
            }
            
            $stmt = $db->prepare("
                UPDATE payment_methods SET 
                name = ?, type = ?, icon = ?, wallet_address = ?, qr_code_url = ?, 
                network = ?, min_amount = ?, exchange_rate = ?, instructions = ?, 
                sort_order = ?, is_active = ?,
                auto_verify = ?, api_type = ?, api_key = ?, check_interval = ?, min_confirmations = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['name'],
                $data['type'],
                $data['icon'] ?? '💰',
                $data['wallet_address'],
                $data['qr_code_url'] ?? null,
                $data['network'] ?? null,
                $data['min_amount'] ?? 1.00,
                $data['exchange_rate'] ?? 1.0000,
                $data['instructions'] ?? null,
                $data['sort_order'] ?? 0,
                $data['is_active'] ?? 1,
                $data['auto_verify'] ?? 0,
                $data['api_type'] ?? null,
                $data['api_key'] ?? null,
                $data['check_interval'] ?? 300,
                $data['min_confirmations'] ?? 1,
                $data['id']
            ]);
            
            logSystem('info', '更新支付方式', ['id' => $data['id']]);
            jsonResponse(['success' => true, 'message' => '支付方式更新成功']);
            break;
            
        case 'DELETE':
            // 删除支付方式
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['id'])) {
                jsonResponse(['success' => false, 'message' => 'ID不能为空']);
            }
            
            $stmt = $db->prepare("DELETE FROM payment_methods WHERE id = ?");
            $stmt->execute([$data['id']]);
            
            logSystem('info', '删除支付方式', ['id' => $data['id']]);
            jsonResponse(['success' => true, 'message' => '支付方式删除成功']);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '不支持的请求方法'], 405);
    }
} catch (Exception $e) {
    logSystem('error', '支付方式API错误', ['error' => $e->getMessage()]);
    jsonResponse(['success' => false, 'message' => '操作失败：' . $e->getMessage()], 500);
}

