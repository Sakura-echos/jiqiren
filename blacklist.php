<?php
require_once 'config.php';
checkLogin();

$db = getDB();
$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>黑名单管理 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .tabs {
            display: flex;
            border-bottom: 2px solid #3a3f47;
            margin-bottom: 20px;
        }
        .tab-btn {
            padding: 12px 24px;
            background: transparent;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
        }
        .tab-btn:hover {
            color: #fff;
        }
        .tab-btn.active {
            color: #60a5fa;
            border-bottom-color: #60a5fa;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        .stat-card .number {
            font-size: 32px;
            font-weight: bold;
            color: #60a5fa;
        }
        .stat-card .label {
            color: #9ca3af;
            margin-top: 5px;
        }
        .stat-card.danger .number {
            color: #f87171;
        }
        .stat-card.warning .number {
            color: #fbbf24;
        }
        .stat-card.success .number {
            color: #34d399;
        }
        
        .toolbar {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .search-box {
            flex: 1;
            min-width: 200px;
        }
        .search-box input {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #3a3f47;
            border-radius: 8px;
            background: #1e293b;
            color: #fff;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-danger {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
        }
        .badge-warning {
            background: rgba(251, 191, 36, 0.2);
            color: #fbbf24;
        }
        .badge-success {
            background: rgba(52, 211, 153, 0.2);
            color: #34d399;
        }
        .badge-info {
            background: rgba(96, 165, 250, 0.2);
            color: #60a5fa;
        }
        
        .user-info-cell {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .user-info-cell .username {
            color: #60a5fa;
            font-size: 12px;
        }
        .user-info-cell .name {
            color: #fff;
            font-weight: 500;
        }
        .user-info-cell .id {
            color: #9ca3af;
            font-size: 11px;
        }
        
        .settings-form {
            max-width: 600px;
        }
        .settings-form .form-group {
            margin-bottom: 20px;
        }
        .settings-form label {
            display: block;
            margin-bottom: 8px;
            color: #e2e8f0;
            font-weight: 500;
        }
        .settings-form .hint {
            color: #9ca3af;
            font-size: 12px;
            margin-top: 5px;
        }
        .switch-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .switch {
            position: relative;
            width: 50px;
            height: 26px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .switch .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: #3a3f47;
            border-radius: 26px;
            transition: 0.3s;
        }
        .switch .slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: 0.3s;
        }
        .switch input:checked + .slider {
            background: #60a5fa;
        }
        .switch input:checked + .slider:before {
            transform: translateX(24px);
        }
        
        .history-timeline {
            position: relative;
            padding-left: 20px;
        }
        .history-timeline:before {
            content: '';
            position: absolute;
            left: 6px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #3a3f47;
        }
        .history-item {
            position: relative;
            padding: 10px 15px;
            margin-bottom: 10px;
            background: #1e293b;
            border-radius: 8px;
        }
        .history-item:before {
            content: '';
            position: absolute;
            left: -17px;
            top: 15px;
            width: 10px;
            height: 10px;
            background: #60a5fa;
            border-radius: 50%;
        }
        .history-item .time {
            color: #9ca3af;
            font-size: 12px;
        }
        .history-item .name {
            color: #fff;
            font-weight: 500;
            margin-top: 5px;
        }
        
        /* Modal样式 */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background: #1e293b;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #3a3f47;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            margin: 0;
            color: #fff;
        }
        .modal-close {
            background: none;
            border: none;
            color: #9ca3af;
            font-size: 24px;
            cursor: pointer;
        }
        .modal-body {
            padding: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #e2e8f0;
        }
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #3a3f47;
            border-radius: 8px;
            background: #0f172a;
            color: #fff;
        }
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>🔒 黑名单管理</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <!-- 统计卡片 -->
            <div class="stats-row">
                <div class="stat-card danger">
                    <div class="number" id="statBlacklistCount">0</div>
                    <div class="label">黑名单用户</div>
                </div>
                <div class="stat-card warning">
                    <div class="number" id="statNameChangeCount">0</div>
                    <div class="label">改名记录</div>
                </div>
                <div class="stat-card success">
                    <div class="number" id="statKickedCount">-</div>
                    <div class="label">今日踢出</div>
                </div>
                <div class="stat-card">
                    <div class="number" id="statMonitoredGroups">-</div>
                    <div class="label">监控群组</div>
                </div>
            </div>
            
            <!-- 标签页 -->
            <div class="tabs">
                <button class="tab-btn active" data-tab="blacklist">🚫 黑名单</button>
                <button class="tab-btn" data-tab="name-monitor">👁️ 改名监控</button>
                <button class="tab-btn" data-tab="settings">⚙️ 设置</button>
            </div>
            
            <!-- 黑名单标签页 -->
            <div id="tab-blacklist" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h2>黑名单列表</h2>
                        <button class="btn btn-primary" onclick="showAddModal()">➕ 添加黑名单</button>
                    </div>
                    <div class="card-body">
                        <div class="toolbar">
                            <div class="search-box">
                                <input type="text" id="searchInput" placeholder="搜索用户ID、用户名、原因..." onkeyup="handleSearch(event)">
                            </div>
                            <button class="btn btn-danger" onclick="batchRemove()">🗑️ 批量移除</button>
                        </div>
                        
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                                        <th>用户信息</th>
                                        <th>适用范围</th>
                                        <th>原因</th>
                                        <th>添加者</th>
                                        <th>踢邀请者</th>
                                        <th>添加时间</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody id="blacklistTable">
                                    <tr>
                                        <td colspan="8" class="empty-state">加载中...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="pagination" id="pagination"></div>
                    </div>
                </div>
            </div>
            
            <!-- 改名监控标签页 -->
            <div id="tab-name-monitor" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2>用户改名记录</h2>
                    </div>
                    <div class="card-body">
                        <p style="color: #9ca3af; margin-bottom: 20px;">
                            📌 此功能会自动追踪群内用户的名称变化，当用户改名时会在群内播报通知。
                        </p>
                        
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>用户ID</th>
                                        <th>当前名称</th>
                                        <th>改名次数</th>
                                        <th>最后更新</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody id="nameChangeTable">
                                    <tr>
                                        <td colspan="5" class="empty-state">加载中...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 设置标签页 -->
            <div id="tab-settings" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2>黑名单与监控设置</h2>
                    </div>
                    <div class="card-body">
                        <form class="settings-form" id="settingsForm">
                            <div class="form-group">
                                <div class="switch-container">
                                    <label class="switch">
                                        <input type="checkbox" id="enableBlacklist" name="enable_blacklist">
                                        <span class="slider"></span>
                                    </label>
                                    <span>启用黑名单功能</span>
                                </div>
                                <div class="hint">开启后，黑名单用户进入任何监控群都会被自动踢出</div>
                            </div>
                            
                            <div class="form-group">
                                <div class="switch-container">
                                    <label class="switch">
                                        <input type="checkbox" id="enableNameMonitor" name="enable_name_monitor">
                                        <span class="slider"></span>
                                    </label>
                                    <span>启用改名监控</span>
                                </div>
                                <div class="hint">开启后，用户改名会在群内播报通知</div>
                            </div>
                            
                            <div class="form-group">
                                <div class="switch-container">
                                    <label class="switch">
                                        <input type="checkbox" id="kickInviter" name="kick_inviter">
                                        <span class="slider"></span>
                                    </label>
                                    <span>踢出邀请黑名单用户的人</span>
                                </div>
                                <div class="hint">开启后，邀请黑名单用户的人也会被踢出并加入黑名单</div>
                            </div>
                            
                            <div class="form-group">
                                <div class="switch-container">
                                    <label class="switch">
                                        <input type="checkbox" id="notifyInGroup" name="notify_in_group">
                                        <span class="slider"></span>
                                    </label>
                                    <span>群内通知</span>
                                </div>
                                <div class="hint">踢出黑名单用户时在群内发送通知</div>
                            </div>
                            
                            <div class="form-group">
                                <div class="switch-container">
                                    <label class="switch">
                                        <input type="checkbox" id="enableMentionCheck" name="enable_mention_check">
                                        <span class="slider"></span>
                                    </label>
                                    <span>@提及检查 (Mention Check)</span>
                                </div>
                                <div class="hint">开启后，当用户@某人时会检查该用户是否为群成员</div>
                            </div>
                            
                            <div id="mentionCheckSettings" style="margin-left: 20px; padding: 15px; background: rgba(255,255,255,0.05); border-radius: 8px; margin-bottom: 15px;">
                                <h4 style="margin-bottom: 15px; color: #10b981;">📝 @提及检查消息模板</h4>
                                
                                <div class="form-group">
                                    <label for="mentionMemberTitle">✅ 是群成员 - 标题</label>
                                    <input type="text" id="mentionMemberTitle" name="mention_member_title" 
                                           placeholder="✅ <b>Notice:</b>" class="form-control">
                                </div>
                                
                                <div class="form-group">
                                    <label for="mentionMemberText">✅ 是群成员 - 内容</label>
                                    <input type="text" id="mentionMemberText" name="mention_member_text" 
                                           placeholder="The following users are group members:" class="form-control">
                                </div>
                                
                                <div class="form-group">
                                    <label for="mentionNonMemberTitle">⚠️ 非群成员 - 标题</label>
                                    <input type="text" id="mentionNonMemberTitle" name="mention_non_member_title" 
                                           placeholder="⚠️ <b>Warning:</b>" class="form-control">
                                </div>
                                
                                <div class="form-group">
                                    <label for="mentionNonMemberText">⚠️ 非群成员 - 内容</label>
                                    <input type="text" id="mentionNonMemberText" name="mention_non_member_text" 
                                           placeholder="The following users are NOT group members:" class="form-control">
                                </div>
                                
                                <div class="form-group">
                                    <label for="mentionNonMemberFooter">⚠️ 非群成员 - 底部提示</label>
                                    <input type="text" id="mentionNonMemberFooter" name="mention_non_member_footer" 
                                           placeholder="📌 Please verify their identity" class="form-control">
                                </div>
                                
                                <div class="hint" style="margin-top: 10px;">
                                    支持HTML格式：&lt;b&gt;粗体&lt;/b&gt;, &lt;i&gt;斜体&lt;/i&gt;, &lt;code&gt;代码&lt;/code&gt;
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="nameChangeMessage">改名播报消息模板</label>
                                <textarea id="nameChangeMessage" name="name_change_message" placeholder="支持变量：{old_name}, {new_name}, {user_id}, {username}"></textarea>
                                <div class="hint">
                                    可用变量：<br>
                                    {old_name} - 旧名称<br>
                                    {new_name} - 新名称<br>
                                    {user_id} - 用户ID<br>
                                    {username} - 用户名（@开头）
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">💾 保存设置</button>
                        </form>
                    </div>
                </div>
                
                <div class="card" style="margin-top: 20px;">
                    <div class="card-header">
                        <h2>📖 使用说明</h2>
                    </div>
                    <div class="card-body">
                        <div style="color: #e2e8f0; line-height: 1.8;">
                            <h4>🚫 黑名单功能</h4>
                            <ul>
                                <li>在后台添加黑名单用户，机器人会自动踢出该用户</li>
                                <li>在群内回复用户消息并发送 <code>/ban</code> 或 <code>/black</code> 可将该用户加入黑名单</li>
                                <li>发送 <code>/unban 用户ID</code> 可解除黑名单</li>
                                <li>黑名单用户无论在哪个群都会被自动踢出</li>
                            </ul>
                            
                            <h4 style="margin-top: 20px;">👁️ 改名监控</h4>
                            <ul>
                                <li>机器人会自动记录用户的名称变化</li>
                                <li>当用户改名时，会在群内播报通知</li>
                                <li>可以查看用户的历史名称记录</li>
                            </ul>
                            
                            <h4 style="margin-top: 20px;">⚠️ 邀请者连坐</h4>
                            <ul>
                                <li>当黑名单用户被邀请进群时，邀请者也会被踢出并加入黑名单</li>
                                <li>可在设置中开启/关闭此功能</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 添加黑名单弹窗 -->
    <div class="modal" id="addModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>➕ 添加黑名单</h3>
                <button class="modal-close" onclick="closeAddModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addForm" onsubmit="handleAddSubmit(event)">
                    <div class="form-group">
                        <label for="addUserId">用户ID *</label>
                        <input type="text" id="addUserId" required placeholder="Telegram用户ID（数字）">
                    </div>
                    <div class="form-group">
                        <label for="addUsername">用户名</label>
                        <input type="text" id="addUsername" placeholder="@username（可选）">
                    </div>
                    <div class="form-group">
                        <label for="addFirstName">名字</label>
                        <input type="text" id="addFirstName" placeholder="用户名字（可选）">
                    </div>
                    <div class="form-group">
                        <label for="addReason">封禁原因</label>
                        <textarea id="addReason" placeholder="封禁原因（可选）"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="addGroupId">适用范围</label>
                        <select id="addGroupId">
                            <option value="">全局（所有群）</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <div class="switch-container">
                            <label class="switch">
                                <input type="checkbox" id="addKickInviter" checked>
                                <span class="slider"></span>
                            </label>
                            <span>同时踢出邀请者</span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">确认添加</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- 改名历史弹窗 -->
    <div class="modal" id="historyModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>📜 改名历史</h3>
                <button class="modal-close" onclick="closeHistoryModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="history-timeline" id="historyTimeline">
                    加载中...
                </div>
            </div>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
    <script>
        let currentPage = 1;
        let searchKeyword = '';
        
        // 标签页切换
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
                
                if (btn.dataset.tab === 'name-monitor') {
                    loadNameChanges();
                } else if (btn.dataset.tab === 'settings') {
                    loadSettings();
                }
            });
        });
        
        // 加载黑名单
        async function loadBlacklist(page = 1) {
            currentPage = page;
            const tbody = document.getElementById('blacklistTable');
            tbody.innerHTML = '<tr><td colspan="8" class="empty-state">加载中...</td></tr>';
            
            let url = `api/blacklist.php?action=list&page=${page}&limit=50`;
            if (searchKeyword) {
                url += `&search=${encodeURIComponent(searchKeyword)}`;
            }
            
            try {
                const response = await fetch(url);
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('statBlacklistCount').textContent = result.total;
                    
                    if (result.data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="8" class="empty-state">暂无黑名单记录</td></tr>';
                        return;
                    }
                    
                    tbody.innerHTML = result.data.map(item => `
                        <tr>
                            <td><input type="checkbox" class="row-checkbox" value="${item.id}"></td>
                            <td>
                                <div class="user-info-cell">
                                    <span class="name">${escapeHtml(item.first_name || '')} ${escapeHtml(item.last_name || '')}</span>
                                    ${item.username ? `<span class="username">@${escapeHtml(item.username)}</span>` : ''}
                                    <span class="id">ID: ${item.user_id}</span>
                                </div>
                            </td>
                            <td>${item.group_title ? `<span class="badge badge-info">${escapeHtml(item.group_title)}</span>` : '<span class="badge badge-danger">全局</span>'}</td>
                            <td>${escapeHtml(item.reason || '-')}</td>
                            <td>${escapeHtml(item.added_by || '-')}</td>
                            <td>${item.kick_inviter ? '<span class="badge badge-warning">是</span>' : '<span class="badge">否</span>'}</td>
                            <td>${formatDate(item.created_at)}</td>
                            <td>
                                <button class="btn btn-sm btn-danger" onclick="removeFromBlacklist(${item.id})">解除</button>
                            </td>
                        </tr>
                    `).join('');
                }
            } catch (error) {
                console.error('Error:', error);
                tbody.innerHTML = '<tr><td colspan="8" class="empty-state">加载失败</td></tr>';
            }
        }
        
        // 加载改名记录
        async function loadNameChanges() {
            const tbody = document.getElementById('nameChangeTable');
            tbody.innerHTML = '<tr><td colspan="5" class="empty-state">加载中...</td></tr>';
            
            try {
                const response = await fetch('api/blacklist.php?action=list_name_changes');
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('statNameChangeCount').textContent = result.data.length;
                    
                    if (result.data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" class="empty-state">暂无改名记录</td></tr>';
                        return;
                    }
                    
                    tbody.innerHTML = result.data.map(item => `
                        <tr>
                            <td>${item.user_id}</td>
                            <td>
                                <div class="user-info-cell">
                                    <span class="name">${escapeHtml(item.current_first_name || '')} ${escapeHtml(item.current_last_name || '')}</span>
                                    ${item.current_username ? `<span class="username">@${escapeHtml(item.current_username)}</span>` : ''}
                                </div>
                            </td>
                            <td><span class="badge badge-warning">${item.change_count} 次</span></td>
                            <td>${formatDate(item.last_seen)}</td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="showHistory(${item.user_id})">查看历史</button>
                                <button class="btn btn-sm btn-danger" onclick="addToBlacklistFromHistory(${item.user_id}, '${escapeHtml(item.current_first_name || '')}')">加入黑名单</button>
                            </td>
                        </tr>
                    `).join('');
                }
            } catch (error) {
                console.error('Error:', error);
                tbody.innerHTML = '<tr><td colspan="5" class="empty-state">加载失败</td></tr>';
            }
        }
        
        // 加载设置
        async function loadSettings() {
            try {
                const response = await fetch('api/blacklist.php?action=get_settings');
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('enableBlacklist').checked = result.settings.enable_blacklist === '1';
                    document.getElementById('enableNameMonitor').checked = result.settings.enable_name_monitor === '1';
                    document.getElementById('kickInviter').checked = result.settings.kick_inviter === '1';
                    document.getElementById('notifyInGroup').checked = result.settings.notify_in_group === '1';
                    document.getElementById('enableMentionCheck').checked = result.settings.enable_mention_check !== '0'; // 默认开启
                    document.getElementById('nameChangeMessage').value = result.settings.name_change_message || '';
                    
                    // @提及检查消息模板
                    document.getElementById('mentionMemberTitle').value = result.settings.mention_member_title || '✅ <b>Notice:</b>';
                    document.getElementById('mentionMemberText').value = result.settings.mention_member_text || 'The following users are group members:';
                    document.getElementById('mentionNonMemberTitle').value = result.settings.mention_non_member_title || '⚠️ <b>Warning:</b>';
                    document.getElementById('mentionNonMemberText').value = result.settings.mention_non_member_text || 'The following users are NOT group members:';
                    document.getElementById('mentionNonMemberFooter').value = result.settings.mention_non_member_footer || '📌 Please verify their identity';
                    
                    // 根据开关显示/隐藏设置区域
                    toggleMentionSettings();
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }
        
        // 切换@提及设置显示
        function toggleMentionSettings() {
            const enabled = document.getElementById('enableMentionCheck').checked;
            document.getElementById('mentionCheckSettings').style.display = enabled ? 'block' : 'none';
        }
        
        // 监听开关变化
        document.getElementById('enableMentionCheck').addEventListener('change', toggleMentionSettings);
        
        // 保存设置
        document.getElementById('settingsForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'save_settings');
            formData.append('enable_blacklist', document.getElementById('enableBlacklist').checked ? '1' : '0');
            formData.append('enable_name_monitor', document.getElementById('enableNameMonitor').checked ? '1' : '0');
            formData.append('kick_inviter', document.getElementById('kickInviter').checked ? '1' : '0');
            formData.append('notify_in_group', document.getElementById('notifyInGroup').checked ? '1' : '0');
            formData.append('enable_mention_check', document.getElementById('enableMentionCheck').checked ? '1' : '0');
            formData.append('name_change_message', document.getElementById('nameChangeMessage').value);
            
            // @提及检查消息模板
            formData.append('mention_member_title', document.getElementById('mentionMemberTitle').value);
            formData.append('mention_member_text', document.getElementById('mentionMemberText').value);
            formData.append('mention_non_member_title', document.getElementById('mentionNonMemberTitle').value);
            formData.append('mention_non_member_text', document.getElementById('mentionNonMemberText').value);
            formData.append('mention_non_member_footer', document.getElementById('mentionNonMemberFooter').value);
            
            try {
                const response = await fetch('api/blacklist.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    App.showAlert('设置已保存', 'success');
                } else {
                    App.showAlert(result.error || '保存失败', 'error');
                }
            } catch (error) {
                App.showAlert('保存失败', 'error');
            }
        });
        
        // 显示添加弹窗
        async function showAddModal() {
            document.getElementById('addModal').classList.add('active');
            
            // 加载群组列表
            try {
                const response = await fetch('api/blacklist.php?action=get_groups');
                const result = await response.json();
                
                if (result.success) {
                    const select = document.getElementById('addGroupId');
                    select.innerHTML = '<option value="">全局（所有群）</option>';
                    result.data.forEach(group => {
                        select.innerHTML += `<option value="${group.id}">${escapeHtml(group.title)}</option>`;
                    });
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }
        
        function closeAddModal() {
            document.getElementById('addModal').classList.remove('active');
            document.getElementById('addForm').reset();
        }
        
        // 添加黑名单
        async function handleAddSubmit(e) {
            e.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('user_id', document.getElementById('addUserId').value.trim());
            formData.append('username', document.getElementById('addUsername').value.trim());
            formData.append('first_name', document.getElementById('addFirstName').value.trim());
            formData.append('reason', document.getElementById('addReason').value.trim());
            formData.append('group_id', document.getElementById('addGroupId').value);
            formData.append('kick_inviter', document.getElementById('addKickInviter').checked ? '1' : '0');
            
            try {
                const response = await fetch('api/blacklist.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    App.showAlert('已添加到黑名单', 'success');
                    closeAddModal();
                    loadBlacklist();
                } else {
                    App.showAlert(result.error || '添加失败', 'error');
                }
            } catch (error) {
                App.showAlert('添加失败', 'error');
            }
        }
        
        // 解除黑名单
        async function removeFromBlacklist(id) {
            if (!confirm('确定要解除此用户的封禁吗？')) return;
            
            const formData = new FormData();
            formData.append('action', 'remove');
            formData.append('id', id);
            
            try {
                const response = await fetch('api/blacklist.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    App.showAlert('已解除封禁', 'success');
                    loadBlacklist(currentPage);
                } else {
                    App.showAlert(result.error || '操作失败', 'error');
                }
            } catch (error) {
                App.showAlert('操作失败', 'error');
            }
        }
        
        // 批量移除
        async function batchRemove() {
            const checkboxes = document.querySelectorAll('.row-checkbox:checked');
            if (checkboxes.length === 0) {
                App.showAlert('请先选择要移除的记录', 'warning');
                return;
            }
            
            if (!confirm(`确定要解除这 ${checkboxes.length} 个用户的封禁吗？`)) return;
            
            const ids = Array.from(checkboxes).map(cb => cb.value);
            
            const formData = new FormData();
            formData.append('action', 'batch_remove');
            formData.append('ids', JSON.stringify(ids));
            
            try {
                const response = await fetch('api/blacklist.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    App.showAlert('批量移除成功', 'success');
                    loadBlacklist(currentPage);
                } else {
                    App.showAlert(result.error || '操作失败', 'error');
                }
            } catch (error) {
                App.showAlert('操作失败', 'error');
            }
        }
        
        // 全选
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll').checked;
            document.querySelectorAll('.row-checkbox').forEach(cb => {
                cb.checked = selectAll;
            });
        }
        
        // 搜索
        function handleSearch(e) {
            if (e.key === 'Enter') {
                searchKeyword = e.target.value.trim();
                loadBlacklist(1);
            }
        }
        
        // 显示改名历史
        async function showHistory(userId) {
            document.getElementById('historyModal').classList.add('active');
            const timeline = document.getElementById('historyTimeline');
            timeline.innerHTML = '加载中...';
            
            try {
                const response = await fetch(`api/blacklist.php?action=get_name_history&user_id=${userId}`);
                const result = await response.json();
                
                if (result.success && result.data.length > 0) {
                    timeline.innerHTML = result.data.map(item => `
                        <div class="history-item">
                            <div class="time">${formatDate(item.recorded_at)}</div>
                            <div class="name">
                                ${escapeHtml(item.first_name || '')} ${escapeHtml(item.last_name || '')}
                                ${item.username ? `(@${escapeHtml(item.username)})` : ''}
                            </div>
                        </div>
                    `).join('');
                } else {
                    timeline.innerHTML = '暂无历史记录';
                }
            } catch (error) {
                timeline.innerHTML = '加载失败';
            }
        }
        
        function closeHistoryModal() {
            document.getElementById('historyModal').classList.remove('active');
        }
        
        // 从改名记录添加到黑名单
        function addToBlacklistFromHistory(userId, firstName) {
            document.getElementById('addUserId').value = userId;
            document.getElementById('addFirstName').value = firstName;
            showAddModal();
        }
        
        // 工具函数
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function formatDate(dateStr) {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            return date.toLocaleString('zh-CN');
        }
        
        // 初始化
        loadBlacklist();
        loadSettings();
    </script>
</body>
</html>
