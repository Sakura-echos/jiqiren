<?php
/**
 * 自动广告模板（循环广告）管理 API
 */

require_once '../config.php';
checkLogin();

$db = getDB();

// GET 请求 - 列表或获取单个
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    if ($action == 'list') {
        try {
            $stmt = $db->query("
                SELECT 
                    t.*, 
                    COALESCE(g.title, '所有群组') as group_title,
                    COUNT(a.id) as ad_count
                FROM auto_ad_templates t
                LEFT JOIN groups g ON t.group_id = g.id 
                LEFT JOIN auto_ads a ON t.id = a.template_id
                GROUP BY t.id
                ORDER BY t.id DESC
            ");
            $templates = $stmt->fetchAll();
            
            jsonResponse(['success' => true, 'data' => $templates]);
        } catch (Exception $e) {
            logSystem('error', 'Fetch templates error', $e->getMessage());
            jsonResponse(['success' => false, 'message' => '获取失败'], 500);
        }
    } elseif ($action == 'get') {
        $id = $_GET['id'] ?? 0;
        
        if (!$id) {
            jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
        }
        
        try {
            // 获取模板信息
            $stmt = $db->prepare("SELECT * FROM auto_ad_templates WHERE id = ?");
            $stmt->execute([$id]);
            $template = $stmt->fetch();
            
            if (!$template) {
                jsonResponse(['success' => false, 'message' => '模板不存在'], 404);
            }
            
            // 获取模板下的所有广告
            $stmt = $db->prepare("SELECT * FROM auto_ads WHERE template_id = ? ORDER BY sequence_order ASC");
            $stmt->execute([$id]);
            $ads = $stmt->fetchAll();
            
            $template['ads'] = $ads;
            
            jsonResponse(['success' => true, 'data' => $template]);
        } catch (Exception $e) {
            logSystem('error', 'Fetch template error', $e->getMessage());
            jsonResponse(['success' => false, 'message' => '获取失败'], 500);
        }
    }
}

