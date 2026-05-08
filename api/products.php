<?php
/**
 * 商品管理 API
 */
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => '未授权访问'], 401);
}

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // 获取商品列表
            $stmt = $db->query("
                SELECT p.*, pc.name as category_name,
                (SELECT COUNT(*) FROM card_stock WHERE product_id = p.id AND status = 'available') as actual_stock
                FROM products p
                LEFT JOIN product_categories pc ON p.category_id = pc.id
                ORDER BY p.sort_order ASC, p.id DESC
            ");
            $products = $stmt->fetchAll();
            jsonResponse(['success' => true, 'data' => $products]);
            break;
            
        case 'POST':
            // 添加商品
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['name']) || empty($data['category_id']) || !isset($data['price'])) {
                jsonResponse(['success' => false, 'message' => '商品名称、分类和价格不能为空']);
            }
            
            $stmt = $db->prepare("
                INSERT INTO products 
                (category_id, name, description, price, image_url, card_type, sort_order, is_active, stock) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
            ");
            $stmt->execute([
                $data['category_id'],
                $data['name'],
                $data['description'] ?? null,
                $data['price'],
                $data['image_url'] ?? null,
                $data['card_type'] ?? 'text',
                $data['sort_order'] ?? 0,
                $data['is_active'] ?? 1
            ]);
            
            logSystem('info', '添加商品', ['name' => $data['name']]);
            jsonResponse(['success' => true, 'message' => '商品添加成功', 'id' => $db->lastInsertId()]);
            break;
            
        case 'PUT':
            // 更新商品
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['id']) || empty($data['name']) || !isset($data['price'])) {
                jsonResponse(['success' => false, 'message' => 'ID、名称和价格不能为空']);
            }
            
            $stmt = $db->prepare("
                UPDATE products SET 
                category_id = ?, name = ?, description = ?, price = ?, 
                image_url = ?, card_type = ?, sort_order = ?, is_active = ? 
                WHERE id = ?
            ");
            $stmt->execute([
                $data['category_id'],
                $data['name'],
                $data['description'] ?? null,
                $data['price'],
                $data['image_url'] ?? null,
                $data['card_type'] ?? 'text',
                $data['sort_order'] ?? 0,
                $data['is_active'] ?? 1,
                $data['id']
            ]);
            
            logSystem('info', '更新商品', ['id' => $data['id'], 'name' => $data['name']]);
            jsonResponse(['success' => true, 'message' => '商品更新成功']);
            break;
            
        case 'DELETE':
            // 删除商品
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['id'])) {
                jsonResponse(['success' => false, 'message' => 'ID不能为空']);
            }
            
            // 删除商品（级联删除库存）
            $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$data['id']]);
            
            logSystem('info', '删除商品', ['id' => $data['id']]);
            jsonResponse(['success' => true, 'message' => '商品删除成功']);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '不支持的请求方法'], 405);
    }
} catch (Exception $e) {
    logSystem('error', '商品API错误', ['error' => $e->getMessage()]);
    jsonResponse(['success' => false, 'message' => '操作失败：' . $e->getMessage()], 500);
}

