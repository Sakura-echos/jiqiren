<?php
/**
 * 文件上传 API
 */
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => '未授权访问'], 401);
}

try {
    if (!isset($_FILES['image'])) {
        jsonResponse(['success' => false, 'message' => '没有上传文件']);
    }
    
    $file = $_FILES['image'];
    $type = $_POST['type'] ?? 'general';
    
    // 检查上传错误
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'message' => '文件上传失败，错误码：' . $file['error']]);
    }
    
    // 检查文件类型
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        jsonResponse(['success' => false, 'message' => '不支持的文件类型，只允许 JPG, PNG, GIF']);
    }
    
    // 检查文件大小（最大5MB）
    $max_size = 5 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        jsonResponse(['success' => false, 'message' => '文件太大，最大允许5MB']);
    }
    
    // 确定上传目录
    $upload_base = __DIR__ . '/../uploads/';
    switch ($type) {
        case 'payment_qr':
            $upload_dir = $upload_base . 'payment_qr/';
            break;
        case 'product':
            $upload_dir = $upload_base . 'products/';
            break;
        default:
            $upload_dir = $upload_base . 'general/';
    }
    
    // 创建目录（如果不存在）
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // 生成唯一文件名
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $type . '_' . time() . '_' . uniqid() . '.' . $extension;
    $filepath = $upload_dir . $filename;
    
    // 移动文件
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonResponse(['success' => false, 'message' => '文件保存失败']);
    }
    
    // 生成相对URL
    $relative_path = str_replace(__DIR__ . '/../', '', $filepath);
    $url = $relative_path;
    
    logSystem('info', '文件上传成功', [
        'type' => $type,
        'filename' => $filename,
        'size' => $file['size']
    ]);
    
    jsonResponse([
        'success' => true,
        'message' => '文件上传成功',
        'url' => $url,
        'filename' => $filename
    ]);
    
} catch (Exception $e) {
    logSystem('error', '文件上传错误', ['error' => $e->getMessage()]);
    jsonResponse(['success' => false, 'message' => '上传失败：' . $e->getMessage()], 500);
}

