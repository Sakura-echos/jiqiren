<?php
require_once 'config.php';
checkLogin();

$db = getDB();

// 获取配置
$stmt = $db->query("SELECT * FROM user_account_config WHERE id = 1");
$config = $stmt->fetch();

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>真人账号配置 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>👤 真人账号配置</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <div class="alert alert-warning">
                <strong>⚠️ 重要提示：</strong>
                <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                    <li>使用真人账号自动回复需要您的Telegram账号登录信息</li>
                    <li>请确保您了解Telegram的使用条款，过度自动化可能导致账号被限制</li>
                    <li>建议使用小号或专门的工作号，不要使用主号</li>
                    <li>Session信息会加密存储在数据库中</li>
                </ul>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>账号状态</h2>
                </div>
                <div class="card-body">
                    <?php if ($config && $config['is_logged_in']): ?>
                        <div class="alert alert-success">
                            <strong>✅ 已登录</strong><br>
                            账号：<?php echo escape($config['phone_number']); ?><br>
                            登录时间：<?php echo $config['logged_in_at']; ?><br>
                            <button class="btn btn-danger" onclick="logout()" style="margin-top: 10px;">退出登录</button>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <strong>ℹ️ 未登录</strong><br>
                            请先登录您的Telegram账号
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <h2>登录Telegram账号</h2>
                </div>
                <div class="card-body">
                    <form onsubmit="event.preventDefault(); loginAccount();">
                        <div class="form-group">
                            <label>手机号 *</label>
                            <input type="tel" id="phoneNumber" class="form-control" required placeholder="+8613800138000">
                            <small class="form-text">请输入完整的国际格式手机号，例如：+86 开头</small>
                        </div>
                        
                        <div id="codeStep" style="display: none;">
                            <div class="form-group">
                                <label>验证码 *</label>
                                <input type="text" id="verificationCode" class="form-control" placeholder="输入收到的验证码">
                                <small class="form-text">Telegram会发送验证码到您的手机或其他设备</small>
                            </div>
                            
                            <div class="form-group">
                                <label>两步验证密码（如有）</label>
                                <input type="password" id="twoFactorPassword" class="form-control" placeholder="如果启用了两步验证，请输入密码">
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary" id="loginBtn">发送验证码</button>
                        <button type="button" class="btn btn-success" id="verifyBtn" onclick="verifyCode()" style="display: none;">验证登录</button>
                    </form>
                </div>
            </div>
            
            <div class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <h2>📖 使用说明</h2>
                </div>
                <div class="card-body">
                    <h3>什么是真人账号自动回复？</h3>
                    <p>当检测到关键词时，使用您的真实Telegram账号（而不是机器人）自动发送回复消息。这样看起来更像是真人在回复。</p>
                    
                    <h3 style="margin-top: 20px;">如何使用？</h3>
                    <ol>
                        <li>在本页面登录您的Telegram账号（建议使用小号）</li>
                        <li>前往"关键词监控"页面</li>
                        <li>添加监控规则时，选择"真人账号自动回复"</li>
                        <li>设置要回复的内容</li>
                        <li>当有人发送包含关键词的消息时，您的账号会自动回复</li>
                    </ol>
                    
                    <h3 style="margin-top: 20px;">注意事项</h3>
                    <ul>
                        <li>⚠️ 不要频繁自动回复，可能被Telegram视为滥用</li>
                        <li>⚠️ 建议使用专门的工作号，不要使用个人主号</li>
                        <li>⚠️ Session信息会保存在服务器，请确保服务器安全</li>
                        <li>⚠️ 如果账号被限制，需要重新登录</li>
                    </ul>
                    
                    <h3 style="margin-top: 20px;">技术说明</h3>
                    <p>本功能使用Telegram的MTProto API（用户API），而不是Bot API。这允许使用真实用户账号发送消息。</p>
                </div>
            </div>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
    <script>
        let phoneNumber = '';
        
        async function loginAccount() {
            phoneNumber = document.getElementById('phoneNumber').value;
            
            if (!phoneNumber) {
                App.showAlert('请输入手机号', 'error');
                return;
            }
            
            // 验证手机号格式
            if (!phoneNumber.startsWith('+')) {
                App.showAlert('手机号必须以 + 开头，例如：+8613800138000', 'error');
                return;
            }
            
            // 显示加载提示
            App.showAlert('正在发送验证码，请稍候...（首次可能需要1-2分钟）', 'info');
            
            // 禁用按钮防止重复点击
            const btn = document.getElementById('loginBtn');
            btn.disabled = true;
            btn.textContent = '发送中...';
            
            try {
                const result = await App.request('api/user_account.php', 'POST', {
                    action: 'send_code',
                    phone_number: phoneNumber
                });
                
                if (result && result.success) {
                    App.showAlert('验证码已发送，请查看您的Telegram');
                    document.getElementById('codeStep').style.display = 'block';
                    document.getElementById('loginBtn').style.display = 'none';
                    document.getElementById('verifyBtn').style.display = 'inline-block';
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('发送失败: ' + (error.message || '请检查浏览器控制台'), 'error');
            } finally {
                // 恢复按钮
                btn.disabled = false;
                btn.textContent = '发送验证码';
            }
        }
        
        async function verifyCode() {
            const code = document.getElementById('verificationCode').value;
            const password = document.getElementById('twoFactorPassword').value;
            
            if (!code) {
                App.showAlert('请输入验证码', 'error');
                return;
            }
            
            const result = await App.request('api/user_account.php', 'POST', {
                action: 'verify_code',
                phone_number: phoneNumber,
                code: code,
                password: password || null
            });
            
            if (result && result.success) {
                App.showAlert('登录成功！');
                setTimeout(() => location.reload(), 1500);
            }
        }
        
        async function logout() {
            if (!App.confirm('确定要退出登录吗？')) return;
            
            const result = await App.request('api/user_account.php', 'POST', {
                action: 'logout'
            });
            
            if (result && result.success) {
                App.showAlert('已退出登录');
                setTimeout(() => location.reload(), 1000);
            }
        }
    </script>
</body>
</html>

