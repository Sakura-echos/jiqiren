<?php
/**
 * 违禁词管理 API
 */

require_once '../config.php';
checkLogin();

$db = getDB();

// GET 请求 - 列表
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    if ($action == 'list') {
        try {
            $stmt = $db->query("
                SELECT bw.*, g.title as group_title 
                FROM banned_words bw 
                LEFT JOIN groups g ON bw.group_id = g.id 
                ORDER BY bw.id DESC
            ");
            $words = $stmt->fetchAll();
            
            jsonResponse(['success' => true, 'data' => $words]);
        } catch (Exception $e) {
            logSystem('error', 'Fetch banned words error', $e->getMessage());
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
            $word = $data['word'] ?? '';
            $group_id = $data['group_id'] ?? null;
            $delete_message = $data['delete_message'] ?? 1;
            $warn_user = $data['warn_user'] ?? 0;
            $kick_user = $data['kick_user'] ?? 0;
            $ban_user = $data['ban_user'] ?? 0;
            $match_type = $data['match_type'] ?? 'exact';
            
            // 验证匹配类型
            $valid_match_types = ['exact', 'contains', 'starts_with', 'ends_with', 'regex'];
            if (!in_array($match_type, $valid_match_types)) {
                jsonResponse(['success' => false, 'message' => '无效的匹配类型'], 400);
            }
            
            if (empty($word)) {
                jsonResponse(['success' => false, 'message' => '请输入违禁词'], 400);
            }
            
            if (!$delete_message && !$warn_user && !$kick_user && !$ban_user) {
                jsonResponse(['success' => false, 'message' => '请至少选择一个处理动作'], 400);
            }
            
            try {
                $stmt = $db->prepare("
                    INSERT INTO banned_words (
                        word, match_type, group_id, delete_message, warn_user, kick_user, ban_user
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?
                    )
                ");
                $stmt->execute([
                    $word, $match_type, $group_id, $delete_message, $warn_user, $kick_user, $ban_user
                ]);
                
                logSystem('info', 'Added banned word', ['word' => $word]);
                jsonResponse(['success' => true, 'message' => '添加成功']);
            } catch (Exception $e) {
                logSystem('error', 'Add banned word error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '添加失败'], 500);
            }
            break;
            
        case 'delete':
            $id = $data['id'] ?? 0;
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            try {
                $stmt = $db->prepare("DELETE FROM banned_words WHERE id = ?");
                $stmt->execute([$id]);
                
                logSystem('info', 'Deleted banned word', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '删除成功']);
            } catch (Exception $e) {
                logSystem('error', 'Delete banned word error', $e->getMessage());
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
                $stmt = $db->prepare("UPDATE banned_words SET is_active = ? WHERE id = ?");
                $stmt->execute([$is_active, $id]);
                
                jsonResponse(['success' => true, 'message' => '状态更新成功']);
            } catch (Exception $e) {
                logSystem('error', 'Toggle banned word error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '更新失败'], 500);
            }
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
    }
}

