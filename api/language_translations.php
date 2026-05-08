<?php
/**
 * 语言翻译API
 */
session_start();
require_once '../config.php';

// 检查登录
if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => '未登录']);
}

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
            jsonResponse(['success' => false, 'message' => '不支持的请求方法']);
    }
} catch (Exception $e) {
    error_log("Language translation API error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => $e->getMessage()]);
}

// 获取翻译列表
function handleGet($db) {
    $language_code = $_GET['language_code'] ?? '';
    
    if ($language_code) {
        $stmt = $db->prepare("SELECT * FROM language_translations WHERE language_code = ? ORDER BY category, trans_key");
        $stmt->execute([$language_code]);
    } else {
        $stmt = $db->query("SELECT * FROM language_translations ORDER BY language_code, category, trans_key");
    }
    
    $translations = $stmt->fetchAll();
    jsonResponse(['success' => true, 'data' => $translations]);
}

// 添加翻译或复制翻译
function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // 检查是否是复制操作
    if (isset($_GET['action']) && $_GET['action'] == 'copy') {
        copyTranslations($db, $input);
        return;
    }
    
    // 验证必填字段
    if (empty($input['language_code']) || empty($input['trans_key']) || empty($input['trans_value'])) {
        jsonResponse(['success' => false, 'message' => '语言代码、翻译键和翻译文本为必填项']);
    }
    
    $db->beginTransaction();
    
    try {
        // 检查键是否已存在
        $stmt = $db->prepare("SELECT id FROM language_translations WHERE language_code = ? AND trans_key = ?");
        $stmt->execute([$input['language_code'], $input['trans_key']]);
        if ($stmt->fetch()) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => '该翻译键已存在']);
        }
        
        // 插入新翻译
        $stmt = $db->prepare("
            INSERT INTO language_translations (language_code, trans_key, trans_value, category)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $input['language_code'],
            $input['trans_key'],
            $input['trans_value'],
            $input['category'] ?? 'other'
        ]);
        
        $db->commit();
        
        logSystem('info', '添加翻译', [
            'language' => $input['language_code'],
            'key' => $input['trans_key']
        ]);
        
        jsonResponse(['success' => true, 'message' => '添加成功', 'id' => $db->lastInsertId()]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

// 更新翻译
function handlePut($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['id']) || empty($input['trans_value'])) {
        jsonResponse(['success' => false, 'message' => 'ID和翻译文本为必填项']);
    }
    
    $stmt = $db->prepare("UPDATE language_translations SET trans_value = ? WHERE id = ?");
    $stmt->execute([$input['trans_value'], $input['id']]);
    
    logSystem('info', '更新翻译', ['id' => $input['id']]);
    
    jsonResponse(['success' => true, 'message' => '更新成功']);
}

// 删除翻译
function handleDelete($db) {
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        jsonResponse(['success' => false, 'message' => '缺少ID参数']);
    }
    
    $stmt = $db->prepare("DELETE FROM language_translations WHERE id = ?");
    $stmt->execute([$id]);
    
    logSystem('info', '删除翻译', ['id' => $id]);
    
    jsonResponse(['success' => true, 'message' => '删除成功']);
}

// 复制翻译
function copyTranslations($db, $input) {
    $from = $input['from'] ?? 'zh_CN';
    $to = $input['to'] ?? '';
    
    if (empty($to)) {
        jsonResponse(['success' => false, 'message' => '目标语言不能为空']);
    }
    
    if ($from == $to) {
        jsonResponse(['success' => false, 'message' => '源语言和目标语言不能相同']);
    }
    
    $db->beginTransaction();
    
    try {
        // 获取源语言的所有翻译
        $stmt = $db->prepare("SELECT * FROM language_translations WHERE language_code = ?");
        $stmt->execute([$from]);
        $source_translations = $stmt->fetchAll();
        
        if (empty($source_translations)) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => '源语言没有翻译数据']);
        }
        
        // 删除目标语言的现有翻译（如果有）
        $stmt = $db->prepare("DELETE FROM language_translations WHERE language_code = ?");
        $stmt->execute([$to]);
        
        // 插入新翻译
        $stmt = $db->prepare("
            INSERT INTO language_translations (language_code, trans_key, trans_value, category)
            VALUES (?, ?, ?, ?)
        ");
        
        $count = 0;
        foreach ($source_translations as $trans) {
            $stmt->execute([
                $to,
                $trans['trans_key'],
                $trans['trans_value'], // 复制原文本，管理员需要自己翻译
                $trans['category']
            ]);
            $count++;
        }
        
        $db->commit();
        
        logSystem('info', '复制翻译', [
            'from' => $from,
            'to' => $to,
            'count' => $count
        ]);
        
        jsonResponse(['success' => true, 'message' => "成功复制 {$count} 条翻译"]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function jsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
?>

