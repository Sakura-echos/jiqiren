<?php
/**
 * Telegram Bot 管理后台 - 
 */

// 调试：标记 config.php 开始加载
error_log("[CONFIG] config.php loading started");

// 数据库配置
define('DB_HOST', 'localhost');
define('DB_NAME', 'jiqiren');
define('DB_USER', 'jiqiren');
define('DB_PASS', 'jiqiren');
define('DB_CHARSET', 'utf8mb4');

// 默认配置（如果数据库中没有设置则使用这些值）
define('DEFAULT_BOT_TOKEN', '8691081603:AAFkxfux7t_vNk49y_hQw63ZJZbbd2wrVno');
define('DEFAULT_BOT_USERNAME', 'mili9813_bot');
define('DEFAULT_SITE_URL', 'https://jiqiren2.cryptoxthefuture.cc');

// Telegram User API 配置（用于真人账号登录）
// 已配置您的 API 凭证
define('TELEGRAM_API_ID', 38356810);
define('TELEGRAM_API_HASH', 'd9d6bd0d866623c86d0994cafef50147');

// Session 配置
define('SESSION_LIFETIME', 86400); // 24小时

// 时区设置
date_default_timezone_set('Asia/Shanghai');

// 错误报告 (生产环境请设置为 0)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 数据库连接类
class Database {
    private static $instance = null;
    private $conn;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->conn = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch(PDOException $e) {
            die("数据库连接失败: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }
}

// 辅助函数
function getDB() {
    return Database::getInstance()->getConnection();
}

/**
 * 从数据库获取系统配置
 * @param string $key 配置键名
 * @param mixed $default 默认值
 * @return mixed
 */
function getSystemSetting($key, $default = null) {
    static $settings_cache = null;
    static $load_attempted = false;
    
    // 首次调用时尝试加载所有设置到缓存
    if ($settings_cache === null && !$load_attempted) {
        $load_attempted = true;
        $settings_cache = [];
        try {
            // 直接创建数据库连接，避免使用可能 die 的 getDB()
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            
            // 先检查表是否存在
            $check = $pdo->query("SHOW TABLES LIKE 'system_settings'");
            if ($check->rowCount() > 0) {
                $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings_cache[$row['setting_key']] = $row['setting_value'];
                }
            }
        } catch (PDOException $e) {
            error_log("[CONFIG] PDO Error loading system settings: " . $e->getMessage());
            $settings_cache = [];
        } catch (Exception $e) {
            error_log("[CONFIG] Failed to load system settings: " . $e->getMessage());
            $settings_cache = [];
        } catch (Error $e) {
            error_log("[CONFIG] Error loading system settings: " . $e->getMessage());
            $settings_cache = [];
        }
    }
    
    // 确保 settings_cache 是数组
    if (!is_array($settings_cache)) {
        $settings_cache = [];
    }
    
    return isset($settings_cache[$key]) && $settings_cache[$key] !== '' ? $settings_cache[$key] : $default;
}

/**
 * 清除设置缓存（用于设置更新后）
 */
function clearSettingsCache() {
    // 通过重新获取来刷新缓存（在下次调用时）
    // 这里我们通过设置一个标志来实现
    global $_settings_cache_cleared;
    $_settings_cache_cleared = true;
}

// 动态加载机器人配置（优先从数据库读取）
define('BOT_TOKEN', getSystemSetting('bot_token', DEFAULT_BOT_TOKEN));
define('BOT_USERNAME', getSystemSetting('bot_username', DEFAULT_BOT_USERNAME));
define('SITE_URL', getSystemSetting('site_url', DEFAULT_SITE_URL));
define('WEBHOOK_URL', SITE_URL . '/bot/webhook.php');

// 检查登录状态
function checkLogin() {
    session_start();
    if (!isset($_SESSION['admin_id'])) {
        header('Location: index.php');
        exit;
    }
}

// 输出JSON响应
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// 日志配置
define('LOG_PATH', __DIR__ . '/logs');
define('ERROR_LOG', LOG_PATH . '/error.log');
define('DEBUG_LOG', LOG_PATH . '/debug.log');
define('ACCESS_LOG', LOG_PATH . '/access.log');

// 确保日志目录存在
try {
    if (!file_exists(LOG_PATH)) {
        @mkdir(LOG_PATH, 0777, true);
    }
} catch (Exception $e) {
    // 忽略错误，不阻塞加载
    error_log("Warning: Failed to create log directory: " . $e->getMessage());
}

// 设置错误日志
ini_set('log_errors', 1);
ini_set('error_log', ERROR_LOG);

// 记录系统日志
function logSystem($level, $message, $context = null) {
    try {
        // 记录到数据库
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO system_logs (level, message, context) VALUES (?, ?, ?)");
        $stmt->execute([$level, $message, json_encode($context, JSON_UNESCAPED_UNICODE)]);
        
        // 同时记录到文件
        $log_message = date('Y-m-d H:i:s') . " [$level] $message";
        if ($context) {
            $log_message .= " Context: " . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        // 根据级别写入不同的日志文件
        switch ($level) {
            case 'error':
                error_log($log_message . "\n", 3, ERROR_LOG);
                break;
            case 'info':
                error_log($log_message . "\n", 3, DEBUG_LOG);
                break;
            default:
                error_log($log_message . "\n", 3, ACCESS_LOG);
        }
    } catch(Exception $e) {
        error_log("Log system error: " . $e->getMessage());
    }
}

// XSS 防护
function escape($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// 调试：标记 config.php 加载完成
error_log("[CONFIG] config.php loading completed successfully");

