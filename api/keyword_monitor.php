<?php
/**
 * 关键词监控管理 API
 */

require_once '../config.php';
checkLogin();

$db = getDB();

// GET 请求 - 获取单个监控规则
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action == 'get') {
        $id = $_GET['id'] ?? 0;
        
        if (!$id) {
            jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
        }
        
        try {
            $stmt = $db->prepare("SELECT * FROM keyword_monitor WHERE id = ?");
            $stmt->execute([$id]);
            $monitor = $stmt->fetch();
            
            if (!$monitor) {
                jsonResponse(['success' => false, 'message' => '监控规则不存在'], 404);
            }
            
            jsonResponse(['success' => true, 'data' => $monitor]);
        } catch (Exception $e) {
            logSystem('error', 'Fetch keyword monitor error', $e->getMessage());
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
            $keyword = $data['keyword'] ?? '';
            $match_type = $data['match_type'] ?? 'contains';
            $monitor_mode = $data['monitor_mode'] ?? 'bot';
            $notify_user_id = $data['notify_user_id'] ?? '';
            $group_id = $data['group_id'] ?? null;
            $description = $data['description'] ?? null;
            $auto_reply_enabled = $data['auto_reply_enabled'] ?? 0;
            $auto_reply_message = $data['auto_reply_message'] ?? null;
            $use_user_account = $data['use_user_account'] ?? 0;
            $reply_delay = $data['reply_delay'] ?? 0;
            
            if (empty($keyword) || empty($notify_user_id)) {
                jsonResponse(['success' => false, 'message' => '请填写所有必填字段'], 400);
            }
            
            // 验证 User ID 格式（应该是纯数字）
            if (!is_numeric($notify_user_id)) {
                jsonResponse(['success' => false, 'message' => 'User ID 必须是数字'], 400);
            }
            
            try {
                $stmt = $db->prepare("INSERT INTO keyword_monitor (keyword, match_type, monitor_mode, notify_user_id, group_id, description, auto_reply_enabled, auto_reply_message, use_user_account, reply_delay) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$keyword, $match_type, $monitor_mode, $notify_user_id, $group_id, $description, $auto_reply_enabled, $auto_reply_message, $use_user_account, $reply_delay]);
                
                logSystem('info', 'Added keyword monitor', [
                    'keyword' => $keyword,
                    'notify_user_id' => $notify_user_id
                ]);
                jsonResponse(['success' => true, 'message' => '添加成功']);
            } catch (Exception $e) {
                logSystem('error', 'Add keyword monitor error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '添加失败: ' . $e->getMessage()], 500);
            }
            break;
            
        case 'update':
            $id = $data['id'] ?? 0;
            $keyword = $data['keyword'] ?? '';
            $match_type = $data['match_type'] ?? 'contains';
            $monitor_mode = $data['monitor_mode'] ?? 'bot';
            $notify_user_id = $data['notify_user_id'] ?? '';
            $group_id = $data['group_id'] ?? null;
            $description = $data['description'] ?? null;
            $auto_reply_enabled = $data['auto_reply_enabled'] ?? 0;
            $auto_reply_message = $data['auto_reply_message'] ?? null;
            $use_user_account = $data['use_user_account'] ?? 0;
            $reply_delay = $data['reply_delay'] ?? 0;
            
            if (!$id || empty($keyword) || empty($notify_user_id)) {
                jsonResponse(['success' => false, 'message' => '请填写所有必填字段'], 400);
            }
            
            // 验证 User ID 格式
            if (!is_numeric($notify_user_id)) {
                jsonResponse(['success' => false, 'message' => 'User ID 必须是数字'], 400);
            }
            
            try {
                $stmt = $db->prepare("UPDATE keyword_monitor SET keyword = ?, match_type = ?, monitor_mode = ?, notify_user_id = ?, group_id = ?, description = ?, auto_reply_enabled = ?, auto_reply_message = ?, use_user_account = ?, reply_delay = ? WHERE id = ?");
                $stmt->execute([$keyword, $match_type, $monitor_mode, $notify_user_id, $group_id, $description, $auto_reply_enabled, $auto_reply_message, $use_user_account, $reply_delay, $id]);
                
                logSystem('info', 'Updated keyword monitor', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '更新成功']);
            } catch (Exception $e) {
                logSystem('error', 'Update keyword monitor error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '更新失败: ' . $e->getMessage()], 500);
            }
            break;
            
        case 'delete':
            $id = $data['id'] ?? 0;
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            try {
                $stmt = $db->prepare("DELETE FROM keyword_monitor WHERE id = ?");
                $stmt->execute([$id]);
                
                logSystem('info', 'Deleted keyword monitor', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '删除成功']);
            } catch (Exception $e) {
                logSystem('error', 'Delete keyword monitor error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '删除失败'], 500);
            }
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
    }
}

