<?php
/**
 * 导航按钮 API
 */

require_once '../config.php';
checkLogin();

$db = getDB();

// 确保表存在
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `navigation_buttons` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `parent_id` int(11) DEFAULT NULL COMMENT '父级ID，NULL表示一级导航',
        `text` varchar(100) NOT NULL COMMENT '按钮文字',
        `url` varchar(500) DEFAULT NULL COMMENT '跳转链接',
        `row_num` int(11) DEFAULT 1 COMMENT '所在行号',
        `sort_order` int(11) DEFAULT 0 COMMENT '排序',
        `is_active` tinyint(1) DEFAULT 1,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_parent_id` (`parent_id`),
        INDEX `idx_sort` (`sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    error_log("Create navigation_buttons table error: " . $e->getMessage());
}

// 获取请求数据
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        // 获取所有按钮
        try {
            $stmt = $db->query("SELECT * FROM navigation_buttons WHERE is_active = 1 ORDER BY row_num ASC, sort_order ASC");
            $buttons = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(['success' => true, 'buttons' => $buttons]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    case 'add':
        // 添加按钮
        $text = trim($input['text'] ?? '');
        $url = trim($input['url'] ?? '');
        $parent_id = $input['parent_id'] ?? null;
        $row_num = intval($input['row_num'] ?? 1);
        $sort_order = intval($input['sort_order'] ?? 0);
        
        if (empty($text)) {
            jsonResponse(['success' => false, 'message' => '按钮文字不能为空']);
        }
        
        try {
            $stmt = $db->prepare("INSERT INTO navigation_buttons (parent_id, text, url, row_num, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$parent_id, $text, $url, $row_num, $sort_order]);
            $id = $db->lastInsertId();
            
            jsonResponse(['success' => true, 'id' => $id]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    case 'update':
        // 更新按钮
        $id = intval($input['id'] ?? 0);
        $text = trim($input['text'] ?? '');
        $url = trim($input['url'] ?? '');
        $row_num = intval($input['row_num'] ?? 1);
        $sort_order = intval($input['sort_order'] ?? 0);
        
        if (!$id) {
            jsonResponse(['success' => false, 'message' => '按钮ID不能为空']);
        }
        
        try {
            $stmt = $db->prepare("UPDATE navigation_buttons SET text = ?, url = ?, row_num = ?, sort_order = ? WHERE id = ?");
            $stmt->execute([$text, $url, $row_num, $sort_order, $id]);
            
            jsonResponse(['success' => true]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    case 'delete':
        // 删除按钮
        $id = intval($input['id'] ?? 0);
        
        if (!$id) {
            jsonResponse(['success' => false, 'message' => '按钮ID不能为空']);
        }
        
        try {
            // 删除子按钮
            $stmt = $db->prepare("UPDATE navigation_buttons SET is_active = 0 WHERE parent_id = ?");
            $stmt->execute([$id]);
            
            // 删除按钮
            $stmt = $db->prepare("UPDATE navigation_buttons SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            
            jsonResponse(['success' => true]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    case 'save_all':
        // 保存所有按钮
        $buttons = $input['buttons'] ?? [];
        
        try {
            $db->beginTransaction();
            
            // 先将所有按钮设为不活跃
            $db->exec("UPDATE navigation_buttons SET is_active = 0");
            
            // 创建ID映射（新ID -> 数据库ID）
            $idMap = [];
            
            // 第一遍：保存所有一级按钮
            foreach ($buttons as $idx => $btn) {
                if (!empty($btn['parent_id'])) continue; // 跳过子按钮
                
                $text = trim($btn['text'] ?? '');
                $url = trim($btn['url'] ?? '');
                $row_num = intval($btn['row_num'] ?? 1);
                $sort_order = intval($btn['sort_order'] ?? $idx);
                $oldId = $btn['id'] ?? null;
                
                if (empty($text)) continue;
                
                if ($oldId && is_numeric($oldId)) {
                    // 更新现有按钮
                    $stmt = $db->prepare("UPDATE navigation_buttons SET text = ?, url = ?, row_num = ?, sort_order = ?, is_active = 1, parent_id = NULL WHERE id = ?");
                    $stmt->execute([$text, $url, $row_num, $sort_order, $oldId]);
                    $idMap[$oldId] = $oldId;
                } else {
                    // 插入新按钮
                    $stmt = $db->prepare("INSERT INTO navigation_buttons (text, url, row_num, sort_order, is_active) VALUES (?, ?, ?, ?, 1)");
                    $stmt->execute([$text, $url, $row_num, $sort_order]);
                    $newId = $db->lastInsertId();
                    if ($oldId) {
                        $idMap[$oldId] = $newId;
                    }
                }
            }
            
            // 第二遍：保存所有子按钮
            foreach ($buttons as $idx => $btn) {
                if (empty($btn['parent_id'])) continue; // 跳过一级按钮
                
                $text = trim($btn['text'] ?? '');
                $url = trim($btn['url'] ?? '');
                $sort_order = intval($btn['sort_order'] ?? $idx);
                $oldId = $btn['id'] ?? null;
                $parentId = $btn['parent_id'];
                
                // 映射父级ID
                if (isset($idMap[$parentId])) {
                    $parentId = $idMap[$parentId];
                }
                
                if (empty($text)) continue;
                
                if ($oldId && is_numeric($oldId)) {
                    // 更新现有按钮
                    $stmt = $db->prepare("UPDATE navigation_buttons SET text = ?, url = ?, parent_id = ?, sort_order = ?, is_active = 1 WHERE id = ?");
                    $stmt->execute([$text, $url, $parentId, $sort_order, $oldId]);
                } else {
                    // 插入新按钮
                    $stmt = $db->prepare("INSERT INTO navigation_buttons (text, url, parent_id, row_num, sort_order, is_active) VALUES (?, ?, ?, 1, ?, 1)");
                    $stmt->execute([$text, $url, $parentId, $sort_order]);
                }
            }
            
            $db->commit();
            
            // 返回更新后的按钮列表
            $stmt = $db->query("SELECT * FROM navigation_buttons WHERE is_active = 1 ORDER BY row_num ASC, sort_order ASC");
            $newButtons = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse(['success' => true, 'buttons' => $newButtons]);
        } catch (PDOException $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    default:
        jsonResponse(['success' => false, 'message' => '未知操作']);
}
