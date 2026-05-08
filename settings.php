<?php
require_once 'config.php';
require_once 'bot/TelegramBot.php';
checkLogin();

$db = getDB();
$bot = new TelegramBot(BOT_TOKEN);

// 获取Bot信息
$bot_info = $bot->getMe();
$webhook_info = $bot->getWebhookInfo();

// 处理Webhook设置
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_webhook'])) {
    $result = $bot->setWebhook(WEBHOOK_URL);
    if ($result) {
        $success_msg = "Webhook 设置成功！";
        $webhook_info = $bot->getWebhookInfo();
    } else {
        $error_msg = "Webhook 设置失败！";
    }
}

// 处理删除Webhook
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_webhook'])) {
    $result = $bot->deleteWebhook();
    if ($result) {
        $success_msg = "Webhook 已删除！";
        $webhook_info = $bot->getWebhookInfo();
    } else {
        $error_msg = "删除 Webhook 失败！";
    }
}

// 处理保存机器人配置
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_bot_config'])) {
    $new_token = trim($_POST['bot_token'] ?? '');
    $new_username = trim($_POST['bot_username'] ?? '');
    $new_site_url = trim($_POST['site_url'] ?? '');
    
    if (empty($new_token)) {
        $error_msg = "Bot Token 不能为空！";
    } else {
        try {
            // 验证新Token是否有效
            $test_bot = new TelegramBot($new_token);
            $test_info = $test_bot->getMe();
            
            if (!$test_info) {
                $error_msg = "无效的 Bot Token，请检查后重试！";
            } else {
                // Token 有效，保存到数据库
                $settings_to_save = [
                    'bot_token' => $new_token,
                    'bot_username' => $new_username ?: ($test_info['username'] ?? ''),
                    'site_url' => $new_site_url
                ];
                
                foreach ($settings_to_save as $key => $value) {
                    // 检查设置是否已存在
                    $stmt = $db->prepare("SELECT id FROM system_settings WHERE setting_key = ?");
                    $stmt->execute([$key]);
                    
                    if ($stmt->fetch()) {
                        // 更新
                        $stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
                        $stmt->execute([$value, $key]);
                    } else {
                        // 插入 - 只使用基本字段
                        $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
                        $stmt->execute([$key, $value]);
                    }
                }
                
                $success_msg = "机器人配置已保存！新Token对应的机器人: @" . ($test_info['username'] ?? 'unknown') . "。请刷新页面并重新设置 Webhook。";
                
                // 记录日志
                logSystem('info', '更新机器人配置', [
                    'new_username' => $test_info['username'] ?? '',
                    'admin_id' => $_SESSION['admin_id'] ?? null
                ]);
            }
        } catch (Exception $e) {
            $error_msg = "保存配置失败: " . $e->getMessage();
        }
    }
}

