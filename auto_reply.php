<?php
require_once 'config.php';
checkLogin();

$db = getDB();

// 获取所有群组
$stmt = $db->query("SELECT id, title FROM groups WHERE is_active = 1 ORDER BY title");
$groups = $stmt->fetchAll();

// 获取自动回复列表
$stmt = $db->query("SELECT ar.*, g.title as group_title FROM auto_replies ar LEFT JOIN groups g ON ar.group_id = g.id ORDER BY ar.id DESC");
$auto_replies = $stmt->fetchAll();

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>自动回复 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>🤖 自动回复</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>自动回复规则</h2>
                    <button class="btn btn-primary" onclick="App.showModal('addReplyModal')">+ 添加规则</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>触发词</th>
                                    <th>回复内容</th>
                                    <th>图片</th>
                                    <th>按钮</th>
                                    <th>匹配方式</th>
                                    <th>应用群组</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($auto_replies)): ?>
                                    <tr>
                                        <td colspan="9" class="empty-state">暂无自动回复规则</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($auto_replies as $reply): ?>
                                        <?php 
                                        $buttons = json_decode($reply['buttons'] ?? '[]', true);
                                        $buttonCount = is_array($buttons) ? count($buttons) : 0;
                                        ?>
                                        <tr>
                                            <td><?php echo $reply['id']; ?></td>
                                            <td><?php echo escape($reply['trigger']); ?></td>
                                            <td><?php echo escape(substr($reply['response'], 0, 30)) . (strlen($reply['response']) > 30 ? '...' : ''); ?></td>
                                            <td><?php echo $reply['image_url'] ? '<span class="badge badge-success">✓</span>' : '-'; ?></td>
                                            <td><?php echo $buttonCount > 0 ? '<span class="badge badge-info">' . $buttonCount . '</span>' : '-'; ?></td>
                                            <td><span class="badge badge-primary"><?php echo escape($reply['match_type']); ?></span></td>
                                            <td><?php echo $reply['group_title'] ? escape($reply['group_title']) : '所有群组'; ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $reply['is_active'] ? 'success' : 'danger'; ?>">
                                                    <?php echo $reply['is_active'] ? '启用' : '禁用'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-primary" onclick="editReply(<?php echo $reply['id']; ?>)">编辑</button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteReply(<?php echo $reply['id']; ?>)">删除</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 添加自动回复模态框 -->
    <div id="addReplyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>添加自动回复规则</h3>
            </div>
            <form onsubmit="event.preventDefault(); addReply();">
                <div class="form-group">
                    <label>触发词 *</label>
                    <input type="text" id="replyTrigger" class="form-control" required placeholder="输入触发词...">
                </div>
                
                <div class="form-group">
                    <label>回复内容 *</label>
                    <textarea id="replyResponse" class="form-control" rows="4" required placeholder="输入回复内容..."></textarea>
                    <small class="form-text">
                        支持变量：<code>{first_name}</code> <code>{last_name}</code> <code>{full_name}</code> <code>{username}</code> <code>{user_id}</code> <code>{group_name}</code> <code>{name}</code>
                    </small>
                </div>
                
                <div class="form-group">
                    <label>图片 (可选)</label>
                    <input type="file" id="replyImage" class="form-control" accept="image/*">
                    <small class="form-text">支持 JPG, PNG, GIF 格式。GIF最大50MB，其他格式最大5MB</small>
                    <div id="imagePreview" style="margin-top: 10px;"></div>
                </div>
                
                <div class="form-group">
                    <label>按钮配置 (可选)</label>
                    <div id="buttonsContainer"></div>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="addButton()">+ 添加按钮</button>
                    <small class="form-text">添加内联按钮，点击可跳转到指定链接</small>
                </div>
                
                <div class="form-group">
                    <label>匹配方式</label>
                    <select id="replyMatchType" class="form-control">
                        <option value="contains">包含</option>
                        <option value="exact">精确匹配</option>
                        <option value="starts_with">开头匹配</option>
                        <option value="ends_with">结尾匹配</option>
                        <option value="regex">正则表达式</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>应用群组</label>
                    <select id="replyGroupId" class="form-control">
                        <option value="">所有群组</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"><?php echo escape($group['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>消息自毁时间（秒）</label>
                    <input type="number" id="replyDeleteAfter" class="form-control" min="0" max="86400" value="0" placeholder="0 表示不自动删除">
                    <small class="form-text">设置后消息将在指定秒数后自动删除，0 表示不自动删除。建议 30-300 秒</small>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('addReplyModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">添加</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 编辑自动回复模态框 -->
    <div id="editReplyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>编辑自动回复规则</h3>
            </div>
            <form onsubmit="event.preventDefault(); updateReply();">
                <input type="hidden" id="editReplyId">
                
                <div class="form-group">
                    <label>触发词 *</label>
                    <input type="text" id="editReplyTrigger" class="form-control" required placeholder="输入触发词...">
                </div>
                
                <div class="form-group">
                    <label>回复内容 *</label>
                    <textarea id="editReplyResponse" class="form-control" rows="4" required placeholder="输入回复内容..."></textarea>
                    <small class="form-text">
                        支持变量：<code>{first_name}</code> <code>{last_name}</code> <code>{full_name}</code> <code>{username}</code> <code>{user_id}</code> <code>{group_name}</code> <code>{name}</code>
                    </small>
                </div>
                
                <div class="form-group">
                    <label>当前图片</label>
                    <div id="editReplyCurrentImage"></div>
                </div>
                
                <div class="form-group">
                    <label>更换图片 (可选)</label>
                    <input type="file" id="editReplyImage" class="form-control" accept="image/*">
                    <small class="form-text">留空则保持原图片不变</small>
                    <div id="editImagePreview" style="margin-top: 10px;"></div>
                </div>
                
                <div class="form-group">
                    <label>按钮配置 (可选)</label>
                    <div id="editButtonsContainer"></div>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="addEditButton()">+ 添加按钮</button>
                    <small class="form-text">添加内联按钮，点击可跳转到指定链接</small>
                </div>
                
                <div class="form-group">
                    <label>匹配方式</label>
                    <select id="editReplyMatchType" class="form-control">
                        <option value="contains">包含</option>
                        <option value="exact">精确匹配</option>
                        <option value="starts_with">开头匹配</option>
                        <option value="ends_with">结尾匹配</option>
                        <option value="regex">正则表达式</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>应用群组</label>
                    <select id="editReplyGroupId" class="form-control">
                        <option value="">所有群组</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"><?php echo escape($group['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>消息自毁时间（秒）</label>
                    <input type="number" id="editReplyDeleteAfter" class="form-control" min="0" max="86400" value="0" placeholder="0 表示不自动删除">
                    <small class="form-text">设置后消息将在指定秒数后自动删除，0 表示不自动删除。建议 30-300 秒</small>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('editReplyModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
    <script>
        let buttonIndex = 0;
        
        // Image preview
        document.addEventListener('DOMContentLoaded', function() {
            // 添加回复图片预览
            const imageInput = document.getElementById('replyImage');
            if (imageInput) {
                imageInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    const preview = document.getElementById('imagePreview');
                    
                    if (file) {
                        // 检查文件大小：GIF最大50MB，其他格式最大5MB
                        const maxSize = file.type === 'image/gif' ? 50 * 1024 * 1024 : 5 * 1024 * 1024;
                        const maxSizeText = file.type === 'image/gif' ? '50MB' : '5MB';
                        
                        if (file.size > maxSize) {
                            App.showAlert('图片大小不能超过 ' + maxSizeText, 'error');
                            e.target.value = '';
                            preview.innerHTML = '';
                            return;
                        }
                        
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            preview.innerHTML = `<img src="${e.target.result}" style="max-width: 200px; max-height: 200px; border: 1px solid #ddd; border-radius: 4px;">`;
                        };
                        reader.readAsDataURL(file);
                    } else {
                        preview.innerHTML = '';
                    }
                });
            }
            
            // 编辑回复图片预览
            const editImageInput = document.getElementById('editReplyImage');
            if (editImageInput) {
                editImageInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    const preview = document.getElementById('editImagePreview');
                    
                    if (file) {
                        // 检查文件大小：GIF最大50MB，其他格式最大5MB
                        const maxSize = file.type === 'image/gif' ? 50 * 1024 * 1024 : 5 * 1024 * 1024;
                        const maxSizeText = file.type === 'image/gif' ? '50MB' : '5MB';
                        
                        if (file.size > maxSize) {
                            App.showAlert('图片大小不能超过 ' + maxSizeText, 'error');
                            e.target.value = '';
                            preview.innerHTML = '';
                            return;
                        }
                        
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            preview.innerHTML = `<img src="${e.target.result}" style="max-width: 200px; max-height: 200px; border: 1px solid #ddd; border-radius: 4px;">`;
                        };
                        reader.readAsDataURL(file);
                    } else {
                        preview.innerHTML = '';
                    }
                });
            }
        });
        
        function addButton() {
            const container = document.getElementById('buttonsContainer');
            const buttonDiv = document.createElement('div');
            buttonDiv.className = 'button-row';
            buttonDiv.style.marginBottom = '10px';
            buttonDiv.innerHTML = `
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="text" class="form-control button-text" placeholder="Button text" style="flex: 1;">
                    <input type="url" class="form-control button-url" placeholder="https://example.com" style="flex: 1;">
                    <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">×</button>
                </div>
            `;
            container.appendChild(buttonDiv);
            buttonIndex++;
        }
        
        function getButtons() {
            const buttons = [];
            const container = document.getElementById('buttonsContainer');
            if (container) {
                container.querySelectorAll('.button-row').forEach(row => {
                    const textInput = row.querySelector('.button-text');
                    const urlInput = row.querySelector('.button-url');
                    if (textInput && urlInput) {
                        const text = textInput.value;
                        const url = urlInput.value;
                        if (text && url) {
                            buttons.push({ text, url });
                        }
                    }
                });
            }
            return buttons;
        }
        
        async function addReply() {
            const trigger = document.getElementById('replyTrigger').value;
            const response = document.getElementById('replyResponse').value;
            const imageFile = document.getElementById('replyImage').files[0];
            const matchType = document.getElementById('replyMatchType').value;
            const groupId = document.getElementById('replyGroupId').value;
            const deleteAfter = document.getElementById('replyDeleteAfter').value;
            const buttons = getButtons();
            
            // Use FormData for file upload
            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('trigger', trigger);
            formData.append('response', response);
            formData.append('match_type', matchType);
            formData.append('delete_after_seconds', deleteAfter);
            if (groupId) formData.append('group_id', groupId);
            if (buttons.length > 0) formData.append('buttons', JSON.stringify(buttons));
            if (imageFile) formData.append('image', imageFile);
            
            try {
                const response = await fetch('api/auto_reply.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result && result.success) {
                    App.showAlert('添加成功');
                    App.hideModal('addReplyModal');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    App.showAlert(result.message || '添加失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('添加失败：' + error.message, 'error');
            }
        }
        
        // Add button for edit reply
        function addEditButton() {
            const container = document.getElementById('editButtonsContainer');
            const buttonDiv = document.createElement('div');
            buttonDiv.className = 'button-row';
            buttonDiv.style.marginBottom = '10px';
            buttonDiv.innerHTML = `
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="text" class="form-control edit-button-text" placeholder="Button text" style="flex: 1;">
                    <input type="url" class="form-control edit-button-url" placeholder="https://example.com" style="flex: 1;">
                    <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">×</button>
                </div>
            `;
            container.appendChild(buttonDiv);
        }
        
        // Get buttons for edit reply
        function getEditButtons() {
            const buttons = [];
            const container = document.getElementById('editButtonsContainer');
            if (container) {
                container.querySelectorAll('.button-row').forEach(row => {
                    const textInput = row.querySelector('.edit-button-text');
                    const urlInput = row.querySelector('.edit-button-url');
                    if (textInput && urlInput) {
                        const text = textInput.value;
                        const url = urlInput.value;
                        if (text && url) {
                            buttons.push({ text, url });
                        }
                    }
                });
            }
            return buttons;
        }
        
        // Edit reply function
        async function editReply(id) {
            try {
                // Fetch reply data
                const response = await fetch(`api/auto_reply.php?action=get&id=${id}`);
                const result = await response.json();
                
                if (result && result.success) {
                    const reply = result.data;
                    
                    // Fill form
                    document.getElementById('editReplyId').value = reply.id;
                    document.getElementById('editReplyTrigger').value = reply.trigger;
                    document.getElementById('editReplyResponse').value = reply.response;
                    document.getElementById('editReplyMatchType').value = reply.match_type;
                    document.getElementById('editReplyGroupId').value = reply.group_id || '';
                    document.getElementById('editReplyDeleteAfter').value = reply.delete_after_seconds || 0;
                    
                    // Show current image
                    const currentImageDiv = document.getElementById('editReplyCurrentImage');
                    if (reply.image_url) {
                        currentImageDiv.innerHTML = `<img src="${reply.image_url}" style="max-width: 200px; max-height: 200px; border: 1px solid #ddd; border-radius: 4px; margin-top: 5px;">`;
                    } else {
                        currentImageDiv.innerHTML = '<p style="color: #999;">无图片</p>';
                    }
                    
                    // Clear and populate buttons
                    const buttonsContainer = document.getElementById('editButtonsContainer');
                    buttonsContainer.innerHTML = '';
                    
                    if (reply.buttons) {
                        const buttons = JSON.parse(reply.buttons);
                        buttons.forEach(button => {
                            addEditButton();
                            const lastRow = buttonsContainer.lastElementChild;
                            lastRow.querySelector('.edit-button-text').value = button.text;
                            lastRow.querySelector('.edit-button-url').value = button.url;
                        });
                    }
                    
                    // Clear image preview
                    document.getElementById('editImagePreview').innerHTML = '';
                    document.getElementById('editReplyImage').value = '';
                    
                    // Show modal
                    App.showModal('editReplyModal');
                } else {
                    App.showAlert('获取回复规则失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('获取回复规则失败：' + error.message, 'error');
            }
        }
        
        // Update reply function
        async function updateReply() {
            const id = document.getElementById('editReplyId').value;
            const trigger = document.getElementById('editReplyTrigger').value;
            const response = document.getElementById('editReplyResponse').value;
            const imageFile = document.getElementById('editReplyImage')?.files[0];
            const matchType = document.getElementById('editReplyMatchType').value;
            const groupId = document.getElementById('editReplyGroupId').value;
            const deleteAfter = document.getElementById('editReplyDeleteAfter').value;
            const buttons = getEditButtons();
            
            // Use FormData for file upload
            const formData = new FormData();
            formData.append('action', 'update');
            formData.append('id', id);
            formData.append('trigger', trigger);
            formData.append('response', response);
            formData.append('match_type', matchType);
            formData.append('delete_after_seconds', deleteAfter);
            if (groupId) formData.append('group_id', groupId);
            if (buttons.length > 0) formData.append('buttons', JSON.stringify(buttons));
            if (imageFile) formData.append('image', imageFile);
            
            try {
                const response = await fetch('api/auto_reply.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result && result.success) {
                    App.showAlert('更新成功');
                    App.hideModal('editReplyModal');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    App.showAlert(result.message || '更新失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('更新失败：' + error.message, 'error');
            }
        }
        
        async function deleteReply(id) {
            if (!App.confirm('确定要删除这个自动回复规则吗？')) return;
            
            const result = await App.request('api/auto_reply.php', 'POST', {
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

