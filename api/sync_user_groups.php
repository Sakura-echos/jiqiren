<?php
/**
 * 同步真人账号的群组列表 API
 */

require_once '../config.php';
checkLogin();

// 加载 MadelineProto
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Logger;

$db = getDB();

// 自定义日志函数
function syncLog($message) {
    error_log("[Sync User Groups] " . $message);
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => '无效的请求方法'], 400);
}

try {
    syncLog("开始同步真人账号群组列表");
    
    // 获取用户账号配置
    $stmt = $db->prepare("SELECT * FROM user_account_config WHERE id = 1 AND is_logged_in = 1");
    $stmt->execute();
    $config = $stmt->fetch();
    
    if (!$config || empty($config['session_data'])) {
        syncLog("真人账号未登录");
        jsonResponse(['success' => false, 'message' => '真人账号未登录，请先登录'], 400);
    }
    
    $session_file = $config['session_data'];
    
    if (!file_exists($session_file)) {
        syncLog("Session文件不存在: $session_file");
        jsonResponse(['success' => false, 'message' => 'Session文件不存在'], 500);
    }
    
    syncLog("加载 MadelineProto");
    
    // 创建设置
    $settings = new Settings;
    $settings->getLogger()->setType(Logger::FILE_LOGGER);
    $settings->getLogger()->setExtra(dirname($session_file) . '/madeline.log');
    $settings->getLogger()->setLevel(Logger::ERROR);
    
    // 配置 API 凭证
    $api_id = defined('TELEGRAM_API_ID') ? TELEGRAM_API_ID : 38356810;
    $api_hash = defined('TELEGRAM_API_HASH') ? TELEGRAM_API_HASH : 'd9d6bd0d866623c86d0994cafef50147';
    $settings->getAppInfo()->setApiId($api_id);
    $settings->getAppInfo()->setApiHash($api_hash);
    
    // 加载 MadelineProto 实例
    $MadelineProto = new API($session_file, $settings);
    
    syncLog("获取对话列表");
    
    // 获取所有对话
    $dialogs = $MadelineProto->messages->getDialogs([
        'limit' => 100 // 获取最近100个对话
    ]);
    
    syncLog("收到 " . count($dialogs['dialogs']) . " 个对话");
    
    $syncedCount = 0;
    $skippedCount = 0;
    $updatedCount = 0;
    
    // 遍历所有对话
    foreach ($dialogs['dialogs'] as $dialog) {
        $peer = $dialog['peer'];
        
        // 只处理频道和超级群组
        if (isset($peer['channel_id'])) {
            $channel_id = $peer['channel_id'];
            
            // 从 chats 中获取群组信息
            $chat = null;
            foreach ($dialogs['chats'] as $c) {
                if (isset($c['id']) && $c['id'] == $channel_id) {
                    $chat = $c;
                    break;
                }
            }
            
            if (!$chat) {
                syncLog("找不到频道 $channel_id 的详细信息");
                continue;
            }
            
            // 跳过普通频道（只要超级群组）
            if (!isset($chat['megagroup']) || !$chat['megagroup']) {
                syncLog("跳过普通频道: " . ($chat['title'] ?? 'Unknown'));
                $skippedCount++;
                continue;
            }
            
            // 构建 chat_id（超级群组的格式是 -100 + channel_id）
            $chat_id = -1000000000000 - $channel_id;
            $title = $chat['title'] ?? 'Unknown Group';
            
            syncLog("发现超级群组: $title (chat_id: $chat_id)");
            
            // 检查群组是否已存在
            $stmt = $db->prepare("SELECT id, source FROM groups WHERE chat_id = ?");
            $stmt->execute([$chat_id]);
            $existingGroup = $stmt->fetch();
            
            if ($existingGroup) {
                // 群组已存在，更新来源标识
                $currentSource = $existingGroup['source'];
                $newSource = $currentSource;
                
                if ($currentSource === 'bot') {
                    $newSource = 'both'; // 机器人和真人账号都在
                } elseif ($currentSource === 'user_account') {
                    $newSource = 'user_account'; // 保持不变
                } elseif ($currentSource === 'both') {
                    $newSource = 'both'; // 保持不变
                }
                
                // 更新群组信息
                $updateStmt = $db->prepare("UPDATE groups SET title = ?, source = ?, is_active = 1, is_deleted = 0 WHERE chat_id = ?");
                $updateStmt->execute([$title, $newSource, $chat_id]);
                
                syncLog("更新群组: $title (source: $currentSource -> $newSource)");
                $updatedCount++;
            } else {
                // 新群组，插入数据库
                $insertStmt = $db->prepare("INSERT INTO groups (chat_id, title, source, is_active, is_deleted) VALUES (?, ?, 'user_account', 1, 0)");
                $insertStmt->execute([$chat_id, $title]);
                
                syncLog("新增群组: $title (source: user_account)");
                $syncedCount++;
            }
        }
    }
    
    syncLog("同步完成！新增: $syncedCount, 更新: $updatedCount, 跳过: $skippedCount");
    
    jsonResponse([
        'success' => true, 
        'message' => "同步完成！新增 $syncedCount 个群组，更新 $updatedCount 个群组",
        'data' => [
            'synced' => $syncedCount,
            'updated' => $updatedCount,
            'skipped' => $skippedCount
        ]
    ]);
    
} catch (Exception $e) {
    syncLog("同步失败: " . $e->getMessage());
    syncLog("Stack trace: " . $e->getTraceAsString());
    jsonResponse(['success' => false, 'message' => '同步失败: ' . $e->getMessage()], 500);
}

