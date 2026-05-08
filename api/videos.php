<?php
require_once '../config.php';
checkLogin();

$db = getDB();

// 确保采集源表存在
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS video_collect_sources (
            id INT AUTO_INCREMENT PRIMARY KEY,
            chat_id BIGINT NOT NULL,
            chat_title VARCHAR(255),
            chat_type VARCHAR(50) DEFAULT 'supergroup',
            category_id INT DEFAULT NULL,
            default_keywords VARCHAR(500) DEFAULT '',
            is_active TINYINT(1) DEFAULT 1,
            auto_forward TINYINT(1) DEFAULT 0,
            forward_to_chat_id BIGINT DEFAULT NULL,
            forward_to_category_id INT DEFAULT NULL,
            collected_count INT DEFAULT 0,
            last_collected_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_chat_id (chat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // 检查是否有 forward_to_category_id 字段
    $stmt = $db->query("SHOW COLUMNS FROM video_collect_sources LIKE 'forward_to_category_id'");
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE video_collect_sources ADD COLUMN forward_to_category_id INT DEFAULT NULL AFTER forward_to_chat_id");
    }
    
    // 检查videos表是否有source_chat_id字段
    $stmt = $db->query("SHOW COLUMNS FROM videos LIKE 'source_chat_id'");
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE videos ADD COLUMN source_chat_id BIGINT DEFAULT NULL");
        $db->exec("ALTER TABLE videos ADD COLUMN source_message_id BIGINT DEFAULT NULL");
        $db->exec("ALTER TABLE videos ADD COLUMN telegram_file_id VARCHAR(255) DEFAULT NULL");
        $db->exec("ALTER TABLE videos ADD COLUMN message_link VARCHAR(500) DEFAULT NULL");
    }
} catch (Exception $e) {
    error_log("Init video_collect_sources table error: " . $e->getMessage());
}

// 解析关键词
function parseKeywords($input) {
    if (empty($input)) return '';
    $keywords = preg_replace('/[\s,，\n\r]+/', ',', trim($input));
    $arr = array_filter(array_map('trim', explode(',', $keywords)));
    return implode(',', array_unique($arr));
}

// 获取action
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (empty($action)) {
    $input = file_get_contents('php://input');
    if ($input) {
        $jsonData = json_decode($input, true);
        if ($jsonData && isset($jsonData['action'])) {
            $action = $jsonData['action'];
        }
    }
}

$isFormData = !empty($_FILES) || (empty($_SERVER['CONTENT_TYPE']) || strpos($_SERVER['CONTENT_TYPE'], 'application/json') === false);

