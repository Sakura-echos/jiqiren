<?php
/**
 * Telegram Bot 核心类
 */

class TelegramBot {
    private $token;
    private $api_url;
    private $lastError = null;
    private $lastResponse = null;
    private $contextChatId = null;
    private $contextMessageThreadId = null;
    
    public function __construct($token) {
        $this->token = $token;
        $this->api_url = "https://api.telegram.org/bot" . $token . "/";
    }

    /**
     * 设置当前消息上下文（用于 forum topic 自动带 message_thread_id）
     */
    public function setMessageContext($chat_id, $message_thread_id = null) {
        $this->contextChatId = $chat_id;
        $this->contextMessageThreadId = $message_thread_id;
    }

    /**
     * 清理当前消息上下文
     */
    public function clearMessageContext() {
        $this->contextChatId = null;
        $this->contextMessageThreadId = null;
    }

    /**
     * 将 thread 上下文注入请求参数（仅同 chat_id 生效）
     */
    private function applyMessageThreadContext($chat_id, array &$parameters, $explicit_message_thread_id = null) {
        if ($explicit_message_thread_id !== null) {
            $parameters['message_thread_id'] = $explicit_message_thread_id;
            return;
        }

        if ($this->contextMessageThreadId === null || $this->contextChatId === null) {
            return;
        }

        if ((string)$this->contextChatId === (string)$chat_id) {
            $parameters['message_thread_id'] = $this->contextMessageThreadId;
        }
    }
    
    /**
     * 获取最后一次API错误信息
     */
    public function getLastError() {
        return $this->lastError;
    }
    
    /**
     * 获取最后一次API响应
     */
    public function getLastResponse() {
        return $this->lastResponse;
    }
    
    /**
     * 发送API请求
     */
    private function apiRequest($method, $parameters = []) {
        if (!is_string($method)) {
            error_log("Method name must be a string");
            return false;
        }
        
        $url = $this->api_url . $method;
        error_log("Making API request to: " . $url);
        error_log("Parameters: " . print_r($parameters, true));
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30秒超时
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10秒连接超时
        
        // Add more debug info
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);
        
        $result = curl_exec($ch);
        
        if (curl_errno($ch)) {
            error_log("Curl error: " . curl_error($ch));
            
            // Get verbose information
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            error_log("Verbose curl log: " . $verboseLog);
            
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        fclose($verbose);
        
        error_log("API response: " . $result);
        $this->lastResponse = $result;
        $decoded = json_decode($result, true);
        
        if (!$decoded) {
            error_log("Failed to decode JSON response");
            $this->lastError = "Failed to decode JSON response";
            return false;
        }
        
        if (!$decoded['ok']) {
            $this->lastError = $result; // 保存完整响应用于错误处理
            error_log("API request failed with error: " . ($decoded['description'] ?? 'Unknown error'));
            return false;
        }
        
        $this->lastError = null;
        return $decoded['result'];
    }
    
    /**
     * 设置 Webhook
     */
    public function setWebhook($url, $allowed_updates = null) {
        $params = ['url' => $url];
        
        // 默认允许接收所有需要的更新类型
        if ($allowed_updates === null) {
            $allowed_updates = [
                'message',           // 普通消息
                'edited_message',    // 编辑的消息
                'channel_post',      // 频道消息
                'edited_channel_post', // 编辑的频道消息
                'callback_query',    // 按钮回调
                'my_chat_member',    // 机器人自身状态变化（加入/退出群组/频道）
                'chat_member',       // 群组成员变化
                'chat_join_request'  // 入群申请
            ];
        }
        
        $params['allowed_updates'] = json_encode($allowed_updates);
        
        return $this->apiRequest('setWebhook', $params);
    }
    
    /**
     * 删除 Webhook
     */
    public function deleteWebhook() {
        return $this->apiRequest('deleteWebhook');
    }
    
    /**
     * 获取 Webhook 信息
     */
    public function getWebhookInfo() {
        return $this->apiRequest('getWebhookInfo');
    }
    
