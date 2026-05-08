<?php
require_once 'config.php';
checkLogin();

$db = getDB();
$page_title = '普通模式菜单';

// 初始化表
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `normal_mode_menu` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `button_text` varchar(100) NOT NULL COMMENT '按钮文本',
        `button_emoji` varchar(20) DEFAULT NULL COMMENT '按钮图标',
        `action_type` varchar(50) NOT NULL COMMENT '动作类型：command/url/reply_text',
        `action_value` text NOT NULL COMMENT '动作值',
        `row_number` int(11) NOT NULL DEFAULT 1 COMMENT '第几行（1-4）',
        `column_number` int(11) NOT NULL DEFAULT 1 COMMENT '第几列（1-3）',
        `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
        `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    error_log("Init normal_mode_menu table error: " . $e->getMessage());
}

// 获取所有按钮
try {
    $stmt = $db->query("SELECT * FROM normal_mode_menu ORDER BY row_number ASC, column_number ASC, sort_order ASC");
    $buttons = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Get buttons error: " . $e->getMessage());
    $buttons = [];
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
        .menu-preview {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            color: white;
        }
        
        .preview-phone {
            max-width: 400px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        
        .preview-header {
            background: #2481cc;
            color: white;
            padding: 15px;
            font-weight: 600;
        }
        
        .preview-chat {
            background: #e6ddd4;
            min-height: 200px;
            padding: 20px;
        }
        
        .preview-message {
            background: white;
            padding: 12px 16px;
            border-radius: 8px;
            max-width: 80%;
            margin-bottom: 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            color: #333;
        }
        
        .preview-keyboard {
            background: white;
            padding: 10px;
            border-top: 1px solid #ddd;
        }
        
        .keyboard-row {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
        }
        
        .keyboard-row:last-child {
            margin-bottom: 0;
        }
        
        .keyboard-button {
            flex: 1;
            background: #f0f0f0;
            border: 1px solid #ccc;
            border-radius: 6px;
            padding: 10px 8px;
            text-align: center;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .keyboard-button:hover {
            background: #e0e0e0;
        }
        
        .buttons-table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .buttons-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .buttons-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }
        
        .buttons-table td {
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .buttons-table tr:last-child td {
            border-bottom: none;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-command {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .badge-reply {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        
        .badge-url {
            background: #e8f5e9;
            color: #388e3c;
        }
        
        .btn-group {
            display: flex;
            gap: 8px;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-edit {
            background: #2196F3;
            color: white;
        }
        
        .btn-edit:hover {
            background: #1976D2;
        }
        
        .btn-delete {
            background: #f44336;
            color: white;
        }
        
        .btn-delete:hover {
            background: #d32f2f;
        }
        
        .btn-primary {
            background: #4CAF50;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            border: none;
            font-size: 16px;
            cursor: pointer;
            display: inline-block;
            text-decoration: none;
        }
        
        .btn-primary:hover {
            background: #45a049;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
        }
        
        .modal-content {
            position: relative;
            background: white;
            max-width: 600px;
            margin: 50px auto;
            border-radius: 8px;
            padding: 30px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            margin-bottom: 20px;
        }
        
        .modal-header h2 {
            margin: 0;
        }
        
        .close-modal {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 28px;
            cursor: pointer;
            color: #999;
        }
        
        .close-modal:hover {
            color: #333;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #2196F3;
        }
        
        .help-text {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert-info {
            background: #e3f2fd;
            color: #1565c0;
            border-left: 4px solid #2196F3;
        }
        
        .emoji-picker {
            display: inline-block;
            cursor: pointer;
            padding: 8px;
            background: #f0f0f0;
            border-radius: 4px;
            margin-left: 10px;
        }
        
        .position-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        /* Switch 开关样式 */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
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
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
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
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h1>📱 <?php echo $page_title; ?></h1>
            <button class="btn-primary" onclick="openModal()">➕ 添加按钮</button>
        </div>
        
        <div class="alert alert-info">
            <strong>💡 说明：</strong>这里配置的是发卡功能关闭时（普通模式）机器人显示的底部菜单按钮。用户点击这些按钮可以触发命令、显示文本或打开链接。
            <br><br>
            <strong>⚡ 自动同步：</strong>动作类型为"执行命令"的按钮会自动显示在左下角的 <strong>Menu</strong> 按钮列表中，方便用户快速访问。
        </div>
        
        <!-- 预览 -->
        <div class="menu-preview">
            <h3 style="text-align: center; margin-bottom: 20px;">📱 菜单预览</h3>
            <div class="preview-phone">
                <div class="preview-header">
                    🤖 Bot
                </div>
                <div class="preview-chat">
                    <div class="preview-message">
                        你好！我是智能助手机器人 🤖
                    </div>
                </div>
                <div class="preview-keyboard" id="keyboardPreview">
                    <!-- 动态生成 -->
                </div>
            </div>
        </div>
        
        <!-- 按钮列表 -->
        <div class="buttons-table">
            <table>
                <thead>
                    <tr>
                        <th>按钮</th>
                        <th>动作类型</th>
                        <th>动作值</th>
                        <th>位置</th>
                        <th>状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="buttonsTableBody">
                    <?php if (empty($buttons)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: #999;">
                            暂无按钮，点击右上角"➕ 添加按钮"创建第一个按钮
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($buttons as $button): ?>
                    <tr data-id="<?php echo $button['id']; ?>">
                        <td>
                            <strong><?php echo htmlspecialchars($button['button_emoji'] . ' ' . $button['button_text']); ?></strong>
                        </td>
                        <td>
                            <?php
                            $type_map = [
                                'command' => ['命令', 'badge-command'],
                                'reply_text' => ['回复文本', 'badge-reply'],
                                'url' => ['打开链接', 'badge-url']
                            ];
                            $type_info = $type_map[$button['action_type']] ?? ['未知', 'badge-command'];
                            ?>
                            <span class="badge <?php echo $type_info[1]; ?>"><?php echo $type_info[0]; ?></span>
                        </td>
                        <td>
                            <small><?php echo htmlspecialchars(mb_substr($button['action_value'], 0, 50)); ?><?php echo mb_strlen($button['action_value']) > 50 ? '...' : ''; ?></small>
                        </td>
                        <td>第<?php echo $button['row_number']; ?>行-第<?php echo $button['column_number']; ?>列</td>
                        <td>
                            <label class="switch">
                                <input type="checkbox" <?php echo $button['is_active'] ? 'checked' : ''; ?> onchange="toggleButton(<?php echo $button['id']; ?>, this.checked)">
                                <span class="slider"></span>
                            </label>
                        </td>
                        <td>
                            <div class="btn-group">
                                <button class="btn-sm btn-edit" onclick="editButton(<?php echo $button['id']; ?>)">✏️ 编辑</button>
                                <button class="btn-sm btn-delete" onclick="deleteButton(<?php echo $button['id']; ?>)">🗑️ 删除</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- 添加/编辑模态框 -->
    <div id="buttonModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <div class="modal-header">
                <h2 id="modalTitle">添加按钮</h2>
            </div>
            <form id="buttonForm">
                <input type="hidden" id="buttonId" name="id">
                
                <div class="form-group">
                    <label>按钮文本 *</label>
                    <input type="text" class="form-control" id="buttonText" name="button_text" required placeholder="例如：帮助">
                </div>
                
                <div class="form-group">
                    <label>按钮图标（Emoji）</label>
                    <input type="text" class="form-control" id="buttonEmoji" name="button_emoji" placeholder="例如：❓" maxlength="20">
                    <div class="help-text">常用图标：❓ ℹ️ 📞 👨‍💼 🏠 📧 ⚙️ 💬</div>
                </div>
                
                <div class="form-group">
                    <label>动作类型 *</label>
                    <select class="form-control" id="actionType" name="action_type" required onchange="updateActionField()">
                        <option value="command">执行命令</option>
                        <option value="reply_text">回复文本</option>
                        <option value="url">打开链接</option>
                    </select>
                </div>
                
                <div class="form-group" id="actionValueGroup">
                    <label id="actionValueLabel">动作值 *</label>
                    <textarea class="form-control" id="actionValue" name="action_value" rows="4" required placeholder=""></textarea>
                    <div class="help-text" id="actionValueHelp"></div>
                </div>
                
                <div class="position-grid">
                    <div class="form-group">
                        <label>第几行 *</label>
                        <select class="form-control" id="rowNumber" name="row_number" required>
                            <option value="1">第1行</option>
                            <option value="2">第2行</option>
                            <option value="3">第3行</option>
                            <option value="4">第4行</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>第几列 *</label>
                        <select class="form-control" id="columnNumber" name="column_number" required>
                            <option value="1">第1列</option>
                            <option value="2">第2列</option>
                            <option value="3">第3列</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>排序</label>
                    <input type="number" class="form-control" id="sortOrder" name="sort_order" value="0">
                    <div class="help-text">数字越小越靠前</div>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="isActive" name="is_active" checked> 启用
                    </label>
                </div>
                
                <div style="text-align: right; margin-top: 30px;">
                    <button type="button" class="btn-sm" onclick="closeModal()" style="background: #999; color: white; padding: 10px 20px;">取消</button>
                    <button type="submit" class="btn-primary" style="margin-left: 10px;">💾 保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // 按钮数据
        let buttonsData = <?php echo json_encode($buttons); ?>;
        
        // 更新预览
        function updatePreview() {
            const preview = document.getElementById('keyboardPreview');
            const rows = {};
            
            // 按行分组
            buttonsData.filter(b => b.is_active).forEach(button => {
                if (!rows[button.row_number]) {
                    rows[button.row_number] = [];
                }
                rows[button.row_number].push(button);
            });
            
            // 生成HTML
            let html = '';
            Object.keys(rows).sort().forEach(rowNum => {
                html += '<div class="keyboard-row">';
                rows[rowNum].sort((a, b) => a.column_number - b.column_number).forEach(button => {
                    html += `<div class="keyboard-button">${button.button_emoji || ''} ${button.button_text}</div>`;
                });
                html += '</div>';
            });
            
            preview.innerHTML = html || '<div style="text-align: center; padding: 20px; color: #999;">暂无按钮</div>';
        }
        
        // 打开模态框
        function openModal(id = null) {
            const modal = document.getElementById('buttonModal');
            const form = document.getElementById('buttonForm');
            form.reset();
            
            if (id) {
                document.getElementById('modalTitle').textContent = '编辑按钮';
                const button = buttonsData.find(b => b.id == id);
                if (button) {
                    document.getElementById('buttonId').value = button.id;
                    document.getElementById('buttonText').value = button.button_text;
                    document.getElementById('buttonEmoji').value = button.button_emoji || '';
                    document.getElementById('actionType').value = button.action_type;
                    document.getElementById('actionValue').value = button.action_value;
                    document.getElementById('rowNumber').value = button.row_number;
                    document.getElementById('columnNumber').value = button.column_number;
                    document.getElementById('sortOrder').value = button.sort_order;
                    document.getElementById('isActive').checked = button.is_active == 1;
                }
            } else {
                document.getElementById('modalTitle').textContent = '添加按钮';
                document.getElementById('buttonId').value = '';
            }
            
            updateActionField();
            modal.style.display = 'block';
        }
        
        // 关闭模态框
        function closeModal() {
            document.getElementById('buttonModal').style.display = 'none';
        }
        
        // 更新动作字段
        function updateActionField() {
            const type = document.getElementById('actionType').value;
            const label = document.getElementById('actionValueLabel');
            const help = document.getElementById('actionValueHelp');
            const input = document.getElementById('actionValue');
            
            switch(type) {
                case 'command':
                    label.textContent = '命令 *';
                    help.textContent = '例如：/help 或 /start';
                    input.placeholder = '/help';
                    input.rows = 2;
                    break;
                case 'reply_text':
                    label.textContent = '回复文本 *';
                    help.textContent = '用户点击按钮后机器人回复的内容';
                    input.placeholder = '这是帮助信息...';
                    input.rows = 6;
                    break;
                case 'url':
                    label.textContent = '链接地址 *';
                    help.textContent = '例如：https://example.com';
                    input.placeholder = 'https://';
                    input.rows = 2;
                    break;
            }
        }
        
        // 提交表单
        document.getElementById('buttonForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const data = {
                action: document.getElementById('buttonId').value ? 'update' : 'create'
            };
            
            formData.forEach((value, key) => {
                if (key === 'is_active') {
                    data[key] = document.getElementById('isActive').checked ? 1 : 0;
                } else {
                    data[key] = value;
                }
            });
            
            fetch('api/normal_mode_menu.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    alert('保存成功！');
                    location.reload();
                } else {
                    alert('保存失败：' + result.message);
                }
            })
            .catch(err => {
                console.error(err);
                alert('操作失败，请重试');
            });
        });
        
        // 编辑按钮
        function editButton(id) {
            openModal(id);
        }
        
        // 删除按钮
        function deleteButton(id) {
            if (!confirm('确定要删除这个按钮吗？')) return;
            
            fetch('api/normal_mode_menu.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'delete', id: id})
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    alert('删除成功！');
                    location.reload();
                } else {
                    alert('删除失败：' + result.message);
                }
            });
        }
        
        // 切换状态
        function toggleButton(id, enabled) {
            fetch('api/normal_mode_menu.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'toggle',
                    id: id,
                    is_active: enabled ? 1 : 0
                })
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    const button = buttonsData.find(b => b.id == id);
                    if (button) button.is_active = enabled ? 1 : 0;
                    updatePreview();
                } else {
                    alert('操作失败：' + result.message);
                }
            });
        }
        
        // 初始化
        updatePreview();
    </script>
</body>
</html>

