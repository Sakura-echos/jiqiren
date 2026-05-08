#!/usr/bin/env php
<?php
/**
 * 真人账号消息监听守护进程
 * 使用 MadelineProto 直接监听群组消息并根据关键词自动回复
 * 
 * 运行方式：
 * nohup php bot/user_monitor.php > logs/user_monitor.log 2>&1 &
 * 
 * 停止方式：
 * kill -9 $(cat logs/user_monitor.pid)
 */

// 设置错误报告
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 禁用输出缓冲
while (ob_get_level()) {
    ob_end_clean();
}
ob_implicit_flush(true);

// 引入配置
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Logger;
use danog\MadelineProto\EventHandler;

// 日志函数
function monitorLog($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    echo $logMessage;
    error_log($logMessage, 3, __DIR__ . '/../logs/user_monitor.log');
}

// 写入 PID 文件
$pidFile = __DIR__ . '/../logs/user_monitor.pid';
file_put_contents($pidFile, getmypid());
monitorLog("守护进程已启动，PID: " . getmypid());

// 注册关闭处理
register_shutdown_function(function() use ($pidFile) {
    if (file_exists($pidFile)) {
        unlink($pidFile);
    }
    monitorLog("守护进程已停止");
});

// 信号处理
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() use ($pidFile) {
        monitorLog("收到 SIGTERM 信号，正在停止...");
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
        exit(0);
    });
    
    pcntl_signal(SIGINT, function() use ($pidFile) {
        monitorLog("收到 SIGINT 信号，正在停止...");
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
        exit(0);
    });
}

/**
 * 事件处理器类
 */
class UserMonitorHandler extends EventHandler
{
    private $db;
    private $lastCheckTime = 0;
    
    public function __construct($API) {
        parent::__construct($API);
        $this->db = getDB();
        monitorLog("事件处理器已初始化");
    }
    