    /**
     * 发送视频
     */
    public function sendVideo($chat_id, $video, $caption = null, $parse_mode = null, $reply_markup = null, $message_thread_id = null) {
        $parameters = [
            'chat_id' => $chat_id
        ];
        
        // 如果video是CURLFile对象，说明是本地文件
        if ($video instanceof CURLFile) {
            $parameters['video'] = $video;
        } else {
            // 否则作为file_id处理
            $parameters['video'] = $video;
        }
        
        if ($caption) {
            $parameters['caption'] = $caption;
        }
        
        if ($parse_mode) {
            $parameters['parse_mode'] = $parse_mode;
        }
        
        if ($reply_markup) {
            $parameters['reply_markup'] = json_encode($reply_markup);
        }

        $this->applyMessageThreadContext($chat_id, $parameters, $message_thread_id);
        
        return $this->apiRequest('sendVideo', $parameters);
    }
    
    /**
     * 转发消息
     */
    public function forwardMessage($chat_id, $from_chat_id, $message_id, $disable_notification = false, $message_thread_id = null) {
        $parameters = [
            'chat_id' => $chat_id,
            'from_chat_id' => $from_chat_id,
            'message_id' => $message_id
        ];
        
        if ($disable_notification) {
            $parameters['disable_notification'] = true;
        }

        $this->applyMessageThreadContext($chat_id, $parameters, $message_thread_id);
        
        return $this->apiRequest('forwardMessage', $parameters);
    }
    
    /**
     * 复制消息（不显示转发来源）
     */
    public function copyMessage($chat_id, $from_chat_id, $message_id, $caption = null, $parse_mode = null, $message_thread_id = null) {
        $parameters = [
            'chat_id' => $chat_id,
            'from_chat_id' => $from_chat_id,
            'message_id' => $message_id
        ];
        
        if ($caption !== null) {
            $parameters['caption'] = $caption;
        }
        
        if ($parse_mode) {
            $parameters['parse_mode'] = $parse_mode;
        }

        $this->applyMessageThreadContext($chat_id, $parameters, $message_thread_id);
        
        return $this->apiRequest('copyMessage', $parameters);
    }
    
    /**
     * 发送消息
     */
    public function sendMessage($chat_id, $text, $parse_mode = null, $reply_markup = null, $delete_after = null, $reply_to_message_id = null, $message_thread_id = null) {
        $parameters = [
            'chat_id' => $chat_id,
            'text' => $text
        ];
        
        if ($parse_mode) {
            $parameters['parse_mode'] = $parse_mode;
        }
        
        if ($reply_markup) {
            $parameters['reply_markup'] = json_encode($reply_markup);
        }
        
        // 回复指定消息
        if ($reply_to_message_id !== null) {
            $parameters['reply_to_message_id'] = $reply_to_message_id;
        }

        $this->applyMessageThreadContext($chat_id, $parameters, $message_thread_id);
        
        // 设置消息自毁时间
        if ($delete_after !== null && $delete_after > 0) {
            // 注意：Telegram要求自毁时间在5秒到28天之间
            if ($delete_after >= 5 && $delete_after <= 2419200) {
                // 使用新的消息自毁方法：先发送消息，然后使用定时任务删除
                // Telegram Bot API不直接支持消息自毁，需要通过手动删除实现
            }
        }
        
        $result = $this->apiRequest('sendMessage', $parameters);
        
        // 如果设置了自毁时间且发送成功，记录需要删除的消息
        if ($result && $delete_after !== null && $delete_after > 0) {
            // 这里可以将消息ID和删除时间保存到数据库，由定时任务处理
            // 或者可以返回消息信息供调用者处理
            if (isset($result['message_id'])) {
                $result['_delete_after'] = $delete_after;
            }
        }
        
        return $result;
    }
    
    /**
     * 发送带Inline键盘的消息
     */
    public function sendMessageWithInlineKeyboard($chat_id, $text, $inline_keyboard, $parse_mode = null) {
        $parameters = [
            'chat_id' => $chat_id,
            'text' => $text
        ];
        
        if ($parse_mode) {
            $parameters['parse_mode'] = $parse_mode;
        }
        
        // 构建InlineKeyboardMarkup
        $parameters['reply_markup'] = json_encode([
            'inline_keyboard' => $inline_keyboard
        ]);
        
        error_log("Sending message with inline keyboard: " . $parameters['reply_markup']);
        
        return $this->apiRequest('sendMessage', $parameters);
    }
    
    /**
     * 删除消息
     */
    public function deleteMessage($chat_id, $message_id) {
        return $this->apiRequest('deleteMessage', [
            'chat_id' => $chat_id,
            'message_id' => $message_id
        ]);
    }
    
