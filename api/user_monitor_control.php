<?php
/**
 * 守护进程控制 API
 */

require_once '../config.php';
checkLogin();

header('Content-Type: application/json; charset=utf-8');

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => '无效的请求方法'], 400);
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

$scriptDir = dirname(__DIR__);
$pidFile = $scriptDir . '/logs/user_monitor.pid';
$logFile = $scriptDir . '/logs/user_monitor.log';
$scriptFile = $scriptDir . '/bot/user_monitor.php';

switch ($action) {
    case 'start':
        // 检查是否已经运行
        if (file_exists($pidFile)) {
            $pid = (int)file_get_contents($pidFile);
            // 检查进程是否真的在运行
            $output = [];
            exec("ps -p $pid", $output, $return);
            if ($return === 0 && count($output) > 1) {
                jsonResponse(['success' => false, 'message' => '守护进程已在运行中（PID: ' . $pid . '）']);
            }
            // PID文件存在但进程不在，清理
            @unlink($pidFile);
        }
        
        // 启动守护进程
        $command = "cd $scriptDir && nohup php bot/user_monitor.php >> logs/user_monitor.log 2>&1 & echo $!";
        $pid = exec($command);
        
        // 等待2秒检查是否成功启动
        sleep(2);
        
        if (file_exists($pidFile)) {
            $newPid = (int)file_get_contents($pidFile);
            // 检查进程是否真的在运行
            $output = [];
            exec("ps -p $newPid", $output, $return);
            if ($return === 0 && count($output) > 1) {
                // 更新数据库状态
                try {
                    $stmt = $db->prepare("INSERT INTO user_monitor_status (id, status, pid, start_time, last_heartbeat, updated_at) VALUES (1, 'running', ?, NOW(), NOW(), NOW()) ON DUPLICATE KEY UPDATE status = 'running', pid = ?, start_time = NOW(), last_heartbeat = NOW(), updated_at = NOW()");
                    $stmt->execute([$newPid, $newPid]);
                } catch (Exception $e) {
                    // 表可能不存在，忽略
                }
                
                jsonResponse(['success' => true, 'message' => '守护进程启动成功！PID: ' . $newPid, 'pid' => $newPid]);
            } else {
                jsonResponse(['success' => false, 'message' => '守护进程启动后立即停止，请查看日志']);
            }
        } else {
            jsonResponse(['success' => false, 'message' => '守护进程启动失败，PID文件未生成']);
        }
        break;
        
    case 'stop':
        if (!file_exists($pidFile)) {
            jsonResponse(['success' => false, 'message' => '守护进程未运行']);
        }
        
        $pid = (int)file_get_contents($pidFile);
        
        // 停止进程
        exec("kill -15 $pid 2>&1", $output, $return);
        
        // 等待1秒
        sleep(1);
        
        // 如果还在运行，强制停止
        exec("ps -p $pid", $checkOutput, $checkReturn);
        if ($checkReturn === 0) {
            exec("kill -9 $pid 2>&1");
        }
        
        // 清理PID文件
        @unlink($pidFile);
        
        // 更新数据库状态
        try {
            $stmt = $db->prepare("UPDATE user_monitor_status SET status = 'stopped', stop_time = NOW(), updated_at = NOW() WHERE id = 1");
            $stmt->execute();
        } catch (Exception $e) {
            // 表可能不存在，忽略
        }
        
        jsonResponse(['success' => true, 'message' => '守护进程已停止']);
        break;
        
    case 'restart':
        // 先停止
        if (file_exists($pidFile)) {
            $pid = (int)file_get_contents($pidFile);
            exec("kill -9 $pid 2>&1");
            sleep(1);
            @unlink($pidFile);
        }
        
        // 清理所有残留进程
        exec("pkill -f 'php bot/user_monitor.php' 2>&1");
        sleep(1);
        
        // 启动守护进程
        $command = "cd $scriptDir && nohup php bot/user_monitor.php >> logs/user_monitor.log 2>&1 & echo $!";
        $pid = exec($command);
        
        // 等待3秒检查
        sleep(3);
        
        if (file_exists($pidFile)) {
            $newPid = (int)file_get_contents($pidFile);
            $output = [];
            exec("ps -p $newPid", $output, $return);
            if ($return === 0 && count($output) > 1) {
                // 更新数据库状态
                try {
                    $stmt = $db->prepare("INSERT INTO user_monitor_status (id, status, pid, start_time, last_heartbeat, updated_at) VALUES (1, 'running', ?, NOW(), NOW(), NOW()) ON DUPLICATE KEY UPDATE status = 'running', pid = ?, start_time = NOW(), last_heartbeat = NOW(), updated_at = NOW()");
                    $stmt->execute([$newPid, $newPid]);
                } catch (Exception $e) {
                    // 忽略
                }
                
                jsonResponse(['success' => true, 'message' => '守护进程重启成功！PID: ' . $newPid, 'pid' => $newPid]);
            } else {
                jsonResponse(['success' => false, 'message' => '守护进程重启失败，请查看日志']);
            }
        } else {
            jsonResponse(['success' => false, 'message' => '守护进程重启失败，PID文件未生成']);
        }
        break;
        
    case 'status':
        $isRunning = false;
        $pid = 0;
        
        if (file_exists($pidFile)) {
            $pid = (int)file_get_contents($pidFile);
            $output = [];
            exec("ps -p $pid", $output, $return);
            $isRunning = ($return === 0 && count($output) > 1);
        }
        
        jsonResponse([
            'success' => true,
            'isRunning' => $isRunning,
            'pid' => $isRunning ? $pid : null,
            'message' => $isRunning ? '守护进程运行中' : '守护进程未运行'
        ]);
        break;
        
    default:
        jsonResponse(['success' => false, 'message' => '无效的操作'], 400);
}

