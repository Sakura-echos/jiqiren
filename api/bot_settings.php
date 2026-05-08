<?php
/**
 * Bot设置API - 用于设置机器人名称、描述等
 */

require_once '../config.php';
require_once '../bot/TelegramBot.php';
checkLogin();

$db = getDB();
$bot = new TelegramBot(BOT_TOKEN);

// GET 请求
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_bot_info':
            // 获取机器人信息
            try {
                // 获取基本信息
                $botInfo = $bot->getMe();
                
                if (!$botInfo) {
                    jsonResponse(['success' => false, 'message' => '无法获取Bot信息，请检查Bot Token是否正确']);
                }
                
                // 尝试获取名称（这个API可能不被所有版本支持）
                $nameResult = $bot->callApi('getMyName');
                if ($nameResult !== false) {
                    if (is_array($nameResult) && isset($nameResult['name'])) {
                        $botInfo['name'] = $nameResult['name'];
                    }
                }
                
                // 尝试获取描述
                $descResult = $bot->callApi('getMyDescription');
                if ($descResult !== false) {
                    if (is_array($descResult) && isset($descResult['description'])) {
                        $botInfo['description'] = $descResult['description'];
                    }
                }
                
                // 尝试获取简短描述
                $shortDescResult = $bot->callApi('getMyShortDescription');
                if ($shortDescResult !== false) {
                    if (is_array($shortDescResult) && isset($shortDescResult['short_description'])) {
                        $botInfo['short_description'] = $shortDescResult['short_description'];
                    }
                }
                
                // 确保这些字段存在（即使为空）
                if (!isset($botInfo['name'])) {
                    $botInfo['name'] = $botInfo['first_name'] ?? '';
                }
                if (!isset($botInfo['description'])) {
                    $botInfo['description'] = '';
                }
                if (!isset($botInfo['short_description'])) {
                    $botInfo['short_description'] = '';
                }
                
                jsonResponse(['success' => true, 'data' => $botInfo]);
            } catch (Exception $e) {
                error_log('Bot settings API error: ' . $e->getMessage());
                jsonResponse(['success' => false, 'message' => '获取失败: ' . $e->getMessage()]);
            }
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
    }
}

// POST 请求
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'set_bot_name':
            // 设置机器人名称
            $name = $_POST['name'] ?? '';
            $language_code = $_POST['language_code'] ?? '';
            
            if (empty($name)) {
                jsonResponse(['success' => false, 'message' => '请输入名称']);
            }
            
            try {
                $params = ['name' => $name];
                if ($language_code) {
                    $params['language_code'] = $language_code;
                }
                $result = $bot->callApi('setMyName', $params);
                
                if ($result) {
                    logSystem('info', '设置机器人名称', [
                        'name' => $name,
                        'language_code' => $language_code,
                        'admin_id' => $_SESSION['admin_id'] ?? null
                    ]);
                    
                    jsonResponse(['success' => true, 'message' => '名称设置成功']);
                } else {
                    jsonResponse(['success' => false, 'message' => '名称设置失败']);
                }
            } catch(Exception $e) {
                jsonResponse(['success' => false, 'message' => '设置失败: ' . $e->getMessage()]);
            }
            break;
            
        case 'set_bot_description':
            // 设置机器人描述
            $description = $_POST['description'] ?? '';
            $language_code = $_POST['language_code'] ?? '';
            
            try {
                $params = ['description' => $description];
                if ($language_code) {
                    $params['language_code'] = $language_code;
                }
                $result = $bot->callApi('setMyDescription', $params);
                
                if ($result) {
                    logSystem('info', '设置机器人描述', [
                        'language_code' => $language_code,
                        'admin_id' => $_SESSION['admin_id'] ?? null
                    ]);
                    
                    jsonResponse(['success' => true, 'message' => '描述设置成功']);
                } else {
                    jsonResponse(['success' => false, 'message' => '描述设置失败']);
                }
            } catch(Exception $e) {
                jsonResponse(['success' => false, 'message' => '设置失败: ' . $e->getMessage()]);
            }
            break;
            
        case 'set_bot_short_description':
            // 设置机器人简短描述
            $short_description = $_POST['short_description'] ?? '';
            $language_code = $_POST['language_code'] ?? '';
            
            try {
                $params = ['short_description' => $short_description];
                if ($language_code) {
                    $params['language_code'] = $language_code;
                }
                $result = $bot->callApi('setMyShortDescription', $params);
                
                if ($result) {
                    logSystem('info', '设置机器人简短描述', [
                        'language_code' => $language_code,
                        'admin_id' => $_SESSION['admin_id'] ?? null
                    ]);
                    
                    jsonResponse(['success' => true, 'message' => '简短描述设置成功']);
                } else {
                    jsonResponse(['success' => false, 'message' => '简短描述设置失败']);
                }
            } catch(Exception $e) {
                jsonResponse(['success' => false, 'message' => '设置失败: ' . $e->getMessage()]);
            }
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
    }
}