    /**
     * 处理新消息
     */
    public function onUpdateNewMessage($update): void
    {
        try {
            // 处理信号
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            
            $message = $update['message'] ?? null;
            if (!$message) {
                return;
            }
            
            // 只处理群组消息
            if (!isset($message['peer_id']['_']) || 
                !in_array($message['peer_id']['_'], ['peerChannel', 'peerChat'])) {
                return;
            }
            
            // 只处理文本消息
            if (!isset($message['message']) || empty($message['message'])) {
                return;
            }
            
            $text = $message['message'];
            $chatId = $message['peer_id']['channel_id'] ?? $message['peer_id']['chat_id'] ?? null;
            
            if (!$chatId) {
                return;
            }
            
            // 转换为标准格式（加上-100前缀）
            if (isset($message['peer_id']['channel_id'])) {
                $chatId = '-100' . $chatId;
            } else {
                $chatId = '-' . $chatId;
            }
            
            $fromId = $message['from_id']['user_id'] ?? 0;
            $messageId = $message['id'] ?? 0;
            
            monitorLog("收到群组消息: chat_id=$chatId, from_id=$fromId, text=" . substr($text, 0, 50));
            
            // 检查关键词
            $this->checkKeywords($chatId, $text, $fromId, $messageId, $message);
            
        } catch (Exception $e) {
            monitorLog("处理消息时出错: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * 检查并处理关键词
     */
    private function checkKeywords($chatId, $text, $fromId, $messageId, $message)
    {
        try {
            // 获取监控规则（只获取使用真人账号监听的规则）
            $stmt = $this->db->prepare("
                SELECT km.* 
                FROM keyword_monitor km 
                LEFT JOIN groups g ON km.group_id = g.id 
                WHERE km.is_active = 1 
                AND (km.monitor_mode = 'user' OR km.monitor_mode = 'both')
                AND (km.group_id IS NULL OR g.chat_id = ?)
            ");
            $stmt->execute([$chatId]);
            $monitors = $stmt->fetchAll();
            
            if (empty($monitors)) {
                return;
            }
            
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
                    monitorLog("关键词匹配: " . $monitor['keyword']);
                    
                    // 发送通知（如果配置了）
                    if (!empty($monitor['notify_user_id'])) {
                        $this->sendNotification($monitor, $chatId, $text, $fromId, $messageId, $message);
                    }
                    
                    // 自动回复（如果启用）
                    if ($monitor['auto_reply_enabled'] && !empty($monitor['auto_reply_message'])) {
                        $this->sendAutoReply($monitor, $chatId, $text, $fromId, $messageId, $message);
                    }
                    
                    // 只触发第一个匹配的规则
                    break;
                }
            }
            
        } catch (Exception $e) {
            monitorLog("检查关键词时出错: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * 发送通知
     */
    private function sendNotification($monitor, $chatId, $text, $fromId, $messageId, $message)
    {
        try {
            // 获取群组信息
            $chatInfo = $this->messages->getChats(['id' => [str_replace('-100', '', $chatId)]]);
            $groupTitle = $chatInfo['chats'][0]['title'] ?? 'Unknown Group';
            
            // 获取用户信息
            $userInfo = $message['from_id'] ?? [];
            $userName = '';
            if (isset($userInfo['user_id'])) {
                $users = $this->users->getUsers(['id' => [$userInfo['user_id']]]);
                $user = $users[0] ?? [];
                $userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            }
            
            $username = $user['username'] ?? '';
            
            // 构建通知消息
            $notifyMessage = "🔍 <b>关键词监控提醒</b>\n\n";
            $notifyMessage .= "📌 <b>关键词：</b><code>" . htmlspecialchars($monitor['keyword']) . "</code>\n";
            $notifyMessage .= "👥 <b>群组：</b>" . htmlspecialchars($groupTitle) . "\n";
            $notifyMessage .= "👤 <b>用户：</b>" . htmlspecialchars($userName);
            if ($username) {
                $notifyMessage .= " (@" . htmlspecialchars($username) . ")";
            }
            $notifyMessage .= "\n";
            $notifyMessage .= "🆔 <b>User ID：</b><code>" . $fromId . "</code>\n";
            $notifyMessage .= "💬 <b>消息内容：</b>\n" . htmlspecialchars($text) . "\n";
            $notifyMessage .= "\n🔗 <b>消息链接：</b> ";
            
            // 构建消息链接
            if (isset($chatInfo['chats'][0]['username'])) {
                $notifyMessage .= "https://t.me/" . $chatInfo['chats'][0]['username'] . "/" . $messageId;
            } else {
                $chatIdStr = str_replace('-100', '', $chatId);
                $notifyMessage .= "https://t.me/c/" . $chatIdStr . "/" . $messageId;
            }
            
            // 发送通知
            $this->messages->sendMessage([
                'peer' => $monitor['notify_user_id'],
                'message' => $notifyMessage,
                'parse_mode' => 'HTML'
            ]);
            
            monitorLog("通知已发送到用户: " . $monitor['notify_user_id']);
            
        } catch (Exception $e) {
            monitorLog("发送通知时出错: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * 发送自动回复
     */
    private function sendAutoReply($monitor, $chatId, $text, $fromId, $messageId, $message)
    {
        try {
            // 延迟回复
            if (!empty($monitor['reply_delay'])) {
                monitorLog("延迟 {$monitor['reply_delay']} 秒后回复...");
                sleep($monitor['reply_delay']);
            }
            
            // 获取用户信息用于变量替换
            $userName = '';
            $username = '';
            if (isset($message['from_id']['user_id'])) {
                $users = $this->users->getUsers(['id' => [$message['from_id']['user_id']]]);
                $user = $users[0] ?? [];
                $userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                $username = '@' . ($user['username'] ?? $userName);
            }
            
            // 替换变量
            $replyMessage = $monitor['auto_reply_message'];
            $replyMessage = str_replace('{name}', $userName, $replyMessage);
            $replyMessage = str_replace('{username}', $username, $replyMessage);
            
            // 发送回复
            $sentMessage = $this->messages->sendMessage([
                'peer' => $chatId,
                'message' => $replyMessage,
                'reply_to_msg_id' => $messageId
            ]);
            
            monitorLog("自动回复已发送: " . substr($replyMessage, 0, 50));
            
        } catch (Exception $e) {
            monitorLog("发送自动回复时出错: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * 定期任务（每分钟执行一次）
     */
    public function onLoop(): \Generator
    {
        $currentTime = time();
        
        // 每60秒执行一次
        if ($currentTime - $this->lastCheckTime >= 60) {
            $this->lastCheckTime = $currentTime;
            monitorLog("心跳检测 - 守护进程运行正常");
            
            // 更新状态到数据库
            try {
                $stmt = $this->db->prepare("
                    INSERT INTO user_monitor_status (id, status, last_heartbeat, updated_at) 
                    VALUES (1, 'running', NOW(), NOW()) 
                    ON DUPLICATE KEY UPDATE status = 'running', last_heartbeat = NOW(), updated_at = NOW()
                ");
                $stmt->execute();
            } catch (Exception $e) {
                // 表可能不存在，忽略
            }
        }
        
        return 0;
    }
}

// 主程序
try {
    monitorLog("正在初始化...");
    
    // 检查用户账号是否已登录
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM user_account_config WHERE id = 1 AND is_logged_in = 1");
    $stmt->execute();
    $config = $stmt->fetch();
    
    if (!$config || empty($config['session_data'])) {
        monitorLog("用户账号未登录，请先在管理后台登录真人账号", 'ERROR');
        exit(1);
    }
    
    $sessionFile = $config['session_data'];
    
    if (!file_exists($sessionFile)) {
        monitorLog("Session 文件不存在: $sessionFile", 'ERROR');
        exit(1);
    }
    
    monitorLog("正在加载 MadelineProto session: $sessionFile");
    
    // 创建设置
    $settings = new Settings;
    $settings->getLogger()->setType(Logger::FILE_LOGGER);
    $settings->getLogger()->setExtra(__DIR__ . '/../logs/user_monitor_madeline.log');
    $settings->getLogger()->setLevel(Logger::ERROR);
    
    // 配置 API 凭证
    $apiId = defined('TELEGRAM_API_ID') ? TELEGRAM_API_ID : 38356810;
    $apiHash = defined('TELEGRAM_API_HASH') ? TELEGRAM_API_HASH : 'd9d6bd0d866623c86d0994cafef50147';
    $settings->getAppInfo()->setApiId($apiId);
    $settings->getAppInfo()->setApiHash($apiHash);
    
    // 创建 MadelineProto 实例并启动事件处理器
    monitorLog("正在创建 MadelineProto 实例和事件处理器...");
    monitorLog("✓ 真人账号消息监听守护进程已启动，按 Ctrl+C 停止");
    
    // 立即更新数据库状态，让前端显示"运行中"
    try {
        $currentPid = getmypid();
        $stmt = $db->prepare("INSERT INTO user_monitor_status (id, status, pid, start_time, last_heartbeat, updated_at) VALUES (1, 'running', ?, NOW(), NOW(), NOW()) ON DUPLICATE KEY UPDATE status = 'running', pid = ?, start_time = NOW(), last_heartbeat = NOW(), updated_at = NOW()");
        $stmt->execute([$currentPid, $currentPid]);
        monitorLog("已更新数据库状态");
    } catch (Exception $e) {
        // 表可能不存在，忽略
        monitorLog("更新数据库状态失败（忽略）: " . $e->getMessage());
    }
    
    // MadelineProto 8.x: 使用 EventHandler::startAndLoop() 启动
    UserMonitorHandler::startAndLoop($sessionFile, $settings);
    
} catch (Throwable $e) {
    monitorLog("致命错误: " . $e->getMessage(), 'ERROR');
    monitorLog("错误文件: " . $e->getFile() . ":" . $e->getLine(), 'ERROR');
    monitorLog("堆栈跟踪: " . $e->getTraceAsString(), 'ERROR');
    
    // 清理PID文件
    if (file_exists($pidFile)) {
        unlink($pidFile);
    }
    
    exit(1);
}