// POST 请求 - 添加/删除/更新
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Check if it's a file upload request or JSON request
    $isFileUpload = !empty($_FILES);
    
    if ($isFileUpload) {
        $action = $_POST['action'] ?? '';
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';
    }
    
    switch ($action) {
        case 'add':
            $template_name = $data['template_name'] ?? '';
            $group_id = $data['group_id'] ?? 0;
            $interval_minutes = $data['interval_minutes'] ?? 60;
            $cycle_interval_minutes = $data['cycle_interval_minutes'] ?? 0;
            $use_user_account = $data['use_user_account'] ?? 0;
            $ads = $data['ads'] ?? [];
            
            if (empty($template_name) || empty($ads)) {
                jsonResponse(['success' => false, 'message' => '请填写模板名称并添加至少一条广告'], 400);
            }
            
            // Convert 0 to NULL for "all groups"
            $final_group_id = ($group_id == 0) ? null : $group_id;
            
            try {
                $db->beginTransaction();
                
                // 创建模板
                $stmt = $db->prepare("INSERT INTO auto_ad_templates (template_name, group_id, interval_minutes, cycle_interval_minutes, use_user_account) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$template_name, $final_group_id, $interval_minutes, $cycle_interval_minutes, $use_user_account]);
                $template_id = $db->lastInsertId();
                
                // 添加广告
                $order = 1;
                foreach ($ads as $ad) {
                    $stmt = $db->prepare("
                        INSERT INTO auto_ads (template_id, group_id, sequence_order, message, image_url, buttons, interval_minutes, delete_after_seconds) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $template_id,
                        $final_group_id,
                        $order,
                        $ad['message'] ?? '',
                        $ad['image_url'] ?? null,
                        $ad['buttons'] ?? null,
                        $interval_minutes,
                        $ad['delete_after_seconds'] ?? 0
                    ]);
                    $order++;
                }
                
                $db->commit();
                
                logSystem('info', 'Added auto ad template', ['template_id' => $template_id]);
                jsonResponse(['success' => true, 'message' => '添加成功', 'template_id' => $template_id]);
            } catch (Exception $e) {
                $db->rollBack();
                logSystem('error', 'Add template error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '添加失败：' . $e->getMessage()], 500);
            }
            break;
            
        case 'update':
            $id = $data['id'] ?? 0;
            $template_name = $data['template_name'] ?? '';
            $group_id = $data['group_id'] ?? 0;
            $interval_minutes = $data['interval_minutes'] ?? 60;
            $cycle_interval_minutes = $data['cycle_interval_minutes'] ?? 0;
            $use_user_account = $data['use_user_account'] ?? 0;
            $ads = $data['ads'] ?? [];
            
            if (!$id || empty($template_name) || empty($ads)) {
                jsonResponse(['success' => false, 'message' => '请填写所有必填字段'], 400);
            }
            
            $final_group_id = ($group_id == 0) ? null : $group_id;
            
            try {
                $db->beginTransaction();
                
                // 更新模板
                $stmt = $db->prepare("UPDATE auto_ad_templates SET template_name = ?, group_id = ?, interval_minutes = ?, cycle_interval_minutes = ?, use_user_account = ? WHERE id = ?");
                $stmt->execute([$template_name, $final_group_id, $interval_minutes, $cycle_interval_minutes, $use_user_account, $id]);
                
                // 删除旧的广告
                $stmt = $db->prepare("DELETE FROM auto_ads WHERE template_id = ?");
                $stmt->execute([$id]);
                
                // 添加新的广告
                $order = 1;
                foreach ($ads as $ad) {
                    $stmt = $db->prepare("
                        INSERT INTO auto_ads (template_id, group_id, sequence_order, message, image_url, buttons, interval_minutes, delete_after_seconds) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $id,
                        $final_group_id,
                        $order,
                        $ad['message'] ?? '',
                        $ad['image_url'] ?? null,
                        $ad['buttons'] ?? null,
                        $interval_minutes,
                        $ad['delete_after_seconds'] ?? 0
                    ]);
                    $order++;
                }
                
                $db->commit();
                
                logSystem('info', 'Updated auto ad template', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '更新成功']);
            } catch (Exception $e) {
                $db->rollBack();
                logSystem('error', 'Update template error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '更新失败：' . $e->getMessage()], 500);
            }
            break;
            
        case 'delete':
            $id = $data['id'] ?? 0;
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            try {
                $db->beginTransaction();
                
                // 删除模板下的所有广告
                $stmt = $db->prepare("DELETE FROM auto_ads WHERE template_id = ?");
                $stmt->execute([$id]);
                
                // 删除模板
                $stmt = $db->prepare("DELETE FROM auto_ad_templates WHERE id = ?");
                $stmt->execute([$id]);
                
                $db->commit();
                
                logSystem('info', 'Deleted auto ad template', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '删除成功']);
            } catch (Exception $e) {
                $db->rollBack();
                logSystem('error', 'Delete template error', $e->getMessage());
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
                // 更新模板状态
                $stmt = $db->prepare("UPDATE auto_ad_templates SET is_active = ? WHERE id = ?");
                $stmt->execute([$is_active, $id]);
                
                // 同时更新模板下所有广告的状态
                $stmt = $db->prepare("UPDATE auto_ads SET is_active = ? WHERE template_id = ?");
                $stmt->execute([$is_active, $id]);
                
                jsonResponse(['success' => true, 'message' => '状态更新成功']);
            } catch (Exception $e) {
                logSystem('error', 'Toggle template error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '更新失败'], 500);
            }
            break;
            
        case 'upload_image':
            // 处理图片上传（用于模板广告）
            if (!isset($_FILES['image'])) {
                jsonResponse(['success' => false, 'message' => '未选择图片'], 400);
            }
            
            if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
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
                jsonResponse(['success' => false, 'message' => $errorMsg], 400);
            }
            
            $uploadDir = dirname(__DIR__) . '/uploads/auto_ads/';
            
            if (!file_exists($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    jsonResponse(['success' => false, 'message' => '无法创建上传目录'], 500);
                }
            }
            
            if (!is_writable($uploadDir)) {
                jsonResponse(['success' => false, 'message' => '上传目录不可写'], 500);
            }
            
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = $_FILES['image']['type'];
            $fileSize = $_FILES['image']['size'];
            
            if (!in_array($fileType, $allowedTypes)) {
                jsonResponse(['success' => false, 'message' => '不支持的图片格式：' . $fileType], 400);
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
                logSystem('info', 'Template image uploaded', ['filename' => $filename]);
                jsonResponse(['success' => true, 'image_url' => $image_url]);
            } else {
                logSystem('error', 'Template image upload failed', ['error' => error_get_last()]);
                jsonResponse(['success' => false, 'message' => '图片上传失败'], 500);
            }
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
    }
}

