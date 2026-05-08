<?php
/**
 * 用户管理 API
 */
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

session_start();

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

try {
    // 管理员操作需要登录
    if (isset($_GET['action']) && in_array($_GET['action'], ['adjust_balance', 'toggle_block'])) {
        if (!isset($_SESSION['admin_id'])) {
            jsonResponse(['success' => false, 'message' => '未授权访问'], 401);
        }
    }
    
    switch ($_GET['action'] ?? 'list') {
        case 'adjust_balance':
            // 调整用户余额
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['user_id']) || empty($data['amount'])) {
                jsonResponse(['success' => false, 'message' => '参数不完整']);
            }
            
            $db->beginTransaction();
            
            try {
                // 获取用户当前余额
                $stmt = $db->prepare("SELECT balance, telegram_id FROM card_users WHERE id = ? FOR UPDATE");
                $stmt->execute([$data['user_id']]);
                $user = $stmt->fetch();
                
                if (!$user) {
                    throw new Exception('用户不存在');
                }
                
                $amount = floatval($data['amount']);
                if ($data['type'] == 'subtract') {
                    $amount = -$amount;
                }
                
                $new_balance = $user['balance'] + $amount;
                if ($new_balance < 0) {
                    throw new Exception('余额不足');
                }
                
                // 更新余额
                $stmt = $db->prepare("UPDATE card_users SET balance = ? WHERE id = ?");
                $stmt->execute([$new_balance, $data['user_id']]);
                
                // 记录变动
                $stmt = $db->prepare("
                    INSERT INTO balance_transactions 
                    (user_id, telegram_id, type, amount, balance_before, balance_after, description) 
                    VALUES (?, ?, 'admin_adjust', ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $data['user_id'],
                    $user['telegram_id'],
                    $amount,
                    $user['balance'],
                    $new_balance,
                    $data['remark'] ?? '管理员调整'
                ]);
                
                $db->commit();
                logSystem('info', '调整用户余额', ['user_id' => $data['user_id'], 'amount' => $amount]);
                jsonResponse(['success' => true, 'message' => '余额调整成功']);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;
            
        case 'toggle_block':
            // 封禁/解封用户
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['user_id'])) {
                jsonResponse(['success' => false, 'message' => '用户ID不能为空']);
            }
            
            $stmt = $db->prepare("UPDATE card_users SET is_blocked = ? WHERE id = ?");
            $stmt->execute([$data['is_blocked'], $data['user_id']]);
            
            logSystem('info', '更新用户状态', ['user_id' => $data['user_id'], 'blocked' => $data['is_blocked']]);
            jsonResponse(['success' => true, 'message' => '操作成功']);
            break;
            
        case 'get_or_create':
            // 获取或创建用户（用于机器人）
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['telegram_id'])) {
                jsonResponse(['success' => false, 'message' => 'Telegram ID不能为空']);
            }
            
            // 查找用户
            $stmt = $db->prepare("SELECT * FROM card_users WHERE telegram_id = ?");
            $stmt->execute([$data['telegram_id']]);
            $user = $stmt->fetch();
            
            if (!$user) {
                // 创建新用户
                $stmt = $db->prepare("
                    INSERT INTO card_users (telegram_id, username, first_name, last_name, language) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $data['telegram_id'],
                    $data['username'] ?? null,
                    $data['first_name'] ?? 'User',
                    $data['last_name'] ?? null,
                    $data['language'] ?? 'zh'
                ]);
                
                $user_id = $db->lastInsertId();
                $stmt = $db->prepare("SELECT * FROM card_users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
            } else {
                // 更新用户信息
                $stmt = $db->prepare("
                    UPDATE card_users 
                    SET username = ?, first_name = ?, last_name = ?, last_active = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['username'] ?? $user['username'],
                    $data['first_name'] ?? $user['first_name'],
                    $data['last_name'] ?? $user['last_name'],
                    $user['id']
                ]);
            }
            
            jsonResponse(['success' => true, 'data' => $user]);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '不支持的操作'], 400);
    }
} catch (Exception $e) {
    logSystem('error', '用户API错误', ['error' => $e->getMessage()]);
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}

