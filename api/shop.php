<?php
/**
 * 发卡商城 API - 用于Telegram机器人调用
 */
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

$db = getDB();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'categories':
            // 获取商品分类列表
            $stmt = $db->query("
                SELECT pc.*, COUNT(p.id) as product_count 
                FROM product_categories pc 
                LEFT JOIN products p ON pc.id = p.category_id AND p.is_active = 1
                WHERE pc.is_active = 1 
                GROUP BY pc.id
                ORDER BY pc.sort_order ASC, pc.id ASC
            ");
            $categories = $stmt->fetchAll();
            jsonResponse(['success' => true, 'data' => $categories]);
            break;
            
        case 'products':
            // 获取商品列表
            $category_id = $_GET['category_id'] ?? '';
            
            $sql = "
                SELECT p.*, pc.name as category_name, pc.icon as category_icon,
                (SELECT COUNT(*) FROM card_stock WHERE product_id = p.id AND status = 'available') as stock
                FROM products p
                LEFT JOIN product_categories pc ON p.category_id = pc.id
                WHERE p.is_active = 1
            ";
            
            $params = [];
            if ($category_id) {
                $sql .= " AND p.category_id = ?";
                $params[] = $category_id;
            }
            
            $sql .= " ORDER BY p.sort_order ASC, p.id DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll();
            
            jsonResponse(['success' => true, 'data' => $products]);
            break;
            
        case 'product_detail':
            // 获取商品详情
            $product_id = $_GET['product_id'] ?? '';
            
            if (empty($product_id)) {
                jsonResponse(['success' => false, 'message' => '商品ID不能为空']);
            }
            
            $stmt = $db->prepare("
                SELECT p.*, pc.name as category_name,
                (SELECT COUNT(*) FROM card_stock WHERE product_id = p.id AND status = 'available') as stock
                FROM products p
                LEFT JOIN product_categories pc ON p.category_id = pc.id
                WHERE p.id = ? AND p.is_active = 1
            ");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch();
            
            if (!$product) {
                jsonResponse(['success' => false, 'message' => '商品不存在或已下架']);
            }
            
            jsonResponse(['success' => true, 'data' => $product]);
            break;
            
        case 'create_order':
            // 创建订单
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['telegram_id']) || empty($data['product_id']) || empty($data['quantity'])) {
                jsonResponse(['success' => false, 'message' => '参数不完整']);
            }
            
            $db->beginTransaction();
            
            try {
                // 获取或创建用户
                $stmt = $db->prepare("SELECT * FROM card_users WHERE telegram_id = ?");
                $stmt->execute([$data['telegram_id']]);
                $user = $stmt->fetch();
                
                if (!$user) {
                    $stmt = $db->prepare("
                        INSERT INTO card_users (telegram_id, username, first_name, last_name) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $data['telegram_id'],
                        $data['username'] ?? null,
                        $data['first_name'] ?? 'User',
                        $data['last_name'] ?? null
                    ]);
                    $user_id = $db->lastInsertId();
                    
                    $stmt = $db->prepare("SELECT * FROM card_users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch();
                }
                
                // 检查用户是否被封禁
                if ($user['is_blocked']) {
                    throw new Exception('您的账户已被封禁，无法购买商品');
                }
                
                // 获取商品信息
                $stmt = $db->prepare("
                    SELECT p.*,
                    (SELECT COUNT(*) FROM card_stock WHERE product_id = p.id AND status = 'available') as stock
                    FROM products p
                    WHERE p.id = ? AND p.is_active = 1
                ");
                $stmt->execute([$data['product_id']]);
                $product = $stmt->fetch();
                
                if (!$product) {
                    throw new Exception('商品不存在或已下架');
                }
                
                // 检查库存
                if ($product['stock'] < $data['quantity']) {
                    throw new Exception('库存不足，当前库存：' . $product['stock']);
                }
                
                // 计算金额
                $unit_price = $product['price'];
                $total_amount = $unit_price * $data['quantity'];
                
                // 生成订单号
                $order_no = 'ORD' . date('Ymd') . strtoupper(substr(md5(uniqid()), 0, 8));
                
                // 创建订单
                $stmt = $db->prepare("
                    INSERT INTO orders 
                    (order_no, user_id, telegram_id, product_id, product_name, quantity, unit_price, total_amount, payment_method, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'balance', 'pending')
                ");
                $stmt->execute([
                    $order_no,
                    $user['id'],
                    $data['telegram_id'],
                    $product['id'],
                    $product['name'],
                    $data['quantity'],
                    $unit_price,
                    $total_amount
                ]);
                
                $order_id = $db->lastInsertId();
                
                $db->commit();
                
                jsonResponse(['success' => true, 'data' => [
                    'order_id' => $order_id,
                    'order_no' => $order_no,
                    'total_amount' => $total_amount,
                    'user_balance' => $user['balance']
                ]]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;
            
        case 'pay_order':
            // 支付订单（余额支付）
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['order_id']) || empty($data['telegram_id'])) {
                jsonResponse(['success' => false, 'message' => '参数不完整']);
            }
            
            $db->beginTransaction();
            
            try {
                // 获取订单
                $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND telegram_id = ? FOR UPDATE");
                $stmt->execute([$data['order_id'], $data['telegram_id']]);
                $order = $stmt->fetch();
                
                if (!$order) {
                    throw new Exception('订单不存在');
                }
                
                if ($order['status'] != 'pending') {
                    throw new Exception('订单状态不正确');
                }
                
                // 获取用户
                $stmt = $db->prepare("SELECT * FROM card_users WHERE id = ? FOR UPDATE");
                $stmt->execute([$order['user_id']]);
                $user = $stmt->fetch();
                
                // 检查余额
                if ($user['balance'] < $order['total_amount']) {
                    throw new Exception('余额不足，当前余额：$' . number_format($user['balance'], 2));
                }
                
                // 扣除余额
                $new_balance = $user['balance'] - $order['total_amount'];
                $stmt = $db->prepare("UPDATE card_users SET balance = ?, total_spent = total_spent + ?, total_orders = total_orders + 1 WHERE id = ?");
                $stmt->execute([$new_balance, $order['total_amount'], $user['id']]);
                
                // 记录余额变动
                $stmt = $db->prepare("
                    INSERT INTO balance_transactions 
                    (user_id, telegram_id, type, amount, balance_before, balance_after, order_id, description) 
                    VALUES (?, ?, 'purchase', ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $user['id'],
                    $user['telegram_id'],
                    -$order['total_amount'],
                    $user['balance'],
                    $new_balance,
                    $order['id'],
                    '购买商品：' . $order['product_name']
                ]);
                
                // 获取卡密
                $stmt = $db->prepare("
                    SELECT id, card_content 
                    FROM card_stock 
                    WHERE product_id = ? AND status = 'available' 
                    ORDER BY id ASC 
                    LIMIT ?
                ");
                $stmt->execute([$order['product_id'], $order['quantity']]);
                $cards = $stmt->fetchAll();
                
                if (count($cards) < $order['quantity']) {
                    throw new Exception('库存不足');
                }
                
                // 标记卡密为已售
                $card_ids = array_column($cards, 'id');
                $card_contents = array_column($cards, 'card_content');
                
                $placeholders = str_repeat('?,', count($card_ids) - 1) . '?';
                $stmt = $db->prepare("
                    UPDATE card_stock 
                    SET status = 'sold', order_id = ?, sold_at = NOW() 
                    WHERE id IN ($placeholders)
                ");
                $stmt->execute(array_merge([$order['id']], $card_ids));
                
                // 更新订单状态
                $stmt = $db->prepare("
                    UPDATE orders 
                    SET status = 'completed', cards_delivered = ?, paid_at = NOW(), completed_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([json_encode($card_contents, JSON_UNESCAPED_UNICODE), $order['id']]);
                
                // 更新商品销量
                $stmt = $db->prepare("UPDATE products SET sales_count = sales_count + ? WHERE id = ?");
                $stmt->execute([$order['quantity'], $order['product_id']]);
                
                $db->commit();
                
                logSystem('info', '订单支付成功', ['order_no' => $order['order_no'], 'amount' => $order['total_amount']]);
                
                jsonResponse(['success' => true, 'data' => [
                    'order_no' => $order['order_no'],
                    'cards' => $card_contents,
                    'new_balance' => $new_balance
                ]]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;
            
        case 'user_orders':
            // 获取用户订单列表
            $telegram_id = $_GET['telegram_id'] ?? '';
            
            if (empty($telegram_id)) {
                jsonResponse(['success' => false, 'message' => 'Telegram ID不能为空']);
            }
            
            $stmt = $db->prepare("
                SELECT * FROM orders 
                WHERE telegram_id = ? 
                ORDER BY id DESC 
                LIMIT 50
            ");
            $stmt->execute([$telegram_id]);
            $orders = $stmt->fetchAll();
            
            jsonResponse(['success' => true, 'data' => $orders]);
            break;
            
        case 'user_info':
            // 获取用户信息
            $telegram_id = $_GET['telegram_id'] ?? '';
            
            if (empty($telegram_id)) {
                jsonResponse(['success' => false, 'message' => 'Telegram ID不能为空']);
            }
            
            $stmt = $db->prepare("SELECT * FROM card_users WHERE telegram_id = ?");
            $stmt->execute([$telegram_id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                jsonResponse(['success' => false, 'message' => '用户不存在']);
            }
            
            jsonResponse(['success' => true, 'data' => $user]);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '不支持的操作'], 400);
    }
} catch (Exception $e) {
    logSystem('error', '商城API错误', ['action' => $action, 'error' => $e->getMessage()]);
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}

