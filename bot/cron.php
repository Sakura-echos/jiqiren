<?php
/**
 * 定时任务处理器
 * 用于定时发送广告等自动化任务
 * 建议每分钟执行一次
 */

// 防止脚本重复执行（子进程检测）
if (getenv('CRON_CHILD_PROCESS')) {
    exit(0); // 如果是子进程，直接退出
}
putenv('CRON_CHILD_PROCESS=1');

// 设置最大执行时间为0（无限制），因为需要处理大量模板
set_time_limit(0);

// 使用绝对路径
define('BASE_PATH', dirname(__FILE__) . '/..');
require_once BASE_PATH . '/config.php';
require_once __DIR__ . '/TelegramBot.php';

// 注意：自动广告不支持真人账号功能，因此不加载 MadelineProto

echo "[CRON] Config loaded, initializing bot...\n";

$bot = new TelegramBot(BOT_TOKEN);
$db = getDB();

echo "[CRON] Bot initialized, starting tasks...\n";

/**
 * 构建Telegram inline keyboard从按钮数据
 * 支持新格式（二维数组）和旧格式（一维数组）
 * 新格式支持每行多个按钮和二级菜单
 * 
 * @param string|array $buttonsJson 按钮数据（JSON字符串或数组）
 * @param int|null $adId 广告ID（用于生成callback_data）
 * @return array|null inline_keyboard数组或null
 */
function buildInlineKeyboard($buttonsJson, $adId = null) {
    if (empty($buttonsJson)) return null;
    
    $buttons = is_string($buttonsJson) ? json_decode($buttonsJson, true) : $buttonsJson;
    if (!is_array($buttons) || count($buttons) === 0) return null;
    
    $keyboard = [];
    
    // 检测格式：新格式第一个元素是数组
    $isNewFormat = isset($buttons[0]) && is_array($buttons[0]) && !isset($buttons[0]['text']);
    
    if ($isNewFormat) {
        // 新格式：二维数组，每个元素是一行按钮
        foreach ($buttons as $rowIndex => $row) {
            if (!is_array($row)) continue;
            
            $keyboardRow = [];
            foreach ($row as $btnIndex => $button) {
                if (empty($button['text'])) continue;
                
                // 检查是否有子按钮
                $hasSubButtons = !empty($button['sub_buttons']) && is_array($button['sub_buttons']);
                
                if ($hasSubButtons) {
                    // 有子按钮，使用callback_data
                    $callbackData = 'submenu_' . ($adId ?? 0) . '_' . $rowIndex . '_' . $btnIndex;
                    $keyboardRow[] = [
                        'text' => trim($button['text']),
                        'callback_data' => $callbackData
                    ];
                } else if (!empty($button['url'])) {
                    // 普通URL按钮
                    $keyboardRow[] = [
                        'text' => trim($button['text']),
                        'url' => trim($button['url'])
                    ];
                }
            }
            
            if (!empty($keyboardRow)) {
                $keyboard[] = $keyboardRow;
            }
        }
    } else {
        // 旧格式：一维数组，每个按钮单独一行
        foreach ($buttons as $button) {
            $buttonUrl = trim($button['url'] ?? '');
            $buttonText = trim($button['text'] ?? '');
            if (!empty($buttonUrl) && !empty($buttonText)) {
                $keyboard[] = [
                    [
                        'text' => $buttonText,
                        'url' => $buttonUrl
                    ]
                ];
            }
        }
    }
    
    return !empty($keyboard) ? ['inline_keyboard' => $keyboard] : null;
}

/**
 * 获取按钮的子菜单数据
 * 
 * @param string|array $buttonsJson 按钮数据
 * @param int $rowIndex 行索引
 * @param int $btnIndex 按钮索引
 * @return array|null 子按钮数组或null
 */
function getSubButtons($buttonsJson, $rowIndex, $btnIndex) {
    if (empty($buttonsJson)) return null;
    
    $buttons = is_string($buttonsJson) ? json_decode($buttonsJson, true) : $buttonsJson;
    if (!is_array($buttons)) return null;
    
    // 新格式检测
    $isNewFormat = isset($buttons[0]) && is_array($buttons[0]) && !isset($buttons[0]['text']);
    if (!$isNewFormat) return null;
    
    if (isset($buttons[$rowIndex][$btnIndex]['sub_buttons'])) {
        return $buttons[$rowIndex][$btnIndex]['sub_buttons'];
    }
    
    return null;
}

