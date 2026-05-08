<?php
require_once 'config.php';
checkLogin();

$db = getDB();
$page_title = '系统设置';

// 初始化系统设置表
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `setting_key` varchar(100) NOT NULL,
        `setting_value` text,
        `setting_name` varchar(200) DEFAULT NULL,
        `setting_desc` text,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_setting_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // 创建导航栏按钮表
    $db->exec("CREATE TABLE IF NOT EXISTS `navigation_buttons` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `parent_id` int(11) DEFAULT NULL COMMENT '父级ID，NULL表示一级导航',
        `text` varchar(100) NOT NULL COMMENT '按钮文字',
        `url` varchar(500) DEFAULT NULL COMMENT '跳转链接',
        `row_num` int(11) DEFAULT 1 COMMENT '所在行号',
        `sort_order` int(11) DEFAULT 0 COMMENT '排序',
        `is_active` tinyint(1) DEFAULT 1,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_parent_id` (`parent_id`),
        INDEX `idx_sort` (`sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // 插入默认设置
    $defaults = [
        ['shop_enabled', '1', '发卡功能开关', '启用后，机器人将显示发卡商城功能；禁用后，机器人将作为普通聊天机器人使用'],
        ['maintenance_mode', '0', '维护模式', '启用后，系统将进入维护模式，所有用户暂时无法使用'],
        ['maintenance_message', '系统正在维护升级，给您带来不便，敬请谅解！', '维护模式提示信息', '用户在维护期间看到的提示信息'],
        ['shop_close_message', '发卡功能暂时关闭，如需帮助请联系客服。', '发卡关闭提示信息', '发卡功能关闭时用户看到的提示信息'],
        ['welcome_message', '你好！我是智能助手机器人 🤖', '欢迎消息（发卡关闭时）', '当发卡功能关闭时，用户发送 /start 看到的欢迎消息'],
        ['navigation_enabled', '1', '导航栏开关', '启用后，欢迎消息下方会显示导航按钮'],
        ['navigation_title', '导航栏：', '导航栏标题', '显示在导航按钮上方的标题文字']
    ];
    
    $stmt = $db->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_name, setting_desc) VALUES (?, ?, ?, ?)");
    foreach ($defaults as $default) {
        $stmt->execute($default);
    }
} catch (PDOException $e) {
    error_log("Init system settings error: " . $e->getMessage());
}

// 获取当前设置
try {
    $stmt = $db->query("SELECT * FROM system_settings ORDER BY id ASC");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取导航按钮
    $stmt = $db->query("SELECT * FROM navigation_buttons WHERE is_active = 1 ORDER BY row_num ASC, sort_order ASC");
    $navButtons = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Get settings error: " . $e->getMessage());
    $settings = [];
    $navButtons = [];
}