    /**
     * 封禁/踢出成员（推荐使用；kickChatMember 在 Bot API 中已弃用）
     */
    public function banChatMember($chat_id, $user_id, $until_date = null, $revoke_messages = null) {
        $parameters = [
            'chat_id' => $chat_id,
            'user_id' => $user_id
        ];
        if ($until_date !== null) {
            $parameters['until_date'] = $until_date;
        }
        if ($revoke_messages !== null) {
            $parameters['revoke_messages'] = $revoke_messages;
        }
        return $this->apiRequest('banChatMember', $parameters);
    }
    
    /**
     * 踢出用户（兼容旧名，内部走 banChatMember）
     */
    public function kickChatMember($chat_id, $user_id, $until_date = null) {
        return $this->banChatMember($chat_id, $user_id, $until_date);
    }
    
    /**
     * 解除禁言
     */
    public function unbanChatMember($chat_id, $user_id) {
        return $this->apiRequest('unbanChatMember', [
            'chat_id' => $chat_id,
            'user_id' => $user_id
        ]);
    }
    
    /**
     * 限制用户权限 (禁言)
     */
    public function restrictChatMember($chat_id, $user_id, $until_date = null, $can_send_messages = false) {
        $permissions = [
            'can_send_messages' => $can_send_messages,
            'can_send_media_messages' => false,
            'can_send_polls' => false,
            'can_send_other_messages' => false,
            'can_add_web_page_previews' => false,
            'can_change_info' => false,
            'can_invite_users' => false,
            'can_pin_messages' => false
        ];
        
        $parameters = [
            'chat_id' => $chat_id,
            'user_id' => $user_id,
            'permissions' => json_encode($permissions)
        ];
        
        if ($until_date) {
            $parameters['until_date'] = $until_date;
        }
        
        return $this->apiRequest('restrictChatMember', $parameters);
    }
    
    /**
     * 恢复用户权限
     */
    public function unrestrictChatMember($chat_id, $user_id) {
        $permissions = [
            'can_send_messages' => true,
            'can_send_media_messages' => true,
            'can_send_polls' => true,
            'can_send_other_messages' => true,
            'can_add_web_page_previews' => true,
            'can_change_info' => false,
            'can_invite_users' => true,
            'can_pin_messages' => false
        ];
        
        $parameters = [
            'chat_id' => $chat_id,
            'user_id' => $user_id,
            'permissions' => json_encode($permissions)
        ];
        
        return $this->apiRequest('restrictChatMember', $parameters);
    }
    
    /**
     * 提升为管理员
     */
    public function promoteChatMember($chat_id, $user_id) {
        return $this->apiRequest('promoteChatMember', [
            'chat_id' => $chat_id,
            'user_id' => $user_id,
            'can_change_info' => true,
            'can_delete_messages' => true,
            'can_invite_users' => true,
            'can_restrict_members' => true,
            'can_pin_messages' => true,
            'can_promote_members' => false
        ]);
    }
    
    /**
     * 获取聊天信息
     */
    public function getChat($chat_id) {
        return $this->apiRequest('getChat', ['chat_id' => $chat_id]);
    }
    
    /**
     * 获取聊天成员数量
     */
    public function getChatMembersCount($chat_id) {
        return $this->apiRequest('getChatMembersCount', ['chat_id' => $chat_id]);
    }
    
    /**
     * 获取聊天成员信息
     */
    public function getChatMember($chat_id, $user_id) {
        return $this->apiRequest('getChatMember', [
            'chat_id' => $chat_id,
            'user_id' => $user_id
        ]);
    }
    
    /**
     * 固定消息
     */
    public function pinChatMessage($chat_id, $message_id) {
        return $this->apiRequest('pinChatMessage', [
            'chat_id' => $chat_id,
            'message_id' => $message_id
        ]);
    }
    
    /**
     * 取消固定消息
     */
    public function unpinChatMessage($chat_id) {
        return $this->apiRequest('unpinChatMessage', ['chat_id' => $chat_id]);
    }
    
    /**
     * 离开聊天
     */
    public function leaveChat($chat_id) {
        return $this->apiRequest('leaveChat', ['chat_id' => $chat_id]);
    }
    