/**
 * 构建子菜单的inline keyboard
 * 
 * @param array $subButtons 子按钮数组
 * @param string $backCallbackData 返回按钮的callback_data
 * @return array|null
 */
function buildSubMenuKeyboard($subButtons, $backCallbackData = null) {
    if (empty($subButtons) || !is_array($subButtons)) return null;
    
    $keyboard = [];
    
    foreach ($subButtons as $subBtn) {
        $text = trim($subBtn['text'] ?? '');
        $url = trim($subBtn['url'] ?? '');
        if (!empty($text) && !empty($url)) {
            $keyboard[] = [
                [
                    'text' => $text,
                    'url' => $url
                ]
            ];
        }
    }
    
    // 添加返回按钮
    if ($backCallbackData) {
        $keyboard[] = [
            [
                'text' => '🔙 返回',
                'callback_data' => $backCallbackData
            ]
        ];
    }
    
    return !empty($keyboard) ? ['inline_keyboard' => $keyboard] : null;
}

// 处理循环广告模板
echo "[CRON] Processing auto ad templates...\n";
processAutoAdTemplates($bot, $db);

// 处理自动广告
echo "[CRON] Processing auto ads...\n";
processAutoAds($bot, $db);

// 处理定时任务
echo "[CRON] Processing scheduled tasks...\n";
processScheduledTasks($bot, $db);

// 更新群组成员数
echo "[CRON] Updating group member counts...\n";
updateGroupMemberCounts($bot, $db);

logSystem('info', 'Cron job executed');

echo "[CRON] All tasks completed!\n";

/**
 * 处理循环广告模板
 */
