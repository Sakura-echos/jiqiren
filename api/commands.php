<?php
/**
 * 自定义命令管理 API
 */

// 设置错误处理
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__DIR__) . '/logs/error.log');

// 包含配置文件
require_once dirname(__DIR__) . '/config.php';

// 设置JSON响应头
header('Content-Type: application/json; charset=utf-8');

// 记录请求信息
$input = file_get_contents('php://input');
error_log("Received request: " . $input);

// 错误处理函数
function handleError($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error: [$errno] $errstr in $errfile on line $errline");
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '系统错误']);
    }
    exit;
}

// 设置错误处理函数
set_error_handler('handleError');

// 设置异常处理函数
set_exception_handler(function($e) {
    error_log("Uncaught Exception: " . $e->getMessage());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '系统错误']);
    }
    exit;
});

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

checkLogin();

try {
    $db = getDB();
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => '数据库连接失败'], 500);
}

// POST 请求
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'add':
            $command = $data['command'] ?? '';
            $description = $data['description'] ?? '';
            $response = $data['response'] ?? '';
            $group_id = $data['group_id'] ?? null;
            
            if (empty($command) || empty($response)) {
                jsonResponse(['success' => false, 'message' => '请填写所有必填字段'], 400);
            }
            
            if (substr($command, 0, 1) !== '/') {
                jsonResponse(['success' => false, 'message' => '命令必须以 / 开头'], 400);
            }
            
            try {
                error_log("Adding custom command - command: $command, description: $description, group_id: " . ($group_id ?? 'null'));
                error_log("Response content: " . $response);
                
                // 检查数据
                if (empty($command)) {
                    throw new Exception('命令不能为空');
                }
                if (empty($response)) {
                    throw new Exception('回复内容不能为空');
                }
                
                // 检查命令格式
                if (substr($command, 0, 1) !== '/') {
                    throw new Exception('命令必须以 / 开头');
                }
                
                // 检查命令是否已存在
                $stmt = $db->prepare("SELECT id FROM custom_commands WHERE command = ? AND (group_id = ? OR group_id IS NULL)");
                $stmt->execute([$command, $group_id]);
                if ($stmt->fetch()) {
                    throw new Exception('该命令已存在');
                }
                
                // 如果指定了群组，检查群组是否存在
                if ($group_id) {
                    $stmt = $db->prepare("SELECT id FROM groups WHERE id = ?");
                    $stmt->execute([$group_id]);
                    if (!$stmt->fetch()) {
                        throw new Exception('指定的群组不存在');
                    }
                }
                
                // 准备SQL
                $sql = "INSERT INTO custom_commands (command, description, response, group_id, is_active) VALUES (?, ?, ?, ?, 1)";
                error_log("SQL: " . $sql);
                
                // 执行插入
                try {
                    error_log("Executing SQL: $sql");
                    error_log("Parameters: " . json_encode([
                        'command' => $command,
                        'description' => $description,
                        'response' => $response,
                        'group_id' => $group_id
                    ]));
                    
                    $stmt = $db->prepare($sql);
                    $result = $stmt->execute([$command, $description, $response, $group_id]);
                    
                     if (!$result) {
                         $error = $stmt->errorInfo();
                         error_log("SQL Error: " . print_r($error, true));
                         throw new Exception("数据库插入失败");
                     }
                     
                     error_log("Custom command added successfully");
                     logSystem('info', 'Added custom command', [
                         'command' => $command,
                         'group_id' => $group_id,
                         'description' => $description
                     ]);
                     jsonResponse(['success' => true, 'message' => '添加成功']);
                } catch (PDOException $e) {
                    error_log("Database error: " . $e->getMessage());
                    throw new Exception("数据库错误：" . $e->getMessage());
                }
            } catch (PDOException $e) {
                error_log("Database error: " . $e->getMessage());
                logSystem('error', 'Add custom command database error', [
                    'error' => $e->getMessage(),
                    'code' => $e->getCode()
                ]);
                jsonResponse(['success' => false, 'message' => '数据库错误：' . $e->getMessage()], 500);
            } catch (Exception $e) {
                error_log("General error: " . $e->getMessage());
                logSystem('error', 'Add custom command error', [
                    'error' => $e->getMessage()
                ]);
                jsonResponse(['success' => false, 'message' => '添加失败：' . $e->getMessage()], 500);
            }
            break;
            
        case 'delete':
            $id = $data['id'] ?? 0;
            
            if (!$id) {
                jsonResponse(['success' => false, 'message' => '无效的ID'], 400);
            }
            
            try {
                $stmt = $db->prepare("DELETE FROM custom_commands WHERE id = ?");
                $stmt->execute([$id]);
                
                logSystem('info', 'Deleted custom command', ['id' => $id]);
                jsonResponse(['success' => true, 'message' => '删除成功']);
            } catch (Exception $e) {
                logSystem('error', 'Delete custom command error', $e->getMessage());
                jsonResponse(['success' => false, 'message' => '删除失败'], 500);
            }
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
    }
}

