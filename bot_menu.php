<?php
/**
 * 机器人底部菜单按钮管理
 */
session_start();
require_once 'config.php';

// 检查登录
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$db = getDB();

// 获取当前菜单配置
try {
    $stmt = $db->query("SELECT * FROM bot_menu_settings LIMIT 1");
    $menu_config = $stmt->fetch();
} catch (Exception $e) {
    $menu_config = null;
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>底部菜单按钮 - TG机器人管理系统</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .preview-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px;
            border-radius: 15px;
            color: white;
            margin-bottom: 30px;
        }
        
        .phone-mockup {
            background: white;
            border-radius: 20px;
            padding: 20px;
            max-width: 400px;
            margin: 20px auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        
        .chat-area {
            background: #f0f0f0;
            border-radius: 10px;
            padding: 15px;
            min-height: 200px;
            margin-bottom: 15px;
        }
        
        .message {
            background: #dcf8c6;
            padding: 10px 15px;
            border-radius: 10px;
            margin-bottom: 10px;
            max-width: 80%;
        }
        
        .menu-button-preview {
            background: #2196f3;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            text-align: center;
            font-weight: bold;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .menu-button-preview:hover {
            background: #1976d2;
        }
        
        .command-item {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid #2196f3;
        }
        
        .command-item h4 {
            margin: 0 0 10px 0;
            color: #333;
        }
        
        .example-box {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border-left: 4px solid #2196f3;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h1>🔘 底部菜单按钮设置</h1>
            <p>在聊天框底部添加快捷菜单按钮</p>
        </div>
        
        <?php if (!$menu_config): ?>
        <!-- 数据库提示 -->
        <div style="background: #fff3cd; padding: 20px; border-radius: 10px; margin-bottom: 20px; border-left: 4px solid #ffc107;">
            <h3 style="margin-top: 0; color: #856404;">⚠️ 首次使用提示</h3>
            <p style="margin: 10px 0;">检测到数据库表可能未创建，请先执行以下操作：</p>
            <ol style="margin: 10px 0 10px 20px; line-height: 1.8;">
                <li>在服务器SSH终端执行：
                    <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; margin: 10px 0;">cd /www/wwwroot/tgbot.5088.buzz
mysql -u tgbot -ptgbot tgbot &lt; database_bot_menu.sql</pre>
                </li>
                <li>或者在phpMyAdmin中导入 <code>database_bot_menu.sql</code> 文件</li>
                <li>刷新本页面，即可开始配置</li>
            </ol>
        </div>
        <?php endif; ?>
        
        <!-- 预览区 -->
        <div class="preview-section">
            <h2 style="text-align: center; margin-top: 0;">📱 效果预览</h2>
            <div class="phone-mockup">
                <div class="chat-area">
                    <div class="message">
                        欢迎使用自动发卡系统！
                    </div>
                    <div class="message">
                        💰 当前余额：$0.00
                    </div>
                </div>
                <div class="menu-button-preview" id="previewButton">
                    <span id="previewIcon">☰</span>
                    <span id="previewText">Menu</span>
                </div>
            </div>
        </div>
        
        <!-- 配置说明 -->
        <div class="example-box">
            <strong>💡 功能说明：</strong>
            <ul style="margin: 10px 0 0 20px; line-height: 1.8;">
                <li>底部菜单按钮会固定显示在聊天框输入区左侧</li>
                <li>用户点击按钮后，会自动发送指定的命令（如 /start）</li>
                <li>这是Telegram官方的 <strong>Bot Menu Button</strong> 功能</li>
                <li>建议设置为 <code>/start</code> 或 <code>/menu</code> 命令</li>
            </ul>
        </div>
        
        <!-- 配置表单 -->
        <div class="card">
            <div class="card-header">
                <h2>⚙️ 菜单按钮配置</h2>
            </div>
            <form id="menuForm" style="padding: 20px;">
                <div class="form-group">
                    <label>按钮状态</label>
                    <div style="display: flex; gap: 20px; margin-top: 10px;">
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="radio" name="enabled" value="1" <?php echo ($menu_config && $menu_config['enabled']) ? 'checked' : ''; ?>>
                            <span>✅ 启用</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="radio" name="enabled" value="0" <?php echo (!$menu_config || !$menu_config['enabled']) ? 'checked' : ''; ?>>
                            <span>❌ 禁用</span>
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>按钮文本 *</label>
                    <input type="text" 
                           id="buttonText" 
                           name="button_text" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($menu_config['button_text'] ?? 'Menu'); ?>" 
                           placeholder="Menu" 
                           required
                           oninput="updatePreview()">
                    <small style="color: #666;">显示在按钮上的文字，如：Menu、菜单、主菜单</small>
                </div>
                
                <div class="form-group">
                    <label>按钮图标 (emoji)</label>
                    <input type="text" 
                           id="buttonIcon" 
                           name="button_icon" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($menu_config['button_icon'] ?? '☰'); ?>" 
                           placeholder="☰"
                           readonly
                           style="cursor: pointer;"
                           onclick="document.getElementById('emojiPicker').style.display='block'">
                    <small style="color: #666;">点击输入框选择emoji</small>
                    
                    <!-- Emoji 选择器 -->
                    <div id="emojiPicker" style="display: none; background: white; border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-top: 10px; max-width: 400px;">
                        <div style="margin-bottom: 10px; font-weight: bold; color: #333;">选择图标：</div>
                        <div style="display: grid; grid-template-columns: repeat(8, 1fr); gap: 8px;">
                            <?php
                            $emojis = ['☰', '🏠', '📋', '📱', '🎯', '⭐', '🛍️', '💰', 
                                      '🔥', '✨', '📦', '🎁', '💎', '🌟', '⚡', '🔔',
                                      '📞', '💬', '🌐', '📊', '⚙️', '🔧', '🎨', '🎵'];
                            foreach ($emojis as $emoji) {
                                echo '<button type="button" class="emoji-btn" onclick="selectEmoji(\'' . $emoji . '\')" style="font-size: 24px; border: 1px solid #e0e0e0; background: white; border-radius: 4px; padding: 8px; cursor: pointer; transition: all 0.2s;">' . $emoji . '</button>';
                            }
                            ?>
                        </div>
                        <div style="margin-top: 10px; text-align: right;">
                            <button type="button" class="btn btn-sm" onclick="document.getElementById('emojiPicker').style.display='none'">关闭</button>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>触发命令 *</label>
                    <select id="commandSelect" name="command" class="form-control" required>
                        <option value="/start" <?php echo ($menu_config && $menu_config['command'] == '/start') ? 'selected' : ''; ?>>/start - 主菜单</option>
                        <option value="/menu" <?php echo ($menu_config && $menu_config['command'] == '/menu') ? 'selected' : ''; ?>>/menu - 菜单</option>
                        <option value="/shop" <?php echo ($menu_config && $menu_config['command'] == '/shop') ? 'selected' : ''; ?>>/shop - 商城</option>
                        <option value="/help" <?php echo ($menu_config && $menu_config['command'] == '/help') ? 'selected' : ''; ?>>/help - 帮助</option>
                    </select>
                    <small style="color: #666;">用户点击按钮时自动发送的命令</small>
                </div>
                
                <div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;">
                    <strong>⚠️ 注意事项：</strong>
                    <ul style="margin: 10px 0 0 20px; line-height: 1.8;">
                        <li>保存后需要用户重新打开聊天才能看到新的按钮</li>
                        <li>或者用户发送任意消息后，按钮会自动更新</li>
                        <li>建议使用简短的文字，如"Menu"或"菜单"</li>
                    </ul>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn" onclick="testMenu()">🧪 测试</button>
                    <button type="submit" class="btn btn-primary">💾 保存配置</button>
                </div>
            </form>
        </div>
        
        <!-- 常用配置示例 -->
        <div class="card">
            <div class="card-header">
                <h2>📝 常用配置示例</h2>
            </div>
            <div style="padding: 20px;">
                <div class="command-item">
                    <h4>🏠 主菜单模式（推荐）</h4>
                    <p><strong>按钮文本：</strong>Menu 或 主菜单</p>
                    <p><strong>按钮图标：</strong>☰ 或 🏠</p>
                    <p><strong>触发命令：</strong>/start</p>
                    <p style="color: #666; margin: 0;">适合发卡商城，点击直接显示主菜单</p>
                </div>
                
                <div class="command-item">
                    <h4>🛍️ 商城模式</h4>
                    <p><strong>按钮文本：</strong>Shop 或 商城</p>
                    <p><strong>按钮图标：</strong>🛍️ 或 🏪</p>
                    <p><strong>触发命令：</strong>/shop</p>
                    <p style="color: #666; margin: 0;">直接进入商品列表</p>
                </div>
                
                <div class="command-item">
                    <h4>📋 多语言模式</h4>
                    <p><strong>按钮文本：</strong>Menu / 菜单</p>
                    <p><strong>按钮图标：</strong>☰</p>
                    <p><strong>触发命令：</strong>/start</p>
                    <p style="color: #666; margin: 0;">中英文混合，兼容多语言用户</p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // 选择emoji
        function selectEmoji(emoji) {
            document.getElementById('buttonIcon').value = emoji;
            document.getElementById('emojiPicker').style.display = 'none';
            updatePreview();
        }
        
        // 更新预览
        function updatePreview() {
            const text = document.getElementById('buttonText').value || 'Menu';
            const icon = document.getElementById('buttonIcon').value || '☰';
            
            document.getElementById('previewText').textContent = text;
            document.getElementById('previewIcon').textContent = icon;
        }
        
        // Emoji按钮hover效果
        document.addEventListener('DOMContentLoaded', function() {
            const emojiButtons = document.querySelectorAll('.emoji-btn');
            emojiButtons.forEach(btn => {
                btn.addEventListener('mouseenter', function() {
                    this.style.background = '#e3f2fd';
                    this.style.transform = 'scale(1.1)';
                });
                btn.addEventListener('mouseleave', function() {
                    this.style.background = 'white';
                    this.style.transform = 'scale(1)';
                });
            });
        });
        
        // 保存配置
        document.getElementById('menuForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const data = {
                enabled: parseInt(formData.get('enabled')),
                button_text: formData.get('button_text'),
                button_icon: formData.get('button_icon'),
                command: formData.get('command')
            };
            
            try {
                const response = await fetch('api/bot_menu.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                
                const result = await response.json();
                console.log('API Response:', result);
                
                if (result.success) {
                    alert('✅ 保存成功！\n\n' + 
                          '用户需要：\n' +
                          '1. 重新打开聊天，或\n' + 
                          '2. 发送任意消息\n' +
                          '即可看到新的底部菜单按钮');
                    location.reload(); // 刷新页面
                } else {
                    alert('❌ 保存失败：' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('❌ 操作失败！\n\n错误信息：' + error.message + '\n\n请检查浏览器控制台查看详细错误');
            }
        });
        
        // 测试菜单按钮
        async function testMenu() {
            if (!confirm('确定要立即应用到机器人吗？')) return;
            
            const formData = new FormData(document.getElementById('menuForm'));
            const data = {
                enabled: parseInt(formData.get('enabled')),
                button_text: formData.get('button_text'),
                button_icon: formData.get('button_icon'),
                command: formData.get('command'),
                test: true
            };
            
            try {
                const response = await fetch('api/bot_menu.php?action=test', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ 测试成功！\n\n' + 
                          '请打开Telegram机器人，查看聊天框左下角的菜单按钮\n\n' +
                          '如果没看到，请发送任意消息刷新界面');
                } else {
                    alert('❌ 测试失败：' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('操作失败，请重试');
            }
        }
        
        // 页面加载时更新预览
        updatePreview();
    </script>
</body>
</html>

