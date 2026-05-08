<?php
/**
 * 黑名单和改名监控处理器
 */

/**
 * 确保必要的数据库表存在
 */
function ensureBlacklistTablesExist($db) {
    try {
        // 创建黑名单表
        $db->exec("
            CREATE TABLE IF NOT EXISTS blacklist (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                username VARCHAR(255) DEFAULT NULL,
                first_name VARCHAR(255) DEFAULT NULL,
                last_name VARCHAR(255) DEFAULT NULL,
                reason VARCHAR(500) DEFAULT NULL,
                group_id INT DEFAULT NULL,
                added_by VARCHAR(255) DEFAULT NULL,
                added_by_user_id BIGINT DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                kick_inviter TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // 创建用户名历史表
        $db->exec("
            CREATE TABLE IF NOT EXISTS user_name_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                username VARCHAR(255) DEFAULT NULL,
                first_name VARCHAR(255) DEFAULT NULL,
                last_name VARCHAR(255) DEFAULT NULL,
                recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // 创建黑名单设置表
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
        error_log("ensureBlacklistTablesExist error: " . $e->getMessage());
    }
}

/**
 * 获取黑名单设置
 */
function getBlacklistSettings($db) {
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM blacklist_settings");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    } catch (Exception $e) {
        error_log("getBlacklistSettings error: " . $e->getMessage());
        return [
            'enable_blacklist' => '1',
            'enable_name_monitor' => '1',
            'kick_inviter' => '1',
            'notify_in_group' => '1',
            'name_change_message' => ''
        ];
    }
}

/**
 * 检查用户是否在黑名单中
 */
function isUserInBlacklist($db, $user_id, $group_id = null) {
    try {
        // 检查全局黑名单和特定群组黑名单
        if ($group_id) {
            $stmt = $db->prepare("
                SELECT * FROM blacklist 
                WHERE user_id = ? AND is_active = 1 
                AND (group_id IS NULL OR group_id = ?)
                LIMIT 1
            ");
            $stmt->execute([$user_id, $group_id]);
        } else {
            $stmt = $db->prepare("
                SELECT * FROM blacklist 
                WHERE user_id = ? AND is_active = 1 AND group_id IS NULL
                LIMIT 1
            ");
            $stmt->execute([$user_id]);
        }
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("isUserInBlacklist error: " . $e->getMessage());
        return false;
    }
}

/**
 * 添加用户到黑名单
 */
function addToBlacklist($db, $user_id, $username = null, $first_name = null, $last_name = null, $reason = null, $group_id = null, $added_by = null, $added_by_user_id = null, $kick_inviter = 1) {
    try {
        // 检查是否已存在
        $stmt = $db->prepare("SELECT id FROM blacklist WHERE user_id = ? AND is_active = 1 AND (group_id IS NULL OR group_id = ?)");
        $stmt->execute([$user_id, $group_id]);
        if ($stmt->fetch()) {
            return true; // 已存在
        }
        
        $stmt = $db->prepare("
            INSERT INTO blacklist (user_id, username, first_name, last_name, reason, group_id, added_by, added_by_user_id, kick_inviter, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $user_id,
            $username,
            $first_name,
            $last_name,
            $reason,
            $group_id,
            $added_by,
            $added_by_user_id,
            $kick_inviter
        ]);
        
        error_log("User $user_id added to blacklist");
        return true;
    } catch (Exception $e) {
        error_log("addToBlacklist error: " . $e->getMessage());
        return false;
    }
}

/**
 * 从黑名单移除用户
 */
function removeFromBlacklist($db, $user_id) {
    try {
        $stmt = $db->prepare("UPDATE blacklist SET is_active = 0 WHERE user_id = ?");
        $stmt->execute([$user_id]);
        error_log("User $user_id removed from blacklist");
        return true;
    } catch (Exception $e) {
        error_log("removeFromBlacklist error: " . $e->getMessage());
        return false;
    }
}

/**
 * 踢出用户
 */
function kickBlacklistedUser($bot, $db, $chat_id, $user_id, $reason = null, $notify = true) {
    try {
        // 踢出用户
        $result = $bot->kickChatMember($chat_id, $user_id);
        
        if ($result) {
            error_log("Successfully kicked user $user_id from chat $chat_id");
            
            // 在群内通知
            if ($notify) {
                $settings = getBlacklistSettings($db);
                if ($settings['notify_in_group'] === '1') {
                    $message = "🚫 已踢出黑名单用户 (ID: $user_id)";
                    if ($reason) {
                        $message .= "\n原因: $reason";
                    }
                    $bot->sendMessage($chat_id, $message);
                }
            }
            
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("kickBlacklistedUser error: " . $e->getMessage());
        return false;
    }
}

/**
 * 处理新成员加入时的黑名单检测
 * @return bool 返回true表示用户被踢出，应跳过后续处理
 */
function handleBlacklistOnJoin($bot, $db, $chat_id, $new_member, $inviter = null) {
    $settings = getBlacklistSettings($db);
    
    // 检查是否启用黑名单功能
    if ($settings['enable_blacklist'] !== '1') {
        return false;
    }
    
    $user_id = $new_member['id'];
    $username = $new_member['username'] ?? null;
    $first_name = $new_member['first_name'] ?? '';
    $last_name = $new_member['last_name'] ?? '';
    
    // 获取群组数据库ID
    $stmt = $db->prepare("SELECT id FROM `groups` WHERE chat_id = ?");
    $stmt->execute([$chat_id]);
    $group = $stmt->fetch();
    $group_db_id = $group ? $group['id'] : null;
    
    // 检查新成员是否在黑名单中
    $blacklistEntry = isUserInBlacklist($db, $user_id, $group_db_id);
    
    if ($blacklistEntry) {
        error_log("User $user_id is in blacklist, kicking...");
        
        // 踢出黑名单用户
        kickBlacklistedUser($bot, $db, $chat_id, $user_id, $blacklistEntry['reason'] ?? null, true);
        
        // 检查是否需要踢出邀请者
        if ($inviter && $settings['kick_inviter'] === '1' && $blacklistEntry['kick_inviter']) {
            $inviter_id = $inviter['id'];
            $inviter_username = $inviter['username'] ?? null;
            $inviter_first_name = $inviter['first_name'] ?? '';
            $inviter_last_name = $inviter['last_name'] ?? '';
            
            // 将邀请者也加入黑名单
            addToBlacklist(
                $db,
                $inviter_id,
                $inviter_username,
                $inviter_first_name,
                $inviter_last_name,
                "邀请黑名单用户 $user_id",
                null, // 全局黑名单
                'System',
                null,
                1
            );
            
            // 踢出邀请者
            kickBlacklistedUser($bot, $db, $chat_id, $inviter_id, "邀请黑名单用户", true);
            
            error_log("Inviter $inviter_id also kicked and added to blacklist");
        }
        
        return true; // 表示用户被踢出
    }
    
    return false;
}

/**
 * 处理消息发送者的黑名单检测
 * @return bool 返回true表示用户被踢出，应跳过后续处理
 */
function handleBlacklistOnMessage($bot, $db, $chat_id, $user) {
    $settings = getBlacklistSettings($db);
    
    // 检查是否启用黑名单功能
    if ($settings['enable_blacklist'] !== '1') {
        return false;
    }
    
    $user_id = $user['id'];
    
    // 获取群组数据库ID
    $stmt = $db->prepare("SELECT id FROM `groups` WHERE chat_id = ?");
    $stmt->execute([$chat_id]);
    $group = $stmt->fetch();
    $group_db_id = $group ? $group['id'] : null;
    
    // 检查用户是否在黑名单中
    $blacklistEntry = isUserInBlacklist($db, $user_id, $group_db_id);
    
    if ($blacklistEntry) {
        error_log("Message sender $user_id is in blacklist, kicking...");
        kickBlacklistedUser($bot, $db, $chat_id, $user_id, $blacklistEntry['reason'] ?? null, true);
        return true;
    }
    
    return false;
}

/**
 * 记录用户名变化（按群组分别记录，每个群都会收到通知）
 */
function trackUserName($db, $user, $chat_id = null) {
    try {
        $user_id = $user['id'];
        $username = $user['username'] ?? null;
        $first_name = $user['first_name'] ?? null;
        $last_name = $user['last_name'] ?? null;
        
        // 确保 user_name_history 表有 chat_id 字段
        try {
            $checkCol = $db->query("SHOW COLUMNS FROM user_name_history LIKE 'chat_id'");
            if ($checkCol->rowCount() == 0) {
                $db->exec("ALTER TABLE user_name_history ADD COLUMN chat_id BIGINT DEFAULT NULL AFTER user_id");
                $db->exec("CREATE INDEX idx_chat_id ON user_name_history(chat_id)");
                error_log("Added chat_id column to user_name_history table");
            }
        } catch (Exception $e) {
            // 忽略
        }
        
        // 获取该用户在该群组的最后一次记录
        if ($chat_id) {
            $stmt = $db->prepare("
                SELECT * FROM user_name_history 
                WHERE user_id = ? AND chat_id = ?
                ORDER BY recorded_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$user_id, $chat_id]);
        } else {
            $stmt = $db->prepare("
                SELECT * FROM user_name_history 
                WHERE user_id = ? AND chat_id IS NULL
                ORDER BY recorded_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$user_id]);
        }
        $lastRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 检查名称是否有变化
        $hasChange = false;
        $oldName = '';
        $newName = '';
        
        if ($lastRecord) {
            if ($lastRecord['username'] !== $username ||
                $lastRecord['first_name'] !== $first_name ||
                $lastRecord['last_name'] !== $last_name) {
                $hasChange = true;
                $oldName = trim(($lastRecord['first_name'] ?? '') . ' ' . ($lastRecord['last_name'] ?? ''));
                if ($lastRecord['username']) {
                    $oldName .= " (@{$lastRecord['username']})";
                }
                $newName = trim(($first_name ?? '') . ' ' . ($last_name ?? ''));
                if ($username) {
                    $newName .= " (@$username)";
                }
            }
        } else {
            // 首次在该群记录
            $hasChange = false; // 首次不算改名
        }
        
        // 如果有变化或是首次记录，保存
        if ($hasChange || !$lastRecord) {
            $stmt = $db->prepare("
                INSERT INTO user_name_history (user_id, chat_id, username, first_name, last_name)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$user_id, $chat_id, $username, $first_name, $last_name]);
            
            if ($hasChange) {
                error_log("User $user_id name changed in chat $chat_id from '$oldName' to '$newName'");
            }
        }
        
        return $hasChange ? [
            'changed' => true,
            'old_name' => $oldName,
            'new_name' => $newName,
            'user_id' => $user_id,
            'username' => $username
        ] : ['changed' => false];
        
    } catch (Exception $e) {
        error_log("trackUserName error: " . $e->getMessage());
        return ['changed' => false];
    }
}

/**
 * 处理改名播报（每个群都会收到通知）
 */
function handleNameChangeNotification($bot, $db, $chat_id, $user) {
    $settings = getBlacklistSettings($db);
    
    // 检查是否启用改名监控
    if ($settings['enable_name_monitor'] !== '1') {
        return;
    }
    
    // 追踪名称变化（按群组分别记录）
    $result = trackUserName($db, $user, $chat_id);
    
    if ($result['changed']) {
        // 发送改名通知
        $message = $settings['name_change_message'] ?? '⚠️ 提醒：用户 {old_name} 已改名为 {new_name}！(ID: {user_id})';
        
        $message = str_replace('{old_name}', $result['old_name'], $message);
        $message = str_replace('{new_name}', $result['new_name'], $message);
        $message = str_replace('{user_id}', $result['user_id'], $message);
        $message = str_replace('{username}', $result['username'] ? '@' . $result['username'] : '', $message);
        
        $bot->sendMessage($chat_id, $message);
        error_log("Name change notification sent for user {$result['user_id']}");
    }
}

/**
 * 处理 /ban 命令
 */
function handleBanCommand($bot, $db, $chat_id, $message, $user) {
    // 检查用户是否是管理员
    $member = $bot->getChatMember($chat_id, $user['id']);
    if (!$member || !in_array($member['status'], ['creator', 'administrator'])) {
        $bot->sendMessage($chat_id, "⚠️ 只有管理员可以使用此命令");
        return;
    }
    
    // 检查是否回复了某条消息
    if (!isset($message['reply_to_message'])) {
        $bot->sendMessage($chat_id, "⚠️ 请回复要封禁的用户的消息来使用此命令\n\n用法：回复用户消息并发送 /ban [原因]");
        return;
    }
    
    $targetUser = $message['reply_to_message']['from'];
    $targetUserId = $targetUser['id'];
    $targetUsername = $targetUser['username'] ?? null;
    $targetFirstName = $targetUser['first_name'] ?? '';
    $targetLastName = $targetUser['last_name'] ?? '';
    
    // 获取封禁原因
    $text = $message['text'] ?? '';
    $parts = explode(' ', $text, 2);
    $reason = isset($parts[1]) ? trim($parts[1]) : '管理员封禁';
    
    // 获取群组ID
    $stmt = $db->prepare("SELECT id FROM `groups` WHERE chat_id = ?");
    $stmt->execute([$chat_id]);
    $group = $stmt->fetch();
    
    // 添加到黑名单
    $result = addToBlacklist(
        $db,
        $targetUserId,
        $targetUsername,
        $targetFirstName,
        $targetLastName,
        $reason,
        null, // 全局黑名单
        $user['first_name'] ?? 'Admin',
        $user['id'],
        1
    );
    
    if ($result) {
        // 踢出用户
        kickBlacklistedUser($bot, $db, $chat_id, $targetUserId, $reason, false);
        
        $name = trim("$targetFirstName $targetLastName");
        $bot->sendMessage($chat_id, "✅ 已将用户 $name (ID: $targetUserId) 加入黑名单并踢出\n原因: $reason");
    } else {
        $bot->sendMessage($chat_id, "❌ 操作失败，请重试");
    }
}

/**
 * 处理 /unban 命令
 */
function handleUnbanCommand($bot, $db, $chat_id, $message, $user) {
    // 检查用户是否是管理员
    $member = $bot->getChatMember($chat_id, $user['id']);
    if (!$member || !in_array($member['status'], ['creator', 'administrator'])) {
        $bot->sendMessage($chat_id, "⚠️ 只有管理员可以使用此命令");
        return;
    }
    
    // 获取要解封的用户ID
    $text = $message['text'] ?? '';
    $parts = explode(' ', $text);
    
    $targetUserId = null;
    
    // 如果回复了消息
    if (isset($message['reply_to_message'])) {
        $targetUserId = $message['reply_to_message']['from']['id'];
    } elseif (isset($parts[1])) {
        $targetUserId = intval($parts[1]);
    }
    
    if (!$targetUserId) {
        $bot->sendMessage($chat_id, "⚠️ 请提供要解封的用户ID\n\n用法：\n/unban 用户ID\n或回复用户消息并发送 /unban");
        return;
    }
    
    // 从黑名单移除
    $result = removeFromBlacklist($db, $targetUserId);
    
    if ($result) {
        // 解除群组封禁
        $bot->unbanChatMember($chat_id, $targetUserId);
        $bot->sendMessage($chat_id, "✅ 已将用户 (ID: $targetUserId) 从黑名单移除");
    } else {
        $bot->sendMessage($chat_id, "❌ 操作失败，请重试");
    }
}

/**
 * 处理 /blacklist 命令 - 查看黑名单
 */
function handleBlacklistCommand($bot, $db, $chat_id, $user) {
    // 检查用户是否是管理员
    $member = $bot->getChatMember($chat_id, $user['id']);
    if (!$member || !in_array($member['status'], ['creator', 'administrator'])) {
        $bot->sendMessage($chat_id, "⚠️ 只有管理员可以使用此命令");
        return;
    }
    
    // 获取黑名单
    $stmt = $db->prepare("SELECT * FROM blacklist WHERE is_active = 1 ORDER BY created_at DESC LIMIT 20");
    $stmt->execute();
    $blacklist = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($blacklist)) {
        $bot->sendMessage($chat_id, "📋 黑名单为空");
        return;
    }
    
    $message = "📋 **黑名单列表** (最近20条)\n\n";
    foreach ($blacklist as $item) {
        $name = trim(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? ''));
        if ($item['username']) {
            $name .= " (@{$item['username']})";
        }
        $message .= "• ID: `{$item['user_id']}`\n";
        $message .= "  名称: {$name}\n";
        if ($item['reason']) {
            $message .= "  原因: {$item['reason']}\n";
        }
        $message .= "\n";
    }
    
    $message .= "💡 使用 /unban 用户ID 可解除封禁";
    
    $bot->sendMessage($chat_id, $message, 'Markdown');
}
