<?php
/**
 * 自动广告管理 API
 */

require_once '../config.php';
checkLogin();

$db = getDB();

// 确保分类表存在
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS group_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            color VARCHAR(20) DEFAULT '#3498db',
            sort_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // 检查groups表是否有category_id字段，没有则添加
    $stmt = $db->query("SHOW COLUMNS FROM groups LIKE 'category_id'");
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE groups ADD COLUMN category_id INT DEFAULT NULL");
        $db->exec("ALTER TABLE groups ADD INDEX idx_category_id (category_id)");
    }
} catch (Exception $e) {
    error_log("Init group_categories table error: " . $e->getMessage());
}

/**
 * 解析关键词（支持空格、逗号、换行、#号分隔，保留#号前缀）
 * @param string $text 原始输入文本
 * @return string 处理后的关键词JSON数组
 */
function parseKeywords($text) {
    if (empty($text) || !trim($text)) return json_encode([]);
    
    // 在 # 前面加空格（处理 #标签1#标签2 这种格式，但保留#号）
    $text = preg_replace('/#/', ' #', $text);
    
    // 将逗号、中文逗号、换行替换为空格，然后按空格分割
    $keywords = preg_split('/[\s,，\n\r]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    
    // 去重
    $keywords = array_values(array_unique($keywords));
    
    error_log("parseKeywords: parsed " . count($keywords) . " keywords");
    
    // 返回JSON数组
    return json_encode($keywords, JSON_UNESCAPED_UNICODE);
}

// GET 请求 - 列表或获取单个
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    if ($action == 'list') {
        try {
            $stmt = $db->query("
                SELECT aa.*, 
                    COALESCE(g.title, '所有群组') as group_title,
                    gc.name as category_name,
                    gc.color as category_color
                FROM auto_ads aa 
                LEFT JOIN groups g ON aa.group_id = g.id 
                LEFT JOIN group_categories gc ON g.category_id = gc.id
                ORDER BY aa.id DESC
            ");
            $ads = $stmt->fetchAll();
            
            jsonResponse(['success' => true, 'data' => $ads]);
        } catch (Exception $e) {
            logSystem('error', 'Fetch auto ads error', $e->getMessage());
            jsonResponse(['success' => false, 'message' => '获取失败'], 500);
        }
    } elseif ($action == 'get') {
        $id = $_GET['id'] ?? 0;
        
        if (!$id) {
            jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
        }
        
        try {
            $stmt = $db->prepare("SELECT * FROM auto_ads WHERE id = ?");
            $stmt->execute([$id]);
            $ad = $stmt->fetch();
            
            if (!$ad) {
                jsonResponse(['success' => false, 'message' => '广告不存在'], 404);
            }
            
            jsonResponse(['success' => true, 'data' => $ad]);
        } catch (Exception $e) {
            logSystem('error', 'Fetch auto ad error', $e->getMessage());
            jsonResponse(['success' => false, 'message' => '获取失败'], 500);
        }
    }
}

// POST 请求 - 添加/删除/更新
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Check if it's a FormData request (has POST data) or JSON request
    $isFormData = !empty($_POST);
    
    if ($isFormData) {
        $action = $_POST['action'] ?? '';
        error_log("FormData request - action: " . $action);
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';
        error_log("JSON request - action: " . $action);
    }
    
    switch ($action) {
        case 'add':
            if ($isFormData) {
                // 支持多群组选择
                $group_ids_json = $_POST['group_ids'] ?? null;
                $group_ids = $group_ids_json ? json_decode($group_ids_json, true) : [];
                
                // 兼容旧版本单群组选择
                if (empty($group_ids) && isset($_POST['group_id'])) {
                    $group_ids = [$_POST['group_id']];
                }
                
                $message = $_POST['message'] ?? '';
                $keywords = parseKeywords($_POST['keywords'] ?? '');
                $keywords_per_send = intval($_POST['keywords_per_send'] ?? 3);
                $interval_minutes = $_POST['interval_minutes'] ?? 60;
                $delete_after_seconds = $_POST['delete_after_seconds'] ?? 0;
                $use_user_account = $_POST['use_user_account'] ?? 0;
                $buttons = $_POST['buttons'] ?? null;
                
                // Handle image upload
                $image_url = null;
                error_log("Checking file upload - FILES: " . print_r($_FILES, true));
                
                if (isset($_FILES['image'])) {
                    error_log("Image file found, error code: " . $_FILES['image']['error']);
                    
                    if ($_FILES['image']['error'] === UPLOAD_ERR_OK) {
                        $uploadDir = dirname(__DIR__) . '/uploads/auto_ads/';
                        error_log("Upload directory: " . $uploadDir);
                        
                        if (!file_exists($uploadDir)) {
                            error_log("Creating upload directory...");
                            if (!mkdir($uploadDir, 0755, true)) {
                                error_log("Failed to create directory");
                                jsonResponse(['success' => false, 'message' => '无法创建上传目录'], 500);
                            }
                        }
                        
                        // Check if directory is writable
                        if (!is_writable($uploadDir)) {
                            error_log("Directory is not writable: " . $uploadDir);
                            jsonResponse(['success' => false, 'message' => '上传目录不可写'], 500);
                        }
                        
                        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        $fileType = $_FILES['image']['type'];
                        $fileSize = $_FILES['image']['size'];
                        
                        error_log("File type: " . $fileType . ", size: " . $fileSize);
                        
                        if (!in_array($fileType, $allowedTypes)) {
                            error_log("Invalid file type: " . $fileType);
                            jsonResponse(['success' => false, 'message' => '不支持的图片格式: ' . $fileType], 400);
                        }
                        
                        // GIF文件最大50MB，其他格式最大5MB
                        $maxSize = ($fileType === 'image/gif') ? 50 * 1024 * 1024 : 5 * 1024 * 1024;
                        $maxSizeText = ($fileType === 'image/gif') ? '50MB' : '5MB';
                        
                        if ($fileSize > $maxSize) {
                            error_log("File too large: " . $fileSize);
                            jsonResponse(['success' => false, 'message' => '图片大小不能超过 ' . $maxSizeText], 400);
                        }
                        
                        $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                        $filename = 'ad_' . time() . '_' . uniqid() . '.' . $extension;
                        $filepath = $uploadDir . $filename;
                        
                        error_log("Attempting to move file to: " . $filepath);
                        error_log("Temp file: " . $_FILES['image']['tmp_name']);
                        
                        if (move_uploaded_file($_FILES['image']['tmp_name'], $filepath)) {
                            $image_url = 'uploads/auto_ads/' . $filename;
                            error_log("File uploaded successfully: " . $image_url);
                        } else {
                            $error = error_get_last();
                            error_log("Failed to move uploaded file. Last error: " . print_r($error, true));
                            jsonResponse(['success' => false, 'message' => '图片上传失败，请检查目录权限'], 500);
                        }
                    } else {
                        $uploadErrors = [
                            UPLOAD_ERR_INI_SIZE => '文件大小超过 php.ini 限制',
                            UPLOAD_ERR_FORM_SIZE => '文件大小超过表单限制',
                            UPLOAD_ERR_PARTIAL => '文件只上传了一部分',
                            UPLOAD_ERR_NO_FILE => '没有文件被上传',
                            UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
                            UPLOAD_ERR_CANT_WRITE => '文件写入失败',
                            UPLOAD_ERR_EXTENSION => 'PHP扩展停止了文件上传'
                        ];
                        $errorMsg = $uploadErrors[$_FILES['image']['error']] ?? '未知上传错误';
                        error_log("Upload error: " . $errorMsg);
                        jsonResponse(['success' => false, 'message' => '上传错误: ' . $errorMsg], 400);
                    }
                } else {
                    error_log("No image file in upload");
                }
            } else {
                $group_ids = $data['group_ids'] ?? [];
                $message = $data['message'] ?? '';
                $keywords = parseKeywords($data['keywords'] ?? '');
                $keywords_per_send = intval($data['keywords_per_send'] ?? 3);
                $image_url = $data['image_url'] ?? null;
                $buttons = $data['buttons'] ?? null;
                $interval_minutes = $data['interval_minutes'] ?? 60;
                $delete_after_seconds = $data['delete_after_seconds'] ?? 0;
                $use_user_account = $data['use_user_account'] ?? 0;
            }
            
            if (empty($message) || empty($group_ids)) {
                jsonResponse(['success' => false, 'message' => '请填写所有必填字段'], 400);
            }
            
            try {
                // 为每个选中的群组创建一条广告记录
                $inserted_count = 0;
                foreach ($group_ids as $group_id) {
                    // Convert 0 to NULL for "all groups"
                    $final_group_id = ($group_id == '0' || $group_id == 0) ? null : $group_id;
                    
                    $stmt = $db->prepare("INSERT INTO auto_ads (group_id, message, keywords, keywords_per_send, keywords_index, image_url, buttons, interval_minutes, delete_after_seconds, use_user_account) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?)");
                    $stmt->execute([$final_group_id, $message, $keywords, $keywords_per_send, $image_url, $buttons, $interval_minutes, $delete_after_seconds, $use_user_account]);
                    $inserted_count++;
                }
                
                logSystem('info', 'Added auto ads', ['group_ids' => $group_ids, 'count' => $inserted_count]);
                jsonResponse(['success' => true, 'message' => "添加成功，共创建 {$inserted_count} 条广告"]);
            } catch (Exception $e) {
                logSystem('error', 'Add auto ad error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '添加失败'], 500);
            }
            break;
            
        case 'update':
            if ($isFormData) {
                $id = $_POST['id'] ?? 0;
                $group_id = $_POST['group_id'] ?? 0;
                $message = $_POST['message'] ?? '';
                $keywords = parseKeywords($_POST['keywords'] ?? '');
                $keywords_per_send = intval($_POST['keywords_per_send'] ?? 3);
                $interval_minutes = $_POST['interval_minutes'] ?? 60;
                $delete_after_seconds = $_POST['delete_after_seconds'] ?? 0;
                $use_user_account = $_POST['use_user_account'] ?? 0;
                $buttons = $_POST['buttons'] ?? null;
                
                // Handle image upload (optional for update)
                $image_url = null;
                $updateImage = false;
                
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = dirname(__DIR__) . '/uploads/auto_ads/';
                    
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
                    $filename = 'ad_' . time() . '_' . uniqid() . '.' . $extension;
                    $filepath = $uploadDir . $filename;
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $filepath)) {
                        $image_url = 'uploads/auto_ads/' . $filename;
                        $updateImage = true;
                    } else {
                        jsonResponse(['success' => false, 'message' => '图片上传失败'], 500);
                    }
                }
            } else {
                $id = $data['id'] ?? 0;
                $group_id = $data['group_id'] ?? 0;
                $message = $data['message'] ?? '';
                $keywords = parseKeywords($data['keywords'] ?? '');
                $keywords_per_send = intval($data['keywords_per_send'] ?? 3);
                $interval_minutes = $data['interval_minutes'] ?? 60;
                $delete_after_seconds = $data['delete_after_seconds'] ?? 0;
                $use_user_account = $data['use_user_account'] ?? 0;
                $image_url = $data['image_url'] ?? null;
                $buttons = $data['buttons'] ?? null;
                $updateImage = isset($data['image_url']);
            }
            
            if (!$id || empty($message)) {
                jsonResponse(['success' => false, 'message' => '请填写所有必填字段'], 400);
            }
            
            // Convert 0 to NULL for "all groups"
            $final_group_id = ($group_id == 0) ? null : $group_id;
            
            try {
                // Build update query - 更新关键词时重置索引为0
                if ($updateImage) {
                    $stmt = $db->prepare("UPDATE auto_ads SET group_id = ?, message = ?, keywords = ?, keywords_per_send = ?, keywords_index = 0, image_url = ?, buttons = ?, interval_minutes = ?, delete_after_seconds = ?, use_user_account = ? WHERE id = ?");
                    $stmt->execute([$final_group_id, $message, $keywords, $keywords_per_send, $image_url, $buttons, $interval_minutes, $delete_after_seconds, $use_user_account, $id]);
                } else {
                    $stmt = $db->prepare("UPDATE auto_ads SET group_id = ?, message = ?, keywords = ?, keywords_per_send = ?, keywords_index = 0, buttons = ?, interval_minutes = ?, delete_after_seconds = ?, use_user_account = ? WHERE id = ?");
                    $stmt->execute([$final_group_id, $message, $keywords, $keywords_per_send, $buttons, $interval_minutes, $delete_after_seconds, $use_user_account, $id]);
                }
                
                logSystem('info', 'Updated auto ad', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '更新成功']);
            } catch (Exception $e) {
                logSystem('error', 'Update auto ad error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '更新失败: ' . $e->getMessage()], 500);
            }
            break;
            
        case 'delete':
            $id = $data['id'] ?? 0;
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            try {
                $stmt = $db->prepare("DELETE FROM auto_ads WHERE id = ?");
                $stmt->execute([$id]);
                
                logSystem('info', 'Deleted auto ad', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '删除成功']);
            } catch (Exception $e) {
                logSystem('error', 'Delete auto ad error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '删除失败'], 500);
            }
            break;
            
        case 'toggle':
            $id = $data['id'] ?? 0;
            $is_active = $data['is_active'] ?? 1;
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            try {
                $stmt = $db->prepare("UPDATE auto_ads SET is_active = ? WHERE id = ?");
                $stmt->execute([$is_active, $id]);
                
                jsonResponse(['success' => true, 'message' => '状态更新成功']);
            } catch (Exception $e) {
                logSystem('error', 'Toggle auto ad error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '更新失败'], 500);
            }
            break;
            
        default:
            error_log("Invalid action received: " . $action);
            error_log("POST data: " . print_r($_POST, true));
            error_log("FILES data: " . print_r($_FILES, true));
            jsonResponse(['success' => false, 'message' => '无效的操作: ' . $action], 400);
    }
}

