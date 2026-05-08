<?php
/**
 * 群组分类管理 API
 */

require_once '../config.php';
checkLogin();

$db = getDB();

// 初始化分类表（如果不存在）
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

// GET 请求 - 列表或获取单个
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    if ($action == 'list') {
        try {
            $stmt = $db->query("
                SELECT gc.*, 
                    (SELECT COUNT(*) FROM groups g WHERE g.category_id = gc.id AND g.is_active = 1 AND g.is_deleted = 0) as group_count
                FROM group_categories gc 
                ORDER BY gc.sort_order ASC, gc.id ASC
            ");
            $categories = $stmt->fetchAll();
            
            jsonResponse(['success' => true, 'data' => $categories]);
        } catch (Exception $e) {
            logSystem('error', 'Fetch group categories error', $e->getMessage());
            jsonResponse(['success' => false, 'message' => '获取失败'], 500);
        }
    } elseif ($action == 'get') {
        $id = $_GET['id'] ?? 0;
        
        if (!$id) {
            jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
        }
        
        try {
            $stmt = $db->prepare("SELECT * FROM group_categories WHERE id = ?");
            $stmt->execute([$id]);
            $category = $stmt->fetch();
            
            if (!$category) {
                jsonResponse(['success' => false, 'message' => '分类不存在'], 404);
            }
            
            jsonResponse(['success' => true, 'data' => $category]);
        } catch (Exception $e) {
            logSystem('error', 'Fetch group category error', $e->getMessage());
            jsonResponse(['success' => false, 'message' => '获取失败'], 500);
        }
    } elseif ($action == 'list_with_groups') {
        // 获取分类列表及其包含的群组
        try {
            $categories = [];
            
            // 先获取所有分类
            $stmt = $db->query("SELECT * FROM group_categories WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
            $cats = $stmt->fetchAll();
            
            foreach ($cats as $cat) {
                // 获取该分类下的群组
                $groupStmt = $db->prepare("
                    SELECT id, title, chat_id 
                    FROM groups 
                    WHERE category_id = ? AND is_active = 1 AND is_deleted = 0 
                    ORDER BY title
                ");
                $groupStmt->execute([$cat['id']]);
                $groups = $groupStmt->fetchAll();
                
                $cat['groups'] = $groups;
                $categories[] = $cat;
            }
            
            // 添加"未分类"选项
            $uncategorizedStmt = $db->query("
                SELECT id, title, chat_id 
                FROM groups 
                WHERE (category_id IS NULL OR category_id = 0) AND is_active = 1 AND is_deleted = 0 
                ORDER BY title
            ");
            $uncategorizedGroups = $uncategorizedStmt->fetchAll();
            
            if (count($uncategorizedGroups) > 0) {
                $categories[] = [
                    'id' => 0,
                    'name' => '未分类',
                    'color' => '#95a5a6',
                    'groups' => $uncategorizedGroups
                ];
            }
            
            jsonResponse(['success' => true, 'data' => $categories]);
        } catch (Exception $e) {
            logSystem('error', 'Fetch categories with groups error', $e->getMessage());
            jsonResponse(['success' => false, 'message' => '获取失败'], 500);
        }
    }
}

// POST 请求 - 添加/删除/更新
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'add':
            $name = trim($data['name'] ?? '');
            $description = trim($data['description'] ?? '');
            $color = $data['color'] ?? '#3498db';
            $sort_order = intval($data['sort_order'] ?? 0);
            
            if (empty($name)) {
                jsonResponse(['success' => false, 'message' => '分类名称不能为空'], 400);
            }
            
            try {
                // 检查名称是否重复
                $stmt = $db->prepare("SELECT id FROM group_categories WHERE name = ?");
                $stmt->execute([$name]);
                if ($stmt->fetch()) {
                    jsonResponse(['success' => false, 'message' => '分类名称已存在'], 400);
                }
                
                $stmt = $db->prepare("INSERT INTO group_categories (name, description, color, sort_order) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $description, $color, $sort_order]);
                
                logSystem('info', 'Added group category', ['name' => $name]);
                jsonResponse(['success' => true, 'message' => '添加成功', 'id' => $db->lastInsertId()]);
            } catch (Exception $e) {
                logSystem('error', 'Add group category error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '添加失败'], 500);
            }
            break;
            
        case 'update':
            $id = $data['id'] ?? 0;
            $name = trim($data['name'] ?? '');
            $description = trim($data['description'] ?? '');
            $color = $data['color'] ?? '#3498db';
            $sort_order = intval($data['sort_order'] ?? 0);
            
            if (!$id || empty($name)) {
                jsonResponse(['success' => false, 'message' => '请填写必填字段'], 400);
            }
            
            try {
                // 检查名称是否重复（排除自己）
                $stmt = $db->prepare("SELECT id FROM group_categories WHERE name = ? AND id != ?");
                $stmt->execute([$name, $id]);
                if ($stmt->fetch()) {
                    jsonResponse(['success' => false, 'message' => '分类名称已存在'], 400);
                }
                
                $stmt = $db->prepare("UPDATE group_categories SET name = ?, description = ?, color = ?, sort_order = ? WHERE id = ?");
                $stmt->execute([$name, $description, $color, $sort_order, $id]);
                
                logSystem('info', 'Updated group category', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '更新成功']);
            } catch (Exception $e) {
                logSystem('error', 'Update group category error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '更新失败'], 500);
            }
            break;
            
        case 'delete':
            $id = $data['id'] ?? 0;
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            try {
                // 将该分类下的群组设为未分类
                $stmt = $db->prepare("UPDATE groups SET category_id = NULL WHERE category_id = ?");
                $stmt->execute([$id]);
                
                // 删除分类
                $stmt = $db->prepare("DELETE FROM group_categories WHERE id = ?");
                $stmt->execute([$id]);
                
                logSystem('info', 'Deleted group category', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '删除成功']);
            } catch (Exception $e) {
                logSystem('error', 'Delete group category error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '删除失败'], 500);
            }
            break;
            
        case 'toggle':
            $id = $data['id'] ?? 0;
            $is_active = $data['is_active'] ?? 1;
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            try {
                $stmt = $db->prepare("UPDATE group_categories SET is_active = ? WHERE id = ?");
                $stmt->execute([$is_active, $id]);
                
                jsonResponse(['success' => true, 'message' => '状态更新成功']);
            } catch (Exception $e) {
                logSystem('error', 'Toggle group category error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '更新失败'], 500);
            }
            break;
            
        case 'set_group_category':
            // 设置群组的分类
            $group_id = $data['group_id'] ?? 0;
            $category_id = $data['category_id'] ?? null;
            
            if (!$group_id) {
                jsonResponse(['success' => false, 'message' => '无效的群组ID'], 400);
            }
            
            try {
                $stmt = $db->prepare("UPDATE groups SET category_id = ? WHERE id = ?");
                $stmt->execute([$category_id ?: null, $group_id]);
                
                logSystem('info', 'Set group category', ['group_id' => $group_id, 'category_id' => $category_id]);
                jsonResponse(['success' => true, 'message' => '分类设置成功']);
            } catch (Exception $e) {
                logSystem('error', 'Set group category error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '设置失败'], 500);
            }
            break;
            
        case 'batch_set_category':
            // 批量设置群组分类
            $group_ids = $data['group_ids'] ?? [];
            $category_id = $data['category_id'] ?? null;
            
            if (empty($group_ids)) {
                jsonResponse(['success' => false, 'message' => '请选择要设置的群组'], 400);
            }
            
            try {
                $placeholders = implode(',', array_fill(0, count($group_ids), '?'));
                $params = array_merge([$category_id ?: null], $group_ids);
                
                $stmt = $db->prepare("UPDATE groups SET category_id = ? WHERE id IN ($placeholders)");
                $stmt->execute($params);
                
                $count = $stmt->rowCount();
                logSystem('info', 'Batch set group category', ['group_ids' => $group_ids, 'category_id' => $category_id]);
                jsonResponse(['success' => true, 'message' => "成功设置 {$count} 个群组的分类"]);
            } catch (Exception $e) {
                logSystem('error', 'Batch set group category error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '批量设置失败'], 500);
            }
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
    }
}

