<?php
/**
 * 群组管理 API
 */

require_once '../config.php';
require_once '../bot/TelegramBot.php';
checkLogin();

$db = getDB();
$bot = new TelegramBot(BOT_TOKEN);

// 确保分类表存在
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS group_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            color VARCHAR(20) DEFAULT '#3498db',
            sort_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // 检查groups表是否有category_id字段，没有则添加
    $stmt = $db->query("SHOW COLUMNS FROM groups LIKE 'category_id'");
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE groups ADD COLUMN category_id INT DEFAULT NULL");
        $db->exec("ALTER TABLE groups ADD INDEX idx_category_id (category_id)");
    }
} catch (Exception $e) {
    error_log("Init group_categories table error: " . $e->getMessage());
}

// GET 请求 - 列表
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    if ($action == 'list') {
        try {
            $stmt = $db->query("
                SELECT g.*, 
                    COALESCE(g.source, 'bot') as source,
                    gc.name as category_name,
                    gc.color as category_color
                FROM groups g
                LEFT JOIN group_categories gc ON g.category_id = gc.id
                ORDER BY g.id DESC
            ");
            $groups = $stmt->fetchAll();
            
            jsonResponse(['success' => true, 'data' => $groups]);
        } catch (Exception $e) {
            logSystem('error', 'Fetch groups error', $e->getMessage());
            jsonResponse(['success' => false, 'message' => '获取失败'], 500);
        }
    }
}

// POST 请求
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'leave':
            $id = $data['id'] ?? 0;
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            try {
                $stmt = $db->prepare("SELECT chat_id FROM groups WHERE id = ?");
                $stmt->execute([$id]);
                $group = $stmt->fetch();
                
                if (!$group) {
                    jsonResponse(['success' => false, 'message' => '群组不存在'], 404);
                }
                
                // 让Bot离开群组
                $bot->leaveChat($group['chat_id']);
                
                // 更新数据库状态
                $stmt = $db->prepare("UPDATE groups SET is_active = 0 WHERE id = ?");
                $stmt->execute([$id]);
                
                logSystem('info', 'Left group', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '已退出群组']);
            } catch (Exception $e) {
                logSystem('error', 'Leave group error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '操作失败'], 500);
            }
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
    }
}

