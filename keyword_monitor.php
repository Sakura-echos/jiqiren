<?php
require_once 'config.php';
checkLogin();

$db = getDB();

// 获取所有群组
$stmt = $db->query("SELECT id, title FROM groups WHERE is_active = 1 ORDER BY title");
$groups = $stmt->fetchAll();

// 获取关键词监控列表
$stmt = $db->query("SELECT km.*, g.title as group_title FROM keyword_monitor km LEFT JOIN groups g ON km.group_id = g.id ORDER BY km.id DESC");
$monitors = $stmt->fetchAll();

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>关键词监控 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>🔍 关键词监控</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <div class="alert alert-info">
                <strong>功能说明：</strong>
                <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                    <li>监控群组中的特定关键词，自动转发消息到指定账号</li>
                    <li>支持多种匹配模式：精确匹配、包含、开头、结尾、正则表达式</li>
                    <li>可按群组设置或监控所有群组</li>
                    <li>通知账号需要先与机器人私聊，输入 /start 激活</li>
                </ul>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>监控规则列表</h2>
                    <button class="btn btn-primary" onclick="App.showModal('addMonitorModal')">+ 添加监控</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>关键词</th>
                                    <th>匹配方式</th>
                                    <th>监听模式</th>
                                    <th>通知账号</th>
                                    <th>应用群组</th>
                                    <th>状态</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($monitors)): ?>
                                    <tr>
                                        <td colspan="9" class="empty-state">暂无监控规则</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($monitors as $monitor): ?>
                                        <tr>
                                            <td><?php echo $monitor['id']; ?></td>
                                            <td><?php echo escape($monitor['keyword']); ?></td>
                                            <td><span class="badge badge-primary"><?php echo escape($monitor['match_type']); ?></span></td>
                                            <td>
                                                <?php 
                                                $monitorMode = $monitor['monitor_mode'] ?? 'bot';
                                                $modeBadgeClass = $monitorMode === 'user' ? 'badge-info' : ($monitorMode === 'both' ? 'badge-warning' : 'badge-success');
                                                $modeText = $monitorMode === 'user' ? '真人监听' : ($monitorMode === 'both' ? '双模式' : '机器人');
                                                ?>
                                                <span class="badge <?php echo $modeBadgeClass; ?>"><?php echo $modeText; ?></span>
                                            </td>
                                            <td><code><?php echo escape($monitor['notify_user_id']); ?></code></td>
                                            <td><?php echo $monitor['group_title'] ? escape($monitor['group_title']) : '所有群组'; ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $monitor['is_active'] ? 'success' : 'danger'; ?>">
                                                    <?php echo $monitor['is_active'] ? '启用' : '禁用'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($monitor['created_at'])); ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-primary" onclick="editMonitor(<?php echo $monitor['id']; ?>)">编辑</button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteMonitor(<?php echo $monitor['id']; ?>)">删除</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <h2>📖 使用说明</h2>
                </div>
                <div class="card-body">
                    <h3>1. 如何获取通知账号的 User ID？</h3>
                    <p>方法一：使用 @userinfobot（推荐）</p>
                    <ol>
                        <li>在 Telegram 中搜索 <code>@userinfobot</code></li>
                        <li>点击 Start 启动机器人</li>
                        <li>机器人会返回您的 User ID</li>
                    </ol>
                    
                    <p>方法二：使用本机器人</p>
                    <ol>
                        <li>与您的机器人私聊</li>
                        <li>发送 /start 命令</li>
                        <li>查看日志文件获取您的 User ID</li>
                    </ol>
                    
                    <h3 style="margin-top: 20px;">2. 激活通知功能</h3>
                    <p>在添加监控前，<strong>必须</strong>先用通知账号与机器人私聊并发送 /start，否则机器人无法主动发送消息。</p>
                    
                    <h3 style="margin-top: 20px;">3. 匹配模式说明</h3>
                    <ul>
                        <li><strong>精确匹配</strong>：消息内容完全等于关键词</li>
                        <li><strong>包含</strong>：消息内容包含关键词</li>
                        <li><strong>开头匹配</strong>：消息以关键词开头</li>
                        <li><strong>结尾匹配</strong>：消息以关键词结尾</li>
                        <li><strong>正则表达式</strong>：使用正则表达式匹配（高级用户）</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 添加监控模态框 -->
    <div id="addMonitorModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>添加关键词监控</h3>
            </div>
            <form onsubmit="event.preventDefault(); addMonitor();">
                <div class="form-group">
                    <label>关键词 *</label>
                    <input type="text" id="monitorKeyword" class="form-control" required placeholder="输入要监控的关键词...">
                    <small class="form-text">输入您想要监控的关键词，例如：付款、订单、客服等</small>
                </div>
                
                <div class="form-group">
                    <label>匹配方式 *</label>
                    <select id="monitorMatchType" class="form-control" required>
                        <option value="contains">包含（推荐）</option>
                        <option value="exact">精确匹配</option>
                        <option value="starts_with">开头匹配</option>
                        <option value="ends_with">结尾匹配</option>
                        <option value="regex">正则表达式</option>
                    </select>
                    <small class="form-text">选择如何匹配关键词，一般选择"包含"即可</small>
                </div>
                
                <div class="form-group">
                    <label>监听模式 *</label>
                    <select id="monitorMode" class="form-control" required>
                        <option value="bot">机器人监听（默认）</option>
                        <option value="user">真人账号监听（更隐蔽）</option>
                        <option value="both">双模式（机器人+真人）</option>
                    </select>
                    <small class="form-text">
                        <strong>机器人监听：</strong>通过机器人监听消息（需要机器人在群组中）<br>
                        <strong>真人账号监听：</strong>用您的真实账号监听（更隐蔽，需启动守护进程）<br>
                        <strong>双模式：</strong>同时使用两种方式（推荐）
                    </small>
                </div>
                
                <div class="form-group">
                    <label>通知账号 User ID *</label>
                    <input type="text" id="monitorUserId" class="form-control" required placeholder="例如：123456789">
                    <small class="form-text">接收通知的 Telegram 账号 ID（纯数字）</small>
                </div>
                
                <div class="form-group">
                    <label>应用群组</label>
                    <select id="monitorGroupId" class="form-control">
                        <option value="">所有群组</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"><?php echo escape($group['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text">选择要监控的群组，不选则监控所有群组</small>
                </div>
                
                <div class="form-group">
                    <label>描述（可选）</label>
                    <input type="text" id="monitorDescription" class="form-control" placeholder="例如：监控付款相关消息">
                    <small class="form-text">备注说明，方便管理</small>
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center;">
                        <input type="checkbox" id="monitorAutoReply" onchange="toggleAutoReply()" style="margin-right: 8px;"> 
                        启用自动回复
                    </label>
                    <small class="form-text">检测到关键词后自动发送回复消息</small>
                </div>
                
                <div id="autoReplyOptions" style="display: none; padding: 15px; background: #f5f5f5; border-radius: 5px; margin-top: 10px;">
                    <div class="form-group">
                        <label>回复消息内容 *</label>
                        <textarea id="monitorReplyMessage" class="form-control" rows="3" placeholder="输入要自动回复的内容..."></textarea>
                        <small class="form-text">支持变量：{name}（用户名）, {username}（@用户名）</small>
                    </div>
                    
                    <div class="form-group">
                        <label>回复方式</label>
                        <select id="monitorUseUserAccount" class="form-control">
                            <option value="0">使用机器人账号回复</option>
                            <option value="1">使用真人账号回复（更真实）</option>
                        </select>
                        <small class="form-text">真人账号需要先在"真人账号配置"页面登录</small>
                    </div>
                    
                    <div class="form-group">
                        <label>回复延迟（秒）</label>
                        <input type="number" id="monitorReplyDelay" class="form-control" min="0" max="60" value="0">
                        <small class="form-text">延迟几秒后回复，模拟真人操作（0=立即回复）</small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('addMonitorModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">添加</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 编辑监控模态框 -->
    <div id="editMonitorModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>编辑关键词监控</h3>
            </div>
            <form onsubmit="event.preventDefault(); updateMonitor();">
                <input type="hidden" id="editMonitorId">
                
                <div class="form-group">
                    <label>关键词 *</label>
                    <input type="text" id="editMonitorKeyword" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>匹配方式 *</label>
                    <select id="editMonitorMatchType" class="form-control" required>
                        <option value="contains">包含</option>
                        <option value="exact">精确匹配</option>
                        <option value="starts_with">开头匹配</option>
                        <option value="ends_with">结尾匹配</option>
                        <option value="regex">正则表达式</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>监听模式 *</label>
                    <select id="editMonitorMode" class="form-control" required>
                        <option value="bot">机器人监听（默认）</option>
                        <option value="user">真人账号监听（更隐蔽）</option>
                        <option value="both">双模式（机器人+真人）</option>
                    </select>
                    <small class="form-text">
                        <strong>机器人监听：</strong>通过机器人监听消息（需要机器人在群组中）<br>
                        <strong>真人账号监听：</strong>用您的真实账号监听（更隐蔽，需启动守护进程）<br>
                        <strong>双模式：</strong>同时使用两种方式（推荐）
                    </small>
                </div>
                
                <div class="form-group">
                    <label>通知账号 User ID *</label>
                    <input type="text" id="editMonitorUserId" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>应用群组</label>
                    <select id="editMonitorGroupId" class="form-control">
                        <option value="">所有群组</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"><?php echo escape($group['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>描述（可选）</label>
                    <input type="text" id="editMonitorDescription" class="form-control">
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center;">
                        <input type="checkbox" id="editMonitorAutoReply" onchange="toggleEditAutoReply()" style="margin-right: 8px;"> 
                        启用自动回复
                    </label>
                </div>
                
                <div id="editAutoReplyOptions" style="display: none; padding: 15px; background: #f5f5f5; border-radius: 5px; margin-top: 10px;">
                    <div class="form-group">
                        <label>回复消息内容 *</label>
                        <textarea id="editMonitorReplyMessage" class="form-control" rows="3"></textarea>
                        <small class="form-text">支持变量：{name}, {username}</small>
                    </div>
                    
                    <div class="form-group">
                        <label>回复方式</label>
                        <select id="editMonitorUseUserAccount" class="form-control">
                            <option value="0">使用机器人账号回复</option>
                            <option value="1">使用真人账号回复</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>回复延迟（秒）</label>
                        <input type="number" id="editMonitorReplyDelay" class="form-control" min="0" max="60">
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('editMonitorModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
    <script>
        function toggleAutoReply() {
            const checkbox = document.getElementById('monitorAutoReply');
            const options = document.getElementById('autoReplyOptions');
            options.style.display = checkbox.checked ? 'block' : 'none';
        }
        
        function toggleEditAutoReply() {
            const checkbox = document.getElementById('editMonitorAutoReply');
            const options = document.getElementById('editAutoReplyOptions');
            options.style.display = checkbox.checked ? 'block' : 'none';
        }
        
        async function addMonitor() {
            const keyword = document.getElementById('monitorKeyword').value;
            const matchType = document.getElementById('monitorMatchType').value;
            const monitorMode = document.getElementById('monitorMode').value;
            const userId = document.getElementById('monitorUserId').value;
            const groupId = document.getElementById('monitorGroupId').value;
            const description = document.getElementById('monitorDescription').value;
            
            // 自动回复选项
            const autoReply = document.getElementById('monitorAutoReply').checked;
            const replyMessage = document.getElementById('monitorReplyMessage').value;
            const useUserAccount = document.getElementById('monitorUseUserAccount').value;
            const replyDelay = document.getElementById('monitorReplyDelay').value;
            
            const result = await App.request('api/keyword_monitor.php', 'POST', {
                action: 'add',
                keyword: keyword,
                match_type: matchType,
                monitor_mode: monitorMode,
                notify_user_id: userId,
                group_id: groupId || null,
                description: description,
                auto_reply_enabled: autoReply ? 1 : 0,
                auto_reply_message: replyMessage,
                use_user_account: useUserAccount,
                reply_delay: replyDelay
            });
            
            if (result && result.success) {
                App.showAlert('添加成功');
                App.hideModal('addMonitorModal');
                setTimeout(() => location.reload(), 1000);
            }
        }
        
        async function editMonitor(id) {
            try {
                const response = await fetch(`api/keyword_monitor.php?action=get&id=${id}`);
                const result = await response.json();
                
                if (result && result.success) {
                    const monitor = result.data;
                    
                    document.getElementById('editMonitorId').value = monitor.id;
                    document.getElementById('editMonitorKeyword').value = monitor.keyword;
                    document.getElementById('editMonitorMatchType').value = monitor.match_type;
                    document.getElementById('editMonitorMode').value = monitor.monitor_mode || 'bot';
                    document.getElementById('editMonitorUserId').value = monitor.notify_user_id;
                    document.getElementById('editMonitorGroupId').value = monitor.group_id || '';
                    document.getElementById('editMonitorDescription').value = monitor.description || '';
                    
                    // 自动回复选项
                    const autoReply = monitor.auto_reply_enabled == 1;
                    document.getElementById('editMonitorAutoReply').checked = autoReply;
                    document.getElementById('editMonitorReplyMessage').value = monitor.auto_reply_message || '';
                    document.getElementById('editMonitorUseUserAccount').value = monitor.use_user_account || '0';
                    document.getElementById('editMonitorReplyDelay').value = monitor.reply_delay || '0';
                    
                    if (autoReply) {
                        document.getElementById('editAutoReplyOptions').style.display = 'block';
                    }
                    
                    App.showModal('editMonitorModal');
                } else {
                    App.showAlert('获取监控规则失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('获取监控规则失败：' + error.message, 'error');
            }
        }
        
        async function updateMonitor() {
            const id = document.getElementById('editMonitorId').value;
            const keyword = document.getElementById('editMonitorKeyword').value;
            const matchType = document.getElementById('editMonitorMatchType').value;
            const monitorMode = document.getElementById('editMonitorMode').value;
            const userId = document.getElementById('editMonitorUserId').value;
            const groupId = document.getElementById('editMonitorGroupId').value;
            const description = document.getElementById('editMonitorDescription').value;
            
            // 自动回复选项
            const autoReply = document.getElementById('editMonitorAutoReply').checked;
            const replyMessage = document.getElementById('editMonitorReplyMessage').value;
            const useUserAccount = document.getElementById('editMonitorUseUserAccount').value;
            const replyDelay = document.getElementById('editMonitorReplyDelay').value;
            
            const result = await App.request('api/keyword_monitor.php', 'POST', {
                action: 'update',
                id: id,
                keyword: keyword,
                match_type: matchType,
                monitor_mode: monitorMode,
                notify_user_id: userId,
                group_id: groupId || null,
                description: description,
                auto_reply_enabled: autoReply ? 1 : 0,
                auto_reply_message: replyMessage,
                use_user_account: useUserAccount,
                reply_delay: replyDelay
            });
            
            if (result && result.success) {
                App.showAlert('更新成功');
                App.hideModal('editMonitorModal');
                setTimeout(() => location.reload(), 1000);
            }
        }
        
        async function deleteMonitor(id) {
            if (!App.confirm('确定要删除这个监控规则吗？')) return;
            
            const result = await App.request('api/keyword_monitor.php', 'POST', {
                action: 'delete',
                id: id
            });
            
            if (result && result.success) {
                App.showAlert('删除成功');
                setTimeout(() => location.reload(), 1000);
            }
        }
    </script>
</body>
</html>

