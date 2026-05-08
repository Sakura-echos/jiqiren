<?php
/**
 * 自动回复管理 API
 */

require_once '../config.php';
checkLogin();

// 开启错误显示
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    $db = getDB();
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => '数据库连接失败'], 500);
}

// GET 请求 - 获取单个自动回复
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action == 'get') {
        $id = $_GET['id'] ?? 0;
        
        if (!$id) {
            jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
        }
        
        try {
            $stmt = $db->prepare("SELECT * FROM auto_replies WHERE id = ?");
            $stmt->execute([$id]);
            $reply = $stmt->fetch();
            
            if (!$reply) {
                jsonResponse(['success' => false, 'message' => '自动回复不存在'], 404);
            }
            
            jsonResponse(['success' => true, 'data' => $reply]);
        } catch (Exception $e) {
            logSystem('error', 'Fetch auto reply error', $e->getMessage());
            jsonResponse(['success' => false, 'message' => '获取失败'], 500);
        }
    }
}

// POST 请求
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Check if it's a FormData request (has POST data) or JSON request
    $isFormData = !empty($_POST);
    
    if ($isFormData) {
        // Handle FormData request
        $action = $_POST['action'] ?? '';
    } else {
        // Handle JSON request
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';
    }
    
    switch ($action) {
        case 'add':
            if ($isFormData) {
                $trigger = $_POST['trigger'] ?? '';
                $response = $_POST['response'] ?? '';
                $buttons = $_POST['buttons'] ?? null;
                $match_type = $_POST['match_type'] ?? 'contains';
                $group_id = $_POST['group_id'] ?? null;
                $delete_after_seconds = $_POST['delete_after_seconds'] ?? 0;
                
                // Handle image upload
                $image_url = null;
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = '../uploads/auto_reply/';
                    
                    // Create directory if not exists
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    // Validate file
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
                    
                    // Generate unique filename
                    $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                    $filename = 'reply_' . time() . '_' . uniqid() . '.' . $extension;
                    $filepath = $uploadDir . $filename;
                    
                    // Move uploaded file
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $filepath)) {
                        $image_url = 'uploads/auto_reply/' . $filename;
                        error_log("Image uploaded successfully: " . $image_url);
                    } else {
                        error_log("Failed to move uploaded file");
                        jsonResponse(['success' => false, 'message' => '图片上传失败'], 500);
                    }
                }
            } else {
                $trigger = $data['trigger'] ?? '';
                $response = $data['response'] ?? '';
                $image_url = $data['image_url'] ?? null;
                $buttons = $data['buttons'] ?? null;
                $match_type = $data['match_type'] ?? 'contains';
                $group_id = $data['group_id'] ?? null;
                $delete_after_seconds = $data['delete_after_seconds'] ?? 0;
            }
            
            if (empty($trigger) || empty($response)) {
                jsonResponse(['success' => false, 'message' => '请填写所有必填字段'], 400);
            }
            
            try {
                error_log("Adding auto reply - trigger: $trigger, response: $response, image_url: " . ($image_url ?? 'null') . ", match_type: $match_type, group_id: " . ($group_id ?? 'null'));
                
                // 检查数据
                if (strlen($trigger) > 255) {
                    jsonResponse(['success' => false, 'message' => '触发词太长'], 400);
                }
                
                // 准备SQL
                $sql = "INSERT INTO auto_replies (`trigger`, response, image_url, buttons, match_type, group_id, delete_after_seconds, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
                error_log("SQL: " . $sql);
                
                // 执行插入
                $stmt = $db->prepare($sql);
                $result = $stmt->execute([$trigger, $response, $image_url, $buttons, $match_type, $group_id, $delete_after_seconds]);
                
                if ($result) {
                    error_log("Auto reply added successfully");
                    logSystem('info', 'Added auto reply', [
                        'trigger' => $trigger,
                        'match_type' => $match_type,
                        'group_id' => $group_id
                    ]);
                    jsonResponse(['success' => true, 'message' => '添加成功']);
                } else {
                    error_log("Failed to add auto reply");
                    throw new Exception("Insert failed");
                }
            } catch (PDOException $e) {
                error_log("Database error: " . $e->getMessage());
                logSystem('error', 'Add auto reply database error', [
                    'error' => $e->getMessage(),
                    'code' => $e->getCode()
                ]);
                jsonResponse(['success' => false, 'message' => '数据库错误：' . $e->getMessage()], 500);
            } catch (Exception $e) {
                error_log("General error: " . $e->getMessage());
                logSystem('error', 'Add auto reply error', [
                    'error' => $e->getMessage()
                ]);
                jsonResponse(['success' => false, 'message' => '添加失败：' . $e->getMessage()], 500);
            }
            break;
            
        case 'update':
            if ($isFormData) {
                $id = $_POST['id'] ?? 0;
                $trigger = $_POST['trigger'] ?? '';
                $response = $_POST['response'] ?? '';
                $buttons = $_POST['buttons'] ?? null;
                $match_type = $_POST['match_type'] ?? 'contains';
                $group_id = $_POST['group_id'] ?? null;
                $delete_after_seconds = $_POST['delete_after_seconds'] ?? 0;
                
                // Handle image upload (optional for update)
                $image_url = null;
                $updateImage = false;
                
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = dirname(__DIR__) . '/uploads/auto_reply/';
                    
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
                    $filename = 'reply_' . time() . '_' . uniqid() . '.' . $extension;
                    $filepath = $uploadDir . $filename;
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $filepath)) {
                        $image_url = 'uploads/auto_reply/' . $filename;
                        $updateImage = true;
                    } else {
                        jsonResponse(['success' => false, 'message' => '图片上传失败'], 500);
                    }
                }
            } else {
                $id = $data['id'] ?? 0;
                $trigger = $data['trigger'] ?? '';
                $response = $data['response'] ?? '';
                $image_url = $data['image_url'] ?? null;
                $buttons = $data['buttons'] ?? null;
                $match_type = $data['match_type'] ?? 'contains';
                $group_id = $data['group_id'] ?? null;
                $delete_after_seconds = $data['delete_after_seconds'] ?? 0;
                $updateImage = isset($data['image_url']);
            }
            
            if (!$id || empty($trigger) || empty($response)) {
                jsonResponse(['success' => false, 'message' => '请填写所有必填字段'], 400);
            }
            
            try {
                // Update auto reply
                if ($updateImage) {
                    $stmt = $db->prepare("UPDATE auto_replies SET `trigger` = ?, response = ?, image_url = ?, buttons = ?, match_type = ?, group_id = ?, delete_after_seconds = ? WHERE id = ?");
                    $stmt->execute([$trigger, $response, $image_url, $buttons, $match_type, $group_id, $delete_after_seconds, $id]);
                } else {
                    $stmt = $db->prepare("UPDATE auto_replies SET `trigger` = ?, response = ?, buttons = ?, match_type = ?, group_id = ?, delete_after_seconds = ? WHERE id = ?");
                    $stmt->execute([$trigger, $response, $buttons, $match_type, $group_id, $delete_after_seconds, $id]);
                }
                
                logSystem('info', 'Updated auto reply', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '更新成功']);
            } catch (Exception $e) {
                error_log("Update auto reply error: " . $e->getMessage());
                logSystem('error', 'Update auto reply error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '更新失败: ' . $e->getMessage()], 500);
            }
            break;
            
        case 'delete':
            $id = $data['id'] ?? 0;
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            try {
                $stmt = $db->prepare("DELETE FROM auto_replies WHERE id = ?");
                $stmt->execute([$id]);
                
                logSystem('info', 'Deleted auto reply', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '删除成功']);
            } catch (Exception $e) {
                logSystem('error', 'Delete auto reply error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '删除失败'], 500);
            }
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
    }
}

