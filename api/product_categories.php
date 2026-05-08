<?php
/**
 * 商品分类管理 API
 */
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

// 简单权限检查
session_start();
if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => '未授权访问'], 401);
}

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // 获取分类列表
            $stmt = $db->query("SELECT * FROM product_categories ORDER BY sort_order ASC, id ASC");
            $categories = $stmt->fetchAll();
            jsonResponse(['success' => true, 'data' => $categories]);
            break;
            
        case 'POST':
            // 添加分类
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['name'])) {
                jsonResponse(['success' => false, 'message' => '分类名称不能为空']);
            }
            
            $stmt = $db->prepare("INSERT INTO product_categories (name, description, icon, sort_order, is_active) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['name'],
                $data['description'] ?? null,
                $data['icon'] ?? '📦',
                $data['sort_order'] ?? 0,
                $data['is_active'] ?? 1
            ]);
            
            logSystem('info', '添加商品分类', ['name' => $data['name']]);
            jsonResponse(['success' => true, 'message' => '分类添加成功', 'id' => $db->lastInsertId()]);
            break;
            
        case 'PUT':
            // 更新分类
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['id']) || empty($data['name'])) {
                jsonResponse(['success' => false, 'message' => 'ID和名称不能为空']);
            }
            
            $stmt = $db->prepare("UPDATE product_categories SET name = ?, description = ?, icon = ?, sort_order = ?, is_active = ? WHERE id = ?");
            $stmt->execute([
                $data['name'],
                $data['description'] ?? null,
                $data['icon'] ?? '📦',
                $data['sort_order'] ?? 0,
                $data['is_active'] ?? 1,
                $data['id']
            ]);
            
            logSystem('info', '更新商品分类', ['id' => $data['id'], 'name' => $data['name']]);
            jsonResponse(['success' => true, 'message' => '分类更新成功']);
            break;
            
        case 'DELETE':
            // 删除分类
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['id'])) {
                jsonResponse(['success' => false, 'message' => 'ID不能为空']);
            }
            
            // 检查是否有商品
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = ?");
            $stmt->execute([$data['id']]);
            $count = $stmt->fetch()['count'];
            
            if ($count > 0) {
                jsonResponse(['success' => false, 'message' => '该分类下还有商品，无法删除']);
            }
            
            $stmt = $db->prepare("DELETE FROM product_categories WHERE id = ?");
            $stmt->execute([$data['id']]);
            
            logSystem('info', '删除商品分类', ['id' => $data['id']]);
            jsonResponse(['success' => true, 'message' => '分类删除成功']);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '不支持的请求方法'], 405);
    }
} catch (Exception $e) {
    logSystem('error', '商品分类API错误', ['error' => $e->getMessage()]);
    jsonResponse(['success' => false, 'message' => '操作失败：' . $e->getMessage()], 500);
}