switch ($action) {
    // ==================== 视频管理 ====================
    case 'list':
        try {
            $categoryId = $_GET['category_id'] ?? null;
            
            $sql = "SELECT v.*, c.name as category_name 
                    FROM videos v 
                    LEFT JOIN video_categories c ON v.category_id = c.id 
                    WHERE 1=1";
            $params = [];
            
            if ($categoryId) {
                $sql .= " AND v.category_id = ?";
                $params[] = $categoryId;
            }
            
            $sql .= " ORDER BY v.created_at DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $videos = $stmt->fetchAll();
            
            jsonResponse(['success' => true, 'data' => $videos]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '获取失败: ' . $e->getMessage()], 500);
        }
        break;
        
    case 'add':
        try {
            if ($isFormData) {
                $categoryId = $_POST['category_id'] ?? null;
                $title = $_POST['title'] ?? '';
                $caption = $_POST['caption'] ?? '';
                $keywords = $_POST['keywords'] ?? '';
                $uploadType = $_POST['upload_type'] ?? 'file';
            } else {
                $data = json_decode(file_get_contents('php://input'), true);
                $categoryId = $data['category_id'] ?? null;
                $title = $data['title'] ?? '';
                $caption = $data['caption'] ?? '';
                $keywords = $data['keywords'] ?? '';
                $uploadType = $data['upload_type'] ?? 'file';
            }
            
            if (empty($title)) {
                jsonResponse(['success' => false, 'message' => '请填写视频名称'], 400);
            }
            
            $keywords = parseKeywords($keywords);
            $videoPath = null;
            $fileSize = 0;
            
            // 处理视频上传
            if ($uploadType === 'file' && !empty($_FILES['video'])) {
                $file = $_FILES['video'];
                
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    jsonResponse(['success' => false, 'message' => '文件上传失败'], 400);
                }
                
                if ($file['size'] > 50 * 1024 * 1024) {
                    jsonResponse(['success' => false, 'message' => '文件大小不能超过50MB'], 400);
                }
                
                $uploadDir = __DIR__ . '/../uploads/videos/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = uniqid('video_') . '_' . time() . '.' . $extension;
                $targetPath = $uploadDir . $filename;
                
                if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                    jsonResponse(['success' => false, 'message' => '文件保存失败'], 500);
                }
                
                $videoPath = 'uploads/videos/' . $filename;
                $fileSize = $file['size'];
                
            } elseif ($uploadType === 'url') {
                $videoUrl = $_POST['video_url'] ?? '';
                if (empty($videoUrl)) {
                    jsonResponse(['success' => false, 'message' => '请输入视频链接'], 400);
                }
                $videoPath = $videoUrl;
            }
            
            // 插入数据库
            $stmt = $db->prepare("
                INSERT INTO videos (category_id, title, caption, keywords, video_path, file_size)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([$categoryId, $title, $caption, $keywords, $videoPath, $fileSize]);
            
            jsonResponse(['success' => true, 'message' => '添加成功', 'data' => ['id' => $db->lastInsertId()]]);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '添加失败：' . $e->getMessage()], 500);
        }
        break;
        
    case 'update':
        try {
            $id = $_POST['id'] ?? 0;
            $categoryId = $_POST['category_id'] ?? null;
            $title = $_POST['title'] ?? '';
            $caption = $_POST['caption'] ?? '';
            $keywords = parseKeywords($_POST['keywords'] ?? '');
            $changeVideo = isset($_POST['change_video']) && $_POST['change_video'] == '1';
            
            if (!$id || empty($title)) {
                jsonResponse(['success' => false, 'message' => '参数错误'], 400);
            }
            
            // 获取原视频
            $stmt = $db->prepare("SELECT * FROM videos WHERE id = ?");
            $stmt->execute([$id]);
            $oldVideo = $stmt->fetch();
            
            if (!$oldVideo) {
                jsonResponse(['success' => false, 'message' => '视频不存在'], 404);
            }
            
            $videoPath = $oldVideo['video_path'];
            $fileSize = $oldVideo['file_size'];
            
            // 更换视频
            if ($changeVideo) {
                $uploadType = $_POST['upload_type'] ?? 'file';
                
                if ($uploadType === 'file' && !empty($_FILES['video'])) {
                    $file = $_FILES['video'];
                    
                    if ($file['error'] === UPLOAD_ERR_OK) {
                        $uploadDir = __DIR__ . '/../uploads/videos/';
                        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
                        
                        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                        $filename = uniqid('video_') . '_' . time() . '.' . $extension;
                        $targetPath = $uploadDir . $filename;
                        
                        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                            // 删除旧文件
                            if ($oldVideo['video_path'] && !preg_match('/^https?:\/\//', $oldVideo['video_path'])) {
                                $oldPath = __DIR__ . '/../' . $oldVideo['video_path'];
                                if (file_exists($oldPath)) @unlink($oldPath);
                            }
                            
                            $videoPath = 'uploads/videos/' . $filename;
                            $fileSize = $file['size'];
                        }
                    }
                } elseif ($uploadType === 'url') {
                    $videoUrl = $_POST['video_url'] ?? '';
                    if (!empty($videoUrl)) {
                        $videoPath = $videoUrl;
                        $fileSize = 0;
                    }
                }
            }
            
            $stmt = $db->prepare("
                UPDATE videos SET 
                    category_id = ?, title = ?, caption = ?, keywords = ?,
                    video_path = ?, file_size = ?, telegram_file_id = NULL, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$categoryId, $title, $caption, $keywords, $videoPath, $fileSize, $id]);
            
            jsonResponse(['success' => true, 'message' => '更新成功']);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '更新失败：' . $e->getMessage()], 500);
        }
        break;
        
    case 'delete':
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? 0;
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => '无效的视频ID'], 400);
            }
            
            // 删除文件
            $stmt = $db->prepare("SELECT video_path FROM videos WHERE id = ?");
            $stmt->execute([$id]);
            $video = $stmt->fetch();
            
            if ($video && $video['video_path'] && !preg_match('/^https?:\/\//', $video['video_path'])) {
                $filePath = __DIR__ . '/../' . $video['video_path'];
                if (file_exists($filePath)) @unlink($filePath);
            }
            
            $stmt = $db->prepare("DELETE FROM videos WHERE id = ?");
            $stmt->execute([$id]);
            
            jsonResponse(['success' => true, 'message' => '删除成功']);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '删除失败'], 500);
        }
        break;
        
    case 'batch_delete':
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $ids = $data['ids'] ?? [];
            
            if (empty($ids)) {
                jsonResponse(['success' => false, 'message' => '请选择要删除的视频'], 400);
            }
            
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            // 删除文件
            $stmt = $db->prepare("SELECT video_path FROM videos WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $videos = $stmt->fetchAll();
            
            foreach ($videos as $video) {
                if ($video['video_path'] && !preg_match('/^https?:\/\//', $video['video_path'])) {
                    $filePath = __DIR__ . '/../' . $video['video_path'];
                    if (file_exists($filePath)) @unlink($filePath);
                }
            }
            
            $stmt = $db->prepare("DELETE FROM videos WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            
            jsonResponse(['success' => true, 'message' => '批量删除成功']);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '删除失败'], 500);
        }
        break;
        
    case 'toggle':
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? 0;
            $isActive = $data['is_active'] ?? 0;
            
            $stmt = $db->prepare("UPDATE videos SET is_active = ? WHERE id = ?");
            $stmt->execute([$isActive, $id]);
            
            jsonResponse(['success' => true, 'message' => '状态更新成功']);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '更新失败'], 500);
        }
        break;
        
    case 'get':
        try {
            $id = $_GET['id'] ?? 0;
            
            $stmt = $db->prepare("
                SELECT v.*, c.name as category_name 
                FROM videos v 
                LEFT JOIN video_categories c ON v.category_id = c.id 
                WHERE v.id = ?
            ");
            $stmt->execute([$id]);
            $video = $stmt->fetch();
            
            if (!$video) {
                jsonResponse(['success' => false, 'message' => '视频不存在'], 404);
            }
            
            jsonResponse(['success' => true, 'data' => $video]);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '获取失败'], 500);
        }
        break;
        
    // ==================== 分类管理 ====================
    case 'list_categories':
        try {
            $stmt = $db->query("
                SELECT c.*, COUNT(v.id) as video_count 
                FROM video_categories c 
                LEFT JOIN videos v ON v.category_id = c.id 
                GROUP BY c.id 
                ORDER BY c.sort_order, c.name
            ");
            $categories = $stmt->fetchAll();
            
            jsonResponse(['success' => true, 'data' => $categories]);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '获取分类失败'], 500);
        }
        break;
        
    case 'add_category':
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $name = $data['name'] ?? '';
            $description = $data['description'] ?? '';
            $sortOrder = $data['sort_order'] ?? 0;
            
            if (empty($name)) {
                jsonResponse(['success' => false, 'message' => '请填写分类名称'], 400);
            }
            
            $stmt = $db->prepare("INSERT INTO video_categories (name, description, sort_order) VALUES (?, ?, ?)");
            $stmt->execute([$name, $description, $sortOrder]);
            
            jsonResponse(['success' => true, 'message' => '添加成功', 'data' => ['id' => $db->lastInsertId()]]);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '添加失败：' . $e->getMessage()], 500);
        }
        break;
        
    case 'get_category':
        try {
            $id = $_GET['id'] ?? 0;
            
            $stmt = $db->prepare("SELECT * FROM video_categories WHERE id = ?");
            $stmt->execute([$id]);
            $category = $stmt->fetch();
            
            if (!$category) {
                jsonResponse(['success' => false, 'message' => '分类不存在'], 404);
            }
            
            jsonResponse(['success' => true, 'data' => $category]);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '获取失败'], 500);
        }
        break;
        
    case 'update_category':
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? 0;
            $name = $data['name'] ?? '';
            $description = $data['description'] ?? '';
            $sortOrder = $data['sort_order'] ?? 0;
            
            if (!$id || empty($name)) {
                jsonResponse(['success' => false, 'message' => '参数错误'], 400);
            }
            
            $stmt = $db->prepare("UPDATE video_categories SET name = ?, description = ?, sort_order = ? WHERE id = ?");
            $stmt->execute([$name, $description, $sortOrder, $id]);
            
            jsonResponse(['success' => true, 'message' => '更新成功']);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '更新失败'], 500);
        }
        break;
        
    case 'delete_category':
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? 0;
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => '无效的分类ID'], 400);
            }
            
            // 将该分类下的视频设为未分类
            $stmt = $db->prepare("UPDATE videos SET category_id = NULL WHERE category_id = ?");
            $stmt->execute([$id]);
            
            $stmt = $db->prepare("DELETE FROM video_categories WHERE id = ?");
            $stmt->execute([$id]);
            
            jsonResponse(['success' => true, 'message' => '删除成功']);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '删除失败'], 500);
        }
        break;
        
    // ==================== 采集源管理 ====================
    case 'list_sources':
        try {
            $stmt = $db->query("
                SELECT s.*, c.name as category_name, 
                    g.title as forward_to_title,
                    gc.name as forward_to_category_name,
                    gc.color as forward_to_category_color
                FROM video_collect_sources s 
                LEFT JOIN video_categories c ON s.category_id = c.id
                LEFT JOIN groups g ON s.forward_to_chat_id = g.chat_id
                LEFT JOIN group_categories gc ON s.forward_to_category_id = gc.id
                ORDER BY s.created_at DESC
            ");
            $sources = $stmt->fetchAll();
            
            jsonResponse(['success' => true, 'data' => $sources]);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '获取采集源失败: ' . $e->getMessage()], 500);
        }
        break;
        
    case 'add_source':
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $chatId = $data['chat_id'] ?? '';
            $chatTitle = $data['chat_title'] ?? '';
            $categoryId = $data['category_id'] ?? null;
            $defaultKeywords = $data['default_keywords'] ?? '';
            $autoForward = $data['auto_forward'] ?? 0;
            $forwardToChatId = $data['forward_to_chat_id'] ?? null;
            $forwardToCategoryId = $data['forward_to_category_id'] ?? null;
            
            if (empty($chatId)) {
                jsonResponse(['success' => false, 'message' => '请输入群组/频道ID'], 400);
            }
            
            // 检查是否已存在
            $stmt = $db->prepare("SELECT id FROM video_collect_sources WHERE chat_id = ?");
            $stmt->execute([$chatId]);
            if ($stmt->fetch()) {
                jsonResponse(['success' => false, 'message' => '该采集源已存在'], 400);
            }
            
            $stmt = $db->prepare("
                INSERT INTO video_collect_sources 
                (chat_id, chat_title, category_id, default_keywords, auto_forward, forward_to_chat_id, forward_to_category_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$chatId, $chatTitle, $categoryId ?: null, $defaultKeywords, $autoForward, $forwardToChatId ?: null, $forwardToCategoryId ?: null]);
            
            jsonResponse(['success' => true, 'message' => '添加成功', 'data' => ['id' => $db->lastInsertId()]]);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '添加失败：' . $e->getMessage()], 500);
        }
        break;
        
    case 'update_source':
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? 0;
            $chatTitle = $data['chat_title'] ?? '';
            $categoryId = $data['category_id'] ?? null;
            $defaultKeywords = $data['default_keywords'] ?? '';
            $autoForward = $data['auto_forward'] ?? 0;
            $forwardToChatId = $data['forward_to_chat_id'] ?? null;
            $forwardToCategoryId = $data['forward_to_category_id'] ?? null;
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            $stmt = $db->prepare("
                UPDATE video_collect_sources SET 
                    chat_title = ?, category_id = ?, default_keywords = ?, 
                    auto_forward = ?, forward_to_chat_id = ?, forward_to_category_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$chatTitle, $categoryId ?: null, $defaultKeywords, $autoForward, $forwardToChatId ?: null, $forwardToCategoryId ?: null, $id]);
            
            jsonResponse(['success' => true, 'message' => '更新成功']);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '更新失败：' . $e->getMessage()], 500);
        }
        break;
        
    case 'delete_source':
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? 0;
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            $stmt = $db->prepare("DELETE FROM video_collect_sources WHERE id = ?");
            $stmt->execute([$id]);
            
            jsonResponse(['success' => true, 'message' => '删除成功']);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '删除失败'], 500);
        }
        break;
        
    case 'toggle_source':
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? 0;
            $isActive = $data['is_active'] ?? 0;
            
            $stmt = $db->prepare("UPDATE video_collect_sources SET is_active = ? WHERE id = ?");
            $stmt->execute([$isActive, $id]);
            
            jsonResponse(['success' => true, 'message' => '状态更新成功']);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '更新失败'], 500);
        }
        break;
        
    case 'get_source':
        try {
            $id = $_GET['id'] ?? 0;
            
            $stmt = $db->prepare("SELECT * FROM video_collect_sources WHERE id = ?");
            $stmt->execute([$id]);
            $source = $stmt->fetch();
            
            if (!$source) {
                jsonResponse(['success' => false, 'message' => '采集源不存在'], 404);
            }
            
            jsonResponse(['success' => true, 'data' => $source]);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '获取失败'], 500);
        }
        break;
        
    // 从群组选择添加采集源
    case 'add_source_from_group':
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $groupId = $data['group_id'] ?? 0;
            $categoryId = $data['category_id'] ?? null;
            
            if (!$groupId) {
                jsonResponse(['success' => false, 'message' => '请选择群组'], 400);
            }
            
            // 获取群组信息
            $stmt = $db->prepare("SELECT * FROM groups WHERE id = ?");
            $stmt->execute([$groupId]);
            $group = $stmt->fetch();
            
            if (!$group) {
                jsonResponse(['success' => false, 'message' => '群组不存在'], 404);
            }
            
            // 检查是否已存在
            $stmt = $db->prepare("SELECT id FROM video_collect_sources WHERE chat_id = ?");
            $stmt->execute([$group['chat_id']]);
            if ($stmt->fetch()) {
                jsonResponse(['success' => false, 'message' => '该采集源已存在'], 400);
            }
            
            $stmt = $db->prepare("
                INSERT INTO video_collect_sources (chat_id, chat_title, category_id) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$group['chat_id'], $group['title'], $categoryId ?: null]);
            
            jsonResponse(['success' => true, 'message' => '添加成功']);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '添加失败：' . $e->getMessage()], 500);
        }
        break;
        
    // 内部接口：webhook调用，保存采集到的视频
    case 'collect_video':
        try {
            // 这个接口不需要登录验证，由webhook内部调用
            $data = json_decode(file_get_contents('php://input'), true);
            $chatId = $data['chat_id'] ?? '';
            $messageId = $data['message_id'] ?? '';
            $fileId = $data['file_id'] ?? '';
            $caption = $data['caption'] ?? '';
            $fileName = $data['file_name'] ?? '';
            
            if (empty($chatId) || empty($fileId)) {
                jsonResponse(['success' => false, 'message' => '参数不完整'], 400);
            }
            
            // 获取采集源配置
            $stmt = $db->prepare("SELECT * FROM video_collect_sources WHERE chat_id = ? AND is_active = 1");
            $stmt->execute([$chatId]);
            $source = $stmt->fetch();
            
            if (!$source) {
                jsonResponse(['success' => false, 'message' => '未找到采集源配置'], 404);
            }
            
            // 检查是否已采集（根据message_id去重）
            $stmt = $db->prepare("SELECT id FROM videos WHERE source_chat_id = ? AND source_message_id = ?");
            $stmt->execute([$chatId, $messageId]);
            if ($stmt->fetch()) {
                jsonResponse(['success' => false, 'message' => '该视频已采集'], 400);
            }
            
            // 生成标题和关键词
            $title = $caption ? mb_substr($caption, 0, 100) : ($fileName ?: '采集视频_' . date('YmdHis'));
            $keywords = $source['default_keywords'];
            if ($caption) {
                $keywords = $keywords ? $keywords . ',' . $caption : $caption;
            }
            $keywords = parseKeywords($keywords);
            
            // 生成消息链接
            $chatIdStr = (string)$chatId;
            if (strpos($chatIdStr, '-100') === 0) {
                $publicChatId = substr($chatIdStr, 4); // 去掉-100前缀
                $messageLink = "https://t.me/c/{$publicChatId}/{$messageId}";
            } else {
                $messageLink = '';
            }
            
            // 保存视频
            $stmt = $db->prepare("
                INSERT INTO videos 
                (category_id, title, caption, keywords, telegram_file_id, source_chat_id, source_message_id, message_link) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $source['category_id'],
                $title,
                $caption,
                $keywords,
                $fileId,
                $chatId,
                $messageId,
                $messageLink
            ]);
            
            $videoId = $db->lastInsertId();
            
            // 更新采集源统计
            $stmt = $db->prepare("UPDATE video_collect_sources SET collected_count = collected_count + 1, last_collected_at = NOW() WHERE id = ?");
            $stmt->execute([$source['id']]);
            
            jsonResponse(['success' => true, 'message' => '采集成功', 'data' => [
                'video_id' => $videoId,
                'auto_forward' => $source['auto_forward'],
                'forward_to_chat_id' => $source['forward_to_chat_id']
            ]]);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '采集失败：' . $e->getMessage()], 500);
        }
        break;
        
    default:
        jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
}
