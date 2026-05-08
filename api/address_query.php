<?php
/**
 * TRC地址查询 API
 */

require_once '../config.php';
checkLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    $db = getDB();
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => '数据库连接失败'], 500);
}

// GET 请求
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action == 'get_query_history') {
        $address = $_GET['address'] ?? '';
        
        if (!$address) {
            jsonResponse(['success' => false, 'message' => '地址不能为空'], 400);
        }
        
        try {
            // 获取查询历史
            $stmt = $db->prepare("SELECT * FROM address_query_logs WHERE address = ? ORDER BY query_time DESC LIMIT 50");
            $stmt->execute([$address]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 获取总查询次数
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM address_query_logs WHERE address = ?");
            $stmt->execute([$address]);
            $count = $stmt->fetch();
            
            jsonResponse([
                'success' => true, 
                'data' => $logs,
                'total_queries' => $count['total']
            ]);
        } catch (Exception $e) {
            error_log('Get query history error: ' . $e->getMessage());
            jsonResponse(['success' => false, 'message' => '获取失败'], 500);
        }
    }
}

// POST 请求 - 暂不支持
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    jsonResponse(['success' => false, 'message' => '查询功能为只读，不支持修改操作'], 403);
}

jsonResponse(['success' => false, 'message' => '无效的请求'], 400);
