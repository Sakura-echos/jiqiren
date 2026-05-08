<?php
/**
 * 欢迎消息管理 API
 */

require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => '未授权访问'], 401);
}

$db = getDB();

// GET 请求 - 获取单个欢迎消息
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action == 'get') {
        $id = $_GET['id'] ?? 0;
        
        if (!$id) {
            jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
        }
        
        try {
            $stmt = $db->prepare("SELECT * FROM welcome_messages WHERE id = ?");
            $stmt->execute([$id]);
            $welcome = $stmt->fetch();
            
            if (!$welcome) {
                jsonResponse(['success' => false, 'message' => '欢迎消息不存在'], 404);
            }
            
            jsonResponse(['success' => true, 'data' => $welcome]);
        } catch (Exception $e) {
            logSystem('error', 'Fetch welcome message error', $e->getMessage());
            jsonResponse(['success' => false, 'message' => '获取失败'], 500);
        }
    }
}

// POST 请求
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Check if it's a FormData request (has POST data) or JSON request
    $isFormData = !empty($_POST);
    
    if ($isFormData) {
        $action = $_POST['action'] ?? '';
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';
    }
    
    switch ($action) {
        case 'add':
            if ($isFormData) {
                $group_id = $_POST['group_id'] ?? 0;
                $message = $_POST['message'] ?? '';
                $buttons = $_POST['buttons'] ?? null;
                $delete_after_seconds = isset($_POST['delete_after_seconds']) ? intval($_POST['delete_after_seconds']) : 30;
                
                // Handle image upload
                $image_url = null;
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = dirname(__DIR__) . '/uploads/welcome/';
                    
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    if (!is_writable($uploadDir)) {
                        jsonResponse(['success' => false, 'message' => '上传目录不可写'], 500);
                    }
                    
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $fileType = $_FILES['image']['type'];
                    $fileSize = $_FILES['image']['size'];
                    
                    if (!in_array($fileType, $allowedTypes)) {
                        jsonResponse(['success' => false, 'message' => '不支持的图片格式'], 400);
                    }
                    
                    // GIF文件最大50MB，其他格式最大5MB
                    $maxSize = ($fileType === 'image/gif') ? 50 * 1024 * 1024 : 5 * 1024 * 1024;
                    $maxSizeText = ($fileType === 'image/gif') ? '50MB' : '5MB';
                    
                    if ($fileSize > $maxSize) {
                        jsonResponse(['success' => false, 'message' => '图片大小不能超过 ' . $maxSizeText], 400);
                    }
                    
                    $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                    $filename = 'welcome_' . time() . '_' . uniqid() . '.' . $extension;
                    $filepath = $uploadDir . $filename;
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $filepath)) {
                        $image_url = 'uploads/welcome/' . $filename;
                    } else {
                        jsonResponse(['success' => false, 'message' => '图片上传失败'], 500);
                    }
                }
            } else {
                $group_id = $data['group_id'] ?? 0;
                $message = $data['message'] ?? '';
                $image_url = $data['image_url'] ?? null;
                $buttons = $data['buttons'] ?? null;
                $delete_after_seconds = isset($data['delete_after_seconds']) ? intval($data['delete_after_seconds']) : 30;
            }
            
            if (empty($message)) {
                jsonResponse(['success' => false, 'message' => '请填写所有必填字段'], 400);
            }
            
            // Convert 0 to NULL for "all groups"
            $final_group_id = ($group_id == 0) ? null : $group_id;
            
            try {
                // 先禁用该群组的其他欢迎消息
                if ($final_group_id === null) {
                    // For "all groups", disable all other "all groups" welcome messages
                    $stmt = $db->prepare("UPDATE welcome_messages SET is_active = 0 WHERE group_id IS NULL");
                    $stmt->execute();
                } else {
                    $stmt = $db->prepare("UPDATE welcome_messages SET is_active = 0 WHERE group_id = ?");
                    $stmt->execute([$final_group_id]);
                }
                
                // 添加新的欢迎消息
                $stmt = $db->prepare("INSERT INTO welcome_messages (group_id, message, image_url, buttons, delete_after_seconds) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$final_group_id, $message, $image_url, $buttons, $delete_after_seconds]);
                
                logSystem('info', 'Added welcome message', ['group_id' => $group_id]);
                jsonResponse(['success' => true, 'message' => '添加成功']);
            } catch (Exception $e) {
                logSystem('error', 'Add welcome message error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '添加失败: ' . $e->getMessage()], 500);
            }
            break;
            
        case 'update':
            if ($isFormData) {
                $id = $_POST['id'] ?? 0;
                $group_id = $_POST['group_id'] ?? 0;
                $message = $_POST['message'] ?? '';
                $buttons = $_POST['buttons'] ?? null;
                $delete_after_seconds = isset($_POST['delete_after_seconds']) ? intval($_POST['delete_after_seconds']) : 30;
                
                // Handle image upload (optional for update)
                $image_url = null;
                $updateImage = false;
                
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = dirname(__DIR__) . '/uploads/welcome/';
                    
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    if (!is_writable($uploadDir)) {
                        jsonResponse(['success' => false, 'message' => '上传目录不可写'], 500);
                    }
                    
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $fileType = $_FILES['image']['type'];
                    $fileSize = $_FILES['image']['size'];
                    
                    if (!in_array($fileType, $allowedTypes)) {
                        jsonResponse(['success' => false, 'message' => '不支持的图片格式'], 400);
                    }
                    
                    // GIF文件最大50MB，其他格式最大5MB
                    $maxSize = ($fileType === 'image/gif') ? 50 * 1024 * 1024 : 5 * 1024 * 1024;
                    $maxSizeText = ($fileType === 'image/gif') ? '50MB' : '5MB';
                    
                    if ($fileSize > $maxSize) {
                        jsonResponse(['success' => false, 'message' => '图片大小不能超过 ' . $maxSizeText], 400);
                    }
                    
                    $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                    $filename = 'welcome_' . time() . '_' . uniqid() . '.' . $extension;
                    $filepath = $uploadDir . $filename;
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $filepath)) {
                        $image_url = 'uploads/welcome/' . $filename;
                        $updateImage = true;
                    } else {
                        jsonResponse(['success' => false, 'message' => '图片上传失败'], 500);
                    }
                }
            } else {
                $id = $data['id'] ?? 0;
                $group_id = $data['group_id'] ?? 0;
                $message = $data['message'] ?? '';
                $image_url = $data['image_url'] ?? null;
                $buttons = $data['buttons'] ?? null;
                $delete_after_seconds = isset($data['delete_after_seconds']) ? intval($data['delete_after_seconds']) : 30;
                $updateImage = isset($data['image_url']);
            }
            
            if (!$id || empty($message)) {
                jsonResponse(['success' => false, 'message' => '请填写所有必填字段'], 400);
            }
            
            // Convert 0 to NULL for "all groups"
            $final_group_id = ($group_id == 0) ? null : $group_id;
            
            try {
                // 先禁用该群组的其他欢迎消息
                if ($final_group_id === null) {
                    // For "all groups", disable all other "all groups" welcome messages
                    $stmt = $db->prepare("UPDATE welcome_messages SET is_active = 0 WHERE group_id IS NULL AND id != ?");
                    $stmt->execute([$id]);
                } else {
                    $stmt = $db->prepare("UPDATE welcome_messages SET is_active = 0 WHERE group_id = ? AND id != ?");
                    $stmt->execute([$final_group_id, $id]);
                }
                
                // 更新欢迎消息
                if ($updateImage) {
                    $stmt = $db->prepare("UPDATE welcome_messages SET group_id = ?, message = ?, image_url = ?, buttons = ?, delete_after_seconds = ? WHERE id = ?");
                    $stmt->execute([$final_group_id, $message, $image_url, $buttons, $delete_after_seconds, $id]);
                } else {
                    $stmt = $db->prepare("UPDATE welcome_messages SET group_id = ?, message = ?, buttons = ?, delete_after_seconds = ? WHERE id = ?");
                    $stmt->execute([$final_group_id, $message, $buttons, $delete_after_seconds, $id]);
                }
                
                logSystem('info', 'Updated welcome message', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '更新成功']);
            } catch (Exception $e) {
                logSystem('error', 'Update welcome message error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '更新失败: ' . $e->getMessage()], 500);
            }
            break;
            
        case 'delete':
            $id = $data['id'] ?? 0;
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            try {
                $stmt = $db->prepare("DELETE FROM welcome_messages WHERE id = ?");
                $stmt->execute([$id]);
                
                logSystem('info', 'Deleted welcome message', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '删除成功']);
            } catch (Exception $e) {
                logSystem('error', 'Delete welcome message error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '删除失败'], 500);
            }
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
    }
}

