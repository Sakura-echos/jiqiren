<?php
/**
 * 真人账号管理 API
 * 使用Telegram MTProto API登录用户账号
 */

// 设置严格的错误处理
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0);

// 禁用所有可能的输出
ini_set('implicit_flush', '0');

// 增加超时时间（MadelineProto 8.x 初始化需要更长时间）
set_time_limit(600); // 10分钟
ini_set('max_execution_time', '600');
ini_set('memory_limit', '512M'); // 增加内存限制

// 开始输出缓冲，防止任何意外输出
ob_start();

// 独立的调试日志
$debug_log_file = __DIR__ . '/../logs/user_account_debug.log';
function userAccountLog($message) {
    global $debug_log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($debug_log_file, "[$timestamp] $message\n", FILE_APPEND);
}

// 捕获所有错误到日志
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    userAccountLog("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true;
});

require_once '../config.php';
require_once '../vendor/autoload.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Logger;

try {
    userAccountLog("=== API 请求开始 ===");
    userAccountLog("请求方法: " . $_SERVER['REQUEST_METHOD']);
    userAccountLog("请求URI: " . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
    
    checkLogin();
    userAccountLog("✓ 登录检查通过");

    $db = getDB();
    userAccountLog("✓ 数据库连接成功");

    // Session 文件目录
    $session_dir = __DIR__ . '/../sessions/';
    if (!is_dir($session_dir)) {
        mkdir($session_dir, 0700, true);
        userAccountLog("✓ 创建 sessions 目录");
    }
    userAccountLog("Session 目录: " . $session_dir);

    // 清空输出缓冲
    ob_clean();

    // POST 请求
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $raw_input = file_get_contents('php://input');
        userAccountLog("接收到 POST 数据长度: " . strlen($raw_input));
        
        $data = json_decode($raw_input, true);
        userAccountLog("JSON 解析结果: " . ($data ? '成功' : '失败'));
        
        $action = $data['action'] ?? '';
        userAccountLog("请求 Action: " . $action);
    
    switch ($action) {
        case 'send_code':
            userAccountLog("--- 开始处理 send_code ---");
            userAccountLog("PHP_BINARY: " . PHP_BINARY . ", PHP_BINDIR: " . PHP_BINDIR . ", PATH: " . getenv('PATH'));
userAccountLog("open_basedir: " . ini_get('open_basedir'));
userAccountLog("php exists: " . (file_exists('/www/server/php/82/bin/php') ? 'yes' : 'no'));
userAccountLog("php executable: " . (is_executable('/www/server/php/82/bin/php') ? 'yes' : 'no'));
            $phone_number = $data['phone_number'] ?? '';
            userAccountLog("手机号: " . $phone_number);
            
            if (empty($phone_number)) {
                userAccountLog("❌ 手机号为空");
                jsonResponse(['success' => false, 'message' => '请输入手机号'], 400);
            }
            
            try {
                // Session 文件路径
                $session_file = $session_dir . 'user_' . md5($phone_number) . '.madeline';
                userAccountLog("Session 文件: " . $session_file);
                
                // 创建 MadelineProto 设置（禁用所有输出）
                userAccountLog("开始创建 MadelineProto Settings");
                $settings = new Settings;
                userAccountLog("✓ Settings 创建成功");
                
                // 配置 API 凭证（统一从 config.php 读取）
                $api_id = TELEGRAM_API_ID;
                $api_hash = TELEGRAM_API_HASH;
                userAccountLog("API ID: " . $api_id);
                userAccountLog("API Hash: " . substr($api_hash, 0, 10) . "...");
                
                $settings->getAppInfo()->setApiId($api_id);
                $settings->getAppInfo()->setApiHash($api_hash);
                userAccountLog("✓ API 凭证配置完成");
                
                // 配置日志
                $settings->getLogger()->setType(Logger::FILE_LOGGER);
                $settings->getLogger()->setExtra($session_dir . 'madeline.log');
                $settings->getLogger()->setLevel(Logger::ERROR);
                userAccountLog("✓ 日志配置完成");
                
                // MadelineProto 8.x 不再需要手动配置 IPC
                // 如果遇到 open_basedir 问题，会自动使用 JSON 模式
                userAccountLog("使用 MadelineProto 8.x 默认配置");
                
                // 清空所有输出
                while (ob_get_level()) {
                    ob_end_clean();
                }
                ob_start();
                
                // 创建 MadelineProto 实例
                userAccountLog("开始创建 MadelineProto API 实例（这可能需要1-2分钟）...");
                $start_time = microtime(true);
                $MadelineProto = new API($session_file, $settings);
                $elapsed = round(microtime(true) - $start_time, 2);
                userAccountLog("✓ MadelineProto API 实例创建成功（耗时: {$elapsed}秒）");
                
                // 清空所有输出
                while (ob_get_level() > 1) {
                    ob_end_clean();
                }
                
                // 发送验证码
                userAccountLog("开始调用 phoneLogin");
                $login_start = microtime(true);
                $MadelineProto->phoneLogin($phone_number);
                $login_elapsed = round(microtime(true) - $login_start, 2);
                userAccountLog("✓ phoneLogin 调用成功（耗时: {$login_elapsed}秒）");
                
                // 清空所有输出
                while (ob_get_level() > 1) {
                    ob_end_clean();
                }
                
                // 保存手机号和 session 路径到数据库
                userAccountLog("保存到数据库");
                $stmt = $db->prepare("INSERT INTO user_account_config (id, phone_number, session_data, updated_at) VALUES (1, ?, ?, NOW()) ON DUPLICATE KEY UPDATE phone_number = ?, session_data = ?, updated_at = NOW()");
                $stmt->execute([$phone_number, $session_file, $phone_number, $session_file]);
                userAccountLog("✓ 数据库保存成功");
                
                logSystem('info', 'Verification code sent', ['phone' => $phone_number]);
                
                // 确保返回纯 JSON
                while (ob_get_level() > 1) {
                    ob_end_clean();
                }
                ob_clean();
                
                userAccountLog("=== 返回成功响应 ===");
                jsonResponse([
                    'success' => true, 
                    'message' => '验证码已发送到您的Telegram，请查收'
                ]);
            } catch (Exception $e) {
                while (ob_get_level() > 1) {
                    ob_end_clean();
                }
                ob_clean();
                
                $error_msg = $e->getMessage();
                userAccountLog("❌ send_code 发生错误: " . $error_msg);
                userAccountLog("错误堆栈:\n" . $e->getTraceAsString());
                
                logSystem('error', 'Send code error', $error_msg);
                jsonResponse(['success' => false, 'message' => '发送失败: ' . $error_msg], 500);
            }
            break;
            
        case 'verify_code':
            $phone_number = $data['phone_number'] ?? '';
            $code = $data['code'] ?? '';
            $password = $data['password'] ?? null;
            
            if (empty($phone_number) || empty($code)) {
                jsonResponse(['success' => false, 'message' => '请填写所有必填字段'], 400);
            }
            
            try {
                // Session 文件路径
                $session_file = $session_dir . 'user_' . md5($phone_number) . '.madeline';
                
                if (!file_exists($session_file)) {
                    ob_clean();
                    jsonResponse(['success' => false, 'message' => '请先发送验证码'], 400);
                }
                
                // 创建 MadelineProto 设置
                $settings = new Settings;
                
                // 配置 API 凭证（统一从 config.php 读取）
                $api_id = TELEGRAM_API_ID;
                $api_hash = TELEGRAM_API_HASH;
                
                $settings->getAppInfo()->setApiId($api_id);
                $settings->getAppInfo()->setApiHash($api_hash);
                
                // 配置日志
                $settings->getLogger()->setType(Logger::FILE_LOGGER);
                $settings->getLogger()->setExtra($session_dir . 'madeline.log');
                $settings->getLogger()->setLevel(Logger::ERROR);
                
                // 清空所有输出
                while (ob_get_level() > 1) {
                    ob_end_clean();
                }
                
                // 创建 MadelineProto 实例
                $MadelineProto = new API($session_file, $settings);
                
                // 清空所有输出
                while (ob_get_level() > 1) {
                    ob_end_clean();
                }
                
                // 完成手机号登录
                $authorization = $MadelineProto->completePhoneLogin($code);
                
                // 清空所有输出
                while (ob_get_level() > 1) {
                    ob_end_clean();
                }
                
                // 检查是否需要两步验证密码
                if ($authorization['_'] === 'account.password') {
                    if (empty($password)) {
                        ob_clean();
                        jsonResponse(['success' => false, 'message' => '需要两步验证密码，请输入密码后重试'], 400);
                    }
                    // 使用两步验证密码完成登录
                    $authorization = $MadelineProto->complete2faLogin($password);
                    ob_clean();
                }
                
                // 登录成功，更新数据库
                $stmt = $db->prepare("UPDATE user_account_config SET is_logged_in = 1, logged_in_at = NOW() WHERE id = 1");
                $stmt->execute();
                
                // 获取账号信息
                $self = $MadelineProto->getSelf();
                $username = $self['username'] ?? 'N/A';
                $name = $self['first_name'] ?? 'User';
                
                logSystem('info', 'User account logged in', [
                    'phone' => $phone_number,
                    'username' => $username,
                    'name' => $name
                ]);
                
                // 确保返回纯 JSON
                while (ob_get_level() > 1) {
                    ob_end_clean();
                }
                ob_clean();
                
                jsonResponse([
                    'success' => true, 
                    'message' => '登录成功！',
                    'account' => [
                        'name' => $name,
                        'username' => $username,
                        'phone' => $phone_number
                    ]
                ]);
            } catch (Exception $e) {
                while (ob_get_level() > 1) {
                    ob_end_clean();
                }
                ob_clean();
                logSystem('error', 'Verify code error', $e->getMessage());
                
                // 根据错误类型提供友好提示
                $error_message = $e->getMessage();
                if (strpos($error_message, 'PHONE_CODE_INVALID') !== false) {
                    $error_message = '验证码错误，请重新输入';
                } elseif (strpos($error_message, 'PHONE_CODE_EXPIRED') !== false) {
                    $error_message = '验证码已过期，请重新发送';
                } elseif (strpos($error_message, 'PASSWORD_HASH_INVALID') !== false) {
                    $error_message = '两步验证密码错误';
                }
                
                jsonResponse(['success' => false, 'message' => $error_message], 500);
            }
            break;
            
        case 'logout':
            try {
                ob_clean();
                $stmt = $db->prepare("UPDATE user_account_config SET is_logged_in = 0 WHERE id = 1");
                $stmt->execute();
                
                logSystem('info', 'User account logged out');
                jsonResponse(['success' => true, 'message' => '退出成功']);
            } catch (Exception $e) {
                ob_clean();
                logSystem('error', 'Logout error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '退出失败'], 500);
            }
            break;
            
        default:
            // 清空输出缓冲
            ob_clean();
            jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
    }
    }
} catch (Throwable $e) {
    // 捕获所有错误
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    // 记录错误到日志
    error_log('User account API error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // 返回友好的错误信息
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => '系统错误: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// 结束输出缓冲
if (ob_get_level()) {
    ob_end_flush();
}
