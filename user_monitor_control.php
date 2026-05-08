<?php
require_once 'config.php';
checkLogin();

$db = getDB();

// 获取守护进程状态
$stmt = $db->query("SELECT * FROM user_monitor_status WHERE id = 1");
$status = $stmt->fetch();

// 获取真人账号登录状态
$stmt = $db->query("SELECT * FROM user_account_config WHERE id = 1");
$accountConfig = $stmt->fetch();

// 判断进程是否运行中
$isRunning = false;
$pidFile = __DIR__ . '/logs/user_monitor.pid';
if ($status && $status['status'] === 'running' && file_exists($pidFile)) {
    $pid = (int)file_get_contents($pidFile);
    // 检查心跳
    $lastHeartbeat = strtotime($status['last_heartbeat']);
    $now = time();
    // 如果心跳超过3分钟，认为进程已停止
    if ($now - $lastHeartbeat < 180 && $pid > 0) {
        // 检查进程是否真的存在（仅Linux/Unix）
        if (PHP_OS !== 'WINNT') {
            $processExists = posix_kill($pid, 0);
            $isRunning = $processExists;
        } else {
            $isRunning = true; // Windows下无法检查，信任心跳
        }
    }
}

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>真人监听守护进程管理 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .status-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
            animation: pulse 2s infinite;
        }
        .status-running {
            background-color: #00ff00;
        }
        .status-stopped {
            background-color: #ff4444;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .control-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        .log-viewer {
            background: #1e1e1e;
            color: #d4d4d4;
            font-family: 'Courier New', monospace;
            padding: 20px;
            border-radius: 5px;
            max-height: 500px;
            overflow-y: auto;
            font-size: 13px;
            line-height: 1.6;
        }
        .command-box {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            margin: 10px 0;
        }
        .requirements-list {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>🔄 真人监听守护进程管理</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <!-- 状态卡片 -->
            <div class="status-card">
                <h2 style="margin-top: 0;">
                    <span class="status-indicator <?php echo $isRunning ? 'status-running' : 'status-stopped'; ?>"></span>
                    守护进程状态：<?php echo $isRunning ? '运行中' : '已停止'; ?>
                </h2>
                
                <?php if ($status): ?>
                    <div style="margin-top: 20px; opacity: 0.9;">
                        <p><strong>进程 PID：</strong><?php echo file_exists($pidFile) ? file_get_contents($pidFile) : 'N/A'; ?></p>
                        <p><strong>最后心跳：</strong><?php echo $status['last_heartbeat'] ? date('Y-m-d H:i:s', strtotime($status['last_heartbeat'])) : 'N/A'; ?></p>
                        <?php if ($status['start_time']): ?>
                            <p><strong>启动时间：</strong><?php echo date('Y-m-d H:i:s', strtotime($status['start_time'])); ?></p>
                        <?php endif; ?>
                        <?php if ($status['error_message']): ?>
                            <p><strong>错误信息：</strong><?php echo escape($status['error_message']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <div class="control-buttons">
                    <?php if (!$isRunning): ?>
                        <button class="btn btn-success" onclick="startDaemon()">▶️ 启动守护进程</button>
                    <?php else: ?>
                        <button class="btn btn-danger" onclick="stopDaemon()">⏹️ 停止守护进程</button>
                        <button class="btn btn-warning" onclick="restartDaemon()">🔄 重启守护进程</button>
                    <?php endif; ?>
                    <button class="btn btn-info" onclick="checkStatus()">🔍 检查状态</button>
                    <button class="btn btn-secondary" onclick="viewLogs()">📋 查看日志</button>
                </div>
            </div>
            
            <!-- 前置条件检查 -->
            <div class="card">
                <div class="card-header">
                    <h2>📋 前置条件检查</h2>
                </div>
                <div class="card-body">
                    <div class="requirements-list">
                        <h3 style="margin-top: 0;">启动守护进程前，请确保：</h3>
                        <ul style="margin: 10px 0;">
                            <li>
                                <strong>真人账号已登录：</strong>
                                <?php if ($accountConfig && $accountConfig['is_logged_in']): ?>
                                    <span style="color: green;">✓ 已登录（<?php echo escape($accountConfig['phone_number']); ?>）</span>
                                <?php else: ?>
                                    <span style="color: red;">✗ 未登录</span>
                                    <a href="user_account_config.php">前往登录</a>
                                <?php endif; ?>
                            </li>
                            <li>
                                <strong>MadelineProto已安装：</strong>
                                <?php if (file_exists(__DIR__ . '/vendor/danog/madelineproto/composer.json')): ?>
                                    <span style="color: green;">✓ 已安装</span>
                                <?php else: ?>
                                    <span style="color: red;">✗ 未安装</span>
                                    <code>bash install_madeline.sh</code>
                                <?php endif; ?>
                            </li>
                            <li>
                                <strong>SSH访问权限：</strong> 需要有服务器 SSH 权限来启动守护进程
                            </li>
                            <li>
                                <strong>关键词监控已配置：</strong> 至少有一条使用"真人账号监听"或"双模式"的监控规则
                                <a href="keyword_monitor.php">前往配置</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- 手动启动说明 -->
            <div class="card">
                <div class="card-header">
                    <h2>💻 手动启动（SSH命令）</h2>
                </div>
                <div class="card-body">
                    <p><strong>提示：</strong>现在可以直接点击上方按钮启动/停止守护进程，无需手动执行SSH命令！</p>
                    <p>如果Web按钮不可用，可以使用以下SSH命令：</p>
                    
                    <h3>方法一：使用 nohup（推荐）</h3>
                    <div class="command-box">
                        cd <?php echo __DIR__; ?><br>
                        nohup php bot/user_monitor.php > logs/user_monitor.log 2>&1 &
                    </div>
                    
                    <h3 style="margin-top: 20px;">方法二：使用 screen</h3>
                    <div class="command-box">
                        cd <?php echo __DIR__; ?><br>
                        screen -S user_monitor<br>
                        php bot/user_monitor.php<br>
                        # 按 Ctrl+A, 然后按 D 退出 screen
                    </div>
                    
                    <h3 style="margin-top: 20px;">查看进程状态</h3>
                    <div class="command-box">
                        ps aux | grep user_monitor
                    </div>
                    
                    <h3 style="margin-top: 20px;">停止进程</h3>
                    <div class="command-box">
                        kill -9 $(cat logs/user_monitor.pid)
                    </div>
                    
                    <h3 style="margin-top: 20px;">查看日志</h3>
                    <div class="command-box">
                        tail -f logs/user_monitor.log
                    </div>
                </div>
            </div>
            
            <!-- 日志查看 -->
            <div id="logSection" class="card" style="display: none;">
                <div class="card-header">
                    <h2>📋 守护进程日志</h2>
                    <button class="btn btn-sm btn-secondary" onclick="refreshLogs()">刷新</button>
                </div>
                <div class="card-body">
                    <div id="logContent" class="log-viewer">
                        加载中...
                    </div>
                </div>
            </div>
            
            <!-- 使用说明 -->
            <div class="card">
                <div class="card-header">
                    <h2>📖 使用说明</h2>
                </div>
                <div class="card-body">
                    <h3>真人监听模式的优势</h3>
                    <ul>
                        <li>✅ <strong>更隐蔽：</strong>使用真实账号监听，不需要机器人在群组中</li>
                        <li>✅ <strong>更真实：</strong>回复消息显示为真人账号，不是机器人</li>
                        <li>✅ <strong>更灵活：</strong>可以监听任何您加入的群组</li>
                        <li>✅ <strong>双模式：</strong>可以同时使用机器人+真人账号，双重保障</li>
                    </ul>
                    
                    <h3 style="margin-top: 20px;">工作原理</h3>
                    <p>守护进程使用 MadelineProto 登录您的真实 Telegram 账号，持续监听所有群组消息。当检测到关键词时：</p>
                    <ol>
                        <li>发送通知到指定账号（如果配置了）</li>
                        <li>使用真人账号自动回复（如果启用了）</li>
                        <li>支持延迟回复，模拟真人操作</li>
                    </ol>
                    
                    <h3 style="margin-top: 20px;">注意事项</h3>
                    <ul>
                        <li>⚠️ 守护进程会保持长期运行，占用一定系统资源</li>
                        <li>⚠️ 不要频繁重启，可能触发 Telegram 的防滥用机制</li>
                        <li>⚠️ 建议使用小号进行监听，避免主号被限制</li>
                        <li>⚠️ 定期查看日志，确保进程运行正常</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
    <script>
        // 启动守护进程
        async function startDaemon() {
            App.showAlert('正在启动守护进程，请稍候...', 'info');
            
            try {
                const result = await App.request('api/user_monitor_control.php', 'POST', {
                    action: 'start'
                });
                
                if (result && result.success) {
                    App.showAlert('✓ ' + result.message, 'success');
                    setTimeout(() => location.reload(), 2000);
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }
        
        // 停止守护进程
        async function stopDaemon() {
            if (!App.confirm('确定要停止守护进程吗？')) return;
            
            App.showAlert('正在停止守护进程...', 'info');
            
            try {
                const result = await App.request('api/user_monitor_control.php', 'POST', {
                    action: 'stop'
                });
                
                if (result && result.success) {
                    App.showAlert('✓ ' + result.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }
        
        // 重启守护进程
        async function restartDaemon() {
            if (!App.confirm('确定要重启守护进程吗？这可能需要几秒钟。')) return;
            
            App.showAlert('正在重启守护进程，请稍候...（预计需要5秒）', 'info');
            
            try {
                const result = await App.request('api/user_monitor_control.php', 'POST', {
                    action: 'restart'
                });
                
                if (result && result.success) {
                    App.showAlert('✓ ' + result.message, 'success');
                    setTimeout(() => location.reload(), 2000);
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }
        
        // 检查状态
        async function checkStatus() {
            try {
                const result = await App.request('api/user_monitor_control.php', 'POST', {
                    action: 'status'
                });
                
                if (result && result.success) {
                    App.showAlert(result.message + (result.pid ? ' (PID: ' + result.pid + ')' : ''), 'info');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    location.reload();
                }
            } catch (error) {
                console.error('Error:', error);
                location.reload();
            }
        }
        
        // 查看日志
        async function viewLogs() {
            document.getElementById('logSection').style.display = 'block';
            document.getElementById('logContent').scrollIntoView({ behavior: 'smooth' });
            refreshLogs();
        }
        
        // 刷新日志
        async function refreshLogs() {
            try {
                const response = await fetch('logs/user_monitor.log');
                const text = await response.text();
                
                if (text.trim()) {
                    // 只显示最后50行
                    const lines = text.trim().split('\n');
                    const lastLines = lines.slice(-50).join('\n');
                    document.getElementById('logContent').textContent = lastLines;
                } else {
                    document.getElementById('logContent').textContent = '暂无日志';
                }
                
                // 滚动到底部
                const logContent = document.getElementById('logContent');
                logContent.scrollTop = logContent.scrollHeight;
            } catch (error) {
                document.getElementById('logContent').textContent = '无法加载日志：' + error.message;
            }
        }
        
        // 每30秒自动刷新日志（如果日志区域可见）
        setInterval(() => {
            const logSection = document.getElementById('logSection');
            if (logSection.style.display !== 'none') {
                refreshLogs();
            }
        }, 30000);
    </script>
</body>
</html>