// 获取当前数据库中的配置
$current_db_token = '';
$current_db_username = '';
$current_db_site_url = '';
try {
    $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('bot_token', 'bot_username', 'site_url')");
    while ($row = $stmt->fetch()) {
        if ($row['setting_key'] === 'bot_token') {
            $current_db_token = $row['setting_value'];
        } elseif ($row['setting_key'] === 'bot_username') {
            $current_db_username = $row['setting_value'];
        } elseif ($row['setting_key'] === 'site_url') {
            $current_db_site_url = $row['setting_value'];
        }
    }
} catch (Exception $e) {
    // 忽略错误
}

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bot设置 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>⚙️ Bot设置</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <?php if (isset($success_msg)): ?>
                <div class="alert alert-success"><?php echo $success_msg; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_msg)): ?>
                <div class="alert alert-error"><?php echo $error_msg; ?></div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h2>🤖 Bot 信息</h2>
                </div>
                <div class="card-body">
                    <?php if ($bot_info): ?>
                        <table style="width: 100%;">
                            <tr>
                                <td style="padding: 10px; width: 200px;"><strong>Bot ID:</strong></td>
                                <td style="padding: 10px;"><?php echo $bot_info['id'] ?? 'N/A'; ?></td>
                            </tr>
                            <tr>
                                <td style="padding: 10px;"><strong>Bot 用户名:</strong></td>
                                <td style="padding: 10px;">@<?php echo $bot_info['username'] ?? 'N/A'; ?></td>
                            </tr>
                            <tr>
                                <td style="padding: 10px;"><strong>Bot 名称:</strong></td>
                                <td style="padding: 10px;"><?php echo $bot_info['first_name'] ?? 'N/A'; ?></td>
                            </tr>
                            <tr>
                                <td style="padding: 10px;"><strong>是否可以加入群组:</strong></td>
                                <td style="padding: 10px;"><?php echo ($bot_info['can_join_groups'] ?? false) ? '是' : '否'; ?></td>
                            </tr>
                        </table>
                    <?php else: ?>
                        <p class="alert alert-error">无法获取Bot信息，请检查Token是否正确</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>🔗 Webhook 设置</h2>
                </div>
                <div class="card-body">
                    <table style="width: 100%; margin-bottom: 20px;">
                        <tr>
                            <td style="padding: 10px; width: 200px;"><strong>Webhook URL:</strong></td>
                            <td style="padding: 10px;"><?php echo $webhook_info['url'] ?? '未设置'; ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 10px;"><strong>状态:</strong></td>
                            <td style="padding: 10px;">
                                <span class="badge badge-<?php echo !empty($webhook_info['url']) ? 'success' : 'danger'; ?>">
                                    <?php echo !empty($webhook_info['url']) ? '已设置' : '未设置'; ?>
                                </span>
                            </td>
                        </tr>
                        <?php if (isset($webhook_info['pending_update_count'])): ?>
                            <tr>
                                <td style="padding: 10px;"><strong>等待处理的更新:</strong></td>
                                <td style="padding: 10px;"><?php echo $webhook_info['pending_update_count']; ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (isset($webhook_info['last_error_message'])): ?>
                            <tr>
                                <td style="padding: 10px;"><strong>最后错误:</strong></td>
                                <td style="padding: 10px; color: red;"><?php echo escape($webhook_info['last_error_message']); ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                    
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                        <p><strong>将要设置的 Webhook URL:</strong></p>
                        <code style="background: white; padding: 10px; display: block; border-radius: 4px;"><?php echo WEBHOOK_URL; ?></code>
                    </div>
                    
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="set_webhook" class="btn btn-success">设置 Webhook</button>
                    </form>
                    
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="delete_webhook" class="btn btn-danger" onclick="return confirm('确定要删除 Webhook 吗？')">删除 Webhook</button>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>🔧 机器人配置</h2>
                </div>
                <div class="card-body">
                    <div class="alert alert-success" style="margin-bottom: 20px;">
                        <strong>💡 提示：</strong>在这里修改机器人Token后，无需修改代码文件。保存后请重新设置 Webhook。
                    </div>
                    
                    <form method="POST">
                        <table style="width: 100%;">
                            <tr>
                                <td style="padding: 10px; width: 150px; vertical-align: top;">
                                    <strong>Bot Token:</strong>
                                    <span style="color: red;">*</span>
                                </td>
                                <td style="padding: 10px;">
                                    <input type="text" name="bot_token" class="form-control" 
                                           value="<?php echo escape($current_db_token ?: BOT_TOKEN); ?>" 
                                           placeholder="输入Bot Token" required
                                           style="width: 100%; padding: 10px; font-family: monospace;">
                                    <small style="color: #666;">从 @BotFather 获取的Token，格式如: 123456789:AAHxxxxxxx</small>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 10px; vertical-align: top;">
                                    <strong>Bot 用户名:</strong>
                                </td>
                                <td style="padding: 10px;">
                                    <input type="text" name="bot_username" class="form-control" 
                                           value="<?php echo escape($current_db_username ?: BOT_USERNAME); ?>" 
                                           placeholder="输入Bot用户名（可留空自动获取）"
                                           style="width: 100%; padding: 10px;">
                                    <small style="color: #666;">机器人用户名，如: mybot（不带@）。留空则自动从Token获取</small>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 10px; vertical-align: top;">
                                    <strong>网站 URL:</strong>
                                </td>
                                <td style="padding: 10px;">
                                    <input type="text" name="site_url" class="form-control" 
                                           value="<?php echo escape($current_db_site_url ?: SITE_URL); ?>" 
                                           placeholder="输入网站URL"
                                           style="width: 100%; padding: 10px;">
                                    <small style="color: #666;">用于设置Webhook的网站地址，如: https://example.com（不带结尾斜杠）</small>
                                </td>
                            </tr>
                        </table>
                        
                        <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 6px;">
                            <strong>⚠️ 注意事项：</strong>
                            <ul style="margin: 10px 0; padding-left: 20px;">
                                <li>修改 Token 后会切换到新的机器人</li>
                                <li>保存后需要重新设置 Webhook</li>
                                <li>确保新Token有效，否则保存会失败</li>
                            </ul>
                        </div>
                        
                        <div style="margin-top: 20px;">
                            <button type="submit" name="save_bot_config" class="btn btn-primary" style="padding: 12px 30px;">
                                💾 保存配置
                            </button>
                        </div>
                    </form>
                    
                    <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                        <strong>📊 当前配置来源：</strong>
                        <p style="margin: 10px 0 0 0;">
                            <?php if ($current_db_token): ?>
                                <span class="badge badge-success">数据库配置</span> - Token已保存在数据库中
                            <?php else: ?>
                                <span class="badge badge-warning">代码默认值</span> - 使用 config.php 中的默认配置
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>🎨 机器人外观设置</h2>
                </div>
                <div class="card-body">
                    <!-- 当前信息显示 -->
                    <div id="currentBotInfo" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                        <p style="text-align: center; color: #999;">加载中...</p>
                    </div>
                    
                    <!-- Tab选项卡 -->
                    <div class="tabs" style="margin-bottom: 20px;">
                        <button class="tab-btn active" onclick="switchTab('name')">名称</button>
                        <button class="tab-btn" onclick="switchTab('description')">描述</button>
                        <button class="tab-btn" onclick="switchTab('short_description')">简短描述</button>
                        <button class="tab-btn" onclick="switchTab('photo')">头像设置</button>
                    </div>
                    
                    <!-- 名称设置 -->
                    <div id="tabName" class="tab-content active">
                        <form id="nameForm">
                            <div class="form-group">
                                <label for="botNameInput">机器人名称 *</label>
                                <input type="text" id="botNameInput" class="form-control" required placeholder="输入新名称" maxlength="64">
                                <small style="color: #666;">用户在 Telegram 中看到的机器人名称</small>
                            </div>
                            <div class="form-group">
                                <label for="nameLanguage">语言代码（选填）</label>
                                <input type="text" id="nameLanguage" class="form-control" placeholder="例如: zh" maxlength="10">
                                <small style="color: #666;">留空则设置默认名称，填写则设置特定语言的名称（如: en, zh, ru）</small>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block">保存名称</button>
                        </form>
                    </div>
                    
                    <!-- 描述设置 -->
                    <div id="tabDescription" class="tab-content">
                        <form id="descriptionForm">
                            <div class="form-group">
                                <label for="botDescriptionInput">机器人描述</label>
                                <textarea id="botDescriptionInput" class="form-control" rows="5" placeholder="输入描述（可留空清除）" maxlength="512"></textarea>
                                <small style="color: #666;">显示在机器人个人资料的详细介绍，最多512字符</small>
                            </div>
                            <div class="form-group">
                                <label for="descLanguage">语言代码（选填）</label>
                                <input type="text" id="descLanguage" class="form-control" placeholder="例如: zh" maxlength="10">
                            </div>
                            <button type="submit" class="btn btn-primary btn-block">保存描述</button>
                        </form>
                    </div>
                    
                    <!-- 简短描述设置 -->
                    <div id="tabShortDescription" class="tab-content">
                        <form id="shortDescriptionForm">
                            <div class="form-group">
                                <label for="botShortDescInput">简短描述</label>
                                <input type="text" id="botShortDescInput" class="form-control" placeholder="输入简短描述（可留空清除）" maxlength="120">
                                <small style="color: #666;">显示在搜索结果中，最多120字符</small>
                            </div>
                            <div class="form-group">
                                <label for="shortDescLanguage">语言代码（选填）</label>
                                <input type="text" id="shortDescLanguage" class="form-control" placeholder="例如: zh" maxlength="10">
                            </div>
                            <button type="submit" class="btn btn-primary btn-block">保存简短描述</button>
                        </form>
                    </div>
                    
                    <!-- 头像设置 -->
                    <div id="tabPhoto" class="tab-content">
                        <div style="padding: 20px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; margin-bottom: 20px;">
                            <h4 style="color: #856404; margin-top: 0;">ℹ️ 关于机器人头像</h4>
                            <p style="color: #856404; margin: 10px 0;">
                                <strong>重要提示：</strong>Telegram Bot API 目前不支持通过程序设置机器人头像。
                            </p>
                            <p style="color: #856404; margin: 10px 0;">
                                您需要手动通过 <strong>@BotFather</strong> 来设置机器人头像。
                            </p>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 6px;">
                            <h4 style="margin-top: 0;">📝 设置步骤：</h4>
                            <ol style="line-height: 2;">
                                <li>在 Telegram 中搜索并打开 <code>@BotFather</code></li>
                                <li>发送命令 <code>/mybots</code></li>
                                <li>选择您要修改的机器人</li>
                                <li>点击 <strong>Edit Bot</strong></li>
                                <li>选择 <strong>Edit Botpic</strong></li>
                                <li>上传新的头像图片</li>
                            </ol>
                            
                            <div style="margin-top: 20px; padding: 15px; background: white; border-left: 4px solid #2196F3; border-radius: 4px;">
                                <strong>💡 图片要求：</strong>
                                <ul style="margin: 10px 0; padding-left: 20px;">
                                    <li>格式：JPG 或 PNG</li>
                                    <li>尺寸：建议 512x512 像素（正方形）</li>
                                    <li>大小：< 5MB</li>
                                </ul>
                            </div>
                            
                            <div style="margin-top: 20px; text-align: center;">
                                <a href="https://t.me/BotFather" target="_blank" class="btn btn-primary" style="display: inline-block; padding: 12px 30px; text-decoration: none; background: #0088cc; color: white; border-radius: 6px;">
                                    🤖 打开 @BotFather
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>📝 当前运行配置</h2>
                </div>
                <div class="card-body">
                    <table style="width: 100%;">
                        <tr>
                            <td style="padding: 10px; width: 200px;"><strong>Bot Token:</strong></td>
                            <td style="padding: 10px;">
                                <code><?php echo substr(BOT_TOKEN, 0, 20) . '...'; ?></code>
                                <?php if ($current_db_token): ?>
                                    <span class="badge badge-success" style="margin-left: 10px;">来自数据库</span>
                                <?php else: ?>
                                    <span class="badge badge-warning" style="margin-left: 10px;">代码默认值</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 10px;"><strong>Bot 用户名:</strong></td>
                            <td style="padding: 10px;">@<?php echo BOT_USERNAME; ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 10px;"><strong>网站URL:</strong></td>
                            <td style="padding: 10px;"><?php echo SITE_URL; ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 10px;"><strong>Webhook Path:</strong></td>
                            <td style="padding: 10px;"><?php echo WEBHOOK_URL; ?></td>
                        </tr>
                    </table>
                    
                    <div class="alert alert-success" style="margin-top: 20px;">
                        <strong>提示：</strong>
                        <ul style="margin: 10px 0; padding-left: 20px;">
                            <li>请确保 Webhook URL 可以从外网访问</li>
                            <li>建议使用 HTTPS（Telegram要求）</li>
                            <li>修改Token后记得重新设置 Webhook</li>
                            <li>设置定时任务来执行 bot/cron.php 以支持自动广告等功能</li>
                            <li>定时任务示例: <code>/www/server/php/73/bin/php /www/wwwroot/你的域名/bot/cron.php</code></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
    <script>
        let currentTab = 'name';
        
        // 切换Tab
        function switchTab(tab) {
            currentTab = tab;
            
            // 更新Tab按钮
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            // 更新内容
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            // 根据tab名称构建对应的ID
            let tabId = 'tabName';
            if (tab === 'description') {
                tabId = 'tabDescription';
            } else if (tab === 'short_description') {
                tabId = 'tabShortDescription';
            } else if (tab === 'photo') {
                tabId = 'tabPhoto';
            }
            
            document.getElementById(tabId).classList.add('active');
        }
        
        // 加载机器人外观信息
        function loadBotAppearanceInfo() {
            const container = document.getElementById('currentBotInfo');
            container.innerHTML = '<p style="text-align: center; color: #999;">加载中...</p>';
            
            fetch('api/bot_settings.php?action=get_bot_info')
                .then(response => {
                    console.log('Response status:', response.status);
                    if (!response.ok) {
                        throw new Error('HTTP error! status: ' + response.status);
                    }
                    return response.text();
                })
                .then(text => {
                    console.log('Response text:', text);
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON parse error:', e);
                        console.error('Response was:', text);
                        throw new Error('无效的JSON响应');
                    }
                })
                .then(data => {
                    console.log('Parsed data:', data);
                    if (data.success) {
                        const info = data.data;
                        container.innerHTML = `
                            <h4 style="margin: 0 0 10px 0;">📱 当前设置</h4>
                            <div style="line-height: 1.8;">
                                <div><strong>Bot ID:</strong> ${info.id || 'N/A'}</div>
                                <div><strong>用户名:</strong> @${info.username || 'N/A'}</div>
                                <div><strong>名称:</strong> ${escapeHtml(info.name || info.first_name || '(未设置)')}</div>
                                <div><strong>描述:</strong> ${escapeHtml(info.description || '(未设置)')}</div>
                                <div><strong>简短描述:</strong> ${escapeHtml(info.short_description || '(未设置)')}</div>
                            </div>
                        `;
                    } else {
                        container.innerHTML = '<p style="color: red;">加载失败: ' + (data.message || '未知错误') + '</p>';
                    }
                })
                .catch(error => {
                    console.error('Error loading bot info:', error);
                    container.innerHTML = '<p style="color: red;">加载失败: ' + error.message + '</p><p style="font-size: 12px; color: #666;">请检查浏览器控制台查看详细错误信息</p>';
                });
        }
        
        // 提交名称表单
        document.getElementById('nameForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const name = document.getElementById('botNameInput').value;
            const languageCode = document.getElementById('nameLanguage').value;
            
            const formData = new FormData();
            formData.append('action', 'set_bot_name');
            formData.append('name', name);
            if (languageCode) formData.append('language_code', languageCode);
            
            fetch('api/bot_settings.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    loadBotAppearanceInfo();
                    document.getElementById('nameForm').reset();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('操作失败');
            });
        });
        
        // 提交描述表单
        document.getElementById('descriptionForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const description = document.getElementById('botDescriptionInput').value;
            const languageCode = document.getElementById('descLanguage').value;
            
            const formData = new FormData();
            formData.append('action', 'set_bot_description');
            formData.append('description', description);
            if (languageCode) formData.append('language_code', languageCode);
            
            fetch('api/bot_settings.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    loadBotAppearanceInfo();
                    document.getElementById('descriptionForm').reset();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('操作失败');
            });
        });
        
        // 提交简短描述表单
        document.getElementById('shortDescriptionForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const shortDescription = document.getElementById('botShortDescInput').value;
            const languageCode = document.getElementById('shortDescLanguage').value;
            
            const formData = new FormData();
            formData.append('action', 'set_bot_short_description');
            formData.append('short_description', shortDescription);
            if (languageCode) formData.append('language_code', languageCode);
            
            fetch('api/bot_settings.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    loadBotAppearanceInfo();
                    document.getElementById('shortDescriptionForm').reset();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('操作失败');
            });
        });
        
        // 辅助函数
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, m => map[m]);
        }
        
        // 页面加载时获取信息
        loadBotAppearanceInfo();
    </script>
    
    <style>
        /* Tab样式 */
        .tabs {
            display: flex;
            border-bottom: 2px solid #eee;
            gap: 5px;
        }
        
        .tab-btn {
            padding: 10px 20px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .tab-btn:hover {
            background: #f8f9fa;
        }
        
        .tab-btn.active {
            border-bottom-color: #2196F3;
            color: #2196F3;
            font-weight: bold;
        }
        
        .tab-content {
            display: none;
            padding: 20px 0;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .btn-block {
            width: 100%;
            margin-top: 10px;
        }
        
        /* 表单输入样式 */
        input.form-control {
            border: 1px solid #ddd;
            border-radius: 4px;
            transition: border-color 0.3s;
        }
        
        input.form-control:focus {
            border-color: #2196F3;
            outline: none;
            box-shadow: 0 0 0 2px rgba(33, 150, 243, 0.1);
        }
        
        /* Badge样式增强 */
        .badge-warning {
            background-color: #ff9800;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
    </style>
</body>
</html>

