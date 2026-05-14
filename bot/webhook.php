<?php
/**
 * Telegram Bot Webhook 处理器
 * 接收并处理来自 Telegram 的更新
 */

// 开启错误日志
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.log');
error_reporting(E_ALL);
ini_set('display_errors', 0); // 不输出到屏幕，只记录到日志

// 设置错误处理
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return false;
});

set_exception_handler(function($exception) {
    error_log("PHP Exception: " . $exception->getMessage());
    error_log("Stack trace: " . $exception->getTraceAsString());
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("PHP Fatal Error: " . print_r($error, true));
    }
});

// 记录调试信息
error_log("Webhook debug - Time: " . date('Y-m-d H:i:s'));
error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
error_log("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
error_log("Content-Length: " . ($_SERVER['CONTENT_LENGTH'] ?? 'not set'));

// **关键：先读取输入数据，再加载其他文件**
// 这样即使后续加载失败，我们也能看到请求内容
$raw_input = @file_get_contents("php://input");
if ($raw_input !== false && !empty($raw_input)) {
    @file_put_contents(__DIR__ . '/last_request.json', $raw_input);
    error_log("Request saved to last_request.json, size: " . strlen($raw_input));
    error_log("Request preview: " . substr($raw_input, 0, 200));
}

error_log("HTTP Headers: " . print_r(getallheaders(), true));

error_log("About to require config.php");
error_log("Config path: " . __DIR__ . '/../config.php');
error_log("File exists: " . (file_exists(__DIR__ . '/../config.php') ? 'yes' : 'no'));

try {
    error_log("[STEP 1] Starting to require config.php");
    require_once __DIR__ . '/../config.php';
    
    // 重新设置错误日志到 webhook 的 debug.log（因为 config.php 可能改变了它）
    ini_set('error_log', __DIR__ . '/debug.log');
    
    error_log("[STEP 2] config.php require statement completed");
    
    // config.php 加载完成
    error_log("[STEP 3] config.php loaded successfully");
    
} catch (Exception $e) {
    error_log("Failed to load config.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    exit;
} catch (Error $e) {
    error_log("Fatal error loading config.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    exit;
}

error_log("About to require TelegramBot.php");
require_once 'TelegramBot.php';
error_log("TelegramBot.php loaded");

error_log("About to require language_pack.php");
require_once '../includes/language_pack.php';
error_log("language_pack.php loaded");

// 加载黑名单处理器
require_once 'blacklist_handler.php';
error_log("blacklist_handler.php loaded");

// 加载 Composer autoload（用于 MadelineProto）
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// MadelineProto 相关类（用于真人账号发送消息）
use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Logger;

/**
 * 确保 webhook 更新去重表存在
 */
function ensureWebhookProcessedUpdatesTable($db) {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS webhook_processed_updates (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bot_hash VARCHAR(64) NOT NULL,
                update_id BIGINT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_bot_update (bot_hash, update_id),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS webhook_processed_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bot_hash VARCHAR(64) NOT NULL,
                event_key VARCHAR(191) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_bot_event (bot_hash, event_key),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $initialized = true;
    } catch (Exception $e) {
        error_log("Failed to ensure webhook_processed_updates table: " . $e->getMessage());
    }
}

/**
 * update_id 幂等去重：已处理过则返回 true
 */
function isDuplicateWebhookUpdate($db, $update_id) {
    if ($update_id === null || $update_id === '') {
        return false;
    }

    try {
        ensureWebhookProcessedUpdatesTable($db);

        $bot_hash = hash('sha256', BOT_TOKEN);
        $stmt = $db->prepare("INSERT IGNORE INTO webhook_processed_updates (bot_hash, update_id) VALUES (?, ?)");
        $stmt->execute([$bot_hash, (int)$update_id]);

        // 定期清理，避免表无限增长
        if (mt_rand(1, 200) === 1) {
            $db->exec("DELETE FROM webhook_processed_updates WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        }

        return $stmt->rowCount() === 0;
    } catch (Exception $e) {
        // 去重失败时不阻断主流程，避免误伤消息处理
        error_log("Webhook dedup check failed: " . $e->getMessage());
        return false;
    }
}

/**
 * 生成入群事件指纹：用于不同 update_id 的重复事件去重
 */
function buildJoinEventDedupKey($update) {
    // 服务消息：new_chat_members
    if (isset($update['message']['new_chat_members'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'] ?? null;
        $message_id = $message['message_id'] ?? null;
        if ($chat_id === null || $message_id === null) {
            return null;
        }

        $member_ids = [];
        foreach ($message['new_chat_members'] as $member) {
            if (isset($member['id'])) {
                $member_ids[] = (string)$member['id'];
            }
        }
        sort($member_ids);

        return 'join_msg:' . $chat_id . ':' . $message_id . ':' . implode(',', $member_ids);
    }

    // chat_member：left/kicked -> member 的入群变更
    if (isset($update['chat_member'])) {
        $cm = $update['chat_member'];
        $chat_id = $cm['chat']['id'] ?? null;
        $new_user_id = $cm['new_chat_member']['user']['id'] ?? null;
        $old_status = $cm['old_chat_member']['status'] ?? '';
        $new_status = $cm['new_chat_member']['status'] ?? '';
        $event_date = $cm['date'] ?? null;
        if ($chat_id === null || $new_user_id === null || $event_date === null) {
            return null;
        }

        if ($new_status === 'member' && in_array($old_status, ['left', 'kicked'], true)) {
            return 'join_cm:' . $chat_id . ':' . $new_user_id . ':' . $event_date;
        }
    }

    return null;
}

/**
 * 入群事件幂等去重：已处理过则返回 true
 */
function isDuplicateJoinEvent($db, $event_key) {
    if (empty($event_key)) {
        return false;
    }

    try {
        ensureWebhookProcessedUpdatesTable($db);

        $bot_hash = hash('sha256', BOT_TOKEN);
        $stmt = $db->prepare("INSERT IGNORE INTO webhook_processed_events (bot_hash, event_key) VALUES (?, ?)");
        $stmt->execute([$bot_hash, $event_key]);

        // 与 update_id 去重保持一致，低频清理历史记录
        if (mt_rand(1, 200) === 1) {
            $db->exec("DELETE FROM webhook_processed_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        }

        return $stmt->rowCount() === 0;
    } catch (Exception $e) {
        error_log("Join event dedup check failed: " . $e->getMessage());
        return false;
    }
}

// 获取 Telegram 发送的更新
// 注意：php://input 只能读取一次，所以使用前面已经读取的 $raw_input
$content = '';
$input_source = 'unknown';

// 使用前面已经读取的 $raw_input（第39行已经读取过）
        if (!empty($raw_input)) {
            $content = $raw_input;
    $input_source = 'php://input (cached)';
    error_log("Using cached raw_input, size: " . strlen($content));
}

// 方法2: 从 HTTP_RAW_POST_DATA 读取（旧版PHP，备用）
if (empty($content) && isset($HTTP_RAW_POST_DATA)) {
    $content = $HTTP_RAW_POST_DATA;
    $input_source = 'HTTP_RAW_POST_DATA';
}

error_log("Input source: $input_source");
error_log("Raw input length: " . strlen($content));
error_log("Received raw content: " . substr($content, 0, 500));  // 只记录前500字符

if (empty($content)) {
    error_log("WARNING: Empty input received, this might be a health check");
    http_response_code(200);
    exit;
}

$update = json_decode($content, true);
error_log("Decoded update: " . print_r($update, true));  // 记录解码后的数据

if (!$update) {
    error_log("No valid update data received, JSON decode error: " . json_last_error_msg());
    exit;
}

// update_id 去重：防止 Telegram 重试/重复投递导致重复处理
try {
    $db_for_dedup = getDB();
    $current_update_id = $update['update_id'] ?? null;
    if (isDuplicateWebhookUpdate($db_for_dedup, $current_update_id)) {
        error_log("Duplicate update skipped, update_id: " . $current_update_id);
        http_response_code(200);
        exit;
    }

    // 事件指纹去重：拦截不同 update_id 但同一入群事件的重复投递
    $join_event_key = buildJoinEventDedupKey($update);
    if ($join_event_key && isDuplicateJoinEvent($db_for_dedup, $join_event_key)) {
        error_log("Duplicate join event skipped, key: " . $join_event_key);
        http_response_code(200);
        exit;
    }
} catch (Exception $e) {
    // 去重环节异常时不终止，继续处理业务逻辑
    error_log("Dedup init failed, continue processing: " . $e->getMessage());
}

// 记录详细的消息信息
if (isset($update['message'])) {
    $message = $update['message'];
    $log_info = [
        'type' => 'Message received',
        'chat_id' => $message['chat']['id'] ?? 'unknown',
        'chat_type' => $message['chat']['type'] ?? 'unknown',
        'chat_title' => $message['chat']['title'] ?? 'unknown',
        'text' => $message['text'] ?? 'no text',
        'from_user' => [
            'id' => $message['from']['id'] ?? 'unknown',
            'username' => $message['from']['username'] ?? 'unknown',
            'first_name' => $message['from']['first_name'] ?? 'unknown'
        ]
    ];
    logSystem('info', 'New message: ' . ($message['text'] ?? 'no text'), $log_info);
} else {
    // 记录其他类型的更新
    logSystem('info', 'Other update received', $update);
}

// 初始化 Bot
$bot = new TelegramBot(BOT_TOKEN);
$db = getDB();
$bot->clearMessageContext();

// 确保黑名单表存在
ensureBlacklistTablesExist($db);

/**
 * 从「新成员」服务消息解析邀请者：与入群者同一人时无法识别（链接入群等），由 chat_member 更新补充
 */
$resolveJoinInviter = function ($message, $new_member) {
    $inviter = $message['from'] ?? null;
    if ($inviter && !empty($inviter['is_bot'])) {
        return null;
    }
    if ($inviter && isset($inviter['id'], $new_member['id']) && (int) $inviter['id'] === (int) $new_member['id']) {
        return null;
    }
    return $inviter;
};

// chat_member：超级群成员变更，邀请者信息通常比 new_chat_members 的 from 更可靠
if (isset($update['chat_member'])) {
    $cm = $update['chat_member'];
    $chat = $cm['chat'] ?? null;
    if ($chat && ($chat['type'] ?? '') !== 'private') {
        $chat_id = $chat['id'];
        $new_part = $cm['new_chat_member'] ?? [];
        $old_part = $cm['old_chat_member'] ?? [];
        $new_user = $new_part['user'] ?? null;
        $new_status = $new_part['status'] ?? '';
        $old_status = $old_part['status'] ?? '';
        if ($new_user && empty($new_user['is_bot']) && $new_status === 'member') {
            // restricted→member 多为验证通过，不应按「新入群」处理
            $was_already_in = in_array($old_status, ['member', 'administrator', 'creator', 'restricted'], true);
            if (!$was_already_in) {
                saveGroup($db, $chat);
                $from_user = $cm['from'] ?? null;
                $inviter = null;
                if ($from_user && empty($from_user['is_bot']) && isset($from_user['id'], $new_user['id']) && (int) $from_user['id'] !== (int) $new_user['id']) {
                    $inviter = $from_user;
                }
                if (handleBlacklistOnJoin($bot, $db, $chat_id, $new_user, $inviter)) {
                    error_log("chat_member: blacklisted user {$new_user['id']} handled in chat $chat_id");
                } else {
                    // 非黑名单用户，保存成员信息（含邀请者，用于后续连坐查询）
                    saveMember($db, $chat_id, $new_user, $inviter);
                }
            }
        }
    }
    http_response_code(200);
    exit;
}

// ⚠️ 优先处理新成员加入事件(必须在普通消息处理之前)
if (isset($update['message']['new_chat_members'])) {
    error_log("New members joined!");
    logSystem('info', '新成员加入', $update['message']);
    
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $join_message_id = $message['message_id'];
    
    // 首先保存群组信息
    if (isset($message['chat'])) {
        error_log("Saving group info for new members");
        saveGroup($db, $message['chat']);
    }
    
    error_log("Processing new members for chat: " . $chat_id);
    
    // 获取群组ID
    $stmt = $db->prepare("SELECT id FROM groups WHERE chat_id = ?");
    $stmt->execute([$chat_id]);
    $group = $stmt->fetch();
    
    if (!$group) {
        error_log("Group not found for verification check");
        // 如果找不到群组，继续正常欢迎流程
        foreach ($update['message']['new_chat_members'] as $new_member) {
            $inviter = $resolveJoinInviter($message, $new_member);
            // 🔒 黑名单检测 - 如果用户在黑名单中，踢出并跳过
            if (handleBlacklistOnJoin($bot, $db, $chat_id, $new_member, $inviter)) {
                error_log("User {$new_member['id']} kicked (blacklisted)");
                continue;
            }
            
            saveMember($db, $chat_id, $new_member, $inviter);
            sendWelcomeMessage($bot, $db, $chat_id, $new_member, $join_message_id);
            updateStatistics($db, $chat_id, 'new_member');
        }
    } else {
        // 检查是否启用进群验证
        $stmt = $db->prepare("SELECT * FROM verification_settings WHERE (group_id = ? OR group_id IS NULL) AND is_active = 1 ORDER BY group_id DESC LIMIT 1");
        $stmt->execute([$group['id']]);
        $verification = $stmt->fetch();
        
        error_log("Verification settings found: " . ($verification ? 'yes' : 'no'));
        if ($verification) {
            error_log("Verification enabled for group, type: " . $verification['verification_type']);
        }
        
        foreach ($update['message']['new_chat_members'] as $new_member) {
            // 跳过机器人
            if (isset($new_member['is_bot']) && $new_member['is_bot']) {
                error_log("Skipping bot member");
                continue;
            }
            
            error_log("Processing new member: " . $new_member['first_name'] . " (ID: " . $new_member['id'] . ")");
            
            $inviter = $resolveJoinInviter($message, $new_member);
            // 🔒 黑名单检测 - 如果用户在黑名单中，踢出并跳过后续处理
            if (handleBlacklistOnJoin($bot, $db, $chat_id, $new_member, $inviter)) {
                error_log("User {$new_member['id']} kicked (blacklisted)");
                continue;
            }
            
            // 保存成员信息（含邀请者，用于后续连坐查询）
            saveMember($db, $chat_id, $new_member, $inviter);
            
            if ($verification) {
                // 启用了验证，进行验证流程
                error_log("Starting verification process for user " . $new_member['id']);
                handleNewMemberVerification($bot, $db, $chat_id, $new_member, $verification, $join_message_id);
            } else {
                // 未启用验证，正常发送欢迎消息
                error_log("No verification enabled, sending welcome message");
                sendWelcomeMessage($bot, $db, $chat_id, $new_member, $join_message_id);
            }
            
            // 更新统计
            updateStatistics($db, $chat_id, 'new_member');
        }
    }
    // 处理完新成员加入后直接退出，不再处理后续逻辑
    exit;
}

// 处理消息
if (isset($update['message']) || isset($update['channel_post'])) {
    // 支持普通消息和频道消息
    $message = isset($update['message']) ? $update['message'] : $update['channel_post'];
    error_log("Processing message: " . print_r($message, true));
    
    $chat_id = $message['chat']['id'];
    $message_thread_id = $message['message_thread_id'] ?? null;
    $chat_type = $message['chat']['type'];
    $bot->setMessageContext($chat_id, $message_thread_id);
    // 处理频道消息的特殊情况
    $user_id = isset($message['from']) ? $message['from']['id'] : 
              (isset($message['sender_chat']) ? $message['sender_chat']['id'] : $chat_id);
    $text = $message['text'] ?? '';
    $message_id = $message['message_id'];
    
    // 确保群组信息存在
    try {
        // 先检查群组是否存在
        $stmt = $db->prepare("SELECT * FROM groups WHERE chat_id = ?");
        $stmt->execute([$chat_id]);
        $existing_group = $stmt->fetch();
        
        if (!$existing_group) {
            error_log("Group not found, creating new group record");
            // 强制创建群组记录
            $stmt = $db->prepare("INSERT INTO groups (chat_id, title, type, is_active) VALUES (?, ?, ?, 1)");
            $stmt->execute([
                $chat_id,
                $message['chat']['title'] ?? 'Unknown',
                $chat_type
            ]);
            error_log("New group created with chat_id: " . $chat_id);
        } else {
            error_log("Group exists: " . print_r($existing_group, true));
        }
    } catch (Exception $e) {
        error_log("Error handling group: " . $e->getMessage());
    }
    
    error_log("Processing message: chat_id=$chat_id, type=$chat_type, text=$text");
    
    // 强制保存群组信息
    saveGroup($db, $message['chat']);
    
    // 记录消息到系统日志
    logSystem('info', '处理新消息', [
        'chat_id' => $chat_id,
        'chat_type' => $chat_type,
        'text' => $text,
        'from' => [
            'id' => $user_id,
            'username' => isset($message['from']) ? ($message['from']['username'] ?? 'unknown') : 'channel',
            'first_name' => isset($message['from']) ? ($message['from']['first_name'] ?? 'unknown') : 'channel'
        ]
    ]);
    
    // 处理私聊消息
    if ($chat_type == 'private') {
        error_log("私聊消息 - User ID: $user_id, Text: $text");
        
        // 检查是否是视频分享链接
        error_log("检查视频分享链接...");
        if (checkVideoShare($bot, $db, $chat_id, $user_id, $text, $message)) {
            error_log("视频分享处理完成");
            exit;
        }
        
        // 检查是否是视频卡密
        error_log("检查视频卡密...");
        if (checkVideoCard($bot, $db, $chat_id, $user_id, $text, $message)) {
            error_log("视频卡密处理完成");
            exit;
        }
        
        // 检查是否是视频关键词搜索
        error_log("检查视频关键词搜索...");
        if (searchVideoByKeyword($bot, $db, $chat_id, $user_id, $text, $message)) {
            error_log("视频关键词搜索完成");
            exit;
        }
        
        // 如果是 /start 命令，显示主菜单
        if ($text == '/start' || $text == '/menu') {
            // 获取或创建用户
            ensureUserExists($db, $user_id, $message['from']);
            // 清除会话状态
            clearUserSession($db, $user_id);
            sendMainMenu($bot, $db, $chat_id, $user_id);
            exit;
        }
        
        // 如果是 /shop 命令，显示商城
        if ($text == '/shop') {
            ensureUserExists($db, $user_id, $message['from']);
            sendCategoryList($bot, $db, $chat_id, $user_id);
            exit;
        }
        
        // 检查是否在等待用户输入购买数量
        if (is_numeric($text) && intval($text) > 0) {
            error_log("User sent numeric input: $text");
            $session = getUserSession($db, $user_id);
            error_log("Session data: " . json_encode($session));
            if ($session && $session['action'] == 'buy_input' && !empty($session['data'])) {
                error_log("User input quantity: $text for product: " . $session['data']);
                $product_id = $session['data'];
                $quantity = intval($text);
                
                // 清除会话
                clearUserSession($db, $user_id);
                
                // 处理购买
                handlePurchase($bot, $db, $chat_id, $user_id, null, $product_id, $quantity, $message);
                exit;
            } else {
                error_log("No valid session found for numeric input");
            }
        }
        
        // 检查是否是普通模式（发卡功能关闭）
        if (!isShopEnabled($db)) {
            // 尝试处理普通模式按钮
            if (handleNormalModeButton($bot, $db, $chat_id, $user_id, $text)) {
                exit;
            }
            // 如果不是按钮，继续处理其他逻辑（如自定义命令等）
        }
        
        // 处理底部固定按钮点击（Reply Keyboard）- 发卡模式
        // 移除emoji前缀进行匹配
        $button_text = preg_replace('/^[^\s]+\s+/', '', $text); // 移除开头的emoji和空格
        
        // 获取用户语言
        $user_lang = getUserLanguage($db, $user_id);
        
        // 匹配按钮文本
        if (strpos($text, '📂') !== false || $button_text == getLang('btn_categories', $user_lang)) {
            // 商品分类
            ensureUserExists($db, $user_id, $message['from']);
            sendCategoryList($bot, $db, $chat_id, $user_id);
            exit;
        }
        
        if (strpos($text, '🛒') !== false || $button_text == getLang('btn_products', $user_lang)) {
            // 商品列表
            ensureUserExists($db, $user_id, $message['from']);
            sendProductList($bot, $db, $chat_id, null, $user_id);
            exit;
        }
        
        if (strpos($text, '📧') !== false || $button_text == getLang('btn_my_orders', $user_lang)) {
            // 我的订单
            ensureUserExists($db, $user_id, $message['from']);
            sendUserOrders($bot, $db, $chat_id, $user_id);
            exit;
        }
        
        if (strpos($text, '👤') !== false || $button_text == getLang('btn_profile', $user_lang)) {
            // 个人中心
            ensureUserExists($db, $user_id, $message['from']);
            sendUserProfile($bot, $db, $chat_id, $user_id);
            exit;
        }
        
        if (strpos($text, '💰') !== false || $button_text == getLang('btn_recharge', $user_lang)) {
            // 余额充值
            ensureUserExists($db, $user_id, $message['from']);
            sendRechargeOptions($bot, $db, $chat_id, $user_id);
            exit;
        }
        
        if (strpos($text, '🧑‍💼') !== false || $button_text == getLang('btn_contact', $user_lang)) {
            // 联系客服
            sendContactInfo($bot, $db, $chat_id);
            exit;
        }
        
        if (strpos($text, '🌐') !== false || $button_text == getLang('btn_language', $user_lang)) {
            // 语言选择
            sendLanguageSelection($bot, $db, $chat_id, $user_id);
            exit;
        }
    }
    
    // 处理群组和频道消息
    if ($chat_type == 'group' || $chat_type == 'supergroup' || $chat_type == 'channel') {
        
        // 保存或更新群组信息
        saveGroup($db, $message['chat']);
        
        // 保存成员信息（只在非频道消息时保存）
        if ($chat_type != 'channel' && isset($message['from'])) {
            saveMember($db, $chat_id, $message['from']);
            
            // 🔒 黑名单检测 - 检查发消息的用户是否在黑名单中
            if (handleBlacklistOnMessage($bot, $db, $chat_id, $message['from'])) {
                $bot->deleteMessage($chat_id, $message_id);
                error_log("Message deleted, user is blacklisted");
                exit;
            }
            
            // 👁️ 改名监控 - 追踪用户名变化并播报
            handleNameChangeNotification($bot, $db, $chat_id, $message['from']);
        }
        
        // ⭐ 检查视频采集（在所有其他处理之前）
        // 1. 直接发送的视频
        if (isset($message['video']) || isset($message['video_note']) || isset($message['animation'])) {
            error_log("检测到视频消息，开始采集...");
            collectVideoFromGroup($bot, $db, $chat_id, $message);
        }
        // 2. Telegram消息链接（如 https://t.me/channel/123）
        if (!empty($text) && preg_match('/https?:\/\/t\.me\/([a-zA-Z0-9_]+)\/(\d+)/i', $text, $matches)) {
            error_log("检测到Telegram消息链接: $text");
            collectVideoLinkFromGroup($bot, $db, $chat_id, $message, $matches[0], $matches[1], $matches[2]);
        }
        
        // 记录消息日志
        logMessage($db, $chat_id, $user_id, $message_id, $text, 'text');
        
        // 检查黑名单（旧的方式，保留向后兼容）
        if (isBlacklisted($db, $user_id, $chat_id)) {
            $bot->kickChatMember($chat_id, $user_id);
            $bot->deleteMessage($chat_id, $message_id);
            exit;
        }
        
        // 🔒 处理黑名单相关命令
        if (strpos($text, '/ban') === 0 || strpos($text, '/black') === 0) {
            handleBanCommand($bot, $db, $chat_id, $message, $message['from']);
            exit;
        }
        if (strpos($text, '/unban') === 0) {
            handleUnbanCommand($bot, $db, $chat_id, $message, $message['from']);
            exit;
        }
        if ($text === '/blacklist') {
            handleBlacklistCommand($bot, $db, $chat_id, $message['from']);
            exit;
        }
        
        // 处理命令
        if (strpos($text, '/') === 0) {
            handleCommand($bot, $db, $message);
            exit;
        }
        
        // 检测违禁词
        if (checkBannedWords($bot, $db, $chat_id, $text, $message_id, $user_id)) {
            exit;
        }
        
        // 防洪水检测
        if (checkAntiFlood($bot, $db, $chat_id, $user_id, $message_id)) {
            exit;
        }
        
        // 关键词监控（不影响消息流程）
        checkKeywordMonitor($bot, $db, $chat_id, $text, $message);
        
        // 检查TRC地址查询（在自动回复之前）
        if (checkTrcAddressQuery($bot, $db, $chat_id, $text, $message)) {
            exit;
        }
        
        // 检查汇率查询
        if (checkExchangeRateQuery($bot, $db, $chat_id, $text, $message)) {
            exit;
        }
        
        // 检查@用户是否为群成员
        if (checkMentionedUsers($bot, $db, $chat_id, $message)) {
            // 不exit，允许消息继续处理
        }
        
        // 自动回复
        checkAutoReply($bot, $db, $chat_id, $text, $message);
    }
}

// 处理回调查询（验证按钮点击和商城操作）
if (isset($update['callback_query'])) {
    $callback_query = $update['callback_query'];
    $callback_data = $callback_query['data'] ?? '';

    if (isset($callback_query['message']['chat']['id'])) {
        $callback_chat_id = $callback_query['message']['chat']['id'];
        $callback_thread_id = $callback_query['message']['message_thread_id'] ?? null;
        $bot->setMessageContext($callback_chat_id, $callback_thread_id);
    } else {
        $bot->clearMessageContext();
    }
    
    error_log("Callback query received: " . $callback_data);
    
    // 视频播放回调
    if (strpos($callback_data, 'video_play_') === 0) {
        error_log("Handling video play callback: " . $callback_data);
        handleVideoPlayCallback($bot, $db, $callback_query);
    }
    // 广告二级菜单回调
    elseif (strpos($callback_data, 'submenu_') === 0 || strpos($callback_data, 'backmenu_') === 0) {
        error_log("Handling ad submenu callback: " . $callback_data);
        handleAdSubMenuCallback($bot, $db, $callback_query);
    }
    // 商城相关的回调
    elseif (strpos($callback_data, 'shop_') === 0 || 
        strpos($callback_data, 'cat_') === 0 || 
        strpos($callback_data, 'prod_') === 0 ||
        strpos($callback_data, 'buy_') === 0 ||
        strpos($callback_data, 'order_') === 0 ||
        strpos($callback_data, 'recharge_') === 0 ||
        strpos($callback_data, 'lang_') === 0) {
        error_log("Handling shop callback: " . $callback_data);
        handleShopCallback($bot, $db, $callback_query);
    } else {
        // 原有的验证回调
        handleCallbackQuery($bot, $db, $callback_query);
    }
}

// 处理机器人状态变化（被加入/移除群组或频道）
if (isset($update['my_chat_member'])) {
    $chat = $update['my_chat_member']['chat'];
    $chat_id = $chat['id'];
    $chat_type = $chat['type'];
    $chat_title = $chat['title'] ?? 'Unknown';
    $new_status = $update['my_chat_member']['new_chat_member']['status'];
    $old_status = $update['my_chat_member']['old_chat_member']['status'] ?? 'none';
    
    error_log("Bot status changed: $old_status -> $new_status in $chat_type ($chat_id)");
    
    // 机器人被加入群组或频道
    if (in_array($new_status, ['member', 'administrator'])) {
        // 保存群组/频道信息
        saveGroup($db, $chat);
        
        // 记录日志
        logSystem('info', 'Bot was added to ' . $chat_type, [
            'chat_id' => $chat_id,
            'title' => $chat_title,
            'status' => $new_status,
            'chat_type' => $chat_type
        ]);
        
        error_log("Bot added to $chat_type: $chat_title ($chat_id) as $new_status");
        exit;
    }
    
    // 机器人被踢出或离开
    if ($new_status == 'left' || $new_status == 'kicked') {
        // 标记群组为已删除
        $stmt = $db->prepare("UPDATE groups SET is_active = 0, is_deleted = 1, updated_at = NOW() WHERE chat_id = ?");
        $stmt->execute([$chat_id]);
        
        // 记录日志
        logSystem('info', 'Bot was removed from ' . $chat_type, [
            'chat_id' => $chat_id,
            'title' => $chat_title,
            'status' => $new_status,
            'chat_type' => $chat_type
        ]);
        
        // 清理相关数据
        $stmt = $db->prepare("UPDATE group_members SET status = 'left', updated_at = NOW() WHERE group_id = (SELECT id FROM groups WHERE chat_id = ?)");
        $stmt->execute([$chat_id]);
        
        error_log("Bot removed from $chat_type: $chat_title ($chat_id)");
        exit;
    }
}

// 处理成员离开
if (isset($update['message']['left_chat_member'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $user_id = $update['message']['left_chat_member']['id'];
    
    // 更新成员状态
    $stmt = $db->prepare("UPDATE group_members SET status = 'left' WHERE group_id = (SELECT id FROM groups WHERE chat_id = ?) AND user_id = ?");
    $stmt->execute([$chat_id, $user_id]);
    
    // 更新统计
    updateStatistics($db, $chat_id, 'left_member');
}

// 保存群组信息
function saveGroup($db, $chat) {
    try {
        // 打印完整的群组信息
        error_log("Saving group data: " . print_r($chat, true));
        
        // 记录开始保存群组
        logSystem('info', '正在保存群组: ' . ($chat['title'] ?? 'Unknown'), [
            'chat_id' => $chat['id'],
            'title' => $chat['title'] ?? 'Unknown',
            'type' => $chat['type']
        ]);

        // 准备数据
        $chat_id = $chat['id'];
        $title = isset($chat['title']) ? $chat['title'] : 'Unknown';
        $type = isset($chat['type']) ? $chat['type'] : 'group';
        
        error_log("Prepared group data - chat_id: $chat_id, title: $title, type: $type");
        
        // 检查群组是否已存在
        $stmt = $db->prepare("SELECT * FROM groups WHERE chat_id = ?");
        $stmt->execute([$chat_id]);
        $existing = $stmt->fetch();

        // 保存或更新群组（恢复被删除的群组）
        $stmt = $db->prepare("
            INSERT INTO groups (chat_id, title, type, is_active, is_deleted) 
            VALUES (?, ?, ?, 1, 0) 
            ON DUPLICATE KEY UPDATE 
                title = ?, 
                type = ?,
                is_active = 1,
                is_deleted = 0,
                updated_at = CURRENT_TIMESTAMP
        ");
        
        try {
            $stmt->execute([
                $chat_id,
                $title,
                $type,
                $title,
                $type
            ]);
            
            // 如果是恢复被删除的群组，记录日志
            if ($existing && ($existing['is_deleted'] == 1 || $existing['is_active'] == 0)) {
                error_log("Group restored from deleted/inactive state: $chat_id");
                logSystem('info', '群组已恢复: ' . $title, [
                    'chat_id' => $chat_id,
                    'previous_state' => [
                        'is_active' => $existing['is_active'],
                        'is_deleted' => $existing['is_deleted']
                    ]
                ]);
            }
            
            error_log("Group saved/updated successfully");
        } catch (PDOException $e) {
            error_log("Database error while saving group: " . $e->getMessage());
            throw $e;
        }

        // 记录保存结果
        if ($existing) {
            logSystem('info', '群组更新成功: ' . ($chat['title'] ?? 'Unknown'), [
                'chat_id' => $chat['id'],
                'action' => 'updated'
            ]);
        } else {
            logSystem('info', '新群组添加成功: ' . ($chat['title'] ?? 'Unknown'), [
                'chat_id' => $chat['id'],
                'action' => 'inserted'
            ]);
        }

        // 验证保存结果
        $stmt = $db->prepare("SELECT * FROM groups WHERE chat_id = ?");
        $stmt->execute([$chat['id']]);
        $saved_group = $stmt->fetch();
        
        if (!$saved_group) {
            throw new Exception('群组保存后无法验证');
        }
    } catch (Exception $e) {
        logSystem('error', '保存群组失败: ' . $e->getMessage(), [
            'chat_id' => $chat['id'],
            'error' => $e->getMessage()
        ]);
    }
}

// 保存成员信息
function saveMember($db, $chat_id, $user, $inviter = null) {
    try {
        // 记录开始保存成员
        logSystem('info', '正在保存成员信息', [
            'chat_id' => $chat_id,
            'user_id' => $user['id'],
            'username' => $user['username'] ?? null,
            'first_name' => $user['first_name'] ?? null
        ]);

        // 确保 group_members 表有邀请者字段（首次自动迁移）
        static $inviterColumnsChecked = false;
        if (!$inviterColumnsChecked) {
            $inviterColumnsChecked = true;
            try {
                $chk = $db->query("SHOW COLUMNS FROM group_members LIKE 'invited_by_user_id'");
                if ($chk->rowCount() == 0) {
                    $db->exec("ALTER TABLE group_members ADD COLUMN invited_by_user_id BIGINT DEFAULT NULL AFTER status");
                    $db->exec("ALTER TABLE group_members ADD COLUMN invited_by_username VARCHAR(255) DEFAULT NULL AFTER invited_by_user_id");
                    error_log("Added invited_by columns to group_members");
                }
            } catch (Exception $e) {
                error_log("saveMember column check error: " . $e->getMessage());
            }
        }

        // 获取群组ID
        $stmt = $db->prepare("SELECT id, title FROM groups WHERE chat_id = ?");
        $stmt->execute([$chat_id]);
        $group = $stmt->fetch();
        
        if (!$group) {
            throw new Exception('找不到对应的群组记录');
        }

        // 检查成员是否已存在
        $stmt = $db->prepare("SELECT * FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$group['id'], $user['id']]);
        $existing = $stmt->fetch();

        $inviter_user_id = isset($inviter['id']) ? (int) $inviter['id'] : null;
        $inviter_username = $inviter['username'] ?? null;
        // Telegram 的 is_bot 可能是 bool/string/null；统一转成 0/1，避免严格 SQL 模式把 false 当成空串
        $is_bot = 0;
        if (array_key_exists('is_bot', $user)) {
            $raw_is_bot = $user['is_bot'];
            if (is_bool($raw_is_bot)) {
                $is_bot = $raw_is_bot ? 1 : 0;
            } elseif (is_numeric($raw_is_bot)) {
                $is_bot = ((int) $raw_is_bot) === 1 ? 1 : 0;
            } elseif (is_string($raw_is_bot)) {
                $normalized = strtolower(trim($raw_is_bot));
                $is_bot = in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
            }
        }

        // 保存或更新成员信息
        $stmt = $db->prepare("
            INSERT INTO group_members 
                (group_id, user_id, username, first_name, last_name, is_bot, status, invited_by_user_id, invited_by_username) 
            VALUES 
                (?, ?, ?, ?, ?, ?, 'member', ?, ?) 
            ON DUPLICATE KEY UPDATE 
                username = ?, 
                first_name = ?, 
                last_name = ?,
                status = 'member',
                invited_by_user_id = COALESCE(VALUES(invited_by_user_id), invited_by_user_id),
                invited_by_username = COALESCE(VALUES(invited_by_username), invited_by_username),
                updated_at = NOW()
        ");

        $stmt->execute([
            $group['id'],
            $user['id'],
            $user['username'] ?? null,
            $user['first_name'] ?? null,
            $user['last_name'] ?? null,
            $is_bot,
            $inviter_user_id,
            $inviter_username,
            $user['username'] ?? null,
            $user['first_name'] ?? null,
            $user['last_name'] ?? null,
        ]);

        // 记录保存结果
        if ($existing) {
            logSystem('info', '成员信息更新成功', [
                'group' => $group['title'],
                'user_id' => $user['id'],
                'username' => $user['username'] ?? null,
                'action' => 'updated'
            ]);
        } else {
            logSystem('info', '新成员添加成功', [
                'group' => $group['title'],
                'user_id' => $user['id'],
                'username' => $user['username'] ?? null,
                'action' => 'inserted'
            ]);
        }
        
        error_log("Member saved: user_id={$user['id']}, username=" . ($user['username'] ?? 'NULL') . ", group={$group['title']}" . ($inviter_user_id ? ", invited_by=$inviter_user_id" : ''));
        
    } catch (Exception $e) {
        logSystem('error', '保存成员失败: ' . $e->getMessage(), [
            'chat_id' => $chat_id,
            'user_id' => $user['id'],
            'error' => $e->getMessage()
        ]);
        error_log("saveMember error: " . $e->getMessage());
    }
}

// 记录消息日志
function logMessage($db, $chat_id, $user_id, $message_id, $text, $type) {
    // 如果是频道消息且user_id为null，使用chat_id作为user_id
    if ($user_id === null) {
        $user_id = $chat_id;
    }
    try {
        error_log("Trying to log message: chat_id=$chat_id, user_id=$user_id, text=$text");
        
        // 先检查群组是否存在
        $stmt = $db->prepare("SELECT id, title FROM groups WHERE chat_id = ?");
        $stmt->execute([$chat_id]);
        $group = $stmt->fetch();
        
        error_log("Found group: " . print_r($group, true));
        
        // 如果找不到群组，尝试创建
        if (!$group) {
            error_log("Group not found in logMessage, creating new group");
            $stmt = $db->prepare("INSERT INTO groups (chat_id, title, type, is_active) VALUES (?, 'Unknown Group', 'group', 1)");
            $stmt->execute([$chat_id]);
            
            // 重新获取群组ID
            $stmt = $db->prepare("SELECT id, title FROM groups WHERE chat_id = ?");
            $stmt->execute([$chat_id]);
            $group = $stmt->fetch();
            error_log("Created new group: " . print_r($group, true));
        }
        
        if (!$group) {
            error_log("Group not found for chat_id: $chat_id");
            return;
        }
        
        // 记录消息
        $stmt = $db->prepare("INSERT INTO message_logs (group_id, user_id, message_id, message_text, message_type) VALUES (?, ?, ?, ?, ?)");
        $result = $stmt->execute([$group['id'], $user_id, $message_id, $text, $type]);
        
        if ($result) {
            error_log("Message logged successfully for group: {$group['title']}");
            // 记录到系统日志
            logSystem('info', '收到新消息', [
                'group' => $group['title'],
                'message' => $text,
                'type' => $type,
                'user_id' => $user_id
            ]);
        } else {
            error_log("Failed to log message");
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("Log message error: $error");
        logSystem('error', 'Log message error', [
            'error' => $error,
            'chat_id' => $chat_id,
            'user_id' => $user_id
        ]);
    }
}

// 检查黑名单
function isBlacklisted($db, $user_id, $chat_id) {
    $stmt = $db->prepare("SELECT id FROM blacklist WHERE user_id = ? AND (group_id IS NULL OR group_id = (SELECT id FROM groups WHERE chat_id = ?))");
    $stmt->execute([$user_id, $chat_id]);
    return $stmt->fetch() !== false;
}

// 检测违禁词
function checkBannedWords($bot, $db, $chat_id, $text, $message_id, $user_id) {
    $stmt = $db->prepare("SELECT bw.* FROM banned_words bw LEFT JOIN groups g ON bw.group_id = g.id WHERE bw.is_active = 1 AND (bw.group_id IS NULL OR g.chat_id = ?)");
    $stmt->execute([$chat_id]);
    $banned_words = $stmt->fetchAll();
    
    foreach ($banned_words as $banned) {
        $matched = false;
        
        switch ($banned['match_type']) {
            case 'exact':
                $matched = strcasecmp($text, $banned['word']) === 0;
                break;
            case 'contains':
                $matched = stripos($text, $banned['word']) !== false;
                break;
            case 'starts_with':
                $matched = stripos($text, $banned['word']) === 0;
                break;
            case 'ends_with':
                $matched = substr_compare($text, $banned['word'], -strlen($banned['word']), strlen($banned['word']), true) === 0;
                break;
            case 'regex':
                $matched = @preg_match('/' . $banned['word'] . '/iu', $text);
                break;
            default:
                $matched = false;
        }
        
        if ($matched) {
            // 删除消息
            $bot->deleteMessage($chat_id, $message_id);
            
            // 执行所有选中的操作
            if ($banned['delete_message']) {
                $bot->deleteMessage($chat_id, $message_id);
            }
            
            if ($banned['warn_user']) {
                $bot->sendMessage($chat_id, "⚠️ Warning: Prohibited content detected!");
            }
            
            if ($banned['kick_user']) {
                $bot->kickChatMember($chat_id, $user_id);
                $bot->unbanChatMember($chat_id, $user_id); // 允许用户再次加入
            }
            
            if ($banned['ban_user']) {
                $bot->kickChatMember($chat_id, $user_id, 0); // 永久封禁
            }
            
            // 记录违规
            $stmt = $db->prepare("SELECT id FROM groups WHERE chat_id = ?");
            $stmt->execute([$chat_id]);
            $group = $stmt->fetch();
            
            if ($group) {
                $stmt = $db->prepare("INSERT INTO violation_logs (group_id, user_id, violation_type, message, action_taken) VALUES (?, ?, 'banned_word', ?, ?)");
                $stmt->execute([$group['id'], $user_id, $text, $banned['action']]);
                
                updateStatistics($db, $chat_id, 'violation');
            }
            
            return true;
        }
    }
    
    return false;
}

// 防洪水检测
function checkAntiFlood($bot, $db, $chat_id, $user_id, $message_id) {
    $stmt = $db->prepare("SELECT af.* FROM antiflood_settings af JOIN groups g ON af.group_id = g.id WHERE g.chat_id = ? AND af.is_active = 1");
    $stmt->execute([$chat_id]);
    $settings = $stmt->fetch();
    
    if (!$settings) {
        return false;
    }
    
    // 检查时间窗口内的消息数量
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM message_logs ml JOIN groups g ON ml.group_id = g.id WHERE g.chat_id = ? AND ml.user_id = ? AND ml.created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute([$chat_id, $user_id, $settings['time_window']]);
    $result = $stmt->fetch();
    
    if ($result['count'] > $settings['max_messages']) {
        // 执行相应操作
        switch ($settings['action']) {
            case 'mute':
                $bot->restrictChatMember($chat_id, $user_id, time() + $settings['mute_duration']);
                $bot->sendMessage($chat_id, "🔇 User muted for " . ($settings['mute_duration'] / 60) . " minutes (spamming)");
                break;
            case 'kick':
                $bot->kickChatMember($chat_id, $user_id);
                break;
            case 'ban':
                $bot->kickChatMember($chat_id, $user_id, 0);
                break;
            case 'warn':
                $bot->sendMessage($chat_id, "⚠️ Warning: Please do not spam!");
                break;
        }
        
        return true;
    }
    
    return false;
}

// 自动回复
function checkAutoReply($bot, $db, $chat_id, $text, $message) {
    error_log("Checking auto replies for text: $text in chat: $chat_id");
    
    $stmt = $db->prepare("SELECT ar.* FROM auto_replies ar LEFT JOIN groups g ON ar.group_id = g.id WHERE ar.is_active = 1 AND (ar.group_id IS NULL OR g.chat_id = ?)");
    $stmt->execute([$chat_id]);
    $replies = $stmt->fetchAll();
    
    error_log("Found " . count($replies) . " auto replies to check");
    
    // Extract user information for variable replacement
    $user = $message['from'] ?? [];
    $first_name = $user['first_name'] ?? 'User';
    $last_name = $user['last_name'] ?? '';
    $username = $user['username'] ?? '';
    $full_name = trim($first_name . ' ' . $last_name);
    $group_name = $message['chat']['title'] ?? 'this group';
    
    foreach ($replies as $reply) {
        $matched = false;
        
        switch ($reply['match_type']) {
            case 'exact':
                $matched = $text === $reply['trigger'];
                break;
            case 'contains':
                $matched = stripos($text, $reply['trigger']) !== false;
                break;
            case 'starts_with':
                $matched = stripos($text, $reply['trigger']) === 0;
                break;
            case 'ends_with':
                $matched = substr_compare($text, $reply['trigger'], -strlen($reply['trigger']), strlen($reply['trigger']), true) === 0;
                break;
            case 'regex':
                $matched = @preg_match('/' . $reply['trigger'] . '/iu', $text);
                break;
        }
        
        if ($matched) {
            error_log("Matched auto reply rule: " . $reply['trigger']);
            
            // Replace variables in response message
            $response = $reply['response'];
            $response = str_replace('{first_name}', $first_name, $response);
            $response = str_replace('{last_name}', $last_name, $response);
            $response = str_replace('{full_name}', $full_name, $response);
            $response = str_replace('{username}', $username ? '@' . $username : $first_name, $response);
            $response = str_replace('{user_id}', $user['id'] ?? '', $response);
            $response = str_replace('{group_name}', $group_name, $response);
            $response = str_replace('{name}', $first_name, $response); // Alias for {first_name}
            
            // Parse buttons if available - 支持新格式（每行多按钮+二级菜单）
            $replyMarkup = buildAdInlineKeyboard($reply['buttons'] ?? null, $reply['id'] ?? null);
            
            // 获取自毁时间
            $delete_after = isset($reply['delete_after_seconds']) ? intval($reply['delete_after_seconds']) : 0;
            
            // Send message with image or text
            if (!empty($reply['image_url'])) {
                // Build full URL for image
                $imageUrl = $reply['image_url'];
                if (!preg_match('/^https?:\/\//', $imageUrl)) {
                    // If it's a relative path, convert to full URL
                    $imageUrl = SITE_URL . '/' . ltrim($imageUrl, '/');
                }
                
                // Send photo with caption
                $result = $bot->sendPhoto($chat_id, $imageUrl, $response, $replyMarkup, 'HTML', $delete_after);
            } else {
                // Send text message
                $result = $bot->sendMessage($chat_id, $response, 'HTML', $replyMarkup, $delete_after);
            }
            
            // 如果设置了自毁时间，创建定时任务删除消息
            if ($result && $delete_after > 0 && isset($result['message_id'])) {
                scheduleMessageDeletion($db, $chat_id, $result['message_id'], $delete_after);
            }
            
            error_log("Auto reply send result: " . ($result ? 'success' : 'failed') . ", delete_after: " . $delete_after);
            break;
        }
    }
}

// 发送欢迎消息
function sendWelcomeMessage($bot, $db, $chat_id, $user, $join_message_id = null) {
    error_log("Sending welcome message for chat: $chat_id");
    logSystem('info', '准备发送欢迎消息', [
        'chat_id' => $chat_id,
        'user' => $user
    ]);

    // 先获取群组ID
    $stmt = $db->prepare("SELECT id FROM groups WHERE chat_id = ?");
    $stmt->execute([$chat_id]);
    $group = $stmt->fetch();

    if (!$group) {
        error_log("Group not found for welcome message");
        return;
    }

    // 获取欢迎消息设置
    // 优先查找该群组特定的欢迎消息，如果没有则使用"所有群组"的欢迎消息
    $stmt = $db->prepare("
        SELECT wm.* FROM welcome_messages wm 
        WHERE (wm.group_id = ? OR wm.group_id IS NULL) 
        AND wm.is_active = 1 
        ORDER BY wm.group_id DESC 
        LIMIT 1
    ");
    $stmt->execute([$group['id']]);
    $welcome = $stmt->fetch();

    error_log("Welcome message found: " . ($welcome ? 'yes' : 'no'));
    
    if ($welcome) {
        $name = $user['first_name'] ?? 'New Member';
        $message = str_replace('{name}', $name, $welcome['message']);
        $message = str_replace('{username}', '@' . ($user['username'] ?? $name), $message);
        
        // Parse buttons if available - 支持新格式（每行多按钮+二级菜单）
        $replyMarkup = buildAdInlineKeyboard($welcome['buttons'] ?? null, $welcome['id'] ?? null);
        
        error_log("Sending welcome message: $message");
        
        // 获取自毁时间
        $delete_after = isset($welcome['delete_after_seconds']) ? intval($welcome['delete_after_seconds']) : 0;
        
        // Send message with image or text
        if (!empty($welcome['image_url'])) {
            // Build full URL for image
            $imageUrl = $welcome['image_url'];
            if (!preg_match('/^https?:\/\//', $imageUrl)) {
                $imageUrl = SITE_URL . '/' . ltrim($imageUrl, '/');
            }
            
            $result = $bot->sendPhoto($chat_id, $imageUrl, $message, $replyMarkup, 'HTML', $delete_after);
        } else {
            $result = $bot->sendMessage($chat_id, $message, 'HTML', $replyMarkup, $delete_after);
        }
        
        // 如果设置了自毁时间，创建定时任务删除消息
        if ($result && $delete_after > 0 && isset($result['message_id'])) {
            scheduleMessageDeletion($db, $chat_id, $result['message_id'], $delete_after);
            
            // 同时删除加入消息
            if ($join_message_id) {
                scheduleMessageDeletion($db, $chat_id, $join_message_id, $delete_after);
            }
        }
        
        logSystem('info', '欢迎消息发送结果', [
            'success' => $result ? true : false,
            'message' => $message,
            'had_image' => !empty($welcome['image_url']),
            'had_buttons' => !empty($replyMarkup),
            'delete_after' => $delete_after
        ]);
    } else {
        error_log("No active welcome message found for group");
    }
}

// 处理新成员验证
function handleNewMemberVerification($bot, $db, $chat_id, $user, $verification, $join_message_id) {
    error_log("Starting verification for user: " . $user['id']);
    
    $user_id = $user['id'];
    $name = $user['first_name'] ?? 'New Member';
    
    // 先获取群组ID
    $stmt = $db->prepare("SELECT id FROM groups WHERE chat_id = ?");
    $stmt->execute([$chat_id]);
    $group = $stmt->fetch();
    
    if (!$group) {
        error_log("Group not found for verification");
        return;
    }
    
    // 限制新成员权限（禁言）
    $bot->restrictChatMember($chat_id, $user_id, null, false);
    
    // 生成验证消息和按钮
    $verification_code = null;
    $verification_message = $verification['verification_message'];
    $keyboard = [];
    
    switch ($verification['verification_type']) {
        case 'button':
            // 简单按钮验证
            if (!$verification_message) {
                $verification_message = "👋 Welcome {name}!\n\nPlease click the button below to complete verification before you can send messages.\n⏰ Timeout: {timeout} seconds";
            }
            $verification_message = str_replace('{name}', $name, $verification_message);
            $verification_message = str_replace('{timeout}', $verification['timeout_seconds'], $verification_message);
            
            $keyboard = [
                [
                    ['text' => '✅ I am not a robot', 'callback_data' => 'verify_' . $user_id]
                ]
            ];
            break;
            
        case 'math':
            // 数学题验证
            $num1 = rand(1, 10);
            $num2 = rand(1, 10);
            $answer = $num1 + $num2;
            $verification_code = strval($answer);
            
            if (!$verification_message) {
                $verification_message = "👋 Welcome {name}!\n\nPlease answer the following question to complete verification:\n❓ {num1} + {num2} = ?\n\nPlease click the correct answer within {timeout} seconds:";
            }
            $verification_message = str_replace('{name}', $name, $verification_message);
            $verification_message = str_replace('{num1}', $num1, $verification_message);
            $verification_message = str_replace('{num2}', $num2, $verification_message);
            $verification_message = str_replace('{timeout}', $verification['timeout_seconds'], $verification_message);
            
            // 生成答案按钮（包括错误答案）
            $options = [$answer];
            while (count($options) < 4) {
                $wrong = rand($answer - 5, $answer + 5);
                if (!in_array($wrong, $options) && $wrong > 0) {
                    $options[] = $wrong;
                }
            }
            shuffle($options);
            
            $button_row = [];
            foreach ($options as $option) {
                $button_row[] = ['text' => strval($option), 'callback_data' => 'verify_' . $user_id . '_' . $option];
            }
            $keyboard = [$button_row];
            break;
    }
    
    // 发送验证消息
    $result = $bot->sendMessage($chat_id, $verification_message, 'HTML', ['inline_keyboard' => $keyboard]);
    
    if ($result && isset($result['message_id'])) {
        $verification_message_id = $result['message_id'];
        $expires_at = date('Y-m-d H:i:s', time() + $verification['timeout_seconds']);
        
        // 保存待验证记录
        try {
            $stmt = $db->prepare("INSERT INTO pending_verifications (group_id, user_id, verification_message_id, join_message_id, verification_code, expires_at, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$group['id'], $user_id, $verification_message_id, $join_message_id, $verification_code, $expires_at]);
            
            // 创建定时任务，超时后踢出用户
            scheduleVerificationTimeout($db, $chat_id, $user_id, $verification['timeout_seconds'], $verification['kick_on_fail']);
            
            logSystem('info', '验证消息已发送', [
                'user_id' => $user_id,
                'chat_id' => $chat_id,
                'timeout' => $verification['timeout_seconds']
            ]);
        } catch (Exception $e) {
            error_log("Failed to save verification record: " . $e->getMessage());
        }
    }
}

// 处理回调查询
function handleCallbackQuery($bot, $db, $callback_query) {
    $callback_id = $callback_query['id'];
    $data = $callback_query['data'] ?? '';
    $user_id = $callback_query['from']['id'];
    $message = $callback_query['message'] ?? null;
    
    if (!$message) {
        return;
    }
    
    $chat_id = $message['chat']['id'];
    $message_id = $message['message_id'];
    
    // 处理导航栏按钮回调
    if (strpos($data, 'nav_') === 0) {
        handleNavigationCallback($bot, $db, $callback_id, $chat_id, $message_id, $data, $message);
        return;
    }
    
    // 处理验证回调
    if (strpos($data, 'verify_') === 0) {
        $parts = explode('_', $data);
        $target_user_id = isset($parts[1]) ? intval($parts[1]) : 0;
        $answer = isset($parts[2]) ? $parts[2] : null;
        
        // 检查是否是本人点击
        if ($user_id != $target_user_id) {
            $bot->answerCallbackQuery($callback_id, '⚠️ This is not your verification!', true);
            return;
        }
        
        // 获取群组ID
        $stmt = $db->prepare("SELECT id FROM groups WHERE chat_id = ?");
        $stmt->execute([$chat_id]);
        $group = $stmt->fetch();
        
        if (!$group) {
            return;
        }
        
        // 获取验证记录
        $stmt = $db->prepare("SELECT * FROM pending_verifications WHERE group_id = ? AND user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$group['id'], $user_id]);
        $verification = $stmt->fetch();
        
        if (!$verification) {
            $bot->answerCallbackQuery($callback_id, '❌ Verification record does not exist or has expired', true);
            return;
        }
        
        // 检查是否超时
        if (strtotime($verification['expires_at']) < time()) {
            $bot->answerCallbackQuery($callback_id, '⏰ Verification has timed out', true);
            $stmt = $db->prepare("UPDATE pending_verifications SET status = 'expired' WHERE id = ?");
            $stmt->execute([$verification['id']]);
            return;
        }
        
        // 验证答案
        $is_correct = false;
        if ($verification['verification_code'] === null) {
            // 简单按钮验证，直接通过
            $is_correct = true;
        } else {
            // 需要检查答案
            $is_correct = ($answer === $verification['verification_code']);
        }
        
        if ($is_correct) {
            // 验证通过
            $bot->answerCallbackQuery($callback_id, '✅ Verification successful! Welcome!');
            
            // 恢复用户权限
            $bot->unrestrictChatMember($chat_id, $user_id);
            
            // 更新验证状态
            $stmt = $db->prepare("UPDATE pending_verifications SET status = 'verified' WHERE id = ?");
            $stmt->execute([$verification['id']]);
            
            // 删除验证消息
            $bot->deleteMessage($chat_id, $message_id);
            
            // 删除加入消息
            if ($verification['join_message_id']) {
                $bot->deleteMessage($chat_id, $verification['join_message_id']);
            }
            
            // 检查是否需要发送欢迎消息
            $stmt = $db->prepare("SELECT * FROM verification_settings WHERE (group_id = ? OR group_id IS NULL) AND is_active = 1 ORDER BY group_id DESC LIMIT 1");
            $stmt->execute([$group['id']]);
            $settings = $stmt->fetch();
            
            if ($settings && $settings['welcome_after_verify']) {
                // 获取用户信息
                $user = $callback_query['from'];
                sendWelcomeMessage($bot, $db, $chat_id, $user);
            }
            
            logSystem('info', '用户验证成功', ['user_id' => $user_id, 'chat_id' => $chat_id]);
        } else {
            // 验证失败
            $bot->answerCallbackQuery($callback_id, '❌ Wrong answer, please try again', true);
        }
    }
}

// 创建消息删除定时任务
function scheduleMessageDeletion($db, $chat_id, $message_id, $delay_seconds) {
    try {
        $scheduled_at = date('Y-m-d H:i:s', time() + $delay_seconds);
        $data = json_encode(['chat_id' => $chat_id, 'message_id' => $message_id]);
        
        $stmt = $db->prepare("INSERT INTO scheduled_tasks (task_type, data, scheduled_at, status) VALUES ('delete_message', ?, ?, 'pending')");
        $stmt->execute([$data, $scheduled_at]);
        
        error_log("Scheduled message deletion: chat_id=$chat_id, message_id=$message_id, delay={$delay_seconds}s");
    } catch (Exception $e) {
        error_log("Failed to schedule message deletion: " . $e->getMessage());
    }
}

// 创建验证超时定时任务
function scheduleVerificationTimeout($db, $chat_id, $user_id, $timeout_seconds, $kick_on_fail) {
    try {
        $scheduled_at = date('Y-m-d H:i:s', time() + $timeout_seconds);
        $data = json_encode(['chat_id' => $chat_id, 'user_id' => $user_id, 'kick' => $kick_on_fail]);
        
        $stmt = $db->prepare("INSERT INTO scheduled_tasks (task_type, data, scheduled_at, status) VALUES ('verification_timeout', ?, ?, 'pending')");
        $stmt->execute([$data, $scheduled_at]);
        
        error_log("Scheduled verification timeout: user_id=$user_id, timeout={$timeout_seconds}s");
    } catch (Exception $e) {
        error_log("Failed to schedule verification timeout: " . $e->getMessage());
    }
}

// 处理命令
function handleCommand($bot, $db, $message) {
    $text = $message['text'];
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    
    $parts = explode(' ', $text);
    $command = strtolower($parts[0]);
    
    // 移除 @ 和 bot 用户名
    $command = explode('@', $command)[0];
    
    switch ($command) {
        case '/start':
        case '/help':
            $help_text = "🤖 <b>Bot Help Menu</b>\n\n";
            $help_text .= "/help - Show this help\n";
            $help_text .= "/stats - Group statistics\n";
            $help_text .= "/rules - Group rules\n";
            $bot->sendMessage($chat_id, $help_text, 'HTML');
            break;
            
        case '/stats':
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM group_members gm JOIN groups g ON gm.group_id = g.id WHERE g.chat_id = ? AND gm.status = 'member'");
            $stmt->execute([$chat_id]);
            $members = $stmt->fetch();
            
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM message_logs ml JOIN groups g ON ml.group_id = g.id WHERE g.chat_id = ? AND DATE(ml.created_at) = CURDATE()");
            $stmt->execute([$chat_id]);
            $messages = $stmt->fetch();
            
            $stats_text = "📊 <b>Group Statistics</b>\n\n";
            $stats_text .= "👥 Members: " . $members['count'] . "\n";
            $stats_text .= "💬 Today's Messages: " . $messages['count'] . "\n";
            
            $bot->sendMessage($chat_id, $stats_text, 'HTML');
            break;
            
        default:
            // 检查自定义命令
            $stmt = $db->prepare("SELECT cc.* FROM custom_commands cc LEFT JOIN groups g ON cc.group_id = g.id WHERE cc.command = ? AND cc.is_active = 1 AND (cc.group_id IS NULL OR g.chat_id = ?)");
            $stmt->execute([$command, $chat_id]);
            $custom_cmd = $stmt->fetch();
            
            if ($custom_cmd) {
                $bot->sendMessage($chat_id, $custom_cmd['response']);
            }
            break;
    }
}

// 使用真人账号发送消息（MadelineProto）
function sendUserAccountMessage($db, $chat_id, $message_text, $reply_to_message_id = null) {
    try {
        // 获取用户账号配置
        $stmt = $db->prepare("SELECT * FROM user_account_config WHERE id = 1 AND is_logged_in = 1");
        $stmt->execute();
        $config = $stmt->fetch();
        
        if (!$config || empty($config['session_data'])) {
            error_log("User account not logged in or session not found");
            return false;
        }
        
        $session_file = $config['session_data'];
        
        if (!file_exists($session_file)) {
            error_log("Session file does not exist: $session_file");
            return false;
        }
        
        error_log("Loading MadelineProto from session: $session_file");
        
        // 创建设置
        $settings = new Settings;
        $settings->getLogger()->setType(Logger::FILE_LOGGER);
        $settings->getLogger()->setExtra(__DIR__ . '/../sessions/madeline.log');
        $settings->getLogger()->setLevel(Logger::ERROR);
        
        // 配置序列化（使用较大值以减少性能影响）
        $settings->getSerialization()->setInterval(60); // 60秒序列化一次
        error_log("Configured serialization interval for webhook: 60 seconds");
        
        // 配置 API 凭证（统一从 config.php 读取）
        $api_id = TELEGRAM_API_ID;
        $api_hash = TELEGRAM_API_HASH;
        $settings->getAppInfo()->setApiId($api_id);
        $settings->getAppInfo()->setApiHash($api_hash);
        
        // 加载 MadelineProto 实例
        $MadelineProto = new API($session_file, $settings);
        
        // 准备消息参数
        $send_params = [
            'peer' => $chat_id,
            'message' => $message_text
        ];
        
        // 如果需要回复某条消息
        if ($reply_to_message_id) {
            $send_params['reply_to_msg_id'] = $reply_to_message_id;
        }
        
        error_log("Sending message via MadelineProto: " . json_encode($send_params));
        
        // 发送消息
        $result = $MadelineProto->messages->sendMessage($send_params);
        
        error_log("MadelineProto sendMessage result: " . json_encode($result));
        
        return $result ? true : false;
        
    } catch (Exception $e) {
        error_log("sendUserAccountMessage error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

// 关键词监控
function checkKeywordMonitor($bot, $db, $chat_id, $text, $message) {
    try {
        error_log("Checking keyword monitor for text: $text in chat: $chat_id");
        
        // 获取监控规则（只获取机器人监听或双模式的规则）
        $stmt = $db->prepare("SELECT km.* FROM keyword_monitor km LEFT JOIN groups g ON km.group_id = g.id WHERE km.is_active = 1 AND (km.monitor_mode = 'bot' OR km.monitor_mode = 'both') AND (km.group_id IS NULL OR g.chat_id = ?)");
        $stmt->execute([$chat_id]);
        $monitors = $stmt->fetchAll();
        
        if (empty($monitors)) {
            return;
        }
        
        error_log("Found " . count($monitors) . " keyword monitors to check");
        
        // 提取消息信息
        $user = $message['from'] ?? [];
        $user_id = $user['id'] ?? 0;
        $username = $user['username'] ?? '';
        $first_name = $user['first_name'] ?? 'Unknown';
        $group_title = $message['chat']['title'] ?? 'Unknown Group';
        $message_id = $message['message_id'] ?? 0;
        
        foreach ($monitors as $monitor) {
            $matched = false;
            
            // 根据匹配类型检查
            switch ($monitor['match_type']) {
                case 'exact':
                    $matched = $text === $monitor['keyword'];
                    break;
                case 'contains':
                    $matched = stripos($text, $monitor['keyword']) !== false;
                    break;
                case 'starts_with':
                    $matched = stripos($text, $monitor['keyword']) === 0;
                    break;
                case 'ends_with':
                    $matched = substr_compare($text, $monitor['keyword'], -strlen($monitor['keyword']), strlen($monitor['keyword']), true) === 0;
                    break;
                case 'regex':
                    $matched = @preg_match('/' . $monitor['keyword'] . '/iu', $text);
                    break;
            }
            
            if ($matched) {
                error_log("Keyword monitor triggered: " . $monitor['keyword']);
                
                // 构建通知消息
                $notify_message = "🔍 <b>关键词监控提醒</b>\n\n";
                $notify_message .= "📌 <b>关键词：</b><code>" . htmlspecialchars($monitor['keyword']) . "</code>\n";
                $notify_message .= "👥 <b>群组：</b>" . htmlspecialchars($group_title) . "\n";
                $notify_message .= "👤 <b>用户：</b>" . htmlspecialchars($first_name);
                if ($username) {
                    $notify_message .= " (@" . htmlspecialchars($username) . ")";
                }
                $notify_message .= "\n";
                $notify_message .= "🆔 <b>User ID：</b><code>" . $user_id . "</code>\n";
                $notify_message .= "💬 <b>消息内容：</b>\n" . htmlspecialchars($text) . "\n";
                $notify_message .= "\n🔗 <b>消息链接：</b> ";
                
                // 尝试构建消息链接（需要群组username或ID）
                if (isset($message['chat']['username'])) {
                    $notify_message .= "https://t.me/" . $message['chat']['username'] . "/" . $message_id;
                } else {
                    // 私有群组使用chat_id（需要去掉-100前缀）
                    $chat_id_str = str_replace('-100', '', $chat_id);
                    $notify_message .= "https://t.me/c/" . $chat_id_str . "/" . $message_id;
                }
                
                // 发送通知到指定用户
                $result = $bot->sendMessage($monitor['notify_user_id'], $notify_message, 'HTML');
                
                if ($result) {
                    error_log("Keyword monitor notification sent successfully to user: " . $monitor['notify_user_id']);
                    
                    // 记录到监控日志表（如果表存在）
                    try {
                        // 获取群组ID
                        $stmt_group = $db->prepare("SELECT id FROM groups WHERE chat_id = ?");
                        $stmt_group->execute([$chat_id]);
                        $group = $stmt_group->fetch();
                        
                        if ($group) {
                            $stmt_log = $db->prepare("INSERT INTO keyword_monitor_logs (monitor_id, group_id, user_id, message_id, message_text) VALUES (?, ?, ?, ?, ?)");
                            $stmt_log->execute([$monitor['id'], $group['id'], $user_id, $message_id, $text]);
                        }
                    } catch (Exception $e) {
                        // 日志表可能不存在，忽略错误
                        error_log("Failed to log keyword monitor trigger: " . $e->getMessage());
                    }
                    
                    logSystem('info', '关键词监控触发', [
                        'keyword' => $monitor['keyword'],
                        'user_id' => $user_id,
                        'chat_id' => $chat_id,
                        'notified' => $monitor['notify_user_id']
                    ]);
                } else {
                    error_log("Failed to send keyword monitor notification to user: " . $monitor['notify_user_id']);
                    error_log("Possible reason: User has not started the bot or blocked it");
                }
                
                // ========== 自动回复功能 ==========
                if (!empty($monitor['auto_reply_enabled']) && !empty($monitor['auto_reply_message'])) {
                    error_log("Auto-reply enabled for keyword: " . $monitor['keyword']);
                    
                    // 处理延迟
                    $reply_delay = intval($monitor['reply_delay'] ?? 0);
                    if ($reply_delay > 0) {
                        error_log("Delaying reply for {$reply_delay} seconds...");
                        sleep($reply_delay);
                    }
                    
                    // 替换消息中的变量
                    $reply_text = $monitor['auto_reply_message'];
                    $reply_text = str_replace('{name}', $first_name, $reply_text);
                    $reply_text = str_replace('{username}', $username ? '@' . $username : $first_name, $reply_text);
                    
                    // 判断使用哪种方式回复
                    $use_user_account = !empty($monitor['use_user_account']);
                    
                    if ($use_user_account) {
                        // 使用真人账号回复
                        error_log("Sending reply using user account (MadelineProto)");
                        $reply_result = sendUserAccountMessage($db, $chat_id, $reply_text, $message_id);
                        
                        if ($reply_result) {
                            error_log("✓ User account reply sent successfully");
                            logSystem('info', '真人账号自动回复成功', [
                                'keyword' => $monitor['keyword'],
                                'chat_id' => $chat_id,
                                'reply' => $reply_text
                            ]);
                        } else {
                            error_log("✗ Failed to send user account reply");
                            logSystem('error', '真人账号自动回复失败', [
                                'keyword' => $monitor['keyword'],
                                'chat_id' => $chat_id
                            ]);
                        }
                    } else {
                        // 使用机器人账号回复
                        error_log("Sending reply using bot account");
                        $reply_result = $bot->sendMessage($chat_id, $reply_text);
                        
                        if ($reply_result) {
                            error_log("✓ Bot reply sent successfully");
                            logSystem('info', '机器人自动回复成功', [
                                'keyword' => $monitor['keyword'],
                                'chat_id' => $chat_id,
                                'reply' => $reply_text
                            ]);
                        } else {
                            error_log("✗ Failed to send bot reply");
                        }
                    }
                }
                
                // 只触发第一个匹配的规则
                break;
            }
        }
    } catch (Exception $e) {
        error_log("Keyword monitor check error: " . $e->getMessage());
        logSystem('error', 'Keyword monitor error', $e->getMessage());
    }
}

// 更新统计
function updateStatistics($db, $chat_id, $type) {
    try {
        $stmt = $db->prepare("SELECT id FROM groups WHERE chat_id = ?");
        $stmt->execute([$chat_id]);
        $group = $stmt->fetch();
        
        if ($group) {
            $column = '';
            switch ($type) {
                case 'new_member':
                    $column = 'new_members';
                    break;
                case 'left_member':
                    $column = 'left_members';
                    break;
                case 'violation':
                    $column = 'violations';
                    break;
                default:
                    return;
            }
            
            $stmt = $db->prepare("INSERT INTO statistics (group_id, date, $column) VALUES (?, CURDATE(), 1) ON DUPLICATE KEY UPDATE $column = $column + 1");
            $stmt->execute([$group['id']]);
        }
    } catch (Exception $e) {
        logSystem('error', 'Update statistics error', $e->getMessage());
    }
}

// ==================== 发卡商城功能 ====================

// 确保用户存在（获取或创建用户）
function ensureUserExists($db, $telegram_id, $from_data) {
    try {
        // 检查用户是否存在
        $stmt = $db->prepare("SELECT id FROM card_users WHERE telegram_id = ?");
        $stmt->execute([$telegram_id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // 创建新用户
            $stmt = $db->prepare("
                INSERT INTO card_users (telegram_id, username, first_name, last_name, last_active) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $telegram_id,
                $from_data['username'] ?? null,
                $from_data['first_name'] ?? 'User',
                $from_data['last_name'] ?? null
            ]);
            error_log("Created new user: " . $telegram_id);
        } else {
            // 更新用户信息和最后活跃时间
            $stmt = $db->prepare("
                UPDATE card_users 
                SET username = ?, first_name = ?, last_name = ?, last_active = NOW() 
                WHERE telegram_id = ?
            ");
            $stmt->execute([
                $from_data['username'] ?? null,
                $from_data['first_name'] ?? 'User',
                $from_data['last_name'] ?? null,
                $telegram_id
            ]);
            error_log("Updated user info: " . $telegram_id);
        }
    } catch (Exception $e) {
        error_log("Error ensuring user exists: " . $e->getMessage());
    }
}

// 获取系统设置（从数据库，需要传入$db参数）
function getSettingFromDB($db, $setting_key, $default = '') {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$setting_key]);
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : $default;
    } catch (PDOException $e) {
        error_log("Get system setting error: " . $e->getMessage());
        return $default;
    }
}

// 检查发卡功能是否启用
function isShopEnabled($db) {
    return getSettingFromDB($db, 'shop_enabled', '1') === '1';
}

// 检查是否维护模式
function isMaintenanceMode($db) {
    return getSettingFromDB($db, 'maintenance_mode', '0') === '1';
}

// 获取导航栏按钮（inline keyboard格式）
function getNavigationInlineKeyboard($db) {
    try {
        // 检查表是否存在
        $tables = $db->query("SHOW TABLES LIKE 'navigation_buttons'")->fetchAll();
        if (empty($tables)) {
            error_log("Navigation buttons table does not exist");
            return null;
        }
        
        // 获取一级按钮（parent_id 为 NULL 或空字符串或0）
        $stmt = $db->query("SELECT * FROM navigation_buttons WHERE is_active = 1 AND (parent_id IS NULL OR parent_id = '' OR parent_id = 0) ORDER BY row_num ASC, sort_order ASC");
        $buttons = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Found " . count($buttons) . " navigation buttons");
        
        if (empty($buttons)) {
            return null;
        }
        
        // 按行号分组
        $rows = [];
        foreach ($buttons as $btn) {
            $row = intval($btn['row_num']) ?: 1;
            if (!isset($rows[$row])) {
                $rows[$row] = [];
            }
            
            // 检查是否有子按钮
            $subStmt = $db->prepare("SELECT COUNT(*) FROM navigation_buttons WHERE parent_id = ? AND is_active = 1");
            $subStmt->execute([$btn['id']]);
            $hasChildren = $subStmt->fetchColumn() > 0;
            
            error_log("Button: {$btn['text']}, URL: {$btn['url']}, hasChildren: " . ($hasChildren ? 'yes' : 'no'));
            
            if ($hasChildren) {
                // 有子按钮，点击后显示子菜单
                $rows[$row][] = [
                    'text' => $btn['text'],
                    'callback_data' => 'nav_menu_' . $btn['id']
                ];
            } else if (!empty($btn['url'])) {
                // 有链接，直接跳转
                $rows[$row][] = [
                    'text' => $btn['text'],
                    'url' => $btn['url']
                ];
            } else {
                // 无链接无子菜单，作为回调
                $rows[$row][] = [
                    'text' => $btn['text'],
                    'callback_data' => 'nav_btn_' . $btn['id']
                ];
            }
        }
        
        // 转换为inline keyboard格式
        $keyboard = [];
        ksort($rows);
        foreach ($rows as $row) {
            $keyboard[] = $row;
        }
        
        error_log("Final keyboard: " . json_encode($keyboard));
        
        return $keyboard;
    } catch (PDOException $e) {
        error_log("Get navigation buttons error: " . $e->getMessage());
        return null;
    }
}

// 获取导航子菜单
function getNavigationSubMenu($db, $parent_id) {
    try {
        $stmt = $db->prepare("SELECT * FROM navigation_buttons WHERE parent_id = ? AND is_active = 1 ORDER BY sort_order ASC");
        $stmt->execute([$parent_id]);
        $buttons = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($buttons)) {
            return null;
        }
        
        $keyboard = [];
        foreach ($buttons as $btn) {
            if (!empty($btn['url'])) {
                $keyboard[] = [[
                    'text' => $btn['text'],
                    'url' => $btn['url']
                ]];
            } else {
                $keyboard[] = [[
                    'text' => $btn['text'],
                    'callback_data' => 'nav_btn_' . $btn['id']
                ]];
            }
        }
        
        // 添加返回按钮
        $keyboard[] = [[
            'text' => '« 返回',
            'callback_data' => 'nav_back'
        ]];
        
        return $keyboard;
    } catch (PDOException $e) {
        error_log("Get navigation sub menu error: " . $e->getMessage());
        return null;
    }
}

// 处理导航栏按钮回调
function handleNavigationCallback($bot, $db, $callback_id, $chat_id, $message_id, $data, $message) {
    error_log("Navigation callback: $data");
    
    // 获取按钮信息
    $getButtonInfo = function($id) use ($db) {
        try {
            $stmt = $db->prepare("SELECT * FROM navigation_buttons WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return null;
        }
    };
    
    if ($data === 'nav_back') {
        // 返回主导航
        $bot->answerCallbackQuery($callback_id);
        
        $welcomeMessage = getSettingFromDB($db, 'welcome_message', '你好！我是智能助手机器人 🤖');
        $navTitle = getSettingFromDB($db, 'navigation_title', '导航栏：');
        $navKeyboard = getNavigationInlineKeyboard($db);
        
        $fullMessage = $welcomeMessage;
        if ($navKeyboard && !empty($navKeyboard)) {
            $fullMessage .= "\n\n" . $navTitle;
        }
        
        $bot->editMessageText($chat_id, $message_id, $fullMessage, 'HTML', $navKeyboard);
        return;
    }
    
    if (strpos($data, 'nav_menu_') === 0) {
        // 显示子菜单
        $parent_id = intval(substr($data, 9));
        $subMenu = getNavigationSubMenu($db, $parent_id);
        
        if ($subMenu && !empty($subMenu)) {
            $bot->answerCallbackQuery($callback_id);
            
            $parentBtn = $getButtonInfo($parent_id);
            $parentText = $parentBtn ? $parentBtn['text'] : '子菜单';
            $subMenuTitle = "📂 " . $parentText . "\n\n请选择：";
            
            $bot->editMessageText($chat_id, $message_id, $subMenuTitle, 'HTML', $subMenu);
        } else {
            // 没有子菜单，检查是否有链接
            $parentBtn = $getButtonInfo($parent_id);
            if ($parentBtn && !empty($parentBtn['url'])) {
                $bot->answerCallbackQuery($callback_id, "请点击链接: " . $parentBtn['url'], true);
            } else {
                $bot->answerCallbackQuery($callback_id, '暂无子菜单', true);
            }
        }
        return;
    }
    
    if (strpos($data, 'nav_btn_') === 0) {
        // 普通按钮点击（无链接的按钮）
        $btn_id = intval(substr($data, 8));
        $btnInfo = $getButtonInfo($btn_id);
        
        if ($btnInfo) {
            if (!empty($btnInfo['url'])) {
                // 如果有URL，提示用户
                $bot->answerCallbackQuery($callback_id, "链接: " . $btnInfo['url'], true);
            } else {
                $bot->answerCallbackQuery($callback_id, "你点击了: " . $btnInfo['text'], true);
            }
        } else {
            $bot->answerCallbackQuery($callback_id);
        }
        return;
    }
    
    $bot->answerCallbackQuery($callback_id);
}

// 获取普通模式菜单键盘
function getNormalModeKeyboard($db) {
    try {
        $stmt = $db->query("SELECT * FROM normal_mode_menu WHERE is_active = 1 ORDER BY row_number ASC, column_number ASC, sort_order ASC");
        $buttons = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($buttons)) {
            return null;
        }
        
        // 按行分组
        $rows = [];
        foreach ($buttons as $button) {
            $row_num = $button['row_number'];
            if (!isset($rows[$row_num])) {
                $rows[$row_num] = [];
            }
            
            $button_text = ($button['button_emoji'] ? $button['button_emoji'] . ' ' : '') . $button['button_text'];
            $rows[$row_num][] = ['text' => $button_text];
        }
        
        // 转换为数组（按行号排序）
        ksort($rows);
        return array_values($rows);
    } catch (PDOException $e) {
        error_log("Get normal mode keyboard error: " . $e->getMessage());
        return null;
    }
}

// 更新 Menu 按钮（根据模式切换命令列表）
function updateMenuButtonForMode($bot, $db, $mode = 'shop') {
    try {
        $commands = [];
        
        if ($mode === 'normal') {
            // 普通模式 - 从 normal_mode_menu 表生成命令
            $stmt = $db->query("SELECT * FROM normal_mode_menu WHERE is_active = 1 AND action_type = 'command' ORDER BY sort_order ASC LIMIT 10");
            $buttons = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($buttons as $button) {
                $command = str_replace('/', '', $button['action_value']); // 移除 /
                $description = $button['button_text'];
                
                $commands[] = [
                    'command' => $command,
                    'description' => $description
                ];
            }
            
            // 如果没有命令，添加默认的 help 命令
            if (empty($commands)) {
                $commands[] = [
                    'command' => 'start',
                    'description' => '开始'
                ];
                $commands[] = [
                    'command' => 'help',
                    'description' => '帮助'
                ];
            }
        } else {
            // 发卡模式 - 从 bot_menu_settings 表读取或使用默认命令
            $stmt = $db->query("SELECT * FROM bot_menu_settings WHERE id = 1");
            $menu_config = $stmt->fetch();
            
            if ($menu_config && $menu_config['enabled']) {
                // 使用配置的命令
                $commands[] = [
                    'command' => str_replace('/', '', $menu_config['command']),
                    'description' => $menu_config['button_text']
                ];
            }
            
            // 添加发卡系统的默认命令
            $default_commands = [
                ['command' => 'start', 'description' => '🏠 主菜单'],
                ['command' => 'shop', 'description' => '🛒 商品列表'],
                ['command' => 'orders', 'description' => '📧 我的订单'],
                ['command' => 'profile', 'description' => '👤 个人中心'],
                ['command' => 'recharge', 'description' => '💰 余额充值']
            ];
            
            // 合并去重
            foreach ($default_commands as $cmd) {
                $exists = false;
                foreach ($commands as $existing) {
                    if ($existing['command'] === $cmd['command']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $commands[] = $cmd;
                }
            }
        }
        
        // 调用 Telegram API 设置命令
        if (!empty($commands)) {
            $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/setMyCommands";
            $response = file_get_contents($url, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json',
                    'content' => json_encode(['commands' => $commands])
                ]
            ]));
            
            $result = json_decode($response, true);
            error_log("Update menu button for mode '$mode': " . ($result['ok'] ? 'success' : 'failed'));
        }
    } catch (Exception $e) {
        error_log("Update menu button error: " . $e->getMessage());
    }
}

// 处理普通模式按钮点击
function handleNormalModeButton($bot, $db, $chat_id, $user_id, $text) {
    try {
        // 查找匹配的按钮
        $stmt = $db->prepare("SELECT * FROM normal_mode_menu WHERE is_active = 1 AND CONCAT(IFNULL(button_emoji, ''), ' ', button_text) = ? OR button_text = ?");
        $stmt->execute([$text, $text]);
        $button = $stmt->fetch();
        
        if (!$button) {
            return false;
        }
        
        error_log("Normal mode button clicked: " . $text . ", action: " . $button['action_type']);
        
        switch ($button['action_type']) {
            case 'command':
                // 执行命令（模拟用户发送命令）
                $command_text = $button['action_value'];
                error_log("Executing command: " . $command_text);
                
                // 简单处理一些常用命令
                if ($command_text === '/help' || $command_text === '/start') {
                    sendMainMenu($bot, $db, $chat_id, $user_id);
                } else {
                    // 检查自定义命令
                    $cmd_stmt = $db->prepare("SELECT * FROM custom_commands WHERE command = ? AND is_active = 1 LIMIT 1");
                    $cmd_stmt->execute([$command_text]);
                    $custom_cmd = $cmd_stmt->fetch();
                    
                    if ($custom_cmd) {
                        $bot->sendMessage($chat_id, $custom_cmd['response']);
                    } else {
                        $bot->sendMessage($chat_id, "命令执行：" . $command_text);
                    }
                }
                break;
                
            case 'reply_text':
                // 直接回复文本
                $bot->sendMessage($chat_id, $button['action_value'], 'HTML');
                break;
                
            case 'url':
                // 发送链接
                $keyboard = [[['text' => '🔗 点击打开', 'url' => $button['action_value']]]];
                $bot->sendMessage($chat_id, "请点击下方按钮访问：", 'HTML', ['inline_keyboard' => $keyboard]);
                break;
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Handle normal mode button error: " . $e->getMessage());
        return false;
    }
}

// 发送主菜单
function sendMainMenu($bot, $db, $chat_id, $user_id) {
    // 检查维护模式
    if (isMaintenanceMode($db)) {
        $message = getSettingFromDB($db, 'maintenance_message', '系统正在维护升级，给您带来不便，敬请谅解！');
        $bot->sendMessage($chat_id, "🔧 <b>系统维护中</b>\n\n" . $message, 'HTML');
        return;
    }
    
    // 检查发卡功能是否启用
    if (!isShopEnabled($db)) {
        // 普通模式 - 更新 Menu 按钮为普通模式命令
        updateMenuButtonForMode($bot, $db, 'normal');
        
        $message = getSettingFromDB($db, 'welcome_message', '你好！我是智能助手机器人 🤖');
        
        // 检查是否启用导航栏
        $navEnabled = getSettingFromDB($db, 'navigation_enabled', '1');
        $navTitle = getSettingFromDB($db, 'navigation_title', '导航栏：');
        $navInlineKeyboard = null;
        
        if ($navEnabled == '1') {
            // 获取导航栏按钮
            $navInlineKeyboard = getNavigationInlineKeyboard($db);
            error_log("Navigation keyboard: " . json_encode($navInlineKeyboard));
            if ($navInlineKeyboard && !empty($navInlineKeyboard)) {
                $message .= "\n\n" . $navTitle;
            }
        }
        
        // 发送消息 - 带导航栏inline按钮
        if ($navInlineKeyboard && !empty($navInlineKeyboard)) {
            $bot->sendMessageWithInlineKeyboard($chat_id, $message, $navInlineKeyboard, 'HTML');
        } else {
            $bot->sendMessage($chat_id, $message, 'HTML');
        }
        return;
    }
    
    // 发卡模式 - 更新 Menu 按钮为发卡命令
    updateMenuButtonForMode($bot, $db, 'shop');
    // 获取用户信息和语言
    $stmt = $db->prepare("SELECT * FROM card_users WHERE telegram_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    $user_lang = $user && $user['language'] ? $user['language'] : 'zh_CN';
    $balance = $user ? number_format($user['balance'], 2) : '0.00';
    
    $message = getLang('welcome', $user_lang) . "\n\n";
    $message .= getLang('welcome_desc', $user_lang) . "\n";
    $message .= getLang('current_balance', $user_lang, $balance) . "\n\n";
    $message .= getLang('select_function', $user_lang);
    
    // Inline键盘（消息中的按钮）
    $inline_keyboard = [
        [
            ['text' => getLang('btn_categories', $user_lang), 'callback_data' => 'shop_categories'],
            ['text' => getLang('btn_products', $user_lang), 'callback_data' => 'shop_products']
        ],
        [
            ['text' => getLang('btn_my_orders', $user_lang), 'callback_data' => 'shop_myorders'],
            ['text' => getLang('btn_profile', $user_lang), 'callback_data' => 'shop_profile']
        ],
        [
            ['text' => getLang('btn_recharge', $user_lang), 'callback_data' => 'shop_recharge'],
            ['text' => getLang('btn_contact', $user_lang), 'callback_data' => 'shop_contact']
        ],
        [
            ['text' => getLang('btn_language', $user_lang), 'callback_data' => 'shop_language']
        ]
    ];
    
    // Reply键盘（底部固定按钮）
    $reply_keyboard = [
        [
            ['text' => '📂 ' . getLang('btn_categories', $user_lang)],
            ['text' => '🛒 ' . getLang('btn_products', $user_lang)]
        ],
        [
            ['text' => '📧 ' . getLang('btn_my_orders', $user_lang)],
            ['text' => '👤 ' . getLang('btn_profile', $user_lang)]
        ],
        [
            ['text' => '💰 ' . getLang('btn_recharge', $user_lang)],
            ['text' => '🧑‍💼 ' . getLang('btn_contact', $user_lang)]
        ],
        [
            ['text' => '🌐 ' . getLang('btn_language', $user_lang)]
        ]
    ];
    
    // 发送消息（同时包含inline键盘和reply键盘）
    $bot->sendMessage($chat_id, $message, 'HTML', [
        'inline_keyboard' => $inline_keyboard,
        'keyboard' => $reply_keyboard,
        'resize_keyboard' => true,
        'persistent' => true,
        'one_time_keyboard' => false
    ]);
}

// 发送分类列表
function sendCategoryList($bot, $db, $chat_id, $user_id = null) {
    // 检查发卡功能是否启用
    if (!isShopEnabled($db)) {
        $message = getSettingFromDB($db, 'shop_close_message', '发卡功能暂时关闭，如需帮助请联系客服。');
        $bot->sendMessage($chat_id, "⚠️ <b>功能暂停</b>\n\n" . $message, 'HTML');
        return;
    }
    
    $user_lang = $user_id ? getUserLanguage($db, $user_id) : 'zh_CN';
    
    $stmt = $db->query("
        SELECT pc.*, COUNT(p.id) as product_count 
        FROM product_categories pc 
        LEFT JOIN products p ON pc.id = p.category_id AND p.is_active = 1
        WHERE pc.is_active = 1 
        GROUP BY pc.id
        ORDER BY pc.sort_order ASC
    ");
    $categories = $stmt->fetchAll();
    
    if (empty($categories)) {
        $bot->sendMessage($chat_id, getLang('no_categories', $user_lang), 'HTML', [
            'inline_keyboard' => [[['text' => getLang('btn_back_menu', $user_lang), 'callback_data' => 'shop_menu']]]
        ]);
        return;
    }
    
    $message = getLang('category_list', $user_lang) . "\n\n" . getLang('select_category', $user_lang);
    
    $keyboard = [];
    foreach ($categories as $category) {
        $keyboard[] = [[
            'text' => $category['icon'] . ' ' . $category['name'] . ' (' . $category['product_count'] . ')',
            'callback_data' => 'cat_' . $category['id']
        ]];
    }
    $keyboard[] = [['text' => getLang('btn_back_menu', $user_lang), 'callback_data' => 'shop_menu']];
    
    $bot->sendMessage($chat_id, $message, 'HTML', ['inline_keyboard' => $keyboard]);
}

// 发送商品列表
function sendProductList($bot, $db, $chat_id, $category_id = null, $user_id = null) {
    // 检查发卡功能是否启用
    if (!isShopEnabled($db)) {
        $message = getSettingFromDB($db, 'shop_close_message', '发卡功能暂时关闭，如需帮助请联系客服。');
        $bot->sendMessage($chat_id, "⚠️ <b>功能暂停</b>\n\n" . $message, 'HTML');
        return;
    }
    
    $user_lang = $user_id ? getUserLanguage($db, $user_id) : 'zh_CN';
    $sql = "
        SELECT p.*, 
        (SELECT COUNT(*) FROM card_stock WHERE product_id = p.id AND status = 'available') as stock
        FROM products p
        WHERE p.is_active = 1
    ";
    
    $params = [];
    if ($category_id) {
        $sql .= " AND p.category_id = ?";
        $params[] = $category_id;
    }
    
    $sql .= " ORDER BY p.sort_order ASC, p.id DESC LIMIT 20";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    
    if (empty($products)) {
        $bot->sendMessage($chat_id, getLang('no_products', $user_lang), 'HTML', [
            'inline_keyboard' => [[['text' => getLang('btn_back_menu', $user_lang), 'callback_data' => 'shop_menu']]]
        ]);
        return;
    }
    
    $message = getLang('product_list', $user_lang) . "\n\n";
    
    $keyboard = [];
    foreach ($products as $product) {
        $stock_text = $product['stock'] > 0 ? getLang('in_stock', $user_lang, $product['stock']) : getLang('out_of_stock', $user_lang);
        $keyboard[] = [[
            'text' => $product['name'] . " - $" . $product['price'] . " " . $stock_text,
            'callback_data' => 'prod_' . $product['id']
        ]];
    }
    $keyboard[] = [
        ['text' => getLang('btn_categories', $user_lang), 'callback_data' => 'shop_categories'],
        ['text' => getLang('btn_back_menu', $user_lang), 'callback_data' => 'shop_menu']
    ];
    
    $bot->sendMessage($chat_id, $message, 'HTML', ['inline_keyboard' => $keyboard]);
}

// 发送商品详情
function sendProductDetail($bot, $db, $chat_id, $product_id) {
    $stmt = $db->prepare("
        SELECT p.*, pc.name as category_name,
        (SELECT COUNT(*) FROM card_stock WHERE product_id = p.id AND status = 'available') as stock
        FROM products p
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE p.id = ? AND p.is_active = 1
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();
    
    if (!$product) {
        $bot->sendMessage($chat_id, "❌ 商品不存在或已下架");
        return;
    }
    
    $message = "🛍️ <b>商品详情</b>\n\n";
    $message .= "📦 <b>名称：</b>" . htmlspecialchars($product['name']) . "\n";
    $message .= "📂 <b>分类：</b>" . htmlspecialchars($product['category_name']) . "\n";
    $message .= "💵 <b>价格：</b><b>$" . number_format($product['price'], 2) . "</b>\n";
    $message .= "📦 <b>库存：</b>" . $product['stock'] . "\n";
    $message .= "📊 <b>销量：</b>" . $product['sales_count'] . "\n\n";
    
    if ($product['description']) {
        $message .= "📝 <b>详情：</b>\n" . htmlspecialchars($product['description']) . "\n\n";
    }
    
    $message .= "请选择购买数量：";
    
    $keyboard = [];
    
    // 数量选择按钮
    if ($product['stock'] > 0) {
        $qty_row = [];
        foreach ([1, 5, 10, 20, 50] as $qty) {
            if ($qty <= $product['stock']) {
                $qty_row[] = ['text' => $qty, 'callback_data' => 'buy_' . $product_id . '_' . $qty];
            }
        }
        if (!empty($qty_row)) {
            $keyboard[] = $qty_row;
        }
        $keyboard[] = [['text' => '⌨️ 输入数量', 'callback_data' => 'buy_input_' . $product_id]];
    } else {
        $message .= "\n❌ <b>该商品已售罄</b>";
    }
    
    $keyboard[] = [
        ['text' => '📋 商品列表', 'callback_data' => 'shop_products'],
        ['text' => '🏠 主菜单', 'callback_data' => 'shop_menu']
    ];
    
    if ($product['image_url']) {
        $bot->sendPhoto($chat_id, $product['image_url'], $message, ['inline_keyboard' => $keyboard], 'HTML');
    } else {
        $bot->sendMessage($chat_id, $message, 'HTML', ['inline_keyboard' => $keyboard]);
    }
}

// 处理商城回调
function handleShopCallback($bot, $db, $callback_query) {
    $callback_id = $callback_query['id'];
    $data = $callback_query['data'];
    $user_id = $callback_query['from']['id'];
    $message = $callback_query['message'] ?? null;
    
    if (!$message) {
        return;
    }
    
    $chat_id = $message['chat']['id'];
    
    try {
        // 解析回调数据
        $parts = explode('_', $data, 3);
        $prefix = $parts[0] ?? '';
        $action = $parts[0] . '_' . ($parts[1] ?? '');
        
        // 对于带参数的回调，使用前缀匹配
        if (in_array($prefix, ['recharge', 'cat', 'prod', 'buy', 'lang'])) {
            $action = $prefix . '_';
        }
        
        // 特殊处理 order_pay（不能简化为 order_）
        if ($prefix === 'order' && ($parts[1] ?? '') === 'pay') {
            $action = 'order_pay';
        } elseif ($prefix === 'order' && ($parts[1] ?? '') !== 'pay') {
            $action = 'order_';
        }
        
        switch ($action) {
            case 'shop_menu':
                sendMainMenu($bot, $db, $chat_id, $user_id);
                $bot->answerCallbackQuery($callback_id);
                break;
                
            case 'shop_categories':
                sendCategoryList($bot, $db, $chat_id, $user_id);
                $bot->answerCallbackQuery($callback_id);
                break;
                
            case 'shop_products':
                sendProductList($bot, $db, $chat_id, null, $user_id);
                $bot->answerCallbackQuery($callback_id);
                break;
                
            case 'shop_profile':
                sendUserProfile($bot, $db, $chat_id, $user_id);
                $bot->answerCallbackQuery($callback_id);
                break;
                
            case 'shop_myorders':
                sendUserOrders($bot, $db, $chat_id, $user_id);
                $bot->answerCallbackQuery($callback_id);
                break;
                
            case 'shop_recharge':
                sendRechargeOptions($bot, $db, $chat_id, $user_id);
                $bot->answerCallbackQuery($callback_id);
                break;
                
            case 'shop_contact':
                sendContactInfo($bot, $db, $chat_id);
                $bot->answerCallbackQuery($callback_id);
                break;
                
            case 'shop_language':
                sendLanguageSelection($bot, $db, $chat_id, $user_id);
                $bot->answerCallbackQuery($callback_id);
                break;
                
            case 'recharge_':
                $payment_method_id = $parts[1] ?? 0;
                error_log("Recharge callback - payment_method_id: " . $payment_method_id);
                sendRechargeDetail($bot, $db, $chat_id, $user_id, $payment_method_id);
                $bot->answerCallbackQuery($callback_id);
                break;
                
            case 'cat_':
                $category_id = $parts[1];
                sendProductList($bot, $db, $chat_id, $category_id, $user_id);
                $bot->answerCallbackQuery($callback_id);
                break;
                
            case 'prod_':
                $product_id = $parts[1];
                sendProductDetail($bot, $db, $chat_id, $product_id);
                $bot->answerCallbackQuery($callback_id);
                break;
                
            case 'buy_':
                if ($parts[1] == 'input') {
                    // 输入数量模式 - 保存会话状态
                    $product_id = $parts[2] ?? 0;
                    error_log("Buy input mode activated for product: $product_id");
                    
                    if ($product_id > 0) {
                        saveUserSession($db, $user_id, 'buy_input', $product_id);
                        $bot->answerCallbackQuery($callback_id, '✏️ 请直接发送购买数量（数字）', true);
                    } else {
                        $bot->answerCallbackQuery($callback_id, '❌ 商品ID错误', true);
                    }
                } else {
                    $product_id = $parts[1];
                    $quantity = isset($parts[2]) ? intval($parts[2]) : 1;
                    handlePurchase($bot, $db, $chat_id, $user_id, $callback_id, $product_id, $quantity, $callback_query);
                }
                break;
                
            case 'order_pay':
                $order_id = $parts[2] ?? 0;
                handleOrderPayment($bot, $db, $chat_id, $user_id, $callback_id, $order_id);
                break;
                
            case 'lang_':
                error_log("=== Lang Callback Detected ===");
                error_log("Parts: " . json_encode($parts));
                $language = $parts[1] . '_' . ($parts[2] ?? '');
                error_log("Language code: " . $language);
                handleLanguageSelection($bot, $db, $chat_id, $user_id, $callback_id, $language, $callback_query);
                break;
                
            default:
                $bot->answerCallbackQuery($callback_id, '未知操作');
        }
    } catch (Exception $e) {
        error_log("Shop callback error: " . $e->getMessage());
        $bot->answerCallbackQuery($callback_id, '操作失败：' . $e->getMessage(), true);
    }
}

// 保存用户会话状态
function saveUserSession($db, $user_id, $action, $data) {
    try {
        // 尝试创建表（如果不存在）
        $create_table_sql = "
            CREATE TABLE IF NOT EXISTS `user_sessions` (
                `user_id` BIGINT NOT NULL,
                `action` VARCHAR(50) NOT NULL,
                `data` TEXT,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`user_id`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户会话状态表'
        ";
        $db->exec($create_table_sql);
        error_log("user_sessions table check/create completed");
        
        $data_json = is_array($data) ? json_encode($data) : $data;
        $stmt = $db->prepare("
            INSERT INTO user_sessions (user_id, action, data, created_at) 
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                action = ?, 
                data = ?, 
                created_at = NOW()
        ");
        $result = $stmt->execute([$user_id, $action, $data_json, $action, $data_json]);
        error_log("Session saved for user $user_id: action=$action, data=$data_json, result=" . ($result ? 'success' : 'failed'));
        return $result;
    } catch (Exception $e) {
        error_log("Save session error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

// 获取用户会话状态
function getUserSession($db, $user_id) {
    try {
        $stmt = $db->prepare("SELECT * FROM user_sessions WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $stmt->execute([$user_id]);
        $session = $stmt->fetch();
        if ($session) {
            error_log("Session found for user $user_id: " . json_encode($session));
        }
        return $session;
    } catch (Exception $e) {
        error_log("Get session error: " . $e->getMessage());
        return null;
    }
}

// 清除用户会话状态
function clearUserSession($db, $user_id) {
    try {
        $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
        $stmt->execute([$user_id]);
        error_log("Session cleared for user $user_id");
    } catch (Exception $e) {
        error_log("Clear session error: " . $e->getMessage());
    }
}

// 处理购买
function handlePurchase($bot, $db, $chat_id, $user_id, $callback_id, $product_id, $quantity, $data_source) {
    try {
        // 获取用户信息（支持从 callback_query 或 message 获取）
        $from = [];
        if (isset($data_source['from'])) {
            $from = $data_source['from'];
        } elseif (isset($data_source['message']['from'])) {
            $from = $data_source['message']['from'];
        }
        
        // 调用API创建订单
        $api_url = SITE_URL . '/api/shop.php?action=create_order';
        
        $response = file_get_contents($api_url, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode([
                    'telegram_id' => $user_id,
                    'username' => $from['username'] ?? null,
                    'first_name' => $from['first_name'] ?? 'User',
                    'last_name' => $from['last_name'] ?? null,
                    'product_id' => $product_id,
                    'quantity' => $quantity
                ])
            ]
        ]));
        
        $result = json_decode($response, true);
        
        if ($result['success']) {
            $order = $result['data'];
            
            $message = "✅ <b>订单创建成功</b>\n\n";
            $message .= "📦 订单号：<code>" . $order['order_no'] . "</code>\n";
            $message .= "💰 订单金额：<b>$" . number_format($order['total_amount'], 2) . "</b>\n";
            $message .= "💵 当前余额：<b>$" . number_format($order['user_balance'], 2) . "</b>\n\n";
            
            if ($order['user_balance'] >= $order['total_amount']) {
                $message .= "请选择付款方式：";
                
                $keyboard = [
                    [['text' => '💰 余额支付', 'callback_data' => 'order_pay_' . $order['order_id']]],
                    [['text' => '❌ 取消订单', 'callback_data' => 'shop_menu']]
                ];
                
                $bot->sendMessage($chat_id, $message, 'HTML', ['inline_keyboard' => $keyboard]);
            } else {
                $need = $order['total_amount'] - $order['user_balance'];
                $message .= "❌ 余额不足，还需充值 <b>$" . number_format($need, 2) . "</b>";
                
                $keyboard = [
                    [['text' => '💰 去充值', 'callback_data' => 'shop_recharge']],
                    [['text' => '🏠 返回主菜单', 'callback_data' => 'shop_menu']]
                ];
                
                $bot->sendMessage($chat_id, $message, 'HTML', ['inline_keyboard' => $keyboard]);
            }
            
            if ($callback_id) {
                $bot->answerCallbackQuery($callback_id);
            }
        } else {
            throw new Exception($result['message'] ?? '创建订单失败');
        }
    } catch (Exception $e) {
        error_log("Purchase error: " . $e->getMessage());
        if ($callback_id) {
            $bot->answerCallbackQuery($callback_id, $e->getMessage(), true);
        } else {
            $bot->sendMessage($chat_id, '❌ ' . $e->getMessage());
        }
    }
}

// 处理订单支付
function handleOrderPayment($bot, $db, $chat_id, $user_id, $callback_id, $order_id) {
    try {
        // 调用API支付订单
        $api_url = SITE_URL . '/api/shop.php?action=pay_order';
        
        $response = file_get_contents($api_url, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode([
                    'telegram_id' => $user_id,
                    'order_id' => $order_id
                ])
            ]
        ]));
        
        $result = json_decode($response, true);
        
        if ($result['success']) {
            $data = $result['data'];
            
            $message = "✅ <b>支付成功！</b>\n\n";
            $message .= "📦 订单号：<code>" . $data['order_no'] . "</code>\n";
            $message .= "💵 剩余余额：<b>$" . number_format($data['new_balance'], 2) . "</b>\n\n";
            $message .= "🎁 <b>您的卡密：</b>\n\n";
            
            foreach ($data['cards'] as $index => $card) {
                $message .= ($index + 1) . ". <code>" . htmlspecialchars($card) . "</code>\n";
            }
            
            $message .= "\n感谢您的购买！";
            
            $keyboard = [
                [['text' => '📧 我的订单', 'callback_data' => 'shop_myorders']],
                [['text' => '🏠 返回主菜单', 'callback_data' => 'shop_menu']]
            ];
            
            $bot->sendMessage($chat_id, $message, 'HTML', ['inline_keyboard' => $keyboard]);
            $bot->answerCallbackQuery($callback_id, '✅ 支付成功！');
        } else {
            throw new Exception($result['message'] ?? '支付失败');
        }
    } catch (Exception $e) {
        $bot->answerCallbackQuery($callback_id, $e->getMessage(), true);
    }
}

// 发送用户资料
function sendUserProfile($bot, $db, $chat_id, $user_id) {
    $stmt = $db->prepare("SELECT * FROM card_users WHERE telegram_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $bot->sendMessage($chat_id, "❌ 用户信息不存在");
        return;
    }
    
    $message = "👤 <b>个人中心</b>\n\n";
    $message .= "🆔 <b>用户ID：</b><code>" . $user['telegram_id'] . "</code>\n";
    $message .= "👤 <b>用户名：</b>" . ($user['username'] ? '@' . $user['username'] : '-') . "\n";
    $message .= "💰 <b>当前余额：</b><b>$" . number_format($user['balance'], 2) . "</b>\n";
    $message .= "💸 <b>累计消费：</b>$" . number_format($user['total_spent'], 2) . "\n";
    $message .= "📦 <b>订单数量：</b>" . $user['total_orders'] . "\n";
    $message .= "📅 <b>注册时间：</b>" . date('Y-m-d', strtotime($user['created_at'])) . "\n";
    
    $keyboard = [
        [
            ['text' => '💰 余额充值', 'callback_data' => 'shop_recharge'],
            ['text' => '📧 我的订单', 'callback_data' => 'shop_myorders']
        ],
        [['text' => '🏠 返回主菜单', 'callback_data' => 'shop_menu']]
    ];
    
    $bot->sendMessage($chat_id, $message, 'HTML', ['inline_keyboard' => $keyboard]);
}

// 发送用户订单
function sendUserOrders($bot, $db, $chat_id, $user_id) {
    $stmt = $db->prepare("
        SELECT * FROM orders 
        WHERE telegram_id = ? 
        ORDER BY id DESC 
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $orders = $stmt->fetchAll();
    
    if (empty($orders)) {
        $bot->sendMessage($chat_id, "您还没有任何订单", 'HTML', [
            'inline_keyboard' => [[['text' => '🏠 返回主菜单', 'callback_data' => 'shop_menu']]]
        ]);
        return;
    }
    
    $message = "📧 <b>我的订单</b>\n\n";
    
    foreach ($orders as $order) {
        $status_emoji = [
            'pending' => '⏳',
            'paid' => '💳',
            'completed' => '✅',
            'cancelled' => '❌',
            'refunded' => '↩️'
        ];
        
        $emoji = $status_emoji[$order['status']] ?? '•';
        $message .= $emoji . " <b>" . htmlspecialchars($order['product_name']) . "</b>\n";
        $message .= "   订单号：<code>" . $order['order_no'] . "</code>\n";
        $message .= "   金额：$" . number_format($order['total_amount'], 2) . " | 数量：" . $order['quantity'] . "\n";
        $message .= "   时间：" . date('Y-m-d H:i', strtotime($order['created_at'])) . "\n\n";
    }
    
    $keyboard = [
        [['text' => '🏠 返回主菜单', 'callback_data' => 'shop_menu']]
    ];
    
    $bot->sendMessage($chat_id, $message, 'HTML', ['inline_keyboard' => $keyboard]);
}

// 发送充值选项
function sendRechargeOptions($bot, $db, $chat_id, $user_id) {
    $stmt = $db->query("
        SELECT * FROM payment_methods 
        WHERE is_active = 1 
        ORDER BY sort_order ASC
    ");
    $methods = $stmt->fetchAll();
    
    if (empty($methods)) {
        $bot->sendMessage($chat_id, "暂无可用的充值方式", 'HTML', [
            'inline_keyboard' => [[['text' => '🏠 返回主菜单', 'callback_data' => 'shop_menu']]]
        ]);
        return;
    }
    
    $message = "💰 <b>余额充值</b>\n\n";
    $message .= "请选择充值方式：\n\n";
    
    foreach ($methods as $method) {
        $message .= $method['icon'] . " <b>" . $method['name'] . "</b>\n";
        $message .= "   最小充值：$" . number_format($method['min_amount'], 2) . "\n\n";
    }
    
    $keyboard = [];
    foreach ($methods as $method) {
        $keyboard[] = [[
            'text' => $method['icon'] . ' ' . $method['name'],
            'callback_data' => 'recharge_' . $method['id']
        ]];
    }
    $keyboard[] = [['text' => '🏠 返回主菜单', 'callback_data' => 'shop_menu']];
    
    $bot->sendMessage($chat_id, $message, 'HTML', ['inline_keyboard' => $keyboard]);
}

// 发送客服联系方式
function sendContactInfo($bot, $db, $chat_id) {
    // 从数据库获取客服联系方式
    $stmt = $db->query("SELECT * FROM customer_service WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 5");
    $contacts = $stmt->fetchAll();
    
    $message = "📱 <b>联系客服</b>\n\n";
    
    if (!empty($contacts)) {
        $message .= "如有任何问题，请联系我们的客服：\n\n";
        foreach ($contacts as $contact) {
            $icon = $contact['type'] == 'telegram' ? '✈️' : ($contact['type'] == 'whatsapp' ? '💬' : ($contact['type'] == 'email' ? '📧' : '📞'));
            $message .= $icon . " <b>" . htmlspecialchars($contact['name']) . "</b>\n";
            $message .= "   " . htmlspecialchars($contact['contact']) . "\n";
            if ($contact['url']) {
                $message .= "   🔗 " . htmlspecialchars($contact['url']) . "\n";
            }
            $message .= "\n";
        }
    } else {
        // 默认客服信息
        $message .= "如有任何问题，请联系我们的客服：\n\n";
        $message .= "✈️ <b>Telegram客服</b>\n";
        $message .= "   请在管理后台配置客服联系方式\n\n";
    }
    
    $message .= "⏰ <b>工作时间：</b>9:00 - 22:00（UTC+8）\n";
    $message .= "💡 转账后请主动联系客服，提供交易截图以加快到账速度";
    
    $keyboard = [
        [['text' => '🏠 返回主菜单', 'callback_data' => 'shop_menu']]
    ];
    
    $bot->sendMessage($chat_id, $message, 'HTML', ['inline_keyboard' => $keyboard]);
}

// 发送充值详情（显示收款地址和二维码）
function sendRechargeDetail($bot, $db, $chat_id, $user_id, $payment_method_id) {
    error_log("sendRechargeDetail called - payment_method_id: " . $payment_method_id);
    
    // 获取支付方式详情
    $stmt = $db->prepare("SELECT * FROM payment_methods WHERE id = ? AND is_active = 1");
    $stmt->execute([$payment_method_id]);
    $method = $stmt->fetch();
    
    error_log("Payment method found: " . ($method ? 'YES' : 'NO'));
    
    if (!$method) {
        error_log("Payment method not found, sending error message");
        $bot->sendMessage($chat_id, "❌ 支付方式不存在或已禁用");
        return;
    }
    
    error_log("Payment method: " . $method['name']);
    
    // 获取用户信息
    $stmt = $db->prepare("SELECT * FROM card_users WHERE telegram_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    $current_balance = $user ? $user['balance'] : 0;
    
    // 构建充值说明消息
    $message = "💰 <b>" . htmlspecialchars($method['name']) . " 充值</b>\n\n";
    $message .= "💵 <b>当前余额：</b>$" . number_format($current_balance, 2) . "\n";
    $message .= "💳 <b>最小充值：</b>$" . number_format($method['min_amount'], 2) . "\n\n";
    
    // 显示收款地址
    $message .= "📮 <b>收款地址：</b>\n";
    $message .= "<code>" . htmlspecialchars($method['wallet_address']) . "</code>\n\n";
    
    // 显示网络信息
    if ($method['network']) {
        $message .= "🌐 <b>网络：</b>" . htmlspecialchars($method['network']) . "\n\n";
    }
    
    // 显示充值说明
    if ($method['instructions']) {
        $message .= "📝 <b>充值说明：</b>\n" . htmlspecialchars($method['instructions']) . "\n\n";
    } else {
        $message .= "📝 <b>充值说明：</b>\n";
        $message .= "1. 复制上方收款地址\n";
        $message .= "2. 使用您的钱包转账\n";
        $message .= "3. 转账完成后截图上传凭证\n";
        $message .= "4. 等待管理员确认到账\n\n";
    }
    
    $message .= "⚠️ <b>注意事项：</b>\n";
    $message .= "• 请确认转账网络正确\n";
    $message .= "• 转账完成后请联系客服\n";
    $message .= "• 通常1-30分钟内到账\n";
    
    $keyboard = [
        [['text' => '📱 联系客服', 'callback_data' => 'shop_contact']],
        [
            ['text' => '🔄 选择其他方式', 'callback_data' => 'shop_recharge'],
            ['text' => '🏠 返回主菜单', 'callback_data' => 'shop_menu']
        ]
    ];
    
    // 如果有二维码，先发送二维码图片
    if (!empty($method['qr_code_url'])) {
        $qr_url = $method['qr_code_url'];
        error_log("QR code URL found: " . $qr_url);
        
        // 如果是相对路径，转换为完整URL
        if (!preg_match('/^https?:\/\//', $qr_url)) {
            $qr_url = SITE_URL . '/' . ltrim($qr_url, '/');
            error_log("QR code URL converted to: " . $qr_url);
        }
        
        // 发送二维码图片和说明
        error_log("Sending photo with QR code");
        $result = $bot->sendPhoto($chat_id, $qr_url, $message, ['inline_keyboard' => $keyboard], 'HTML');
        error_log("Photo sent result: " . ($result ? 'success' : 'failed'));
    } else {
        // 只发送文字说明
        error_log("No QR code, sending text only");
        $result = $bot->sendMessage($chat_id, $message, 'HTML', ['inline_keyboard' => $keyboard]);
        error_log("Message sent result: " . ($result ? 'success' : 'failed'));
    }
}

// 发送语言选择
function sendLanguageSelection($bot, $db, $chat_id, $user_id) {
    // 获取用户当前语言
    $user_lang = getUserLanguage($db, $user_id);
    
    // 从数据库读取已启用的语言
    $stmt = $db->query("
        SELECT code, name, flag, status 
        FROM language_settings 
        WHERE status = 'active' 
        ORDER BY sort_order ASC, id ASC
    ");
    $languages = $stmt->fetchAll();
    
    $message = getLang('language_title', $user_lang) . "\n\n";
    $message .= getLang('language_desc', $user_lang);
    
    // 构建语言按钮
    $keyboard = [];
    $row = [];
    $count = 0;
    
    foreach ($languages as $lang) {
        $row[] = [
            'text' => $lang['flag'] . ' ' . $lang['name'],
            'callback_data' => 'lang_' . $lang['code']
        ];
        $count++;
        
        // 每行2个按钮
        if ($count % 2 == 0) {
            $keyboard[] = $row;
            $row = [];
        }
    }
    
    // 添加剩余的按钮
    if (!empty($row)) {
        $keyboard[] = $row;
    }
    
    // 添加返回按钮
    $keyboard[] = [
        ['text' => getLang('btn_back_menu', $user_lang), 'callback_data' => 'shop_menu']
    ];
    
    $bot->sendMessage($chat_id, $message, 'HTML', ['inline_keyboard' => $keyboard]);
}

// 处理语言选择
function handleLanguageSelection($bot, $db, $chat_id, $user_id, $callback_id, $language, $callback_query = null) {
    try {
        error_log("=== Language Selection Start ===");
        error_log("User ID: " . $user_id);
        error_log("Language Code: " . $language);
        
        // 从数据库获取语言信息
        $stmt = $db->prepare("SELECT name, status FROM language_settings WHERE code = ?");
        $stmt->execute([$language]);
        $lang_info = $stmt->fetch();
        
        error_log("Language Info: " . json_encode($lang_info));
        
        if (!$lang_info) {
            error_log("ERROR: Language not found in database");
            $bot->answerCallbackQuery($callback_id, '语言不存在', true);
            return;
        }
        
        if ($lang_info['status'] != 'active') {
            error_log("ERROR: Language not active. Status: " . $lang_info['status']);
            $bot->answerCallbackQuery($callback_id, '该语言暂未启用', true);
            return;
        }
        
        // 确保用户存在
        $stmt = $db->prepare("SELECT id FROM card_users WHERE telegram_id = ?");
        $stmt->execute([$user_id]);
        $user_exists = $stmt->fetch();
        
        if (!$user_exists) {
            error_log("ERROR: User not found in card_users table");
            // 创建用户
            $stmt = $db->prepare("INSERT INTO card_users (telegram_id, language) VALUES (?, ?)");
            $stmt->execute([$user_id, $language]);
            error_log("User created with language: " . $language);
        } else {
            // 更新用户语言偏好
            $stmt = $db->prepare("UPDATE card_users SET language = ? WHERE telegram_id = ?");
            $result = $stmt->execute([$language, $user_id]);
            error_log("UPDATE result: " . ($result ? 'success' : 'failed'));
            error_log("Rows affected: " . $stmt->rowCount());
        }
        
        // 验证更新
        $stmt = $db->prepare("SELECT language FROM card_users WHERE telegram_id = ?");
        $stmt->execute([$user_id]);
        $updated_user = $stmt->fetch();
        error_log("User language after update: " . ($updated_user ? $updated_user['language'] : 'NULL'));
        
        // 删除语言选择消息
        if ($callback_query && isset($callback_query['message']['message_id'])) {
            try {
                $bot->deleteMessage($chat_id, $callback_query['message']['message_id']);
                error_log("Old message deleted");
            } catch (Exception $e) {
                error_log("Failed to delete message: " . $e->getMessage());
            }
        }
        
        // 使用新语言发送确认消息
        $message = getLang('language_set', $language);
        error_log("Sending message: " . $message);
        
        $keyboard = [
            [
                ['text' => getLang('btn_back_menu', $language), 'callback_data' => 'shop_menu']
            ]
        ];
        
        $bot->sendMessage($chat_id, $message, 'HTML', ['inline_keyboard' => $keyboard]);
        $bot->answerCallbackQuery($callback_id, getLang('language_set_success', $language));
        
        // 立即发送新语言的主菜单
        sleep(1); // 稍微延迟一下
        sendMainMenu($bot, $db, $chat_id, $user_id);
        
        error_log("=== Language Selection End ===");
        
    } catch (Exception $e) {
        error_log("Language selection error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        $bot->answerCallbackQuery($callback_id, '设置失败: ' . $e->getMessage(), true);
    }
}

/**
 * 检查TRC地址查询
 */
function checkTrcAddressQuery($bot, $db, $chat_id, $text, $message) {
    // 匹配TRC地址格式（T开头，34位字符）
    if (preg_match('/^(T[A-Za-z1-9]{33})$/', trim($text), $matches)) {
        $address = $matches[1];
        error_log("TRC address detected: " . $address);
        
        try {
            // 使用 TronScan API 查询账户信息
            $accountUrl = "https://apilist.tronscanapi.com/api/account?address=" . urlencode($address);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $accountUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$response) {
                error_log("TronScan API error: HTTP " . $httpCode . ", Error: " . $error);
                $bot->sendMessage($chat_id, "❌ 查询失败，请稍后再试", 'HTML');
                return true;
            }
            
            $accountData = json_decode($response, true);
            
            if (!$accountData) {
                error_log("Invalid JSON response");
                $bot->sendMessage($chat_id, "❌ 查询失败，数据解析错误", 'HTML');
                return true;
            }
            
            // 提取余额信息
            $trx_balance = 0;
            $usdt_balance = 0;
            
            // TRX 余额（SUN 转 TRX）
            if (isset($accountData['balance'])) {
                $trx_balance = floatval($accountData['balance']) / 1000000;
            }
            
            // USDT 余额（从 trc20token_balances 中查找）
            if (isset($accountData['trc20token_balances']) && is_array($accountData['trc20token_balances'])) {
                foreach ($accountData['trc20token_balances'] as $token) {
                    // USDT TRC20 合约地址
                    if (isset($token['tokenId']) && $token['tokenId'] === 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t') {
                        $decimals = isset($token['tokenDecimal']) ? intval($token['tokenDecimal']) : 6;
                        $usdt_balance = floatval($token['balance']) / pow(10, $decimals);
                        break;
                    }
                }
            }
            
            // 查询 TRC20 转账记录
            $usdtContract = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
            $transferUrl = "https://apilist.tronscanapi.com/api/token_trc20/transfers?relatedAddress=" . urlencode($address) . "&contract_address=" . $usdtContract . "&limit=10";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $transferUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]);
            
            $transferResponse = curl_exec($ch);
            curl_close($ch);
            
            $transferData = json_decode($transferResponse, true);
            $transactions = $transferData['token_transfers'] ?? [];
            
            error_log("Found " . count($transactions) . " USDT transactions");
            
            // 分类收款和出款
            $income_records = [];
            $outcome_records = [];
            
            foreach ($transactions as $tx) {
                $is_income = (strtolower($tx['to_address']) === strtolower($address));
                $amount = floatval($tx['quant']) / 1000000; // USDT 精度为 6
                $timestamp = isset($tx['block_ts']) ? date('Y-m-d H:i', $tx['block_ts'] / 1000) : '';
                $tx_hash = $tx['transaction_id'] ?? '';
                
                $record = [
                    'time' => $timestamp,
                    'amount' => $amount,
                    'hash' => $tx_hash
                ];
                
                if ($is_income) {
                    if (count($income_records) < 5) {
                        $income_records[] = $record;
                    }
                } else {
                    if (count($outcome_records) < 5) {
                        $outcome_records[] = $record;
                    }
                }
                
                if (count($income_records) >= 5 && count($outcome_records) >= 5) {
                    break;
                }
            }
            
            // 构建回复消息
            $response = "📍 <b>地址:</b>\n<code>" . htmlspecialchars($address) . "</code>\n\n";
            
            // 余额信息
            $response .= "<b>实类:</b>  TRC  USDT余额💰:  <b>" . number_format($usdt_balance, 6) . "</b>\n";
            $response .= "<b>实类:</b>  TRC  TRX余额💰:  <b>" . number_format($trx_balance, 6) . "</b>\n\n";
            
            $response .= "🔸 <b>账号交易出入记录⚡</b>\n\n";
            
            // 显示近期转入记录
            $response .= "<b>近期转入记录📥</b>\n";
            if (!empty($income_records)) {
                foreach ($income_records as $record) {
                    $response .= $record['time'] . "  " . number_format($record['amount'], 0) . "U  ";
                    if (!empty($record['hash'])) {
                        $response .= "<a href='https://tronscan.org/#/transaction/" . $record['hash'] . "'>查看详情</a>";
                    }
                    $response .= "\n";
                }
            } else {
                $response .= "暂无记录\n";
            }
            
            $response .= "\n";
            
            // 显示近期转出记录
            $response .= "<b>近期转出记录📤</b>\n";
            if (!empty($outcome_records)) {
                foreach ($outcome_records as $record) {
                    $response .= $record['time'] . "  " . number_format($record['amount'], 0) . "U  ";
                    if (!empty($record['hash'])) {
                        $response .= "<a href='https://tronscan.org/#/transaction/" . $record['hash'] . "'>查看详情</a>";
                    }
                    $response .= "\n";
                }
            } else {
                $response .= "暂无记录\n";
            }
            
            // 发送查询结果
            $bot->sendMessage($chat_id, $response, 'HTML');
            
            // 记录到数据库
            try {
                $stmt = $db->prepare("
                    INSERT INTO address_query_logs (chat_id, user_id, username, address, usdt_balance, trx_balance) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $chat_id,
                    $message['from']['id'] ?? null,
                    $message['from']['username'] ?? null,
                    $address,
                    $usdt_balance,
                    $trx_balance
                ]);
                error_log("Query logged to database for address: " . $address);
            } catch (Exception $e) {
                error_log("Failed to log query to database: " . $e->getMessage());
            }
            
            // 记录查询日志
            logSystem('info', 'TRC地址查询', [
                'address' => $address,
                'chat_id' => $chat_id,
                'user_id' => $message['from']['id'] ?? 'unknown',
                'usdt_balance' => $usdt_balance,
                'trx_balance' => $trx_balance
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("TRC address query error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            $bot->sendMessage($chat_id, "❌ 查询失败: " . $e->getMessage(), 'HTML');
            return true;
        }
    }
    
    return false;
}

/**
 * 检查USDT汇率查询
 */
function checkExchangeRateQuery($bot, $db, $chat_id, $text, $message) {
    // 匹配命令：/汇率 或 /rate 或 汇率查询 或 汇率
    $text_trim = trim($text);
    
    // 支持多种命令格式
    if (!preg_match('/^\/?(汇率查询|汇率|rate)$/ui', $text_trim)) {
        return false;
    }
    
    error_log("Exchange rate query detected: " . $text);
    
    try {
        $avgBuyPrice = 0;
        $avgSellPrice = 0;
        $bestBuyPrice = 0;
        $bestSellPrice = 0;
        $dataSource = '';
        
        // 方案1：尝试使用币安P2P API
        $binanceResult = getBinanceP2PRate();
        
        if ($binanceResult) {
            $avgBuyPrice = $binanceResult['avgBuyPrice'];
            $avgSellPrice = $binanceResult['avgSellPrice'];
            $bestBuyPrice = $binanceResult['bestBuyPrice'];
            $bestSellPrice = $binanceResult['bestSellPrice'];
            $dataSource = '币安P2P市场';
            error_log("Using Binance P2P data");
        }
        
        // 方案2：如果币安失败，尝试使用欧易OKX API
        if ($avgBuyPrice == 0 && $avgSellPrice == 0) {
            error_log("Binance failed, trying OKX...");
            $okxResult = getOKXP2PRate();
            
            if ($okxResult) {
                $avgBuyPrice = $okxResult['avgBuyPrice'];
                $avgSellPrice = $okxResult['avgSellPrice'];
                $bestBuyPrice = $okxResult['bestBuyPrice'];
                $bestSellPrice = $okxResult['bestSellPrice'];
                $dataSource = '欧易OKX市场';
                error_log("Using OKX data");
            }
        }
        
        // 方案3：如果都失败，使用备用汇率API
        if ($avgBuyPrice == 0 && $avgSellPrice == 0) {
            error_log("OKX failed, trying backup API...");
            $backupResult = getBackupExchangeRate();
            
            if ($backupResult) {
                $avgBuyPrice = $backupResult['rate'];
                $avgSellPrice = $backupResult['rate'];
                $bestBuyPrice = $backupResult['rate'];
                $bestSellPrice = $backupResult['rate'];
                $dataSource = $backupResult['source'];
                error_log("Using backup data from: " . $dataSource);
            }
        }
        
        if ($avgBuyPrice == 0 && $avgSellPrice == 0) {
            error_log("All exchange rate APIs failed");
            $bot->sendMessage($chat_id, "❌ 暂无汇率数据，请稍后再试", 'HTML');
            return true;
        }
        
        // 计算中间价
        $midPrice = 0;
        if ($avgBuyPrice > 0 && $avgSellPrice > 0) {
            $midPrice = ($avgBuyPrice + $avgSellPrice) / 2;
        } elseif ($avgBuyPrice > 0) {
            $midPrice = $avgBuyPrice;
        } elseif ($avgSellPrice > 0) {
            $midPrice = $avgSellPrice;
        }
        
        // 构建回复消息
        $response = "💱 <b>USDT 实时汇率</b>\n\n";
        $response .= "⚡ <b>更新时间：</b>" . date('Y-m-d H:i:s') . "\n\n";
        
        $response .= "🇨🇳 <b>人民币 CNY</b>\n\n";
        
        if ($avgBuyPrice > 0 && $avgBuyPrice != $avgSellPrice) {
            $response .= "📈 <b>买入价：</b>￥" . number_format($avgBuyPrice, 2) . "\n";
            if ($bestBuyPrice > 0 && $bestBuyPrice != $avgBuyPrice) {
                $response .= "   <i>最优买入：￥" . number_format($bestBuyPrice, 2) . "</i>\n";
            }
            $response .= "\n";
        }
        
        if ($avgSellPrice > 0 && $avgBuyPrice != $avgSellPrice) {
            $response .= "📉 <b>卖出价：</b>￥" . number_format($avgSellPrice, 2) . "\n";
            if ($bestSellPrice > 0 && $bestSellPrice != $avgSellPrice) {
                $response .= "   <i>最优卖出：￥" . number_format($bestSellPrice, 2) . "</i>\n";
            }
            $response .= "\n";
        }
        
        if ($midPrice > 0) {
            $response .= "💰 <b>参考汇率：</b>￥" . number_format($midPrice, 2) . "\n\n";
        }
        
        $response .= "📊 <i>数据来源：{$dataSource}</i>\n";
        $response .= "🔄 <i>仅供参考，以实际交易为准</i>";
        
        // 发送汇率信息
        $bot->sendMessage($chat_id, $response, 'HTML');
        
        // 记录查询日志
        logSystem('info', 'USDT汇率查询', [
            'chat_id' => $chat_id,
            'user_id' => $message['from']['id'] ?? 'unknown',
            'username' => $message['from']['username'] ?? 'unknown',
            'buy_price' => $avgBuyPrice,
            'sell_price' => $avgSellPrice,
            'source' => $dataSource
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Exchange rate query error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        $bot->sendMessage($chat_id, "❌ 汇率查询失败: " . $e->getMessage(), 'HTML');
        return true;
    }
}

/**
 * 获取币安P2P汇率
 */
function getBinanceP2PRate() {
    try {
        $apiUrl = "https://p2p.binance.com/bapi/c2c/v2/friendly/c2c/adv/search";
        
        // 查询买入价格
        $buyParams = json_encode([
            'asset' => 'USDT',
            'fiat' => 'CNY',
            'merchantCheck' => false,
            'page' => 1,
            'rows' => 20,
            'tradeType' => 'BUY',
            'payTypes' => [],
            'publisherType' => null
        ]);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $buyParams);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: */*',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Origin: https://p2p.binance.com',
            'Referer: https://p2p.binance.com/',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ]);
        
        $buyResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        error_log("Binance BUY response code: " . $httpCode);
        error_log("Binance BUY response (first 500): " . substr($buyResponse, 0, 500));
        
        if ($httpCode !== 200 || !$buyResponse) {
            error_log("Binance P2P API error (BUY): HTTP " . $httpCode . ", Error: " . $error);
            curl_close($ch);
            return null;
        }
        
        // 查询卖出价格
        $sellParams = json_encode([
            'asset' => 'USDT',
            'fiat' => 'CNY',
            'merchantCheck' => false,
            'page' => 1,
            'rows' => 20,
            'tradeType' => 'SELL',
            'payTypes' => [],
            'publisherType' => null
        ]);
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, $sellParams);
        $sellResponse = curl_exec($ch);
        curl_close($ch);
        
        error_log("Binance SELL response (first 500): " . substr($sellResponse, 0, 500));
        
        $buyData = json_decode($buyResponse, true);
        $sellData = json_decode($sellResponse, true);
        
        // 解析买入价格
        $buyPrices = [];
        if (isset($buyData['data']) && is_array($buyData['data'])) {
            foreach ($buyData['data'] as $adv) {
                if (isset($adv['adv']['price'])) {
                    $buyPrices[] = floatval($adv['adv']['price']);
                }
                if (count($buyPrices) >= 5) break;
            }
        }
        
        // 解析卖出价格
        $sellPrices = [];
        if (isset($sellData['data']) && is_array($sellData['data'])) {
            foreach ($sellData['data'] as $adv) {
                if (isset($adv['adv']['price'])) {
                    $sellPrices[] = floatval($adv['adv']['price']);
                }
                if (count($sellPrices) >= 5) break;
            }
        }
        
        error_log("Binance buyPrices: " . json_encode($buyPrices));
        error_log("Binance sellPrices: " . json_encode($sellPrices));
        
        if (empty($buyPrices) && empty($sellPrices)) {
            return null;
        }
        
        return [
            'avgBuyPrice' => !empty($buyPrices) ? array_sum($buyPrices) / count($buyPrices) : 0,
            'avgSellPrice' => !empty($sellPrices) ? array_sum($sellPrices) / count($sellPrices) : 0,
            'bestBuyPrice' => !empty($buyPrices) ? min($buyPrices) : 0,
            'bestSellPrice' => !empty($sellPrices) ? max($sellPrices) : 0
        ];
    } catch (Exception $e) {
        error_log("getBinanceP2PRate error: " . $e->getMessage());
        return null;
    }
}

/**
 * 获取欧易OKX P2P汇率
 */
function getOKXP2PRate() {
    try {
        // OKX C2C API
        $apiUrl = "https://www.okx.com/v3/c2c/tradingOrders/books";
        
        // 查询买入价格
        $buyUrl = $apiUrl . "?quoteCurrency=CNY&baseCurrency=USDT&side=sell&paymentMethod=all&userType=all&showTrade=false&showFollow=false&showAlreadyTraded=false&isAbleFilter=false";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $buyUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Accept-Language: zh-CN,zh;q=0.9',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ]);
        
        $buyResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        error_log("OKX BUY response code: " . $httpCode);
        error_log("OKX BUY response (first 500): " . substr($buyResponse, 0, 500));
        
        if ($httpCode !== 200 || !$buyResponse) {
            curl_close($ch);
            return null;
        }
        
        // 查询卖出价格
        $sellUrl = $apiUrl . "?quoteCurrency=CNY&baseCurrency=USDT&side=buy&paymentMethod=all&userType=all&showTrade=false&showFollow=false&showAlreadyTraded=false&isAbleFilter=false";
        
        curl_setopt($ch, CURLOPT_URL, $sellUrl);
        $sellResponse = curl_exec($ch);
        curl_close($ch);
        
        error_log("OKX SELL response (first 500): " . substr($sellResponse, 0, 500));
        
        $buyData = json_decode($buyResponse, true);
        $sellData = json_decode($sellResponse, true);
        
        // 解析买入价格
        $buyPrices = [];
        if (isset($buyData['data']['sell']) && is_array($buyData['data']['sell'])) {
            foreach ($buyData['data']['sell'] as $order) {
                if (isset($order['price'])) {
                    $buyPrices[] = floatval($order['price']);
                }
                if (count($buyPrices) >= 5) break;
            }
        }
        
        // 解析卖出价格
        $sellPrices = [];
        if (isset($sellData['data']['buy']) && is_array($sellData['data']['buy'])) {
            foreach ($sellData['data']['buy'] as $order) {
                if (isset($order['price'])) {
                    $sellPrices[] = floatval($order['price']);
                }
                if (count($sellPrices) >= 5) break;
            }
        }
        
        error_log("OKX buyPrices: " . json_encode($buyPrices));
        error_log("OKX sellPrices: " . json_encode($sellPrices));
        
        if (empty($buyPrices) && empty($sellPrices)) {
            return null;
        }
        
        return [
            'avgBuyPrice' => !empty($buyPrices) ? array_sum($buyPrices) / count($buyPrices) : 0,
            'avgSellPrice' => !empty($sellPrices) ? array_sum($sellPrices) / count($sellPrices) : 0,
            'bestBuyPrice' => !empty($buyPrices) ? min($buyPrices) : 0,
            'bestSellPrice' => !empty($sellPrices) ? max($sellPrices) : 0
        ];
    } catch (Exception $e) {
        error_log("getOKXP2PRate error: " . $e->getMessage());
        return null;
    }
}

/**
 * 备用汇率API（使用第三方汇率服务）
 */
function getBackupExchangeRate() {
    try {
        // 尝试使用 exchangerate-api
        $apiUrl = "https://open.er-api.com/v6/latest/USD";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        error_log("Backup API response code: " . $httpCode);
        error_log("Backup API response (first 300): " . substr($response, 0, 300));
        
        if ($httpCode !== 200 || !$response) {
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['rates']['CNY'])) {
            return [
                'rate' => floatval($data['rates']['CNY']),
                'source' => 'ExchangeRate-API (USD/CNY)'
            ];
        }
        
        return null;
    } catch (Exception $e) {
        error_log("getBackupExchangeRate error: " . $e->getMessage());
        return null;
    }
}

/**
 * 从黑名单设置表获取设置
 */
function getBlacklistSetting($db, $key, $default = '') {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM blacklist_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

/**
 * 检查@的用户是否为群成员
 */
function checkMentionedUsers($bot, $db, $chat_id, $message) {
    // 检查是否启用@提及检查功能（从blacklist_settings表读取）
    $enable_mention_check = getBlacklistSetting($db, 'enable_mention_check', '1');
    if ($enable_mention_check !== '1') {
        return false;
    }
    
    // 检查是否有 entities （mention 或 text_mention）
    if (!isset($message['entities']) || empty($message['entities'])) {
        return false;
    }
    
    $text = $message['text'] ?? '';
    $mentioned_users = [];
    
    // 遍历所有 entities
    foreach ($message['entities'] as $entity) {
        if ($entity['type'] == 'mention') {
            // @username 格式
            $username = mb_substr($text, $entity['offset'] + 1, $entity['length'] - 1); // +1 跳过 @
            $mentioned_users[] = ['type' => 'username', 'value' => $username];
        } elseif ($entity['type'] == 'text_mention') {
            // @用户名称 格式（直接点击用户）
            if (isset($entity['user'])) {
                $mentioned_users[] = [
                    'type' => 'user_id',
                    'value' => $entity['user']['id'],
                    'name' => ($entity['user']['first_name'] ?? '') . ' ' . ($entity['user']['last_name'] ?? '')
                ];
            }
        }
    }
    
    if (empty($mentioned_users)) {
        return false;
    }
    
    error_log("Detected mentions: " . json_encode($mentioned_users));
    
    try {
        // 获取群组ID
        $stmt = $db->prepare("SELECT id FROM groups WHERE chat_id = ?");
        $stmt->execute([$chat_id]);
        $group = $stmt->fetch();
        
        if (!$group) {
            error_log("Group not found for mention check");
            return false;
        }
        
        $members = [];      // 是群成员
        $non_members = [];  // 不是群成员
        
        foreach ($mentioned_users as $user) {
            $is_member = false;
            $display_name = '';
            
            if ($user['type'] == 'username') {
                $display_name = '@' . $user['value'];
                
                // 先从数据库查找（忽略status，只要有记录就检查）
                $stmt = $db->prepare("
                    SELECT gm.*, gm.user_id 
                    FROM group_members gm 
                    WHERE gm.group_id = ? AND LOWER(gm.username) = LOWER(?)
                ");
                $stmt->execute([$group['id'], $user['value']]);
                $member = $stmt->fetch();
                
                if ($member) {
                    // 数据库有记录，检查状态
                    if ($member['status'] == 'member' || $member['status'] == '' || $member['status'] === null) {
                        $is_member = true;
                        error_log("Username @" . $user['value'] . " found in database - is member");
                    } else {
                        $is_member = false;
                        error_log("Username @" . $user['value'] . " found but status is: " . $member['status']);
                    }
                } else {
                    // 数据库没有该用户，尝试通过API查询
                    error_log("Username @" . $user['value'] . " not in database, trying API...");
                    
                    try {
                        // Telegram Bot API 支持使用 @username 作为 user_id 参数
                        $chatMember = $bot->getChatMember($chat_id, '@' . $user['value']);
                        error_log("getChatMember by username result: " . json_encode($chatMember));
                        
                        if ($chatMember && isset($chatMember['status'])) {
                            $status = $chatMember['status'];
                            if (in_array($status, ['member', 'administrator', 'creator', 'restricted'])) {
                                $is_member = true;
                                error_log("Username @" . $user['value'] . " verified via API - is member");
                                
                                // 同步到数据库
                                if (isset($chatMember['user'])) {
                                    saveMember($db, $chat_id, $chatMember['user']);
                                    error_log("Synced username member to database");
                                }
                            } else {
                                $is_member = false;
                                error_log("Username @" . $user['value'] . " API status: " . $status . " - not member");
                            }
                        } else {
                            $is_member = false;
                            error_log("Username @" . $user['value'] . " - API returned no valid response");
                        }
                    } catch (Exception $e) {
                        // API 查询失败，可能用户不存在或其他错误
                        $is_member = false;
                        error_log("API check username @" . $user['value'] . " error: " . $e->getMessage());
                    }
                }
            } elseif ($user['type'] == 'user_id') {
                $display_name = trim($user['name']);
                if (empty($display_name)) {
                    $display_name = 'ID:' . $user['value'];
                }
                
                // 先从数据库查找
                $stmt = $db->prepare("
                    SELECT gm.* 
                    FROM group_members gm 
                    WHERE gm.group_id = ? AND gm.user_id = ? AND gm.status = 'member'
                ");
                $stmt->execute([$group['id'], $user['value']]);
                $member = $stmt->fetch();
                
                if ($member) {
                    $is_member = true;
                    error_log("User ID " . $user['value'] . " found in database - is member");
                } else {
                    // 数据库没有，通过API实时查询
                    try {
                        $chatMember = $bot->getChatMember($chat_id, $user['value']);
                        error_log("getChatMember result: " . json_encode($chatMember));
                        
                        if ($chatMember && isset($chatMember['status'])) {
                            $status = $chatMember['status'];
                            // member, administrator, creator 都算是群成员
                            if (in_array($status, ['member', 'administrator', 'creator'])) {
                                $is_member = true;
                                
                                // 同步到数据库
                                $user_info = $chatMember['user'] ?? [];
                                if (!empty($user_info)) {
                                    saveMember($db, $chat_id, $user_info);
                                    error_log("Synced member to database: " . $user['value']);
                                }
                            } else {
                                // left, kicked, restricted 等状态
                                $is_member = false;
                                error_log("User ID " . $user['value'] . " status is " . $status . " - not member");
                            }
                        } else {
                            $is_member = false;
                            error_log("User ID " . $user['value'] . " - API returned no status");
                        }
                    } catch (Exception $e) {
                        error_log("API check user_id error: " . $e->getMessage());
                        $is_member = false; // API 出错也认为不是成员
                    }
                }
            }
            
            // 分类
            if ($is_member) {
                $members[] = $display_name;
            } else {
                $non_members[] = $display_name;
            }
        }
        
        // 构建回复消息
        $responses = [];
        
        // 获取自定义消息模板（从blacklist_settings表读取）
        $member_title = getBlacklistSetting($db, 'mention_member_title', '✅ <b>Notice:</b>');
        $member_text = getBlacklistSetting($db, 'mention_member_text', 'The following users are group members:');
        $non_member_title = getBlacklistSetting($db, 'mention_non_member_title', '⚠️ <b>Warning:</b>');
        $non_member_text = getBlacklistSetting($db, 'mention_non_member_text', 'The following users are NOT group members:');
        $non_member_footer = getBlacklistSetting($db, 'mention_non_member_footer', '📌 Please verify their identity');
        
        // 如果有群成员
        if (!empty($members)) {
            $response = $member_title . "\n\n";
            $response .= $member_text . "\n\n";
            
            foreach ($members as $index => $user_name) {
                $response .= ($index + 1) . ". " . htmlspecialchars($user_name) . "\n";
            }
            
            $responses[] = $response;
        }
        
        // 如果有非群成员
        if (!empty($non_members)) {
            $response = $non_member_title . "\n\n";
            $response .= $non_member_text . "\n\n";
            
            foreach ($non_members as $index => $user_name) {
                $response .= ($index + 1) . ". " . htmlspecialchars($user_name) . "\n";
            }
            
            $response .= "\n" . $non_member_footer;
            
            $responses[] = $response;
        }
        
        // 发送回复
        if (!empty($responses)) {
            $full_response = implode("\n\n", $responses);
            
            // 回复原消息
            $bot->sendMessage($chat_id, $full_response, 'HTML', null, null, $message['message_id']);
            
            // 记录日志
            logSystem('info', '检测到@用户', [
                'chat_id' => $chat_id,
                'members' => $members,
                'non_members' => $non_members,
                'mentioned_by' => $message['from']['id'] ?? 'unknown'
            ]);
            
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Check mentioned users error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

// 检查视频分享链接
function checkVideoShare($bot, $db, $chat_id, $user_id, $text, $message) {
    try {
        error_log("checkVideoShare - 收到文本: " . $text);
        
        // 匹配视频链接格式: /video/TOKEN 或 https://domain.com/video/TOKEN
        if (!preg_match('/\/video\/([a-f0-9]{32})/i', $text, $matches)) {
            error_log("checkVideoShare - 正则不匹配，返回false");
            return false;
        }
        
        $token = $matches[1];
        error_log("检测到视频分享链接，token: " . $token);
        
        // 检查videos表是否存在
        try {
            $tableCheck = $db->query("SHOW TABLES LIKE 'videos'");
            if ($tableCheck->rowCount() == 0) {
                error_log("错误：videos表不存在！请先执行 sql/install_videos.sql");
                $bot->sendMessage($chat_id, "❌ 系统配置错误，请联系管理员");
                return true;
            }
        } catch (Exception $e) {
            error_log("检查videos表错误: " . $e->getMessage());
        }
        
        // 查找视频
        error_log("正在查询视频，token: " . $token);
        $stmt = $db->prepare("
            SELECT v.*, g.title as group_title, g.chat_id as group_chat_id
            FROM videos v
            LEFT JOIN `groups` g ON v.required_group_id = g.id
            WHERE v.share_token = ? AND v.is_active = 1
        ");
        $stmt->execute([$token]);
        $video = $stmt->fetch();
        
        if (!$video) {
            error_log("未找到视频，token: " . $token);
            // 检查是否存在但被禁用
            $stmt2 = $db->prepare("SELECT id, title, is_active FROM videos WHERE share_token = ?");
            $stmt2->execute([$token]);
            $disabledVideo = $stmt2->fetch();
            if ($disabledVideo) {
                error_log("视频存在但被禁用: ID=" . $disabledVideo['id'] . ", title=" . $disabledVideo['title'] . ", is_active=" . $disabledVideo['is_active']);
            } else {
                error_log("数据库中不存在此token的视频记录");
            }
            $bot->sendMessage($chat_id, "❌ 视频不存在或已被禁用");
            return true;
        }
        
        error_log("找到视频: ID=" . $video['id'] . ", title=" . $video['title']);
        
        // 检查访问方式
        if ($video['access_type'] === 'card') {
            $bot->sendMessage($chat_id, "⚠️ 该视频需要使用卡密访问，请直接发送卡密给机器人");
            return true;
        }
        
        // 检查是否需要加入群组
        if ($video['required_group_id']) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as is_member 
                FROM group_members 
                WHERE group_id = ? AND user_id = ? AND status = 'member'
            ");
            $stmt->execute([$video['required_group_id'], $user_id]);
            $isMember = $stmt->fetchColumn() > 0;
            
            if (!$isMember) {
                $message_text = "🚫 需要加入指定群组才能观看该视频\n\n";
                $message_text .= "📢 群组：" . $video['group_title'] . "\n";
                $message_text .= "\n请加入群组后再次发送链接";
                
                // 如果群组有公开链接，提供加入按钮
                if ($video['group_chat_id']) {
                    $group_link = "https://t.me/c/" . str_replace('-100', '', $video['group_chat_id']);
                    $keyboard = [[['text' => '👉 加入群组', 'url' => $group_link]]];
                    $bot->sendMessage($chat_id, $message_text, 'HTML', ['inline_keyboard' => $keyboard]);
                } else {
                    $bot->sendMessage($chat_id, $message_text);
                }
                
                return true;
            }
        }
        
        // 发送视频
        sendVideoToUser($bot, $db, $chat_id, $user_id, $video, 'link');
        return true;
        
    } catch (Exception $e) {
        error_log("视频分享检查错误: " . $e->getMessage());
        return false;
    }
}

// 检查视频卡密
function checkVideoCard($bot, $db, $chat_id, $user_id, $text, $message) {
    try {
        error_log("checkVideoCard - 收到文本: " . $text);
        
        // 匹配卡密格式 (VID + 8位大写字母数字)
        if (!preg_match('/^VID[A-Z0-9]{8,}$/i', trim($text))) {
            error_log("checkVideoCard - 正则不匹配，返回false");
            return false;
        }
        
        $cardCode = strtoupper(trim($text));
        error_log("检测到视频卡密: " . $cardCode);
        
        // 检查videos表是否存在
        try {
            $tableCheck = $db->query("SHOW TABLES LIKE 'videos'");
            if ($tableCheck->rowCount() == 0) {
                error_log("错误：videos表不存在！请先执行 sql/install_videos.sql");
                $bot->sendMessage($chat_id, "❌ 系统配置错误，请联系管理员");
                return true;
            }
        } catch (Exception $e) {
            error_log("检查videos表错误: " . $e->getMessage());
        }
        
        // 查找视频
        $stmt = $db->prepare("
            SELECT v.*, g.title as group_title, g.chat_id as group_chat_id
            FROM videos v
            LEFT JOIN `groups` g ON v.required_group_id = g.id
            WHERE v.card_code = ? AND v.is_active = 1
        ");
        $stmt->execute([$cardCode]);
        $video = $stmt->fetch();
        
        if (!$video) {
            $bot->sendMessage($chat_id, "❌ 卡密不存在或已被禁用");
            return true;
        }
        
        // 检查访问方式
        if ($video['access_type'] === 'link') {
            $bot->sendMessage($chat_id, "⚠️ 该视频仅支持链接访问，不支持卡密");
            return true;
        }
        
        // 检查卡密是否过期
        if ($video['card_expiry'] && strtotime($video['card_expiry']) < time()) {
            $bot->sendMessage($chat_id, "❌ 卡密已过期");
            return true;
        }
        
        // 检查使用次数
        if ($video['card_use_limit'] > 0) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM video_access_logs 
                WHERE video_id = ? AND access_method = 'card'
            ");
            $stmt->execute([$video['id']]);
            $usageCount = $stmt->fetchColumn();
            
            if ($usageCount >= $video['card_use_limit']) {
                $bot->sendMessage($chat_id, "❌ 卡密使用次数已达上限");
                return true;
            }
        }
        
        // 检查是否需要加入群组
        if ($video['required_group_id']) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as is_member 
                FROM group_members 
                WHERE group_id = ? AND user_id = ? AND status = 'member'
            ");
            $stmt->execute([$video['required_group_id'], $user_id]);
            $isMember = $stmt->fetchColumn() > 0;
            
            if (!$isMember) {
                $message_text = "🚫 需要加入指定群组才能使用该卡密\n\n";
                $message_text .= "📢 群组：" . $video['group_title'] . "\n";
                $message_text .= "\n请加入群组后再次发送卡密";
                
                if ($video['group_chat_id']) {
                    $group_link = "https://t.me/c/" . str_replace('-100', '', $video['group_chat_id']);
                    $keyboard = [[['text' => '👉 加入群组', 'url' => $group_link]]];
                    $bot->sendMessage($chat_id, $message_text, 'HTML', ['inline_keyboard' => $keyboard]);
                } else {
                    $bot->sendMessage($chat_id, $message_text);
                }
                
                return true;
            }
        }
        
        // 发送视频
        sendVideoToUser($bot, $db, $chat_id, $user_id, $video, 'card');
        return true;
        
    } catch (Exception $e) {
        error_log("卡密检查错误: " . $e->getMessage());
        return false;
    }
}

// 处理视频播放回调
function handleVideoPlayCallback($bot, $db, $callback_query) {
    $callback_id = $callback_query['id'];
    $data = $callback_query['data'];
    $user_id = $callback_query['from']['id'];
    $message = $callback_query['message'] ?? null;
    $chat_id = $message['chat']['id'] ?? $user_id;
    
    try {
        // 提取视频ID
        $video_id = str_replace('video_play_', '', $data);
        
        if (!is_numeric($video_id)) {
            $bot->answerCallbackQuery($callback_id, '无效的视频ID');
            return;
        }
        
        // 查询视频
        $stmt = $db->prepare("
            SELECT v.*, g.title as group_title, g.chat_id as group_chat_id
            FROM videos v
            LEFT JOIN `groups` g ON v.required_group_id = g.id
            WHERE v.id = ? AND v.is_active = 1
        ");
        $stmt->execute([$video_id]);
        $video = $stmt->fetch();
        
        if (!$video) {
            $bot->answerCallbackQuery($callback_id, '视频不存在或已被禁用');
            return;
        }
        
        // 检查是否需要加入群组
        if ($video['required_group_id']) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as is_member 
                FROM group_members 
                WHERE group_id = ? AND user_id = ? AND status = 'member'
            ");
            $stmt->execute([$video['required_group_id'], $user_id]);
            $isMember = $stmt->fetchColumn() > 0;
            
            if (!$isMember) {
                $message_text = "🚫 需要加入指定群组才能观看\n📢 群组：" . htmlspecialchars($video['group_title']);
                $bot->answerCallbackQuery($callback_id, $message_text, true);
                return;
            }
        }
        
        // 应答回调
        $bot->answerCallbackQuery($callback_id, '正在加载视频...');
        
        // 发送视频
        sendVideoToUser($bot, $db, $chat_id, $user_id, $video, 'keyword');
        
    } catch (Exception $e) {
        error_log("视频播放回调错误: " . $e->getMessage());
        $bot->answerCallbackQuery($callback_id, '加载失败，请重试');
    }
}

// 根据关键词搜索视频
function searchVideoByKeyword($bot, $db, $chat_id, $user_id, $text, $message) {
    try {
        $keyword = trim($text);
        
        // 忽略空文本和命令
        if (empty($keyword) || strpos($keyword, '/') === 0) {
            return false;
        }
        
        // 忽略太短的关键词（至少2个字符）
        if (mb_strlen($keyword) < 2) {
            return false;
        }
        
        error_log("searchVideoByKeyword - 搜索关键词: " . $keyword);
        
        // 检查videos表是否存在
        try {
            $tableCheck = $db->query("SHOW TABLES LIKE 'videos'");
            if ($tableCheck->rowCount() == 0) {
                error_log("videos表不存在");
                return false;
            }
        } catch (Exception $e) {
            error_log("检查videos表错误: " . $e->getMessage());
            return false;
        }
        
        // 搜索匹配关键词的视频
        // 使用 FIND_IN_SET 或 LIKE 进行匹配
        $stmt = $db->prepare("
            SELECT v.*, g.title as group_title, g.chat_id as group_chat_id
            FROM videos v
            LEFT JOIN `groups` g ON v.required_group_id = g.id
            WHERE v.is_active = 1 
            AND (
                FIND_IN_SET(?, v.keywords) > 0
                OR v.keywords LIKE CONCAT('%', ?, '%')
                OR v.title LIKE CONCAT('%', ?, '%')
            )
            ORDER BY RAND()
            LIMIT 5
        ");
        $stmt->execute([$keyword, $keyword, $keyword]);
        $videos = $stmt->fetchAll();
        
        if (empty($videos)) {
            error_log("未找到匹配的视频，关键词: " . $keyword);
            return false; // 返回false让其他处理程序继续
        }
        
        error_log("找到 " . count($videos) . " 个匹配的视频");
        
        // 如果只找到一个视频，直接发送
        if (count($videos) == 1) {
            $video = $videos[0];
            
            // 检查是否需要加入群组
            if ($video['required_group_id']) {
                $stmt = $db->prepare("
                    SELECT COUNT(*) as is_member 
                    FROM group_members 
                    WHERE group_id = ? AND user_id = ? AND status = 'member'
                ");
                $stmt->execute([$video['required_group_id'], $user_id]);
                $isMember = $stmt->fetchColumn() > 0;
                
                if (!$isMember) {
                    $message_text = "🎬 找到视频：<b>" . htmlspecialchars($video['title']) . "</b>\n\n";
                    $message_text .= "🚫 需要加入指定群组才能观看\n";
                    $message_text .= "📢 群组：" . htmlspecialchars($video['group_title']) . "\n";
                    $message_text .= "\n请加入群组后再次发送关键词";
                    
                    if ($video['group_chat_id']) {
                        $group_link = "https://t.me/c/" . str_replace('-100', '', $video['group_chat_id']);
                        $keyboard = [[['text' => '👉 加入群组', 'url' => $group_link]]];
                        $bot->sendMessage($chat_id, $message_text, 'HTML', ['inline_keyboard' => $keyboard]);
                    } else {
                        $bot->sendMessage($chat_id, $message_text, 'HTML');
                    }
                    
                    return true;
                }
            }
            
            // 发送视频
            sendVideoToUser($bot, $db, $chat_id, $user_id, $video, 'keyword');
            return true;
        }
        
        // 找到多个视频，显示列表让用户选择
        $message_text = "🔍 <b>搜索结果</b>：找到 " . count($videos) . " 个相关视频\n\n";
        $message_text .= "关键词：<code>" . htmlspecialchars($keyword) . "</code>\n\n";
        
        $keyboard = [];
        foreach ($videos as $index => $video) {
            $message_text .= ($index + 1) . ". " . htmlspecialchars($video['title']) . "\n";
            $keyboard[] = [['text' => ($index + 1) . ". " . mb_substr($video['title'], 0, 20), 'callback_data' => 'video_play_' . $video['id']]];
        }
        
        $message_text .= "\n👇 点击下方按钮观看视频";
        
        $bot->sendMessage($chat_id, $message_text, 'HTML', ['inline_keyboard' => $keyboard]);
        return true;
        
    } catch (Exception $e) {
        error_log("关键词搜索错误: " . $e->getMessage());
        return false;
    }
}

// 发送视频给用户
function sendVideoToUser($bot, $db, $chat_id, $user_id, $video, $access_method) {
    try {
        error_log("=== sendVideoToUser 开始 ===");
        error_log("准备发送视频: " . $video['title'] . " (ID: " . $video['id'] . ")");
        error_log("视频数据: telegram_file_id=" . ($video['telegram_file_id'] ?? 'null') . ", video_path=" . ($video['video_path'] ?? 'null'));
        
        // 准备文案
        $caption = '';
        if (!empty($video['caption'])) {
            $caption = $video['caption'];
        }
        error_log("视频文案: " . ($caption ?: '(空)'));
        
        // 发送视频
        $result = false;
        
        if (!empty($video['telegram_file_id'])) {
            // 使用Telegram文件ID发送（缓存的file_id）
            error_log("使用Telegram文件ID发送: " . $video['telegram_file_id']);
            $result = $bot->sendVideo($chat_id, $video['telegram_file_id'], $caption);
            error_log("sendVideo结果: " . ($result ? json_encode($result) : 'false'));
        } elseif (!empty($video['video_path'])) {
            // 检查是否为外部链接
            if (preg_match('/^https?:\/\//', $video['video_path'])) {
                $videoUrl = $video['video_path'];
                error_log("检测到外部链接: " . $videoUrl);
                
                // 检查是否是 Telegram 消息链接
                if (preg_match('/^https?:\/\/(t\.me|telegram\.me)\//', $videoUrl)) {
                    error_log("检测到Telegram消息链接，尝试复制消息");
                    
                    $source_chat = null;
                    $source_message_id = null;
                    
                    // 解析 t.me 链接: https://t.me/channel/id 或 https://t.me/c/chatid/id
                    if (preg_match('/^https?:\/\/t\.me\/c\/(\d+)\/(\d+)/', $videoUrl, $matches)) {
                        $source_chat = '-100' . $matches[1];
                        $source_message_id = $matches[2];
                        error_log("私有频道: chat=$source_chat, msg=$source_message_id");
                    } elseif (preg_match('/^https?:\/\/t\.me\/([^\/]+)\/(\d+)/', $videoUrl, $matches)) {
                        $source_chat = '@' . $matches[1];
                        $source_message_id = $matches[2];
                        error_log("公开频道: chat=$source_chat, msg=$source_message_id");
                    }
                    
                    if ($source_chat && $source_message_id) {
                        // 使用 copyMessage（不显示转发来源）
                        $result = $bot->copyMessage($chat_id, $source_chat, $source_message_id, $caption ?: null);
                        error_log("copyMessage结果: " . json_encode($result));
                        
                        if ($result && isset($result['message_id'])) {
                            error_log("消息复制成功");
                        } elseif (!$result) {
                            // 复制失败，尝试转发
                            error_log("copyMessage失败，尝试forwardMessage");
                            $result = $bot->forwardMessage($chat_id, $source_chat, $source_message_id);
                            error_log("forwardMessage结果: " . json_encode($result));
                            
                            if ($result && isset($result['video']['file_id'])) {
                                $fileId = $result['video']['file_id'];
                                $stmt = $db->prepare("UPDATE videos SET telegram_file_id = ? WHERE id = ?");
                                $stmt->execute([$fileId, $video['id']]);
                                error_log("保存file_id: " . $fileId);
                            }
                        }
                        
                        if (!$result) {
                            $bot->sendMessage($chat_id, "❌ 无法获取该视频\n\n可能原因：\n• 机器人未加入源频道\n• 频道是私有的\n• 消息已被删除");
                            return false;
                        }
                    } else {
                        error_log("无法解析链接: " . $videoUrl);
                        $bot->sendMessage($chat_id, "❌ 无法解析此链接\n\n支持格式：\n• t.me/频道名/消息ID\n• t.me/c/频道ID/消息ID");
                        return false;
                    }
                } else {
                    // 普通外部链接，先尝试作为视频发送
                    error_log("使用外部链接发送: " . $videoUrl);
                    $result = $bot->sendVideo($chat_id, $videoUrl, $caption);
                    error_log("sendVideo结果: " . json_encode($result));
                    
                    // 如果成功，保存file_id以便下次快速发送
                    if ($result && isset($result['video']['file_id'])) {
                        $fileId = $result['video']['file_id'];
                        $stmt = $db->prepare("UPDATE videos SET telegram_file_id = ? WHERE id = ?");
                        $stmt->execute([$fileId, $video['id']]);
                        error_log("保存视频file_id: " . $fileId);
                    } elseif ($result) {
                        error_log("发送成功。返回结果: " . json_encode($result));
                    } elseif (!$result) {
                        // 视频发送失败，改为发送文本消息（包含链接）
                        error_log("视频发送失败，改为发送文本消息: " . $videoUrl);
                        $message = "";
                        if (!empty($caption)) {
                            $message = $caption . "\n\n";
                        }
                        $message .= "🔗 " . $videoUrl;
                        $result = $bot->sendMessage($chat_id, $message);
                        error_log("文本消息发送结果: " . json_encode($result));
                    }
                }
            } else {
            // 使用本地文件发送
            $filePath = __DIR__ . '/../' . $video['video_path'];
            error_log("使用本地文件发送: " . $filePath);
            
            if (file_exists($filePath)) {
                // 上传视频到Telegram
                $result = $bot->sendVideo($chat_id, new CURLFile($filePath), $caption);
                
                // 如果成功，保存file_id以便下次快速发送
                    if ($result && isset($result['video']['file_id'])) {
                        $fileId = $result['video']['file_id'];
                    $stmt = $db->prepare("UPDATE videos SET telegram_file_id = ? WHERE id = ?");
                    $stmt->execute([$fileId, $video['id']]);
                    error_log("保存视频file_id: " . $fileId);
                    } elseif ($result) {
                        error_log("视频发送成功，但未能获取file_id。返回结果: " . json_encode($result));
                }
            } else {
                error_log("视频文件不存在: " . $filePath);
                $bot->sendMessage($chat_id, "❌ 视频文件不存在，请联系管理员");
                return false;
                }
            }
        } else {
            error_log("视频数据不完整");
            $bot->sendMessage($chat_id, "❌ 视频数据错误");
            return false;
        }
        
        if ($result) {
            // 记录访问日志
            $stmt = $db->prepare("
                INSERT INTO video_access_logs (video_id, user_id, access_method) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$video['id'], $user_id, $access_method]);
            
            // 更新观看次数
            $stmt = $db->prepare("UPDATE videos SET view_count = view_count + 1 WHERE id = ?");
            $stmt->execute([$video['id']]);
            
            // 记录系统日志
            logSystem('info', '视频发送成功', [
                'video_id' => $video['id'],
                'video_title' => $video['title'],
                'user_id' => $user_id,
                'access_method' => $access_method
            ]);
            
            error_log("视频发送成功");
            return true;
        } else {
            error_log("视频发送失败");
            $bot->sendMessage($chat_id, "❌ 视频发送失败，请稍后重试");
            return false;
        }
        
    } catch (Exception $e) {
        error_log("发送视频错误: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        $bot->sendMessage($chat_id, "❌ 发送视频时发生错误");
        return false;
    }
}

/**
 * 转发消息到目标群组（支持单个群组或分类下的所有群组）
 */
function forwardToTargets($bot, $db, $source, $from_chat_id, $message_id) {
    try {
        $forwardCount = 0;
        
        // 优先检查是否转发到分类
        if (!empty($source['forward_to_category_id'])) {
            error_log("准备转发到分类ID: " . $source['forward_to_category_id']);
            
            // 获取该分类下的所有群组
            $stmt = $db->prepare("
                SELECT chat_id, title FROM groups 
                WHERE category_id = ? AND is_active = 1 AND is_deleted = 0
            ");
            $stmt->execute([$source['forward_to_category_id']]);
            $groups = $stmt->fetchAll();
            
            error_log("找到分类下的群组数量: " . count($groups));
            
            foreach ($groups as $group) {
                // 跳过源群组自己
                if ($group['chat_id'] == $from_chat_id) {
                    continue;
                }
                
                try {
                    $result = $bot->forwardMessage($group['chat_id'], $from_chat_id, $message_id);
                    if ($result) {
                        $forwardCount++;
                        error_log("转发到群组成功: " . $group['title'] . " (" . $group['chat_id'] . ")");
                    } else {
                        // 检查是否是群组迁移错误
                        $lastError = $bot->getLastError();
                        if ($lastError && strpos($lastError, 'migrate_to_chat_id') !== false) {
                            // 解析新的 chat_id
                            if (preg_match('/"migrate_to_chat_id":(-?\d+)/', $lastError, $matches)) {
                                $newChatId = $matches[1];
                                error_log("群组已迁移，更新chat_id: " . $group['chat_id'] . " -> " . $newChatId);
                                
                                // 更新数据库中的chat_id
                                $updateStmt = $db->prepare("UPDATE groups SET chat_id = ? WHERE chat_id = ?");
                                $updateStmt->execute([$newChatId, $group['chat_id']]);
                                
                                // 用新ID重试转发
                                $retryResult = $bot->forwardMessage($newChatId, $from_chat_id, $message_id);
                                if ($retryResult) {
                                    $forwardCount++;
                                    error_log("使用新ID转发成功: " . $group['title']);
                                }
                            }
                        }
                    }
                    // 添加延迟避免频率限制
                    usleep(100000); // 100ms
                } catch (Exception $e) {
                    error_log("转发到群组失败: " . $group['title'] . " - " . $e->getMessage());
                }
            }
            
            error_log("分类转发完成，成功转发到 {$forwardCount} 个群组");
            
        } elseif (!empty($source['forward_to_chat_id'])) {
            // 转发到单个群组
            error_log("准备转发到单个群组: " . $source['forward_to_chat_id']);
            
            try {
                $result = $bot->forwardMessage($source['forward_to_chat_id'], $from_chat_id, $message_id);
                if ($result) {
                    $forwardCount = 1;
                    error_log("单个群组转发成功");
                }
            } catch (Exception $e) {
                error_log("单个群组转发失败: " . $e->getMessage());
            }
        } else {
            error_log("未设置转发目标");
        }
        
        return $forwardCount;
        
    } catch (Exception $e) {
        error_log("转发错误: " . $e->getMessage());
        return 0;
    }
}

/**
 * 从群组采集Telegram消息链接
 * 保存视频链接到数据库
 */
function collectVideoLinkFromGroup($bot, $db, $chat_id, $message, $full_link, $channel, $msg_id) {
    try {
        error_log("开始采集消息链接 - chat_id: $chat_id, link: $full_link");
        
        // 确保采集源表存在
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS video_collect_sources (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    chat_id BIGINT NOT NULL,
                    chat_title VARCHAR(255),
                    chat_type VARCHAR(50) DEFAULT 'supergroup',
                    category_id INT DEFAULT NULL,
                    default_keywords VARCHAR(500) DEFAULT '',
                    is_active TINYINT(1) DEFAULT 1,
                    auto_forward TINYINT(1) DEFAULT 0,
                    forward_to_chat_id BIGINT DEFAULT NULL,
                    collect_links TINYINT(1) DEFAULT 1,
                    collected_count INT DEFAULT 0,
                    last_collected_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_chat_id (chat_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Exception $e) {
            // 表已存在
        }
        
        // 检查该群组是否配置为采集源
        $stmt = $db->prepare("SELECT * FROM video_collect_sources WHERE chat_id = ? AND is_active = 1");
        $stmt->execute([$chat_id]);
        $source = $stmt->fetch();
        
        if (!$source) {
            error_log("群组 $chat_id 未配置为采集源，跳过链接采集");
            return false;
        }
        
        error_log("找到采集源配置，开始采集链接");
        
        // 先确保videos表有必要字段（在查询之前！）
        try {
            $stmt = $db->query("SHOW COLUMNS FROM videos LIKE 'message_link'");
            if ($stmt->rowCount() == 0) {
                error_log("添加 message_link 字段到 videos 表");
                $db->exec("ALTER TABLE videos ADD COLUMN message_link VARCHAR(500) DEFAULT NULL");
            }
            $stmt = $db->query("SHOW COLUMNS FROM videos LIKE 'source_chat_id'");
            if ($stmt->rowCount() == 0) {
                $db->exec("ALTER TABLE videos ADD COLUMN source_chat_id BIGINT DEFAULT NULL");
                $db->exec("ALTER TABLE videos ADD COLUMN source_message_id BIGINT DEFAULT NULL");
            }
        } catch (Exception $e) {
            error_log("添加videos表字段错误: " . $e->getMessage());
        }
        
        $message_id = $message['message_id'];
        $caption = $message['text'] ?? $message['caption'] ?? '';
        
        // 检查是否已采集（根据链接去重）
        $stmt = $db->prepare("SELECT id FROM videos WHERE message_link = ?");
        $stmt->execute([$full_link]);
        if ($stmt->fetch()) {
            error_log("链接已采集，跳过: $full_link");
            return false;
        }
        
        // 生成标题 - 从消息内容提取或使用链接
        $title = '';
        if (!empty($caption)) {
            // 提取第一行作为标题（去除链接本身）
            $lines = explode("\n", str_replace($full_link, '', $caption));
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && !preg_match('/^https?:\/\//', $line)) {
                    $title = mb_substr($line, 0, 100);
                    break;
                }
            }
        }
        if (empty($title)) {
            $title = "视频链接_" . $channel . "_" . $msg_id;
        }
        
        // 提取关键词（标签）
        $keywords = $source['default_keywords'] ?? '';
        preg_match_all('/#([^\s#]+)/u', $caption, $matches);
        if (!empty($matches[1])) {
            $tags = implode(',', $matches[1]);
            $keywords = $keywords ? $keywords . ',' . $tags : $tags;
        }
        
        // 保存视频链接
        $stmt = $db->prepare("
            INSERT INTO videos 
            (category_id, title, caption, keywords, video_path, source_chat_id, source_message_id, message_link) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $source['category_id'],
            $title,
            $caption,
            $keywords,
            $full_link,  // 保存链接到 video_path
            $chat_id,
            $message_id,
            $full_link
        ]);
        
        $videoId = $db->lastInsertId();
        error_log("消息链接采集成功，video_id: $videoId, link: $full_link");
        
        // 更新采集源统计
        $stmt = $db->prepare("UPDATE video_collect_sources SET collected_count = collected_count + 1, last_collected_at = NOW() WHERE id = ?");
        $stmt->execute([$source['id']]);
        
        // 自动转发
        if ($source['auto_forward']) {
            forwardToTargets($bot, $db, $source, $chat_id, $message_id);
        }
        
        logSystem('info', '消息链接采集成功', [
            'video_id' => $videoId,
            'link' => $full_link,
            'source_chat_id' => $chat_id
        ]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("消息链接采集错误: " . $e->getMessage());
        return false;
    }
}

/**
 * 从群组采集视频
 * 检查群组是否配置为采集源，如果是则保存视频信息
 */
function collectVideoFromGroup($bot, $db, $chat_id, $message) {
    try {
        error_log("开始检查视频采集 - chat_id: $chat_id");
        
        // 确保采集源表存在
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS video_collect_sources (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    chat_id BIGINT NOT NULL,
                    chat_title VARCHAR(255),
                    chat_type VARCHAR(50) DEFAULT 'supergroup',
                    category_id INT DEFAULT NULL,
                    default_keywords VARCHAR(500) DEFAULT '',
                    is_active TINYINT(1) DEFAULT 1,
                    auto_forward TINYINT(1) DEFAULT 0,
                    forward_to_chat_id BIGINT DEFAULT NULL,
                    collected_count INT DEFAULT 0,
                    last_collected_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_chat_id (chat_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Exception $e) {
            // 表已存在，忽略错误
        }
        
        // 检查该群组是否配置为采集源
        $stmt = $db->prepare("SELECT * FROM video_collect_sources WHERE chat_id = ? AND is_active = 1");
        $stmt->execute([$chat_id]);
        $source = $stmt->fetch();
        
        if (!$source) {
            error_log("群组 $chat_id 未配置为采集源，跳过");
            return false;
        }
        
        error_log("找到采集源配置: " . print_r($source, true));
        
        // 获取视频信息
        $video = null;
        $file_id = '';
        $file_name = '';
        
        if (isset($message['video'])) {
            $video = $message['video'];
            $file_id = $video['file_id'];
            $file_name = $video['file_name'] ?? '';
        } elseif (isset($message['video_note'])) {
            $video = $message['video_note'];
            $file_id = $video['file_id'];
            $file_name = 'video_note_' . date('YmdHis');
        } elseif (isset($message['animation'])) {
            $video = $message['animation'];
            $file_id = $video['file_id'];
            $file_name = $video['file_name'] ?? 'animation_' . date('YmdHis');
        }
        
        if (empty($file_id)) {
            error_log("无法获取视频file_id");
            return false;
        }
        
        $message_id = $message['message_id'];
        $caption = $message['caption'] ?? '';
        
        error_log("准备采集视频 - file_id: $file_id, message_id: $message_id");
        
        // 先确保videos表有必要字段（在查询之前！）
        try {
            $stmt = $db->query("SHOW COLUMNS FROM videos LIKE 'source_chat_id'");
            if ($stmt->rowCount() == 0) {
                error_log("添加采集相关字段到 videos 表");
                $db->exec("ALTER TABLE videos ADD COLUMN source_chat_id BIGINT DEFAULT NULL");
                $db->exec("ALTER TABLE videos ADD COLUMN source_message_id BIGINT DEFAULT NULL");
                $db->exec("ALTER TABLE videos ADD COLUMN message_link VARCHAR(500) DEFAULT NULL");
            }
        } catch (Exception $e) {
            error_log("添加videos表字段错误: " . $e->getMessage());
        }
        
        // 检查是否已采集（根据message_id去重）
        $stmt = $db->prepare("SELECT id FROM videos WHERE source_chat_id = ? AND source_message_id = ?");
        $stmt->execute([$chat_id, $message_id]);
        if ($stmt->fetch()) {
            error_log("视频已采集，跳过");
            return false;
        }
        
        // 生成标题和关键词
        $title = $caption ? mb_substr(preg_replace('/[\n\r]+/', ' ', $caption), 0, 100) : ($file_name ?: '采集视频_' . date('YmdHis'));
        $keywords = $source['default_keywords'];
        if ($caption) {
            // 提取caption中的标签作为关键词
            preg_match_all('/#(\w+)/u', $caption, $matches);
            if (!empty($matches[1])) {
                $tags = implode(',', $matches[1]);
                $keywords = $keywords ? $keywords . ',' . $tags : $tags;
            }
        }
        
        // 生成消息链接
        $chatIdStr = (string)$chat_id;
        $messageLink = '';
        if (strpos($chatIdStr, '-100') === 0) {
            $publicChatId = substr($chatIdStr, 4); // 去掉-100前缀
            $messageLink = "https://t.me/c/{$publicChatId}/{$message_id}";
        }
        
        // 保存视频
        $stmt = $db->prepare("
            INSERT INTO videos 
            (category_id, title, caption, keywords, telegram_file_id, source_chat_id, source_message_id, message_link) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $source['category_id'],
            $title,
            $caption,
            $keywords,
            $file_id,
            $chat_id,
            $message_id,
            $messageLink
        ]);
        
        $videoId = $db->lastInsertId();
        error_log("视频采集成功，video_id: $videoId");
        
        // 更新采集源统计
        $stmt = $db->prepare("UPDATE video_collect_sources SET collected_count = collected_count + 1, last_collected_at = NOW() WHERE id = ?");
        $stmt->execute([$source['id']]);
        
        // 自动转发
        if ($source['auto_forward']) {
            forwardToTargets($bot, $db, $source, $chat_id, $message_id);
        }
        
        // 记录日志
        logSystem('info', '视频采集成功', [
            'video_id' => $videoId,
            'source_chat_id' => $chat_id,
            'message_id' => $message_id,
            'title' => $title
        ]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("视频采集错误: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * 处理广告二级菜单回调
 * callback_data格式: submenu_{ad_id}_{row_index}_{btn_index}
 * 或 backmenu_{ad_id}
 */
function handleAdSubMenuCallback($bot, $db, $callback_query) {
    $callback_id = $callback_query['id'];
    $callback_data = $callback_query['data'] ?? '';
    $message = $callback_query['message'] ?? null;
    
    if (!$message) {
        $bot->answerCallbackQuery($callback_id, '消息不存在', true);
        return;
    }
    
    $chat_id = $message['chat']['id'];
    $message_id = $message['message_id'];
    
    error_log("Processing ad submenu callback: " . $callback_data);
    
    try {
        if (strpos($callback_data, 'submenu_') === 0) {
            // 显示子菜单
            // 解析 callback_data: submenu_{ad_id}_{row_index}_{btn_index}
            $parts = explode('_', $callback_data);
            if (count($parts) < 4) {
                $bot->answerCallbackQuery($callback_id, '参数错误', true);
                return;
            }
            
            $ad_id = intval($parts[1]);
            $row_index = intval($parts[2]);
            $btn_index = intval($parts[3]);
            
            // 首先尝试从 auto_ads 表获取
            $stmt = $db->prepare("SELECT buttons FROM auto_ads WHERE id = ?");
            $stmt->execute([$ad_id]);
            $ad = $stmt->fetch();
            
            // 如果没找到，尝试从 auto_ad_template_items 表获取
            if (!$ad) {
                $stmt = $db->prepare("SELECT buttons FROM auto_ad_template_items WHERE id = ?");
                $stmt->execute([$ad_id]);
                $ad = $stmt->fetch();
            }
            
            if (!$ad || empty($ad['buttons'])) {
                $bot->answerCallbackQuery($callback_id, '广告不存在或无按钮配置', true);
                return;
            }
            
            $buttons = json_decode($ad['buttons'], true);
            if (!$buttons) {
                $bot->answerCallbackQuery($callback_id, '按钮配置错误', true);
                return;
            }
            
            // 获取子按钮
            $subButtons = null;
            $buttonText = '';
            
            // 检查是否为新格式
            $isNewFormat = isset($buttons[0]) && is_array($buttons[0]) && !isset($buttons[0]['text']);
            
            if ($isNewFormat && isset($buttons[$row_index][$btn_index])) {
                $buttonData = $buttons[$row_index][$btn_index];
                $buttonText = $buttonData['text'] ?? '';
                $subButtons = $buttonData['sub_buttons'] ?? null;
            }
            
            if (empty($subButtons)) {
                $bot->answerCallbackQuery($callback_id, '此按钮无子菜单', true);
                return;
            }
            
            // 构建子菜单keyboard
            $subKeyboard = [];
            foreach ($subButtons as $subBtn) {
                $text = trim($subBtn['text'] ?? '');
                $url = trim($subBtn['url'] ?? '');
                if (!empty($text) && !empty($url)) {
                    $subKeyboard[] = [
                        [
                            'text' => $text,
                            'url' => $url
                        ]
                    ];
                }
            }
            
            // 添加返回按钮
            $subKeyboard[] = [
                [
                    'text' => '🔙 返回',
                    'callback_data' => 'backmenu_' . $ad_id
                ]
            ];
            
            $replyMarkup = ['inline_keyboard' => $subKeyboard];
            
            // 编辑消息显示子菜单
            $bot->editMessageReplyMarkup($chat_id, $message_id, $replyMarkup);
            $bot->answerCallbackQuery($callback_id, '📂 ' . $buttonText);
            
        } elseif (strpos($callback_data, 'backmenu_') === 0) {
            // 返回主菜单
            $ad_id = intval(substr($callback_data, 9));
            
            // 首先尝试从 auto_ads 表获取
            $stmt = $db->prepare("SELECT buttons FROM auto_ads WHERE id = ?");
            $stmt->execute([$ad_id]);
            $ad = $stmt->fetch();
            
            // 如果没找到，尝试从 auto_ad_template_items 表获取
            if (!$ad) {
                $stmt = $db->prepare("SELECT buttons FROM auto_ad_template_items WHERE id = ?");
                $stmt->execute([$ad_id]);
                $ad = $stmt->fetch();
            }
            
            if (!$ad || empty($ad['buttons'])) {
                $bot->answerCallbackQuery($callback_id, '广告不存在', true);
                return;
            }
            
            // 重新构建主菜单keyboard
            $replyMarkup = buildAdInlineKeyboard($ad['buttons'], $ad_id);
            
            if ($replyMarkup) {
                $bot->editMessageReplyMarkup($chat_id, $message_id, $replyMarkup);
            }
            $bot->answerCallbackQuery($callback_id);
        }
        
    } catch (Exception $e) {
        error_log("Ad submenu callback error: " . $e->getMessage());
        $bot->answerCallbackQuery($callback_id, '处理错误', true);
    }
}

/**
 * 构建广告的inline keyboard（用于webhook）
 * 支持新格式（二维数组）和旧格式（一维数组）
 */
function buildAdInlineKeyboard($buttonsJson, $adId = null) {
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
