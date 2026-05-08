<?php
/**
 * 语言设置 API
 */
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => '未授权访问'], 401);
}

// 获取数据库连接
$db = getDB();

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($db);
            break;
        case 'POST':
            handlePost($db);
            break;
        case 'PUT':
            handlePut($db);
            break;
        case 'DELETE':
            handleDelete($db);
            break;
        default:
            jsonResponse(['success' => false, 'message' => '不支持的请求方法'], 405);
    }
} catch (Exception $e) {
    logSystem('error', '语言设置API错误', ['error' => $e->getMessage()]);
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}

function handleGet($db) {
    $id = $_GET['id'] ?? null;
    
    if ($id) {
        $stmt = $db->prepare("SELECT * FROM language_settings WHERE id = ?");
        $stmt->execute([$id]);
        $language = $stmt->fetch();
        
        if ($language) {
            jsonResponse(['success' => true, 'data' => $language]);
        } else {
            jsonResponse(['success' => false, 'message' => '语言不存在'], 404);
        }
    } else {
        $stmt = $db->query("SELECT * FROM language_settings ORDER BY sort_order ASC, id ASC");
        $languages = $stmt->fetchAll();
        jsonResponse(['success' => true, 'data' => $languages]);
    }
}

function handlePost($db) {
    // 检查是否是初始化请求
    if (isset($_GET['action']) && $_GET['action'] == 'initialize') {
        initializeDefaultLanguages($db);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonResponse(['success' => false, 'message' => '无效的请求数据']);
    }
    
    // 验证必填字段
    if (empty($input['name']) || empty($input['code'])) {
        jsonResponse(['success' => false, 'message' => '语言名称和代码不能为空']);
    }
    
    $db->beginTransaction();
    
    try {
        if (isset($input['id']) && $input['id']) {
            // 更新
            $stmt = $db->prepare("
                UPDATE language_settings 
                SET name = ?, code = ?, flag = ?, 
                    status = ?, sort_order = ?, is_default = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $input['name'],
                $input['code'],
                $input['flag'] ?? '',
                $input['status'] ?? 'inactive',
                $input['sort_order'] ?? 0,
                $input['is_default'] ?? 0,
                $input['id']
            ]);
            
            $message = '更新成功';
        } else {
            // 检查代码是否已存在
            $stmt = $db->prepare("SELECT id FROM language_settings WHERE code = ?");
            $stmt->execute([$input['code']]);
            if ($stmt->fetch()) {
                $db->rollBack();
                jsonResponse(['success' => false, 'message' => '语言代码已存在']);
            }
            
            // 新增
            $stmt = $db->prepare("
                INSERT INTO language_settings (name, code, flag, status, sort_order, is_default)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $input['name'],
                $input['code'],
                $input['flag'] ?? '',
                $input['status'] ?? 'inactive',
                $input['sort_order'] ?? 0,
                $input['is_default'] ?? 0
            ]);
            
            $message = '添加成功';
        }
        
        // 如果设置为默认语言，清除其他语言的默认标记
        if (!empty($input['is_default'])) {
            $stmt = $db->prepare("UPDATE language_settings SET is_default = 0 WHERE id != ?");
            $stmt->execute([$input['id'] ?? $db->lastInsertId()]);
        }
        
        $db->commit();
        
        logSystem('info', '语言设置操作', [
            'action' => isset($input['id']) ? 'update' : 'create',
            'language' => $input['name']
        ]);
        
        jsonResponse(['success' => true, 'message' => $message]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function handlePut($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || empty($input['id'])) {
        jsonResponse(['success' => false, 'message' => '缺少必要参数']);
    }
    
    // 更新状态
    if (isset($input['status'])) {
        $stmt = $db->prepare("UPDATE language_settings SET status = ? WHERE id = ?");
        $stmt->execute([$input['status'], $input['id']]);
        
        logSystem('info', '更新语言状态', [
            'id' => $input['id'],
            'status' => $input['status']
        ]);
        
        jsonResponse(['success' => true, 'message' => '更新成功']);
    }
    
    jsonResponse(['success' => false, 'message' => '无效的操作']);
}

function handleDelete($db) {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        jsonResponse(['success' => false, 'message' => '缺少必要参数']);
    }
    
    // 检查是否为默认语言
    $stmt = $db->prepare("SELECT is_default FROM language_settings WHERE id = ?");
    $stmt->execute([$id]);
    $lang = $stmt->fetch();
    
    if ($lang && $lang['is_default']) {
        jsonResponse(['success' => false, 'message' => '不能删除默认语言']);
    }
    
    $stmt = $db->prepare("DELETE FROM language_settings WHERE id = ?");
    $stmt->execute([$id]);
    
    logSystem('info', '删除语言', ['id' => $id]);
    
    jsonResponse(['success' => true, 'message' => '删除成功']);
}

function initializeDefaultLanguages($db) {
    $defaultLanguages = [
        [
            'name' => '简体中文',
            'code' => 'zh_CN',
            'flag' => '🇨🇳',
            'status' => 'active',
            'sort_order' => 1,
            'is_default' => 1
        ],
        [
            'name' => '繁體中文',
            'code' => 'zh_TW',
            'flag' => '🇹🇼',
            'status' => 'inactive',
            'sort_order' => 2,
            'is_default' => 0
        ],
        [
            'name' => 'English',
            'code' => 'en_US',
            'flag' => '🇺🇸',
            'status' => 'inactive',
            'sort_order' => 3,
            'is_default' => 0
        ],
        [
            'name' => 'Русский',
            'code' => 'ru_RU',
            'flag' => '🇷🇺',
            'status' => 'inactive',
            'sort_order' => 4,
            'is_default' => 0
        ]
    ];
    
    $db->beginTransaction();
    
    try {
        // 清空现有数据
        $db->exec("TRUNCATE TABLE language_settings");
        
        $stmt = $db->prepare("
            INSERT INTO language_settings (name, code, flag, status, sort_order, is_default)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($defaultLanguages as $lang) {
            $stmt->execute([
                $lang['name'],
                $lang['code'],
                $lang['flag'],
                $lang['status'],
                $lang['sort_order'],
                $lang['is_default']
            ]);
        }
        
        $db->commit();
        
        logSystem('info', '初始化默认语言', ['count' => count($defaultLanguages)]);
        
        jsonResponse(['success' => true, 'message' => '初始化成功']);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

