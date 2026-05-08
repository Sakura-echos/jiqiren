<?php
/**
 * 机器人底部菜单按钮API
 */

// 禁用错误显示，只返回JSON
error_reporting(0);
ini_set('display_errors', 0);

session_start();
require_once '../config.php';

// jsonResponse 函数由 config.php 提供，不需要重复定义

// 检查登录
if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => '未登录']);
}

try {
    $db = getDB();
    
    // 检查表是否存在
    $stmt = $db->query("SHOW TABLES LIKE 'bot_menu_settings'");
    if (!$stmt->fetch()) {
        jsonResponse([
            'success' => false, 
            'message' => '数据库表未创建，请先导入 database_bot_menu.sql 文件'
        ]);
    }
} catch (Exception $e) {
    jsonResponse([
        'success' => false, 
        'message' => '数据库连接失败：' . $e->getMessage()
    ]);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method == 'POST') {
        handlePost($db);
    } elseif ($method == 'GET') {
        handleGet($db);
    } else {
        jsonResponse(['success' => false, 'message' => '不支持的请求方法']);
    }
} catch (Exception $e) {
    error_log("Bot menu API error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => $e->getMessage()]);
}

// 保存或测试菜单配置
function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input)) {
        jsonResponse(['success' => false, 'message' => '无效的数据']);
    }
    
    $enabled = $input['enabled'] ?? 1;
    $button_text = trim($input['button_text'] ?? 'Menu');
    $button_icon = trim($input['button_icon'] ?? '☰');
    $command = $input['command'] ?? '/start';
    $is_test = $input['test'] ?? false;
    
    // 保存到数据库
    $db->beginTransaction();
    
    try {
        // 检查是否已有配置
        $stmt = $db->query("SELECT id FROM bot_menu_settings LIMIT 1");
        $existing = $stmt->fetch();
        
        if ($existing) {
            // 更新
            $stmt = $db->prepare("
                UPDATE bot_menu_settings 
                SET enabled = ?, button_text = ?, button_icon = ?, command = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$enabled, $button_text, $button_icon, $command, $existing['id']]);
        } else {
            // 插入
            $stmt = $db->prepare("
                INSERT INTO bot_menu_settings (enabled, button_text, button_icon, command)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$enabled, $button_text, $button_icon, $command]);
        }
        
        $db->commit();
        
        // 应用到Telegram Bot
        if ($enabled) {
            $result = applyMenuButton($button_text, $command);
            
            if (!$result['success']) {
                // 即使Telegram API失败，数据库配置也已保存
                jsonResponse([
                    'success' => true, 
                    'message' => '配置已保存，但应用到Telegram失败：' . $result['message'],
                    'warning' => true
                ]);
            }
        } else {
            // 禁用时，移除菜单按钮
            removeMenuButton();
        }
        
        logSystem('info', '更新底部菜单按钮', [
            'enabled' => $enabled,
            'text' => $button_text,
            'command' => $command
        ]);
        
        jsonResponse(['success' => true, 'message' => '保存成功']);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

// 获取当前配置
function handleGet($db) {
    $stmt = $db->query("SELECT * FROM bot_menu_settings LIMIT 1");
    $config = $stmt->fetch();
    
    jsonResponse(['success' => true, 'data' => $config]);
}

// 应用菜单按钮到Telegram
function applyMenuButton($text, $command) {
    require_once '../config.php';
    
    $bot_token = BOT_TOKEN;
    
    if (empty($bot_token)) {
        return ['success' => false, 'message' => 'BOT_TOKEN未配置'];
    }
    
    // 使用Telegram Bot API设置菜单按钮
    $url = "https://api.telegram.org/bot{$bot_token}/setChatMenuButton";
    
    $params = [
        'menu_button' => [
            'type' => 'commands',
            'text' => $text
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($http_code == 200 && $result['ok']) {
        return ['success' => true];
    } else {
        $error_msg = $result['description'] ?? 'Unknown error';
        error_log("Set menu button failed: " . $error_msg);
        return ['success' => false, 'message' => $error_msg];
    }
}

// 移除菜单按钮
function removeMenuButton() {
    require_once '../config.php';
    
    $bot_token = BOT_TOKEN;
    
    if (empty($bot_token)) {
        return false;
    }
    
    $url = "https://api.telegram.org/bot{$bot_token}/setChatMenuButton";
    
    $params = [
        'menu_button' => [
            'type' => 'default'
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return true;
}
?>

