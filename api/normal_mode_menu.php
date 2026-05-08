<?php
session_start();
require_once '../config.php';

header('Content-Type: application/json');

// 检查登录
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

$db = getDB();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $stmt = $db->prepare("INSERT INTO normal_mode_menu (button_text, button_emoji, action_type, action_value, row_number, column_number, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $input['button_text'],
                $input['button_emoji'] ?? null,
                $input['action_type'],
                $input['action_value'],
                $input['row_number'] ?? 1,
                $input['column_number'] ?? 1,
                $input['sort_order'] ?? 0,
                $input['is_active'] ?? 1
            ]);
            
            echo json_encode(['success' => true, 'message' => '添加成功']);
            break;
            
        case 'update':
            $stmt = $db->prepare("UPDATE normal_mode_menu SET button_text = ?, button_emoji = ?, action_type = ?, action_value = ?, row_number = ?, column_number = ?, sort_order = ?, is_active = ? WHERE id = ?");
            $stmt->execute([
                $input['button_text'],
                $input['button_emoji'] ?? null,
                $input['action_type'],
                $input['action_value'],
                $input['row_number'] ?? 1,
                $input['column_number'] ?? 1,
                $input['sort_order'] ?? 0,
                $input['is_active'] ?? 1,
                $input['id']
            ]);
            
            echo json_encode(['success' => true, 'message' => '更新成功']);
            break;
            
        case 'delete':
            $stmt = $db->prepare("DELETE FROM normal_mode_menu WHERE id = ?");
            $stmt->execute([$input['id']]);
            
            echo json_encode(['success' => true, 'message' => '删除成功']);
            break;
            
        case 'toggle':
            $stmt = $db->prepare("UPDATE normal_mode_menu SET is_active = ? WHERE id = ?");
            $stmt->execute([$input['is_active'], $input['id']]);
            
            echo json_encode(['success' => true, 'message' => '状态已更新']);
            break;
            
        case 'get_all':
            $stmt = $db->query("SELECT * FROM normal_mode_menu ORDER BY row_number ASC, column_number ASC, sort_order ASC");
            $buttons = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $buttons]);
            break;
            
        default:
            throw new Exception('未知操作');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

