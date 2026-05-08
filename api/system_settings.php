<?php
session_start();
require_once '../config.php';

header('Content-Type: application/json');

// 检查登录
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

$db = getDB();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'update':
            $setting_key = $input['setting_key'] ?? '';
            $setting_value = $input['setting_value'] ?? '';
            
            if (empty($setting_key)) {
                throw new Exception('参数错误');
            }
            
            $stmt = $db->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
            $stmt->execute([$setting_value, $setting_key]);
            
            echo json_encode([
                'success' => true,
                'message' => '设置已更新'
            ]);
            break;
            
        case 'get':
            $setting_key = $input['setting_key'] ?? '';
            
            if (empty($setting_key)) {
                // 获取所有设置
                $stmt = $db->query("SELECT * FROM system_settings ORDER BY id ASC");
                $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'data' => $settings
                ]);
            } else {
                // 获取单个设置
                $stmt = $db->prepare("SELECT * FROM system_settings WHERE setting_key = ?");
                $stmt->execute([$setting_key]);
                $setting = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'data' => $setting
                ]);
            }
            break;
            
        default:
            throw new Exception('未知操作');
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

