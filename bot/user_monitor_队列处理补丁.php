<?php
/**
 * user_monitor.php 队列处理补丁
 * 
 * 将此代码添加到 UserMonitorHandler 类中
 */

// ==================== 在 UserMonitorHandler 类中添加这些方法 ====================

/**
 * 启动后台队列处理任务
 */
public function onStart(): void
{
    monitorLog("守护进程启动，开始队列处理任务");
    
    // 创建一个后台任务，持续处理消息队列
    $this->callFork($this->processMessageQueueLoop());
}

/**
 * 消息队列处理循环（异步）
 */
private function processMessageQueueLoop(): \Generator
{
    while (true) {
        try {
            yield $this->processMessageQueue();
        } catch (\Throwable $e) {
            monitorLog("队列处理循环错误: " . $e->getMessage());
        }
        
        // 每 5 秒检查一次队列
        yield $this->sleep(5);
    }
}

/**
 * 处理消息队列
 */
private function processMessageQueue(): \Generator
{
    try {
        // 1. 获取待发送的消息（按创建时间排序）
        $stmt = $this->db->prepare("
            SELECT * FROM user_message_queue 
            WHERE status = 'pending' 
            AND retry_count < max_retries 
            ORDER BY created_at ASC 
            LIMIT 10
        ");
        $stmt->execute();
        $messages = $stmt->fetchAll();
        
        if (empty($messages)) {
            return; // 没有待发送的消息
        }
        
        monitorLog("队列中有 " . count($messages) . " 条待发送消息");
        
        // 2. 处理每条消息
        foreach ($messages as $msg) {
            // 标记为处理中
            $updateStmt = $this->db->prepare("UPDATE user_message_queue SET status = 'processing' WHERE id = ?");
            $updateStmt->execute([$msg['id']]);
            
            try {
                monitorLog("正在发送队列消息 ID: {$msg['id']}, chat_id: {$msg['chat_id']}");
                
                // 3. 发送消息
                $sendParams = [
                    'peer' => $msg['chat_id'],
                    'message' => $msg['message_text']
                ];
                
                if (!empty($msg['image_url'])) {
                    // 发送图片消息
                    monitorLog("发送图片消息, URL: {$msg['image_url']}");
                    $sendParams['media'] = [
                        '_' => 'inputMediaPhotoExternal',
                        'url' => $msg['image_url']
                    ];
                    $result = yield $this->messages->sendMedia($sendParams);
                } else {
                    // 发送文本消息
                    monitorLog("发送文本消息");
                    $result = yield $this->messages->sendMessage($sendParams);
                }
                
                // 4. 提取 message_id
                $message_id = null;
                if (isset($result['updates'])) {
                    foreach ($result['updates'] as $update) {
                        if (isset($update['message']['id'])) {
                            $message_id = $update['message']['id'];
                            break;
                        }
                    }
                }
                
                // 5. 标记为已发送
                $updateStmt = $this->db->prepare("
                    UPDATE user_message_queue 
                    SET status = 'sent', sent_message_id = ?, sent_at = NOW() 
                    WHERE id = ?
                ");
                $updateStmt->execute([$message_id, $msg['id']]);
                
                monitorLog("✓ 队列消息已发送: queue_id={$msg['id']}, message_id=$message_id");
                
                // 6. 如果需要自毁，创建自毁任务
                if ($msg['delete_after_seconds'] > 0 && $message_id) {
                    $scheduledTime = date('Y-m-d H:i:s', time() + $msg['delete_after_seconds']);
                    $taskData = json_encode([
                        'chat_id' => $msg['chat_id'],
                        'message_id' => $message_id
                    ]);
                    
                    $taskStmt = $this->db->prepare("INSERT INTO scheduled_tasks (task_type, data, scheduled_at) VALUES (?, ?, ?)");
                    $taskStmt->execute(['delete_message', $taskData, $scheduledTime]);
                    
                    monitorLog("✓ 已安排消息自毁任务: {$msg['delete_after_seconds']} 秒后删除");
                }
                
            } catch (\Throwable $e) {
                // 7. 发送失败，增加重试次数
                $retryCount = $msg['retry_count'] + 1;
                $status = ($retryCount >= $msg['max_retries']) ? 'failed' : 'pending';
                
                $updateStmt = $this->db->prepare("
                    UPDATE user_message_queue 
                    SET status = ?, retry_count = ?, error_message = ? 
                    WHERE id = ?
                ");
                $updateStmt->execute([$status, $retryCount, $e->getMessage(), $msg['id']]);
                
                monitorLog("✗ 队列消息发送失败: queue_id={$msg['id']}, retry=$retryCount/{$msg['max_retries']}, error=" . $e->getMessage());
            }
            
            // 8. 稍微延迟，避免发送过快
            yield $this->sleep(1);
        }
        
    } catch (\Throwable $e) {
        monitorLog("队列处理错误: " . $e->getMessage());
        monitorLog("Stack trace: " . $e->getTraceAsString());
    }
}

// ==================== 使用说明 ====================
/*

1. 打开 bot/user_monitor.php
2. 找到 UserMonitorHandler 类
3. 在类中添加上面的三个方法：
   - onStart()
   - processMessageQueueLoop()
   - processMessageQueue()

4. 重启守护进程
   访问：https://tgbot.5088.buzz/user_monitor_control.php
   点击：重启

5. 查看日志
   tail -f bot/user_monitor.log | grep "队列"

*/