    /**
     * 发送图片（自动检测GIF并使用合适的方法）
     */
    public function sendPhoto($chat_id, $photo, $caption = null, $reply_markup = null, $parse_mode = 'HTML', $delete_after = null, $message_thread_id = null) {
        // 检测是否是GIF文件
        if ($this->isGif($photo)) {
            return $this->sendAnimation($chat_id, $photo, $caption, $reply_markup, $parse_mode, $delete_after, $message_thread_id);
        }
        
        // 检查是否是本地文件路径，如果是大文件则使用文件上传
        if ($this->isLocalFile($photo)) {
            $filePath = $this->getLocalFilePath($photo);
            if ($filePath && file_exists($filePath)) {
                $fileSize = filesize($filePath);
                // 如果文件大于5MB，使用CURLFile上传
                if ($fileSize > 5 * 1024 * 1024) {
                    error_log("Photo file size: " . $fileSize . " bytes, using CURLFile upload");
                    $parameters = [
                        'chat_id' => $chat_id,
                        'photo' => new \CURLFile($filePath),
                        'parse_mode' => $parse_mode
                    ];
                    
                    if ($caption) {
                        $parameters['caption'] = $caption;
                    }
                    
                    if ($reply_markup) {
                        $parameters['reply_markup'] = json_encode($reply_markup);
                    }

                    $this->applyMessageThreadContext($chat_id, $parameters, $message_thread_id);
                    
                    error_log("Sending large photo via CURLFile");
                    $result = $this->apiRequest('sendPhoto', $parameters);
                    
                    if ($result && $delete_after !== null && $delete_after > 0) {
                        if (isset($result['message_id'])) {
                            $result['_delete_after'] = $delete_after;
                        }
                    }
                    
                    return $result;
                }
            }
        }
        
        $parameters = [
            'chat_id' => $chat_id,
            'photo' => $photo,
            'parse_mode' => $parse_mode
        ];
        
        if ($caption) {
            $parameters['caption'] = $caption;
        }
        
        if ($reply_markup) {
            $parameters['reply_markup'] = json_encode($reply_markup);
        }

        $this->applyMessageThreadContext($chat_id, $parameters, $message_thread_id);
        
        error_log("Sending photo with parameters: " . print_r($parameters, true));
        $result = $this->apiRequest('sendPhoto', $parameters);
        
        // 如果设置了自毁时间且发送成功，记录需要删除的消息
        if ($result && $delete_after !== null && $delete_after > 0) {
            if (isset($result['message_id'])) {
                $result['_delete_after'] = $delete_after;
            }
        }
        
        return $result;
    }
    
    /**
     * 发送动画/GIF
     */
    public function sendAnimation($chat_id, $animation, $caption = null, $reply_markup = null, $parse_mode = 'HTML', $delete_after = null, $message_thread_id = null) {
        // 检查是否是本地文件路径，如果是大文件则使用文件上传
        if ($this->isLocalFile($animation)) {
            $filePath = $this->getLocalFilePath($animation);
            if ($filePath && file_exists($filePath)) {
                $fileSize = filesize($filePath);
                error_log("Animation file size: " . $fileSize . " bytes");
                
                // 如果文件大于10MB，使用CURLFile上传（Telegram对GIF的URL限制约20MB，但使用文件上传可达50MB）
                if ($fileSize > 10 * 1024 * 1024) {
                    error_log("Large animation file, using CURLFile upload");
                    $parameters = [
                        'chat_id' => $chat_id,
                        'animation' => new \CURLFile($filePath),
                        'parse_mode' => $parse_mode
                    ];
                    
                    if ($caption) {
                        $parameters['caption'] = $caption;
                    }
                    
                    if ($reply_markup) {
                        $parameters['reply_markup'] = json_encode($reply_markup);
                    }

                    $this->applyMessageThreadContext($chat_id, $parameters, $message_thread_id);
                    
                    error_log("Sending large animation via CURLFile");
                    $result = $this->apiRequest('sendAnimation', $parameters);
                    
                    if ($result && $delete_after !== null && $delete_after > 0) {
                        if (isset($result['message_id'])) {
                            $result['_delete_after'] = $delete_after;
                        }
                    }
                    
                    return $result;
                }
            }
        }
        
        $parameters = [
            'chat_id' => $chat_id,
            'animation' => $animation,
            'parse_mode' => $parse_mode
        ];
        
        if ($caption) {
            $parameters['caption'] = $caption;
        }
        
        if ($reply_markup) {
            $parameters['reply_markup'] = json_encode($reply_markup);
        }

        $this->applyMessageThreadContext($chat_id, $parameters, $message_thread_id);
        
        error_log("Sending animation with parameters: " . print_r($parameters, true));
        $result = $this->apiRequest('sendAnimation', $parameters);
        
        // 如果设置了自毁时间且发送成功，记录需要删除的消息
        if ($result && $delete_after !== null && $delete_after > 0) {
            if (isset($result['message_id'])) {
                $result['_delete_after'] = $delete_after;
            }
        }
        
        return $result;
    }
    
