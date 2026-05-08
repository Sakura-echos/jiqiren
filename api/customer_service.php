<?php
/**
 * 客服管理API
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
    error_log("Customer service API error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => $e->getMessage()]);
}

// 获取客服列表
function handleGet($db) {
    $stmt = $db->query("SELECT * FROM customer_service ORDER BY sort_order ASC, id ASC");
    $contacts = $stmt->fetchAll();
    
    jsonResponse(['success' => true, 'data' => $contacts]);
}

// 添加或更新客服
function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['name']) || empty($input['type']) || empty($input['contact'])) {
        jsonResponse(['success' => false, 'message' => '客服名称、类型和联系方式为必填项']);
    }
    
    $db->beginTransaction();
    
    try {
        if (isset($input['id']) && $input['id']) {
            // 更新
            $stmt = $db->prepare("
                UPDATE customer_service 
                SET name = ?, type = ?, contact = ?, url = ?, description = ?, 
                    is_active = ?, sort_order = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $input['name'],
                $input['type'],
                $input['contact'],
                $input['url'] ?? null,
                $input['description'] ?? null,
                $input['is_active'] ?? 1,
                $input['sort_order'] ?? 0,
                $input['id']
            ]);
            
            $message = '更新成功';
        } else {
            // 新增
            $stmt = $db->prepare("
                INSERT INTO customer_service (name, type, contact, url, description, is_active, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $input['name'],
                $input['type'],
                $input['contact'],
                $input['url'] ?? null,
                $input['description'] ?? null,
                $input['is_active'] ?? 1,
                $input['sort_order'] ?? 0
            ]);
            
            $message = '添加成功';
        }
        
        $db->commit();
        
        logSystem('info', '客服配置操作', [
            'action' => isset($input['id']) ? 'update' : 'create',
            'name' => $input['name']
        ]);
        
        jsonResponse(['success' => true, 'message' => $message]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

// 更新客服状态
function handlePut($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['id'])) {
        jsonResponse(['success' => false, 'message' => 'ID不能为空']);
    }
    
    $stmt = $db->prepare("UPDATE customer_service SET is_active = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$input['is_active'] ?? 1, $input['id']]);
    
    logSystem('info', '修改客服状态', ['id' => $input['id'], 'status' => $input['is_active']]);
    
    jsonResponse(['success' => true, 'message' => '状态修改成功']);
}

// 删除客服
function handleDelete($db) {
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        jsonResponse(['success' => false, 'message' => '缺少ID参数']);
    }
    
    $stmt = $db->prepare("DELETE FROM customer_service WHERE id = ?");
    $stmt->execute([$id]);
    
    logSystem('info', '删除客服', ['id' => $id]);
    
    jsonResponse(['success' => true, 'message' => '删除成功']);
}
?>

