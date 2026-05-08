<?php
/**
 * 黑名单管理 API - 增强版
 * 支持全局黑名单、自动踢出、邀请者连坐、改名监控
 */

require_once '../config.php';
checkLogin();

$db = getDB();

// 确保黑名单表有所有需要的字段
function ensureBlacklistTableExists() {
    global $db;
    
    try {
        // 创建黑名单表（如果不存在）
        $db->exec("
            CREATE TABLE IF NOT EXISTS blacklist (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                username VARCHAR(255) DEFAULT NULL,
                first_name VARCHAR(255) DEFAULT NULL,
                last_name VARCHAR(255) DEFAULT NULL,
                reason VARCHAR(500) DEFAULT NULL,
                group_id INT DEFAULT NULL COMMENT '为NULL表示全局黑名单',
                added_by VARCHAR(255) DEFAULT NULL COMMENT '添加者',
                added_by_user_id BIGINT DEFAULT NULL COMMENT '添加者TG用户ID',
                is_active TINYINT(1) DEFAULT 1,
                kick_inviter TINYINT(1) DEFAULT 0 COMMENT '是否踢出邀请者',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_group_id (group_id),
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // 检查并添加新字段
        $columns_to_add = [
            'username' => "ALTER TABLE blacklist ADD COLUMN username VARCHAR(255) DEFAULT NULL AFTER user_id",
            'first_name' => "ALTER TABLE blacklist ADD COLUMN first_name VARCHAR(255) DEFAULT NULL AFTER username",
            'last_name' => "ALTER TABLE blacklist ADD COLUMN last_name VARCHAR(255) DEFAULT NULL AFTER first_name",
            'added_by' => "ALTER TABLE blacklist ADD COLUMN added_by VARCHAR(255) DEFAULT NULL AFTER group_id",
            'added_by_user_id' => "ALTER TABLE blacklist ADD COLUMN added_by_user_id BIGINT DEFAULT NULL AFTER added_by",
            'is_active' => "ALTER TABLE blacklist ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER kick_inviter",
            'kick_inviter' => "ALTER TABLE blacklist ADD COLUMN kick_inviter TINYINT(1) DEFAULT 0 AFTER is_active"
        ];
        
        foreach ($columns_to_add as $column => $sql) {
            $stmt = $db->query("SHOW COLUMNS FROM blacklist LIKE '$column'");
            if ($stmt->rowCount() == 0) {
                try {
                    $db->exec($sql);
                } catch (Exception $e) {
                    // 忽略重复列错误
                }
            }
        }
        
        // 创建用户名历史表（用于改名监控）
        $db->exec("
            CREATE TABLE IF NOT EXISTS user_name_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                username VARCHAR(255) DEFAULT NULL,
                first_name VARCHAR(255) DEFAULT NULL,
                last_name VARCHAR(255) DEFAULT NULL,
                recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_recorded_at (recorded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // 创建黑名单设置表（全局设置）
        $db->exec("
            CREATE TABLE IF NOT EXISTS blacklist_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_setting_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // 插入默认设置
        $defaultSettings = [
            'enable_blacklist' => '1',
            'enable_name_monitor' => '1',
            'kick_inviter' => '1',
            'notify_in_group' => '1',
            'name_change_message' => '⚠️ 提醒：用户 {old_name} 已改名为 {new_name}，别以为改了名字我就不认识你了！(ID: {user_id})'
        ];
        
        foreach ($defaultSettings as $key => $value) {
            $stmt = $db->prepare("INSERT IGNORE INTO blacklist_settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        }
        
    } catch (Exception $e) {
        error_log("ensureBlacklistTableExists error: " . $e->getMessage());
    }
}

ensureBlacklistTableExists();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        // 获取黑名单列表
        $page = intval($_GET['page'] ?? 1);
        $limit = intval($_GET['limit'] ?? 50);
        $offset = ($page - 1) * $limit;
        $search = $_GET['search'] ?? '';
        
        $where = "WHERE b.is_active = 1";
        $params = [];
        
        if ($search) {
            $where .= " AND (b.user_id LIKE ? OR b.username LIKE ? OR b.first_name LIKE ? OR b.reason LIKE ?)";
            $searchParam = "%$search%";
            $params = [$searchParam, $searchParam, $searchParam, $searchParam];
        }
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM blacklist b $where");
        $stmt->execute($params);
        $total = $stmt->fetchColumn();
        
        $stmt = $db->prepare("
            SELECT b.*, g.title as group_title 
            FROM blacklist b 
            LEFT JOIN `groups` g ON b.group_id = g.id 
            $where
            ORDER BY b.created_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $blacklist = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(['success' => true, 'data' => $blacklist, 'total' => $total]);
        break;
        
    case 'add':
        // 添加到黑名单
        $user_id = $_POST['user_id'] ?? '';
        $username = $_POST['username'] ?? '';
        $first_name = $_POST['first_name'] ?? '';
        $last_name = $_POST['last_name'] ?? '';
        $reason = $_POST['reason'] ?? '';
        $group_id = $_POST['group_id'] ?? null;
        $kick_inviter = intval($_POST['kick_inviter'] ?? 1);
        
        if (empty($user_id)) {
            jsonResponse(['success' => false, 'error' => '用户ID不能为空']);
        }
        
        // 检查是否已存在
        $stmt = $db->prepare("SELECT id FROM blacklist WHERE user_id = ? AND is_active = 1 AND (group_id IS NULL OR group_id = ?)");
        $stmt->execute([$user_id, $group_id]);
        if ($stmt->fetch()) {
            jsonResponse(['success' => false, 'error' => '该用户已在黑名单中']);
        }
        
        $stmt = $db->prepare("
            INSERT INTO blacklist (user_id, username, first_name, last_name, reason, group_id, added_by, kick_inviter, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $user_id,
            $username ?: null,
            $first_name ?: null,
            $last_name ?: null,
            $reason ?: null,
            $group_id ?: null,
            $_SESSION['admin_username'] ?? 'Admin',
            $kick_inviter
        ]);
        
        jsonResponse(['success' => true, 'message' => '已添加到黑名单']);
        break;
        
    case 'remove':
        // 从黑名单移除
        $id = $_POST['id'] ?? '';
        
        if (empty($id)) {
            jsonResponse(['success' => false, 'error' => 'ID不能为空']);
        }
        
        $stmt = $db->prepare("UPDATE blacklist SET is_active = 0 WHERE id = ?");
        $stmt->execute([$id]);
        
        jsonResponse(['success' => true, 'message' => '已从黑名单移除']);
        break;
        
    case 'batch_remove':
        // 批量移除
        $ids = $_POST['ids'] ?? [];
        if (is_string($ids)) {
            $ids = json_decode($ids, true);
        }
        
        if (empty($ids)) {
            jsonResponse(['success' => false, 'error' => '请选择要移除的记录']);
        }
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE blacklist SET is_active = 0 WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        
        jsonResponse(['success' => true, 'message' => '批量移除成功']);
        break;
        
    case 'check':
        // 检查用户是否在黑名单
        $user_id = $_POST['user_id'] ?? $_GET['user_id'] ?? '';
        $group_id = $_POST['group_id'] ?? $_GET['group_id'] ?? null;
        
        if (empty($user_id)) {
            jsonResponse(['success' => false, 'error' => '用户ID不能为空']);
        }
        
        // 检查全局黑名单和特定群组黑名单
        $stmt = $db->prepare("
            SELECT * FROM blacklist 
            WHERE user_id = ? AND is_active = 1 
            AND (group_id IS NULL OR group_id = ?)
            LIMIT 1
        ");
        $stmt->execute([$user_id, $group_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        jsonResponse(['success' => true, 'in_blacklist' => !empty($result), 'data' => $result]);
        break;
        
    case 'get_settings':
        // 获取黑名单设置
        $stmt = $db->query("SELECT setting_key, setting_value FROM blacklist_settings");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        jsonResponse(['success' => true, 'settings' => $settings]);
        break;
        
    case 'save_settings':
        // 保存设置
        $settings = [
            'enable_blacklist' => $_POST['enable_blacklist'] ?? '1',
            'enable_name_monitor' => $_POST['enable_name_monitor'] ?? '0',
            'kick_inviter' => $_POST['kick_inviter'] ?? '0',
            'notify_in_group' => $_POST['notify_in_group'] ?? '1',
            'enable_mention_check' => $_POST['enable_mention_check'] ?? '1',
            'name_change_message' => $_POST['name_change_message'] ?? '',
            // @提及检查消息模板
            'mention_member_title' => $_POST['mention_member_title'] ?? '✅ <b>Notice:</b>',
            'mention_member_text' => $_POST['mention_member_text'] ?? 'The following users are group members:',
            'mention_non_member_title' => $_POST['mention_non_member_title'] ?? '⚠️ <b>Warning:</b>',
            'mention_non_member_text' => $_POST['mention_non_member_text'] ?? 'The following users are NOT group members:',
            'mention_non_member_footer' => $_POST['mention_non_member_footer'] ?? '📌 Please verify their identity'
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $db->prepare("
                INSERT INTO blacklist_settings (setting_key, setting_value) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$key, $value]);
        }
        
        jsonResponse(['success' => true, 'message' => '设置已保存']);
        break;
        
    case 'get_name_history':
        // 获取用户改名历史
        $user_id = $_GET['user_id'] ?? '';
        
        if (empty($user_id)) {
            jsonResponse(['success' => false, 'error' => '用户ID不能为空']);
        }
        
        $stmt = $db->prepare("
            SELECT * FROM user_name_history 
            WHERE user_id = ? 
            ORDER BY recorded_at DESC 
            LIMIT 50
        ");
        $stmt->execute([$user_id]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(['success' => true, 'data' => $history]);
        break;
        
    case 'list_name_changes':
        // 获取所有改名记录
        $page = intval($_GET['page'] ?? 1);
        $limit = intval($_GET['limit'] ?? 50);
        $offset = ($page - 1) * $limit;
        
        $stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM user_name_history");
        $total = $stmt->fetchColumn();
        
        // 获取有多次记录的用户（表示改过名）
        $stmt = $db->prepare("
            SELECT 
                h1.user_id,
                h1.username as current_username,
                h1.first_name as current_first_name,
                h1.last_name as current_last_name,
                h1.recorded_at as last_seen,
                (SELECT COUNT(*) FROM user_name_history WHERE user_id = h1.user_id) as change_count
            FROM user_name_history h1
            WHERE h1.id = (
                SELECT MAX(id) FROM user_name_history WHERE user_id = h1.user_id
            )
            HAVING change_count > 1
            ORDER BY h1.recorded_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(['success' => true, 'data' => $users, 'total' => $total]);
        break;
        
    case 'get_groups':
        // 获取群组列表（用于下拉选择）
        $stmt = $db->query("SELECT id, chat_id, title FROM `groups` WHERE is_active = 1 ORDER BY title");
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(['success' => true, 'data' => $groups]);
        break;
        
    default:
        jsonResponse(['success' => false, 'error' => '未知操作']);
}