    /**
     * 检测文件是否为GIF
     */
    private function isGif($fileUrl) {
        // 检查文件扩展名
        $extension = strtolower(pathinfo($fileUrl, PATHINFO_EXTENSION));
        if ($extension === 'gif') {
            return true;
        }
        
        // 检查URL中是否包含.gif
        if (stripos($fileUrl, '.gif') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 检测是否为本地文件（相对路径或包含域名的URL）
     */
    private function isLocalFile($path) {
        // 如果是外部URL（http://或https://开头但不是本站域名），返回false
        if (preg_match('/^https?:\/\//', $path)) {
            // 检查是否是本站URL
            if (defined('SITE_URL') && strpos($path, SITE_URL) === 0) {
                return true;
            }
            return false;
        }
        // 相对路径视为本地文件
        return true;
    }
    
    /**
     * 获取本地文件的绝对路径
     */
    private function getLocalFilePath($path) {
        // 如果是完整的本站URL，转换为文件路径
        if (defined('SITE_URL') && strpos($path, SITE_URL) === 0) {
            $relativePath = str_replace(SITE_URL . '/', '', $path);
            $basePath = dirname(dirname(__FILE__)); // 从bot目录往上两级
            return $basePath . '/' . $relativePath;
        }
        
        // 如果是相对路径
        if (!preg_match('/^https?:\/\//', $path)) {
            $basePath = dirname(dirname(__FILE__));
            return $basePath . '/' . ltrim($path, '/');
        }
        
        return null;
    }
    
    /**
     * 发送文档
     */
    public function sendDocument($chat_id, $document, $caption = null) {
        $parameters = [
            'chat_id' => $chat_id,
            'document' => $document
        ];
        
        if ($caption) {
            $parameters['caption'] = $caption;
        }
        
        return $this->apiRequest('sendDocument', $parameters);
    }
    
    /**
     * 编辑消息文本
     */
    public function editMessageText($chat_id, $message_id, $text, $parse_mode = null, $inline_keyboard = null) {
        $parameters = [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $text
        ];
        
        if ($parse_mode) {
            $parameters['parse_mode'] = $parse_mode;
        }
        
        if ($inline_keyboard) {
            $parameters['reply_markup'] = json_encode([
                'inline_keyboard' => $inline_keyboard
            ]);
        }
        
        return $this->apiRequest('editMessageText', $parameters);
    }
    
    /**
     * 编辑消息的inline keyboard
     */
    public function editMessageReplyMarkup($chat_id, $message_id, $reply_markup = null) {
        $parameters = [
            'chat_id' => $chat_id,
            'message_id' => $message_id
        ];
        
        if ($reply_markup) {
            $parameters['reply_markup'] = json_encode($reply_markup);
        }
        
        return $this->apiRequest('editMessageReplyMarkup', $parameters);
    }
    
    /**
     * 获取Bot信息
     */
    public function getMe() {
        return $this->apiRequest('getMe');
    }
    
    /**
     * 公开的API请求方法（用于调用任意Telegram Bot API）
     */
    public function callApi($method, $parameters = []) {
        return $this->apiRequest($method, $parameters);
    }
    
    /**
     * 回答回调查询（用于内联按钮）
     */
    public function answerCallbackQuery($callback_query_id, $text = null, $show_alert = false) {
        $parameters = [
            'callback_query_id' => $callback_query_id
        ];
        
        if ($text !== null) {
            $parameters['text'] = $text;
        }
        
        if ($show_alert) {
            $parameters['show_alert'] = true;
        }
        
        return $this->apiRequest('answerCallbackQuery', $parameters);
    }
}

