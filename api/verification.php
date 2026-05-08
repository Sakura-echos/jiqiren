<?php
/**
 * 进群验证管理 API
 */

require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

// 确保验证设置表有新字段
function ensureVerificationColumns($db) {
    $columns = [
        'require_channel' => "ALTER TABLE verification_settings ADD COLUMN require_channel TINYINT(1) DEFAULT 0",
        'required_channel' => "ALTER TABLE verification_settings ADD COLUMN required_channel VARCHAR(255) DEFAULT NULL",
        'channel_btn_text' => "ALTER TABLE verification_settings ADD COLUMN channel_btn_text VARCHAR(255) DEFAULT '第一步 【请先订阅频道】🙋‍♀️'",
        'verify_btn_text' => "ALTER TABLE verification_settings ADD COLUMN verify_btn_text VARCHAR(255) DEFAULT '第二步 【点击解除禁言】👍'"
    ];
    
    foreach ($columns as $column => $sql) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM verification_settings LIKE '$column'");
            if ($stmt->rowCount() == 0) {
                $db->exec($sql);
            }
        } catch (Exception $e) {
            // 忽略错误
        }
    }
}

session_start();
if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => '未授权访问'], 401);
}

$db = getDB();

// GET 请求 - 获取单个验证设置
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action == 'get') {
        $id = $_GET['id'] ?? 0;
        
        if (!$id) {
            jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
        }
        
        try {
            $stmt = $db->prepare("SELECT * FROM verification_settings WHERE id = ?");
            $stmt->execute([$id]);
            $verification = $stmt->fetch();
            
            if (!$verification) {
                jsonResponse(['success' => false, 'message' => '验证设置不存在'], 404);
            }
            
            jsonResponse(['success' => true, 'data' => $verification]);
        } catch (Exception $e) {
            logSystem('error', 'Fetch verification settings error', $e->getMessage());
            jsonResponse(['success' => false, 'message' => '获取失败'], 500);
        }
    }
}

// POST 请求
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'add':
            $group_id = $data['group_id'] ?? 0;
            $verification_type = $data['verification_type'] ?? 'button';
            $timeout_seconds = $data['timeout_seconds'] ?? 60;
            $kick_on_fail = $data['kick_on_fail'] ?? 1;
            $welcome_after_verify = $data['welcome_after_verify'] ?? 1;
            $verification_message = $data['verification_message'] ?? null;
            $require_channel = $data['require_channel'] ?? 0;
            $required_channel = $data['required_channel'] ?? null;
            $channel_btn_text = $data['channel_btn_text'] ?? '第一步 【请先订阅频道】🙋‍♀️';
            $verify_btn_text = $data['verify_btn_text'] ?? '第二步 【点击解除禁言】👍';
            
            if (empty($verification_type) || empty($timeout_seconds)) {
                jsonResponse(['success' => false, 'message' => '请填写所有必填字段'], 400);
            }
            
            // Convert 0 to NULL for "all groups"
            $final_group_id = ($group_id == 0) ? null : $group_id;
            
            try {
                // 确保新字段存在
                ensureVerificationColumns($db);
                
                // 先禁用该群组的其他验证设置
                if ($final_group_id === null) {
                    $stmt = $db->prepare("UPDATE verification_settings SET is_active = 0 WHERE group_id IS NULL");
                    $stmt->execute();
                } else {
                    $stmt = $db->prepare("UPDATE verification_settings SET is_active = 0 WHERE group_id = ?");
                    $stmt->execute([$final_group_id]);
                }
                
                // 添加新的验证设置
                $stmt = $db->prepare("INSERT INTO verification_settings (group_id, verification_type, timeout_seconds, kick_on_fail, welcome_after_verify, verification_message, require_channel, required_channel, channel_btn_text, verify_btn_text) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$final_group_id, $verification_type, $timeout_seconds, $kick_on_fail, $welcome_after_verify, $verification_message, $require_channel, $required_channel, $channel_btn_text, $verify_btn_text]);
                
                logSystem('info', 'Added verification settings', ['group_id' => $group_id]);
                jsonResponse(['success' => true, 'message' => '添加成功']);
            } catch (Exception $e) {
                logSystem('error', 'Add verification settings error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '添加失败: ' . $e->getMessage()], 500);
            }
            break;
            
        case 'update':
            $id = $data['id'] ?? 0;
            $group_id = $data['group_id'] ?? 0;
            $verification_type = $data['verification_type'] ?? 'button';
            $timeout_seconds = $data['timeout_seconds'] ?? 60;
            $kick_on_fail = $data['kick_on_fail'] ?? 1;
            $welcome_after_verify = $data['welcome_after_verify'] ?? 1;
            $verification_message = $data['verification_message'] ?? null;
            $require_channel = $data['require_channel'] ?? 0;
            $required_channel = $data['required_channel'] ?? null;
            $channel_btn_text = $data['channel_btn_text'] ?? '第一步 【请先订阅频道】🙋‍♀️';
            $verify_btn_text = $data['verify_btn_text'] ?? '第二步 【点击解除禁言】👍';
            
            if (!$id || empty($verification_type) || empty($timeout_seconds)) {
                jsonResponse(['success' => false, 'message' => '请填写所有必填字段'], 400);
            }
            
            // Convert 0 to NULL for "all groups"
            $final_group_id = ($group_id == 0) ? null : $group_id;
            
            try {
                // 确保新字段存在
                ensureVerificationColumns($db);
                
                // 先禁用该群组的其他验证设置
                if ($final_group_id === null) {
                    $stmt = $db->prepare("UPDATE verification_settings SET is_active = 0 WHERE group_id IS NULL AND id != ?");
                    $stmt->execute([$id]);
                } else {
                    $stmt = $db->prepare("UPDATE verification_settings SET is_active = 0 WHERE group_id = ? AND id != ?");
                    $stmt->execute([$final_group_id, $id]);
                }
                
                // 更新验证设置
                $stmt = $db->prepare("UPDATE verification_settings SET group_id = ?, verification_type = ?, timeout_seconds = ?, kick_on_fail = ?, welcome_after_verify = ?, verification_message = ?, require_channel = ?, required_channel = ?, channel_btn_text = ?, verify_btn_text = ? WHERE id = ?");
                $stmt->execute([$final_group_id, $verification_type, $timeout_seconds, $kick_on_fail, $welcome_after_verify, $verification_message, $require_channel, $required_channel, $channel_btn_text, $verify_btn_text, $id]);
                
                logSystem('info', 'Updated verification settings', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '更新成功']);
            } catch (Exception $e) {
                logSystem('error', 'Update verification settings error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '更新失败: ' . $e->getMessage()], 500);
            }
            break;
            
        case 'delete':
            $id = $data['id'] ?? 0;
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            try {
                $stmt = $db->prepare("DELETE FROM verification_settings WHERE id = ?");
                $stmt->execute([$id]);
                
                logSystem('info', 'Deleted verification settings', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '删除成功']);
            } catch (Exception $e) {
                logSystem('error', 'Delete verification settings error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '删除失败'], 500);
            }
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
    }
}

