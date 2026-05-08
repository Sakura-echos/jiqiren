<?php
/**
 * 防洪水设置 API
 */

require_once '../config.php';
checkLogin();

$db = getDB();

// POST 请求
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'save':
            $group_id = $data['group_id'] ?? 0;
            $max_messages = $data['max_messages'] ?? 5;
            $time_window = $data['time_window'] ?? 5;
            $action_type = $data['action_type'] ?? 'warn';
            $mute_duration = $data['mute_duration'] ?? 300;
            
            if (!$group_id) {
                jsonResponse(['success' => false, 'message' => '无效的群组ID'], 400);
            }
            
            try {
                // 检查是否已存在设置
                $stmt = $db->prepare("SELECT id FROM antiflood_settings WHERE group_id = ?");
                $stmt->execute([$group_id]);
                $existing = $stmt->fetch();
                
                if ($existing) {
                    // 更新
                    $stmt = $db->prepare("UPDATE antiflood_settings SET max_messages = ?, time_window = ?, action = ?, mute_duration = ?, is_active = 1 WHERE group_id = ?");
                    $stmt->execute([$max_messages, $time_window, $action_type, $mute_duration, $group_id]);
                } else {
                    // 插入
                    $stmt = $db->prepare("INSERT INTO antiflood_settings (group_id, max_messages, time_window, action, mute_duration) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$group_id, $max_messages, $time_window, $action_type, $mute_duration]);
                }
                
                logSystem('info', 'Updated antiflood settings', ['group_id' => $group_id]);
                jsonResponse(['success' => true, 'message' => '保存成功']);
            } catch (Exception $e) {
                logSystem('error', 'Save antiflood settings error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '保存失败'], 500);
            }
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
    }
}