// 将设置转为关联数组
$settingsMap = [];
foreach ($settings as $s) {
    $settingsMap[$s['setting_key']] = $s['setting_value'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - 发卡系统</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .settings-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .setting-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .setting-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .setting-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }
        
        .setting-desc {
            color: #666;
            font-size: 14px;
            margin-bottom: 15px;
            line-height: 1.6;
        }
        
        .setting-control {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: #4CAF50;
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .status-enabled {
            background-color: #e8f5e9;
            color: #2e7d32;
        }
        
        .status-disabled {
            background-color: #ffebee;
            color: #c62828;
        }
        
        .textarea-setting {
            width: 100%;
            min-height: 80px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            resize: vertical;
        }
        
        .save-btn {
            background-color: #2196F3;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .save-btn:hover {
            background-color: #1976D2;
        }
        
        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert-info {
            background-color: #e3f2fd;
            color: #1565c0;
            border-left: 4px solid #2196F3;
        }
        
        .alert-warning {
            background-color: #fff3e0;
            color: #e65100;
            border-left: 4px solid #ff9800;
        }
        
        /* 导航栏配置样式 */
        .nav-config-card {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-top: 15px;
        }
        
        .nav-preview {
            background: #1a1a2e;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .nav-preview-title {
            color: #fff;
            font-size: 14px;
            margin-bottom: 15px;
            opacity: 0.9;
        }
        
        .nav-preview-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .nav-preview-row {
            display: flex;
            gap: 8px;
            width: 100%;
            margin-bottom: 8px;
        }
        
        .nav-preview-btn {
            background: #2d2d44;
            color: #fff;
            border: 1px solid #3d3d5c;
            border-radius: 6px;
            padding: 10px 16px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            flex: 1;
            text-align: center;
            text-decoration: none;
        }
        
        .nav-preview-btn:hover {
            background: #3d3d5c;
        }
        
        .nav-buttons-list {
            margin-top: 20px;
        }
        
        .nav-button-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            margin-bottom: 10px;
        }
        
        .nav-button-item .drag-handle {
            cursor: move;
            color: #999;
            font-size: 18px;
        }
        
        .nav-button-item input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .nav-button-item input.btn-text {
            max-width: 150px;
        }
        
        .nav-button-item input.btn-url {
            flex: 2;
        }
        
        .nav-button-item input.btn-row {
            max-width: 60px;
        }
        
        .nav-button-item .btn-actions {
            display: flex;
            gap: 5px;
        }
        
        .nav-button-item .btn-delete {
            background: #ff5252;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .nav-button-item .btn-sub {
            background: #9c27b0;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .add-nav-btn {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-right: 10px;
        }
        
        .add-nav-btn:hover {
            background: #43A047;
        }
        
        .sub-buttons {
            margin-left: 40px;
            border-left: 3px solid #9c27b0;
            padding-left: 15px;
        }
        
        .sub-button-item {
            background: #f3e5f5;
        }
        
        .nav-row-divider {
            border-top: 2px dashed #ccc;
            margin: 15px 0;
            position: relative;
        }
        
        .nav-row-divider span {
            position: absolute;
            top: -10px;
            left: 20px;
            background: #f8f9fa;
            padding: 0 10px;
            color: #666;
            font-size: 12px;
        }
        
        .input-group {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .input-group label {
            font-size: 12px;
            color: #666;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h1>⚙️ <?php echo $page_title; ?></h1>
        </div>
        
        <div class="settings-container">
            <div class="alert alert-info">
                <strong>💡 说明：</strong>这里可以控制机器人的核心功能。关闭发卡功能后，机器人将不再显示商城相关的菜单和按钮。
            </div>
            
            <?php foreach ($settings as $setting): ?>
            <?php if ($setting['setting_key'] == 'navigation_enabled' || $setting['setting_key'] == 'navigation_title') continue; ?>
            <div class="setting-card">
                <div class="setting-header">
                    <div>
                        <div class="setting-title"><?php echo htmlspecialchars($setting['setting_name']); ?></div>
                        <div class="setting-desc"><?php echo htmlspecialchars($setting['setting_desc']); ?></div>
                    </div>
                    
                    <?php if ($setting['setting_key'] == 'shop_enabled' || $setting['setting_key'] == 'maintenance_mode'): ?>
                        <div class="setting-control">
                            <span class="status-badge <?php echo $setting['setting_value'] == '1' ? 'status-enabled' : 'status-disabled'; ?>" 
                                  id="status_<?php echo $setting['setting_key']; ?>">
                                <?php echo $setting['setting_value'] == '1' ? '✅ 已启用' : '❌ 已禁用'; ?>
                            </span>
                            <label class="switch">
                                <input type="checkbox" 
                                       data-key="<?php echo $setting['setting_key']; ?>"
                                       <?php echo $setting['setting_value'] == '1' ? 'checked' : ''; ?>
                                       onchange="toggleSetting(this)">
                                <span class="slider"></span>
                            </label>
                        </div>
                    <?php else: ?>
                        <div class="setting-control">
                            <textarea class="textarea-setting" 
                                      id="value_<?php echo $setting['setting_key']; ?>"
                                      data-key="<?php echo $setting['setting_key']; ?>"
                                      placeholder="请输入内容..."><?php echo htmlspecialchars($setting['setting_value']); ?></textarea>
                            <button class="save-btn" onclick="saveSetting('<?php echo $setting['setting_key']; ?>')">
                                💾 保存
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($setting['setting_key'] == 'welcome_message'): ?>
                <!-- 导航栏配置区域 -->
                <div class="nav-config-card">
                    <div class="setting-header">
                        <div>
                            <div class="setting-title">🧭 导航栏按钮配置</div>
                            <div class="setting-desc">配置欢迎消息下方的导航按钮，用户点击后可跳转到指定链接（如群话题、频道等）</div>
                        </div>
                        <div class="setting-control">
                            <span class="status-badge <?php echo ($settingsMap['navigation_enabled'] ?? '1') == '1' ? 'status-enabled' : 'status-disabled'; ?>" 
                                  id="status_navigation_enabled">
                                <?php echo ($settingsMap['navigation_enabled'] ?? '1') == '1' ? '✅ 已启用' : '❌ 已禁用'; ?>
                            </span>
                            <label class="switch">
                                <input type="checkbox" 
                                       data-key="navigation_enabled"
                                       <?php echo ($settingsMap['navigation_enabled'] ?? '1') == '1' ? 'checked' : ''; ?>
                                       onchange="toggleSetting(this)">
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                    
                    <!-- 导航栏标题 -->
                    <div style="margin: 15px 0;">
                        <div class="input-group" style="max-width: 300px;">
                            <label>导航栏标题：</label>
                            <input type="text" id="value_navigation_title" 
                                   value="<?php echo htmlspecialchars($settingsMap['navigation_title'] ?? '导航栏：'); ?>"
                                   style="flex:1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            <button class="save-btn" onclick="saveSetting('navigation_title')" style="padding: 8px 12px;">保存</button>
                        </div>
                    </div>
                    
                    <!-- 预览区域 -->
                    <div class="nav-preview">
                        <div class="nav-preview-title" id="previewTitle"><?php echo htmlspecialchars($settingsMap['navigation_title'] ?? '导航栏：'); ?></div>
                        <div id="navPreview">
                            <!-- 动态渲染预览 -->
                        </div>
                    </div>
                    
                    <!-- 按钮列表 -->
                    <div class="nav-buttons-list" id="navButtonsList">
                        <!-- 动态渲染按钮列表 -->
                    </div>
                    
                    <div style="margin-top: 15px;">
                        <button class="add-nav-btn" onclick="addNavButton()">➕ 添加导航按钮</button>
                        <button class="save-btn" onclick="saveAllNavButtons()">💾 保存所有按钮</button>
                    </div>
                    
                    <div class="alert alert-info" style="margin-top: 15px; margin-bottom: 0;">
                        <strong>💡 使用说明：</strong>
                        <ul style="margin: 10px 0 0 20px; padding: 0;">
                            <li><b>按钮文字</b>：显示在按钮上的文字</li>
                            <li><b>跳转链接</b>：点击按钮后跳转的URL，支持话题链接如 <code>https://t.me/groupname/123</code></li>
                            <li><b>行号</b>：相同行号的按钮会显示在同一行</li>
                            <li><b>二级菜单</b>：点击"子菜单"可为按钮添加下级选项（点击一级按钮后显示二级按钮）</li>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            
            <div class="alert alert-warning">
                <strong>⚠️ 注意：</strong>
                <ul style="margin: 10px 0 0 20px; padding: 0;">
                    <li>关闭"发卡功能"后，用户将看不到商城菜单，机器人变为普通聊天模式</li>
                    <li>"维护模式"会让所有功能暂停，建议仅在系统升级时使用</li>
                    <li>修改设置后会立即生效，请谨慎操作</li>
                </ul>
            </div>
        </div>
    </div>
    
    <script>
        // 导航按钮数据
        let navButtons = <?php echo json_encode($navButtons); ?>;
        
        // 页面加载时渲染
        document.addEventListener('DOMContentLoaded', function() {
            renderNavButtons();
            renderPreview();
        });
        
        function toggleSetting(checkbox) {
            const key = checkbox.getAttribute('data-key');
            const value = checkbox.checked ? '1' : '0';
            
            fetch('api/system_settings.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'update',
                    setting_key: key,
                    setting_value: value
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 更新状态显示
                    const statusBadge = document.getElementById('status_' + key);
                    if (statusBadge) {
                        if (value == '1') {
                            statusBadge.textContent = '✅ 已启用';
                            statusBadge.className = 'status-badge status-enabled';
                        } else {
                            statusBadge.textContent = '❌ 已禁用';
                            statusBadge.className = 'status-badge status-disabled';
                        }
                    }
                    
                    showMessage('设置已更新！', 'success');
                } else {
                    alert('保存失败：' + data.message);
                    checkbox.checked = !checkbox.checked;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('操作失败，请重试');
                checkbox.checked = !checkbox.checked;
            });
        }
        
        function saveSetting(key) {
            const input = document.getElementById('value_' + key);
            const value = input.value;
            
            fetch('api/system_settings.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'update',
                    setting_key: key,
                    setting_value: value
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('保存成功！', 'success');
                    if (key === 'navigation_title') {
                        document.getElementById('previewTitle').textContent = value;
                    }
                } else {
                    alert('保存失败：' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('操作失败，请重试');
            });
        }
        
        function showMessage(message, type) {
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-' + (type === 'success' ? 'info' : 'warning');
            alertDiv.innerHTML = '<strong>' + message + '</strong>';
            alertDiv.style.position = 'fixed';
            alertDiv.style.top = '20px';
            alertDiv.style.right = '20px';
            alertDiv.style.zIndex = '9999';
            alertDiv.style.minWidth = '300px';
            
            document.body.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 3000);
        }
        
        // 渲染导航按钮列表
        function renderNavButtons() {
            const container = document.getElementById('navButtonsList');
            
            // 按行号分组
            const rows = {};
            navButtons.filter(b => !b.parent_id).forEach(btn => {
                const row = btn.row_num || 1;
                if (!rows[row]) rows[row] = [];
                rows[row].push(btn);
            });
            
            let html = '';
            const rowNums = Object.keys(rows).sort((a, b) => a - b);
            
            rowNums.forEach((rowNum, idx) => {
                if (idx > 0) {
                    html += `<div class="nav-row-divider"><span>第 ${rowNum} 行</span></div>`;
                } else {
                    html += `<div style="margin-bottom: 10px; color: #666; font-size: 12px;">第 ${rowNum} 行</div>`;
                }
                
                rows[rowNum].forEach(btn => {
                    html += renderButtonItem(btn);
                    
                    // 渲染子按钮
                    const subButtons = navButtons.filter(b => b.parent_id == btn.id);
                    if (subButtons.length > 0) {
                        html += '<div class="sub-buttons">';
                        subButtons.forEach(sub => {
                            html += renderButtonItem(sub, true);
                        });
                        html += '</div>';
                    }
                });
            });
            
            if (html === '') {
                html = '<div style="text-align: center; color: #999; padding: 20px;">暂无导航按钮，点击下方"添加导航按钮"开始配置</div>';
            }
            
            container.innerHTML = html;
        }
        
        function renderButtonItem(btn, isSub = false) {
            return `
                <div class="nav-button-item ${isSub ? 'sub-button-item' : ''}" data-id="${btn.id || 'new_' + Date.now()}">
                    <span class="drag-handle">☰</span>
                    <div class="input-group">
                        <label>文字:</label>
                        <input type="text" class="btn-text" value="${escapeHtml(btn.text || '')}" placeholder="按钮文字">
                    </div>
                    <div class="input-group">
                        <label>链接:</label>
                        <input type="text" class="btn-url" value="${escapeHtml(btn.url || '')}" placeholder="https://t.me/...">
                    </div>
                    ${!isSub ? `
                    <div class="input-group">
                        <label>行:</label>
                        <input type="number" class="btn-row" value="${btn.row_num || 1}" min="1" max="10">
                    </div>
                    ` : ''}
                    <div class="btn-actions">
                        ${!isSub ? `<button class="btn-sub" onclick="addSubButton(this)" title="添加子菜单">➕子菜单</button>` : ''}
                        <button class="btn-delete" onclick="deleteNavButton(this)">🗑️</button>
                    </div>
                </div>
            `;
        }
        
        // 渲染预览
        function renderPreview() {
            const container = document.getElementById('navPreview');
            
            // 按行号分组
            const rows = {};
            navButtons.filter(b => !b.parent_id).forEach(btn => {
                const row = btn.row_num || 1;
                if (!rows[row]) rows[row] = [];
                rows[row].push(btn);
            });
            
            let html = '';
            const rowNums = Object.keys(rows).sort((a, b) => a - b);
            
            rowNums.forEach(rowNum => {
                html += '<div class="nav-preview-row">';
                rows[rowNum].forEach(btn => {
                    const hasChildren = navButtons.some(b => b.parent_id == btn.id);
                    html += `<a href="${btn.url || '#'}" target="_blank" class="nav-preview-btn" title="${btn.url || '无链接'}">${escapeHtml(btn.text || '未命名')}${hasChildren ? ' ▼' : ''}</a>`;
                });
                html += '</div>';
            });
            
            if (html === '') {
                html = '<div style="color: #666; text-align: center; padding: 20px;">暂无导航按钮</div>';
            }
            
            container.innerHTML = html;
        }
        
        // 添加导航按钮
        function addNavButton() {
            const maxRow = Math.max(1, ...navButtons.filter(b => !b.parent_id).map(b => b.row_num || 1));
            
            navButtons.push({
                id: 'new_' + Date.now(),
                parent_id: null,
                text: '',
                url: '',
                row_num: maxRow,
                sort_order: navButtons.length
            });
            
            renderNavButtons();
            renderPreview();
        }
        
        // 添加子按钮
        function addSubButton(btn) {
            const item = btn.closest('.nav-button-item');
            const parentId = item.getAttribute('data-id');
            
            navButtons.push({
                id: 'new_' + Date.now(),
                parent_id: parentId,
                text: '',
                url: '',
                row_num: 1,
                sort_order: navButtons.length
            });
            
            renderNavButtons();
        }
        
        // 删除导航按钮
        function deleteNavButton(btn) {
            if (!confirm('确定要删除这个按钮吗？')) return;
            
            const item = btn.closest('.nav-button-item');
            const id = item.getAttribute('data-id');
            
            // 如果是新按钮，直接从数组移除
            navButtons = navButtons.filter(b => b.id != id && b.parent_id != id);
            
            // 如果是已保存的按钮，调用API删除
            if (!String(id).startsWith('new_')) {
                fetch('api/navigation_buttons.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', id: id })
                });
            }
            
            renderNavButtons();
            renderPreview();
        }
        
        // 保存所有按钮
        function saveAllNavButtons() {
            // 收集表单数据
            const items = document.querySelectorAll('.nav-button-item');
            const buttons = [];
            
            items.forEach((item, idx) => {
                const id = item.getAttribute('data-id');
                const text = item.querySelector('.btn-text').value.trim();
                const url = item.querySelector('.btn-url').value.trim();
                const rowInput = item.querySelector('.btn-row');
                const row = rowInput ? parseInt(rowInput.value) || 1 : 1;
                const isSub = item.classList.contains('sub-button-item');
                
                // 查找父级ID
                let parentId = null;
                if (isSub) {
                    const subContainer = item.closest('.sub-buttons');
                    if (subContainer) {
                        const parentItem = subContainer.previousElementSibling;
                        if (parentItem && parentItem.classList.contains('nav-button-item')) {
                            parentId = parentItem.getAttribute('data-id');
                        }
                    }
                }
                
                if (text) {
                    buttons.push({
                        id: String(id).startsWith('new_') ? null : id,
                        parent_id: parentId,
                        text: text,
                        url: url,
                        row_num: row,
                        sort_order: idx
                    });
                }
            });
            
            fetch('api/navigation_buttons.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'save_all', buttons: buttons })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('导航按钮保存成功！', 'success');
                    // 刷新数据
                    navButtons = data.buttons || [];
                    renderNavButtons();
                    renderPreview();
                } else {
                    alert('保存失败：' + (data.message || '未知错误'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('保存失败，请重试');
            });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
