<?php
require_once '../config.php';

header('Content-Type: application/json');

// 检查登录状态
session_start();
if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => '未登录'], 401);
}

$db = getDB();

// 获取请求数据
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// 支持 POST 和 JSON 格式
$action = $data['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'add':
        $buttonText = $data['button_text'] ?? $_POST['button_text'] ?? '';
        $buttonUrl = $data['button_url'] ?? $_POST['button_url'] ?? '';
        $sortOrder = (int)($data['sort_order'] ?? $_POST['sort_order'] ?? 0);

        if (empty($buttonText) || empty($buttonUrl)) {
            jsonResponse(['success' => false, 'message' => '请填写完整信息']);
        }

        try {
            $stmt = $db->prepare("INSERT INTO menu_buttons (button_text, button_url, sort_order) VALUES (?, ?, ?)");
            $result = $stmt->execute([$buttonText, $buttonUrl, $sortOrder]);
            
            if ($result) {
                jsonResponse(['success' => true, 'message' => '添加成功']);
            } else {
                jsonResponse(['success' => false, 'message' => '添加失败']);
            }
        } catch (Exception $e) {
            error_log("Error adding menu button: " . $e->getMessage());
            jsonResponse(['success' => false, 'message' => '系统错误']);
        }
        break;

    case 'list':
        try {
            $stmt = $db->query("SELECT * FROM menu_buttons WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
            $buttons = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(['success' => true, 'data' => $buttons]);
        } catch (Exception $e) {
            error_log("Error listing menu buttons: " . $e->getMessage());
            jsonResponse(['success' => false, 'message' => '系统错误']);
        }
        break;

    case 'delete':
        $id = (int)($data['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => '无效的ID']);
        }

        try {
            $stmt = $db->prepare("UPDATE menu_buttons SET is_active = 0 WHERE id = ?");
            $result = $stmt->execute([$id]);
            
            if ($result) {
                jsonResponse(['success' => true, 'message' => '删除成功']);
            } else {
                jsonResponse(['success' => false, 'message' => '删除失败']);
            }
        } catch (Exception $e) {
            error_log("Error deleting menu button: " . $e->getMessage());
            jsonResponse(['success' => false, 'message' => '系统错误']);
        }
        break;

    default:
        jsonResponse(['success' => false, 'message' => '未知操作']);
}
