<?php
/**
 * 认证 API
 */

require_once '../config.php';

session_start();

// 处理登出
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

// 获取请求数据
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

switch ($action) {
    case 'login':
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            jsonResponse(['success' => false, 'message' => '请填写用户名和密码'], 400);
        }
        
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();
            
            if ($admin && $password === $admin['password']) {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                
                logSystem('info', 'Admin login', ['username' => $username]);
                
                jsonResponse(['success' => true, 'message' => '登录成功']);
            } else {
                jsonResponse(['success' => false, 'message' => '用户名或密码错误'], 401);
            }
        } catch (Exception $e) {
            logSystem('error', 'Login error', $e->getMessage());
            jsonResponse(['success' => false, 'message' => '系统错误'], 500);
        }
        break;
        
    default:
        jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
}