function processAutoAdTemplates($bot, $db) {
    try {
        echo "[CRON] Starting to process auto ad templates...\n";
        error_log("Starting to process auto ad templates...");
        logSystem('info', '开始处理循环广告模板');
        
        // 获取需要发送的模板
        echo "[CRON] Querying database for templates...\n";
        $stmt = $db->prepare("
            SELECT t.*
            FROM auto_ad_templates t 
            WHERE t.is_active = 1 
            AND (t.last_sent_at IS NULL OR TIMESTAMPDIFF(MINUTE, t.last_sent_at, NOW()) >= t.interval_minutes)
            ORDER BY t.last_sent_at ASC
        ");
        $stmt->execute();
        $templates = $stmt->fetchAll();
        echo "[CRON] Found " . count($templates) . " templates to process\n";
        
        error_log("Found " . count($templates) . " templates to process");
        logSystem('info', '找到待发送的模板', ['count' => count($templates)]);
        
        foreach ($templates as $template) {
            // 获取模板下的所有广告，按顺序排列
            $adStmt = $db->prepare("
                SELECT * FROM auto_ads 
                WHERE template_id = ? AND is_active = 1 
                ORDER BY sequence_order ASC
            ");
            $adStmt->execute([$template['id']]);
            $ads = $adStmt->fetchAll();
            
            if (empty($ads)) {
                error_log("Template ID " . $template['id'] . " has no ads");
                continue;
            }
            
            // 获取当前应该发送的广告索引
            $currentIndex = $template['current_index'] ?? 0;
            if ($currentIndex >= count($ads)) {
                $currentIndex = 0; // 循环回到第一条
            }
            
            // 检查循环间隔
            // 如果当前索引是0（即刚完成一轮循环），且设置了循环间隔，需要检查是否还在等待期
            if ($currentIndex == 0 && $template['cycle_interval_minutes'] > 0 && $template['cycle_completed_at']) {
                $cycleCompletedTime = strtotime($template['cycle_completed_at']);
                $cycleIntervalSeconds = $template['cycle_interval_minutes'] * 60;
                $timeSinceCycleCompleted = time() - $cycleCompletedTime;
                
                if ($timeSinceCycleCompleted < $cycleIntervalSeconds) {
                    // 还在循环等待期内，跳过此模板
                    error_log("Template ID " . $template['id'] . " is in cycle interval waiting period");
                    logSystem('info', 'Template in cycle waiting', [
                        'template_id' => $template['id'],
                        'waiting_seconds' => $cycleIntervalSeconds - $timeSinceCycleCompleted
                    ]);
                    continue;
                }
            }
            
            $ad = $ads[$currentIndex];
            
            error_log("Template ID " . $template['id'] . " sending ad index " . $currentIndex . " (ID: " . $ad['id'] . ")");
            
            // 确定要发送到哪些群组
            $targetGroups = [];
            
            if ($template['group_id'] === null) {
                // 所有群组
                $groupStmt = $db->prepare("SELECT id, chat_id, title FROM groups WHERE is_active = 1");
                $groupStmt->execute();
                $targetGroups = $groupStmt->fetchAll();
                error_log("Template targets ALL groups: " . count($targetGroups) . " groups");
            } else {
                // 特定群组
                $groupStmt = $db->prepare("SELECT id, chat_id, title FROM groups WHERE id = ? AND is_active = 1");
                $groupStmt->execute([$template['group_id']]);
                $group = $groupStmt->fetch();
                if ($group) {
                    $targetGroups = [$group];
                    error_log("Template targets specific group: " . $group['title']);
                }
            }
            
            if (empty($targetGroups)) {
                error_log("No target groups found for template ID " . $template['id']);
                continue;
            }
            
            // Parse buttons if available - 支持新格式（每行多按钮+二级菜单）
            $replyMarkup = buildInlineKeyboard($ad['buttons'] ?? null, $ad['id'] ?? null);
            
            // 发送到所有目标群组
            $successCount = 0;
            $failCount = 0;
            
            foreach ($targetGroups as $group) {
                echo "[CRON] Sending to chat " . $group['chat_id'] . " (" . $group['title'] . ")...\n";
                error_log("Sending template ad to chat " . $group['chat_id'] . " (" . $group['title'] . ")");
                
                try {
                    // 检查是否使用真人账号发送
                    $useUserAccount = isset($template['use_user_account']) && $template['use_user_account'] == 1;
                    
                    if ($useUserAccount) {
                        // 使用真人账号发送
                        error_log("[Template Ad] Using user account to send template ID " . $template['id']);
                        
                        // 构建完整的图片URL（如果有）
                        $imageUrl = null;
                        if (!empty($ad['image_url'])) {
                            $imageUrl = $ad['image_url'];
                            if (!preg_match('/^https?:\/\//', $imageUrl)) {
                                $imageUrl = SITE_URL . '/' . ltrim($imageUrl, '/');
                            }
                        }
                        
                        $result = sendUserAccountMessage($db, $group['chat_id'], $ad['message'], $imageUrl, $replyMarkup);
                    } else {
                        // 使用机器人发送
                        error_log("[Template Ad] Using bot to send template ID " . $template['id']);
                        
                        if (!empty($ad['image_url'])) {
                            // Build full URL for image
                            $imageUrl = $ad['image_url'];
                            if (!preg_match('/^https?:\/\//', $imageUrl)) {
                                $imageUrl = SITE_URL . '/' . ltrim($imageUrl, '/');
                            }
                            
                            $result = $bot->sendPhoto($group['chat_id'], $imageUrl, $ad['message'], $replyMarkup, 'HTML');
                        } else {
                            $result = $bot->sendMessage($group['chat_id'], $ad['message'], 'HTML', $replyMarkup);
                        }
                    }
                    
                    if ($result) {
                        echo "[CRON] -> SUCCESS\n";
                        $successCount++;
                        logSystem('info', 'Template ad sent successfully', [
                            'template_id' => $template['id'],
                            'ad_id' => $ad['id'],
                            'index' => $currentIndex,
                            'chat_id' => $group['chat_id'],
                            'group_title' => $group['title'],
                            'send_method' => $useUserAccount ? 'user_account' : 'bot'
                        ]);
                        
                        // 获取message_id（真人账号和机器人返回格式不同）
                        $message_id = null;
                        if (is_array($result)) {
                            $message_id = $result['message_id'] ?? null;
                        } elseif (isset($result['message_id'])) {
                            $message_id = $result['message_id'];
                        }
                        
                        // 如果设置了消息自毁时间，创建定时删除任务
                        if (isset($ad['delete_after_seconds']) && $ad['delete_after_seconds'] > 0 && $message_id) {
                            $scheduledTime = date('Y-m-d H:i:s', time() + $ad['delete_after_seconds']);
                            $taskData = json_encode([
                                'chat_id' => $group['chat_id'],
                                'message_id' => $message_id
                            ]);
                            
                            $taskStmt = $db->prepare("INSERT INTO scheduled_tasks (task_type, data, scheduled_at) VALUES (?, ?, ?)");
                            $taskStmt->execute(['delete_message', $taskData, $scheduledTime]);
                            
                            logSystem('info', '已安排消息自毁任务', [
                                'chat_id' => $group['chat_id'],
                                'message_id' => $message_id,
                                'delete_after' => $ad['delete_after_seconds']
                            ]);
                        }
                    } else {
                        $failCount++;
                        // 获取详细的错误信息
                        $errorDetail = $bot->getLastError();
                        $errorMsg = '未知错误';
                        $errorCode = 0;
                        $shouldDeactivate = false;
                        
                        if ($errorDetail) {
                            $errorData = json_decode($errorDetail, true);
                            if ($errorData) {
                                $errorMsg = $errorData['description'] ?? '未知错误';
                                $errorCode = $errorData['error_code'] ?? 0;
                                
                                // 检查是否需要处理群组迁移
                                if ($errorCode == 400 && strpos($errorMsg, 'group chat was upgraded to a supergroup chat') !== false) {
                                    if (isset($errorData['parameters']['migrate_to_chat_id'])) {
                                        $newChatId = $errorData['parameters']['migrate_to_chat_id'];
                                        // 更新群组的chat_id
                                        $updateStmt = $db->prepare("UPDATE groups SET chat_id = ?, updated_at = NOW() WHERE id = ?");
                                        $updateStmt->execute([$newChatId, $group['id']]);
                                        error_log("[Template Ad] Group migrated from {$group['chat_id']} to $newChatId, database updated");
                                        logSystem('info', 'Group chat_id updated after migration', [
                                            'old_chat_id' => $group['chat_id'],
                                            'new_chat_id' => $newChatId
                                        ]);
                                    }
                                }
                                
                                // 检查是否是永久性错误
                                if ($errorCode == 403 || 
                                    strpos($errorMsg, 'bot was kicked') !== false ||
                                    strpos($errorMsg, 'bot is not a member') !== false ||
                                    strpos($errorMsg, 'chat not found') !== false ||
                                    strpos($errorMsg, 'Forbidden') !== false) {
                                    $shouldDeactivate = true;
                                }
                            }
                        }
                        
                        echo "[CRON] -> FAILED: $errorMsg\n";
                        error_log("[Template Ad] Failed - Chat: {$group['chat_id']}, Error: $errorMsg (code: $errorCode)");
                        
                        logSystem('error', 'Failed to send template ad', [
                            'template_id' => $template['id'],
                            'ad_id' => $ad['id'],
                            'chat_id' => $group['chat_id'],
                            'error_code' => $errorCode,
                            'error_msg' => $errorMsg
                        ]);
                        
                        // 如果是永久性错误，将群组设为非活跃
                        if ($shouldDeactivate) {
                            $deactivateStmt = $db->prepare("UPDATE groups SET is_active = 0, updated_at = NOW() WHERE id = ?");
                            $deactivateStmt->execute([$group['id']]);
                            error_log("[Template Ad] Group {$group['chat_id']} deactivated due to permanent error");
                            logSystem('warning', 'Group deactivated due to bot access error', [
                                'chat_id' => $group['chat_id'],
                                'error_msg' => $errorMsg
                            ]);
                        }
                    }
                } catch (Exception $e) {
                    $failCount++;
                    error_log("Error sending template ad to " . $group['chat_id'] . ": " . $e->getMessage());
                    logSystem('error', 'Exception while sending template ad', [
                        'template_id' => $template['id'],
                        'chat_id' => $group['chat_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // 更新模板状态（移动到下一条广告）
            if ($successCount > 0) {
                $nextIndex = ($currentIndex + 1) % count($ads); // 循环
                
                // 如果刚发送完最后一条广告（下一个索引回到0），记录循环完成时间
                if ($nextIndex == 0 && $template['cycle_interval_minutes'] > 0) {
                    $updateStmt = $db->prepare("UPDATE auto_ad_templates SET current_index = ?, last_sent_at = NOW(), cycle_completed_at = NOW() WHERE id = ?");
                    $updateStmt->execute([$nextIndex, $template['id']]);
                    
                    error_log("Template ID " . $template['id'] . " completed one cycle, will wait " . $template['cycle_interval_minutes'] . " minutes before next cycle");
                    logSystem('info', 'Template cycle completed', [
                        'template_id' => $template['id'],
                        'cycle_interval_minutes' => $template['cycle_interval_minutes']
                    ]);
                } else {
                    $updateStmt = $db->prepare("UPDATE auto_ad_templates SET current_index = ?, last_sent_at = NOW() WHERE id = ?");
                    $updateStmt->execute([$nextIndex, $template['id']]);
                }
                
                error_log("Template ID " . $template['id'] . " sent to " . $successCount . " groups, next index: " . $nextIndex);
                logSystem('info', 'Template batch completed', [
                    'template_id' => $template['id'],
                    'current_index' => $currentIndex,
                    'next_index' => $nextIndex,
                    'success' => $successCount,
                    'failed' => $failCount
                ]);
            }
        }
    } catch (Exception $e) {
        logSystem('error', 'Process auto ad templates error', $e->getMessage());
    }
}

/**
 * 处理自动广告
 */
function processAutoAds($bot, $db) {
    try {
        echo "[CRON] Starting to process auto ads...\n";
        error_log("Starting to process auto ads...");
        logSystem('info', '开始处理自动广告');
        
        // 获取需要发送的广告（只获取不属于模板的独立广告）
        echo "[CRON] Querying database for ads...\n";
        $stmt = $db->prepare("
            SELECT aa.*
            FROM auto_ads aa 
            WHERE aa.is_active = 1 
            AND aa.template_id IS NULL
            AND (aa.last_sent_at IS NULL OR TIMESTAMPDIFF(MINUTE, aa.last_sent_at, NOW()) >= aa.interval_minutes)
            ORDER BY aa.last_sent_at ASC
        ");
        $stmt->execute();
        $ads = $stmt->fetchAll();
        echo "[CRON] Found " . count($ads) . " ads to process\n";
        
        error_log("Found " . count($ads) . " ads to process");
        logSystem('info', '找到待发送的广告', ['count' => count($ads)]);
        
        foreach ($ads as $ad) {
            // 确定要发送到哪些群组
            $targetGroups = [];
            
            if ($ad['group_id'] === null) {
                // 所有群组 - 获取所有活跃群组
                $groupStmt = $db->prepare("SELECT id, chat_id, title FROM groups WHERE is_active = 1");
                $groupStmt->execute();
                $targetGroups = $groupStmt->fetchAll();
                error_log("Ad ID " . $ad['id'] . " targets ALL groups: " . count($targetGroups) . " groups");
            } else {
                // 特定群组
                $groupStmt = $db->prepare("SELECT id, chat_id, title FROM groups WHERE id = ? AND is_active = 1");
                $groupStmt->execute([$ad['group_id']]);
                $group = $groupStmt->fetch();
                if ($group) {
                    $targetGroups = [$group];
                    error_log("Ad ID " . $ad['id'] . " targets specific group: " . $group['title']);
                } else {
                    error_log("Ad ID " . $ad['id'] . " - target group not found or inactive");
                }
            }
            
            if (empty($targetGroups)) {
                error_log("No target groups found for ad ID " . $ad['id']);
                continue;
            }
            
            // Parse buttons if available - 支持新格式（每行多按钮+二级菜单）
            $replyMarkup = buildInlineKeyboard($ad['buttons'] ?? null, $ad['id'] ?? null);
            
            // 发送到所有目标群组
            $successCount = 0;
            $failCount = 0;
            
            // 处理消息内容，添加关键词标签
            $messageContent = $ad['message'];
            
            // 从关键词库中获取关键词
            if (!empty($ad['keywords'])) {
                $keywords = json_decode($ad['keywords'], true);
                if (is_array($keywords) && count($keywords) > 0) {
                    $keywordsPerSend = intval($ad['keywords_per_send'] ?? 3);
                    $keywordsIndex = intval($ad['keywords_index'] ?? 0);
                    $totalKeywords = count($keywords);
                    
                    // 获取本次要发送的关键词
                    $selectedKeywords = [];
                    for ($i = 0; $i < $keywordsPerSend; $i++) {
                        $idx = ($keywordsIndex + $i) % $totalKeywords;
                        $kw = $keywords[$idx];
                        // 确保关键词带 # 号
                        if (strpos($kw, '#') !== 0) {
                            $kw = '#' . $kw;
                        }
                        $selectedKeywords[] = $kw;
                    }
                    
                    // 更新关键词索引
                    $newIndex = ($keywordsIndex + $keywordsPerSend) % $totalKeywords;
                    $updateStmt = $db->prepare("UPDATE auto_ads SET keywords_index = ? WHERE id = ?");
                    $updateStmt->execute([$newIndex, $ad['id']]);
                    
                    // 将关键词以遮挡效果添加到消息末尾
                    $keywordsText = implode(' ', $selectedKeywords);
                    // 使用 <tg-spoiler> 标签实现遮挡效果
                    $messageContent = $messageContent . "\n<tg-spoiler>" . htmlspecialchars($keywordsText) . "</tg-spoiler>";
                    error_log("Ad ID " . $ad['id'] . " - Added spoiler keywords: " . $keywordsText . " (index: $keywordsIndex -> $newIndex, total: $totalKeywords)");
                }
            }
            
            foreach ($targetGroups as $group) {
                echo "[CRON] Sending ad to chat " . $group['chat_id'] . "...\n";
                error_log("Sending ad ID " . $ad['id'] . " to chat " . $group['chat_id'] . " (" . $group['title'] . ")");
                
                try {
                    // 只使用机器人发送（已禁用真人发送）
                    error_log("[Auto Ad] Sending ad ID " . $ad['id'] . " via bot");
                    
                    if (!empty($ad['image_url'])) {
                        // Build full URL for image
                        $imageUrl = $ad['image_url'];
                        if (!preg_match('/^https?:\/\//', $imageUrl)) {
                            $imageUrl = SITE_URL . '/' . ltrim($imageUrl, '/');
                        }
                        
                        $result = $bot->sendPhoto($group['chat_id'], $imageUrl, $messageContent, $replyMarkup, 'HTML');
                    } else {
                        $result = $bot->sendMessage($group['chat_id'], $messageContent, 'HTML', $replyMarkup);
                    }
                    
                    if ($result) {
                        echo "[CRON] -> SUCCESS\n";
                        $successCount++;
                        logSystem('info', 'Auto ad sent successfully', [
                            'ad_id' => $ad['id'], 
                            'chat_id' => $group['chat_id'],
                            'group_title' => $group['title']
                        ]);
                        
                        // 获取message_id
                        $message_id = null;
                        if (is_array($result)) {
                            $message_id = $result['message_id'] ?? null;
                        } elseif (isset($result['message_id'])) {
                            $message_id = $result['message_id'];
                        }
                        
                        // 如果设置了消息自毁时间，创建定时删除任务
                        if (isset($ad['delete_after_seconds']) && $ad['delete_after_seconds'] > 0 && $message_id) {
                            $scheduledTime = date('Y-m-d H:i:s', time() + $ad['delete_after_seconds']);
                            $taskData = json_encode([
                                'chat_id' => $group['chat_id'],
                                'message_id' => $message_id
                            ]);
                            
                            $taskStmt = $db->prepare("INSERT INTO scheduled_tasks (task_type, data, scheduled_at) VALUES (?, ?, ?)");
                            $taskStmt->execute(['delete_message', $taskData, $scheduledTime]);
                            
                            logSystem('info', '已安排消息自毁任务', [
                                'chat_id' => $group['chat_id'],
                                'message_id' => $message_id,
                                'delete_after' => $ad['delete_after_seconds']
                            ]);
                        }
                    } else {
                        $failCount++;
                        // 获取详细的错误信息
                        $errorDetail = $bot->getLastError();
                        $errorMsg = '未知错误';
                        $errorCode = 0;
                        $shouldDeactivate = false;
                        
                        if ($errorDetail) {
                            $errorData = json_decode($errorDetail, true);
                            if ($errorData) {
                                $errorMsg = $errorData['description'] ?? '未知错误';
                                $errorCode = $errorData['error_code'] ?? 0;
                                
                                // 检查是否需要处理群组迁移
                                if ($errorCode == 400 && strpos($errorMsg, 'group chat was upgraded to a supergroup chat') !== false) {
                                    if (isset($errorData['parameters']['migrate_to_chat_id'])) {
                                        $newChatId = $errorData['parameters']['migrate_to_chat_id'];
                                        // 更新群组的chat_id
                                        $updateStmt = $db->prepare("UPDATE groups SET chat_id = ?, updated_at = NOW() WHERE id = ?");
                                        $updateStmt->execute([$newChatId, $group['id']]);
                                        error_log("[Auto Ad] Group migrated from {$group['chat_id']} to $newChatId, database updated");
                                        logSystem('info', 'Group chat_id updated after migration', [
                                            'old_chat_id' => $group['chat_id'],
                                            'new_chat_id' => $newChatId
                                        ]);
                                    }
                                }
                                
                                // 检查是否是永久性错误（机器人被踢出/禁止/群组被删除）
                                if ($errorCode == 403 || 
                                    strpos($errorMsg, 'bot was kicked') !== false ||
                                    strpos($errorMsg, 'bot is not a member') !== false ||
                                    strpos($errorMsg, 'chat not found') !== false ||
                                    strpos($errorMsg, 'Forbidden') !== false) {
                                    $shouldDeactivate = true;
                                }
                            }
                        }
                        
                        echo "[CRON] -> FAILED: $errorMsg\n";
                        error_log("[Auto Ad] Failed - Chat: {$group['chat_id']}, Error: $errorMsg (code: $errorCode)");
                        
                        logSystem('error', 'Failed to send auto ad', [
                            'ad_id' => $ad['id'],
                            'chat_id' => $group['chat_id'],
                            'group_title' => $group['title'],
                            'error_code' => $errorCode,
                            'error_msg' => $errorMsg
                        ]);
                        
                        // 如果是永久性错误，可以考虑将群组设为非活跃
                        if ($shouldDeactivate) {
                            $deactivateStmt = $db->prepare("UPDATE groups SET is_active = 0, updated_at = NOW() WHERE id = ?");
                            $deactivateStmt->execute([$group['id']]);
                            error_log("[Auto Ad] Group {$group['chat_id']} deactivated due to permanent error");
                            logSystem('warning', 'Group deactivated due to bot access error', [
                                'chat_id' => $group['chat_id'],
                                'error_msg' => $errorMsg
                            ]);
                        }
                    }
                } catch (Exception $e) {
                    $failCount++;
                    error_log("Error sending ad to " . $group['chat_id'] . ": " . $e->getMessage());
                    logSystem('error', 'Exception while sending ad', [
                        'ad_id' => $ad['id'],
                        'chat_id' => $group['chat_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // 更新最后发送时间（无论是否所有都成功）
            if ($successCount > 0) {
                $update_stmt = $db->prepare("UPDATE auto_ads SET last_sent_at = NOW() WHERE id = ?");
                $update_stmt->execute([$ad['id']]);
                
                error_log("Ad ID " . $ad['id'] . " sent to " . $successCount . " groups successfully, " . $failCount . " failed");
                logSystem('info', 'Auto ad batch completed', [
                    'ad_id' => $ad['id'],
                    'success' => $successCount,
                    'failed' => $failCount
                ]);
            }
        }
    } catch (Exception $e) {
        logSystem('error', 'Process auto ads error', $e->getMessage());
    }
}

/**
 * 处理定时任务
 */
function processScheduledTasks($bot, $db) {
    try {
        // Use PHP current time to avoid DB timezone mismatch.
        $currentTime = date('Y-m-d H:i:s');
        $stmt = $db->prepare("SELECT * FROM scheduled_tasks WHERE status = 'pending' AND scheduled_at <= ?");
        $stmt->execute([$currentTime]);
        $tasks = $stmt->fetchAll();
        
        foreach ($tasks as $task) {
            $data = json_decode($task['data'], true);
            $success = false;
            
            switch ($task['task_type']) {
                case 'send_message':
                    if (isset($data['chat_id']) && isset($data['message'])) {
                        $success = $bot->sendMessage($data['chat_id'], $data['message']);
                    }
                    break;
                    
                case 'unban_user':
                    if (isset($data['chat_id']) && isset($data['user_id'])) {
                        $success = $bot->unbanChatMember($data['chat_id'], $data['user_id']);
                    }
                    break;
                    
                case 'delete_message':
                    // 处理消息自毁
                    if (isset($data['chat_id']) && isset($data['message_id'])) {
                        $success = $bot->deleteMessage($data['chat_id'], $data['message_id']);
                        if ($success) {
                            logSystem('info', '消息自毁成功', [
                                'chat_id' => $data['chat_id'],
                                'message_id' => $data['message_id']
                            ]);
                        }
                    }
                    break;
                    
                case 'verification_timeout':
                    // 处理验证超时
                    if (isset($data['chat_id']) && isset($data['user_id'])) {
                        // 获取群组ID
                        $groupStmt = $db->prepare("SELECT id FROM groups WHERE chat_id = ?");
                        $groupStmt->execute([$data['chat_id']]);
                        $group = $groupStmt->fetch();
                        
                        if ($group) {
                            // 检查验证状态
                            $verifyStmt = $db->prepare("SELECT * FROM pending_verifications WHERE group_id = ? AND user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
                            $verifyStmt->execute([$group['id'], $data['user_id']]);
                            $verification = $verifyStmt->fetch();
                            
                            if ($verification) {
                                // 用户仍未验证，标记为超时
                                $updateStmt = $db->prepare("UPDATE pending_verifications SET status = 'failed' WHERE id = ?");
                                $updateStmt->execute([$verification['id']]);
                                
                                // 删除验证消息
                                if ($verification['verification_message_id']) {
                                    $bot->deleteMessage($data['chat_id'], $verification['verification_message_id']);
                                }
                                
                                // 删除加入消息
                                if ($verification['join_message_id']) {
                                    $bot->deleteMessage($data['chat_id'], $verification['join_message_id']);
                                }
                                
                                // 如果设置了踢出，则踢出用户
                                if (isset($data['kick']) && $data['kick']) {
                                    $bot->kickChatMember($data['chat_id'], $data['user_id']);
                                    logSystem('info', '验证超时，用户已被踢出', [
                                        'chat_id' => $data['chat_id'],
                                        'user_id' => $data['user_id']
                                    ]);
                                } else {
                                    // 不踢出，但保持限制
                                    logSystem('info', '验证超时，用户保持限制状态', [
                                        'chat_id' => $data['chat_id'],
                                        'user_id' => $data['user_id']
                                    ]);
                                }
                                
                                $success = true;
                            } else {
                                // 验证记录不存在或已完成，直接标记任务为成功
                                $success = true;
                            }
                        }
                    }
                    break;
            }
            
            // 更新任务状态
            $status = $success ? 'executed' : 'failed';
            $update_stmt = $db->prepare("UPDATE scheduled_tasks SET status = ?, executed_at = NOW() WHERE id = ?");
            $update_stmt->execute([$status, $task['id']]);
        }
    } catch (Exception $e) {
        logSystem('error', 'Process scheduled tasks error', $e->getMessage());
    }
}

/**
 * 更新群组成员数
 */
function updateGroupMemberCounts($bot, $db) {
    try {
        // 每次只更新最多10个群组，避免API调用过多导致超时
        // 优先更新最久没更新的群组
        $stmt = $db->prepare("
            SELECT id, chat_id FROM groups 
            WHERE is_active = 1 
            ORDER BY updated_at ASC 
            LIMIT 10
        ");
        $stmt->execute();
        $groups = $stmt->fetchAll();
        
        $updated = 0;
        foreach ($groups as $group) {
            try {
                $count = $bot->getChatMembersCount($group['chat_id']);

                if ($count === false) {
                    // 处理群组升级迁移：旧 chat_id 会返回 migrate_to_chat_id
                    $errorDetail = $bot->getLastError();
                    if ($errorDetail) {
                        $errorData = json_decode($errorDetail, true);
                        if (
                            is_array($errorData)
                            && (int)($errorData['error_code'] ?? 0) === 400
                            && strpos(($errorData['description'] ?? ''), 'group chat was upgraded to a supergroup chat') !== false
                            && isset($errorData['parameters']['migrate_to_chat_id'])
                        ) {
                            $newChatId = $errorData['parameters']['migrate_to_chat_id'];
                            $migrateStmt = $db->prepare("UPDATE groups SET chat_id = ?, updated_at = NOW() WHERE id = ?");
                            $migrateStmt->execute([$newChatId, $group['id']]);
                            error_log("[CRON] Group migrated: {$group['chat_id']} -> {$newChatId} (group id: {$group['id']})");
                            logSystem('info', 'Group chat_id updated after migration (member count)', [
                                'group_id' => $group['id'],
                                'old_chat_id' => $group['chat_id'],
                                'new_chat_id' => $newChatId
                            ]);

                            // 使用新 chat_id 重试一次
                            $count = $bot->getChatMembersCount($newChatId);
                        }
                    }
                }

                if ($count !== false) {
                    $update_stmt = $db->prepare("UPDATE groups SET member_count = ?, updated_at = NOW() WHERE id = ?");
                    $update_stmt->execute([$count, $group['id']]);
                    $updated++;
                }
                
                // 每次API调用后短暂暂停，避免触发速率限制
                usleep(100000); // 0.1秒
                
            } catch (Exception $e) {
                // 单个群组失败不影响其他群组
                error_log("[CRON] Failed to update member count for group {$group['chat_id']}: " . $e->getMessage());
                continue;
            }
        }
        
        echo "[CRON] Updated member counts for $updated groups\n";
        
    } catch (Exception $e) {
        logSystem('error', 'Update member counts error', $e->getMessage());
    }
}

// 注意：自动广告目前不支持真人账号发送功能
// 如需使用真人账号发送，请在 webhook.php 中处理
