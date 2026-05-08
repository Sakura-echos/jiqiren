<?php
require_once 'config.php';
checkLogin();

$db = getDB();

// 获取所有群组
$stmt = $db->query("SELECT id, title FROM groups WHERE is_active = 1 ORDER BY title");
$groups = $stmt->fetchAll();

// 获取欢迎消息列表
$stmt = $db->query("SELECT wm.*, COALESCE(g.title, '所有群组') as group_title FROM welcome_messages wm LEFT JOIN groups g ON wm.group_id = g.id ORDER BY wm.id DESC");
$welcome_messages = $stmt->fetchAll();

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>欢迎消息 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>👋 欢迎消息</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <div class="alert alert-success">
                <strong>可用变量：</strong> {name} - 新成员名字，{username} - 新成员用户名
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>欢迎消息列表</h2>
                    <button class="btn btn-primary" onclick="App.showModal('addWelcomeModal')">+ 添加欢迎消息</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>群组</th>
                                    <th>消息内容</th>
                                    <th>图片</th>
                                    <th>按钮</th>
                                    <th>自毁时间</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($welcome_messages)): ?>
                                    <tr>
                                        <td colspan="8" class="empty-state">暂无欢迎消息</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($welcome_messages as $msg): ?>
                                        <?php 
                                        $buttons = json_decode($msg['buttons'] ?? '[]', true);
                                        $buttonCount = is_array($buttons) ? count($buttons) : 0;
                                        ?>
                                        <tr>
                                            <td><?php echo $msg['id']; ?></td>
                                            <td><?php echo escape($msg['group_title']); ?></td>
                                            <td><?php echo escape(substr($msg['message'], 0, 50)) . (strlen($msg['message']) > 50 ? '...' : ''); ?></td>
                                            <td><?php echo $msg['image_url'] ? '<span class="badge badge-success">✓</span>' : '-'; ?></td>
                                            <td><?php echo $buttonCount > 0 ? '<span class="badge badge-info">' . $buttonCount . '</span>' : '-'; ?></td>
                                            <td><?php echo $msg['delete_after_seconds'] ? $msg['delete_after_seconds'] . '秒' : '不自毁'; ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $msg['is_active'] ? 'success' : 'danger'; ?>">
                                                    <?php echo $msg['is_active'] ? '启用' : '禁用'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-primary" onclick="editWelcome(<?php echo $msg['id']; ?>)">编辑</button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteWelcome(<?php echo $msg['id']; ?>)">删除</button>
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
    
    <!-- 添加欢迎消息模态框 -->
    <div id="addWelcomeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>添加欢迎消息</h3>
            </div>
            <form onsubmit="event.preventDefault(); addWelcome();">
                <div class="form-group">
                    <label>选择群组 *</label>
                    <select id="welcomeGroupId" class="form-control" required>
                        <option value="">请选择群组</option>
                        <option value="0">📢 所有群组</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"><?php echo escape($group['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>欢迎消息 *</label>
                    <textarea id="welcomeMessage" class="form-control" rows="5" required placeholder="欢迎 {name} 加入群组！"></textarea>
                </div>
                
                <div class="form-group">
                    <label>图片 (可选)</label>
                    <input type="file" id="welcomeImage" class="form-control" accept="image/*">
                    <small class="form-text">支持 JPG, PNG, GIF 格式。GIF最大50MB，其他格式最大5MB</small>
                    <div id="welcomeImagePreview" style="margin-top: 10px;"></div>
                </div>
                
                <div class="form-group">
                    <label>按钮配置 (可选)</label>
                    <div id="welcomeButtonsContainer"></div>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="addWelcomeButton()">+ 添加按钮</button>
                    <small class="form-text">添加内联按钮，点击可跳转到指定链接</small>
                </div>
                
                <div class="form-group">
                    <label>消息自毁时间 (秒)</label>
                    <input type="number" id="welcomeDeleteAfter" class="form-control" min="0" max="300" value="30" placeholder="30">
                    <small class="form-text">消息发送后自动删除的时间，0 表示不自毁，建议设置 30-60 秒</small>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('addWelcomeModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">添加</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 编辑欢迎消息模态框 -->
    <div id="editWelcomeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>编辑欢迎消息</h3>
            </div>
            <form onsubmit="event.preventDefault(); updateWelcome();">
                <input type="hidden" id="editWelcomeId">
                
                <div class="form-group">
                    <label>选择群组 *</label>
                    <select id="editWelcomeGroupId" class="form-control" required>
                        <option value="">请选择群组</option>
                        <option value="0">📢 所有群组</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"><?php echo escape($group['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>欢迎消息 *</label>
                    <textarea id="editWelcomeMessage" class="form-control" rows="5" required placeholder="欢迎 {name} 加入群组！"></textarea>
                </div>
                
                <div class="form-group">
                    <label>当前图片</label>
                    <div id="editWelcomeCurrentImage"></div>
                </div>
                
                <div class="form-group">
                    <label>更换图片 (可选)</label>
                    <input type="file" id="editWelcomeImage" class="form-control" accept="image/*">
                    <small class="form-text">留空则保持原图片不变</small>
                    <div id="editWelcomeImagePreview" style="margin-top: 10px;"></div>
                </div>
                
                <div class="form-group">
                    <label>按钮配置 (可选)</label>
                    <div id="editWelcomeButtonsContainer"></div>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="addEditWelcomeButton()">+ 添加按钮</button>
                    <small class="form-text">添加内联按钮，点击可跳转到指定链接</small>
                </div>
                
                <div class="form-group">
                    <label>消息自毁时间 (秒)</label>
                    <input type="number" id="editWelcomeDeleteAfter" class="form-control" min="0" max="300" placeholder="30">
                    <small class="form-text">消息发送后自动删除的时间，0 表示不自毁，建议设置 30-60 秒</small>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('editWelcomeModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
    <script>
        // Image preview for welcome message
        document.addEventListener('DOMContentLoaded', function() {
            // 添加欢迎消息图片预览
            const imageInput = document.getElementById('welcomeImage');
            if (imageInput) {
                imageInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    const preview = document.getElementById('welcomeImagePreview');
                    
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
            
            // 编辑欢迎消息图片预览
            const editImageInput = document.getElementById('editWelcomeImage');
            if (editImageInput) {
                editImageInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    const preview = document.getElementById('editWelcomeImagePreview');
                    
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
        
        // Add button for welcome message
        function addWelcomeButton() {
            const container = document.getElementById('welcomeButtonsContainer');
            const buttonDiv = document.createElement('div');
            buttonDiv.className = 'button-row';
            buttonDiv.style.marginBottom = '10px';
            buttonDiv.innerHTML = `
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="text" class="form-control welcome-button-text" placeholder="Button text" style="flex: 1;">
                    <input type="url" class="form-control welcome-button-url" placeholder="https://example.com" style="flex: 1;">
                    <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">×</button>
                </div>
            `;
            container.appendChild(buttonDiv);
        }
        
        // Get buttons for welcome message
        function getWelcomeButtons() {
            const buttons = [];
            document.querySelectorAll('#welcomeButtonsContainer .button-row').forEach(row => {
                const text = row.querySelector('.welcome-button-text').value;
                const url = row.querySelector('.welcome-button-url').value;
                if (text && url) {
                    buttons.push({ text, url });
                }
            });
            return buttons;
        }
        
        async function addWelcome() {
            const groupId = document.getElementById('welcomeGroupId').value;
            const message = document.getElementById('welcomeMessage').value;
            const imageFile = document.getElementById('welcomeImage')?.files[0];
            const buttons = getWelcomeButtons();
            const deleteAfter = document.getElementById('welcomeDeleteAfter').value;
            
            // Use FormData for file upload
            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('group_id', groupId);
            formData.append('message', message);
            if (buttons.length > 0) formData.append('buttons', JSON.stringify(buttons));
            if (imageFile) formData.append('image', imageFile);
            if (deleteAfter) formData.append('delete_after_seconds', deleteAfter);
            
            try {
                const response = await fetch('api/welcome.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result && result.success) {
                    App.showAlert('添加成功');
                    App.hideModal('addWelcomeModal');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    App.showAlert(result.message || '添加失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('添加失败：' + error.message, 'error');
            }
        }
        
        // Add button for edit welcome
        function addEditWelcomeButton() {
            const container = document.getElementById('editWelcomeButtonsContainer');
            const buttonDiv = document.createElement('div');
            buttonDiv.className = 'button-row';
            buttonDiv.style.marginBottom = '10px';
            buttonDiv.innerHTML = `
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="text" class="form-control edit-welcome-button-text" placeholder="Button text" style="flex: 1;">
                    <input type="url" class="form-control edit-welcome-button-url" placeholder="https://example.com" style="flex: 1;">
                    <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">×</button>
                </div>
            `;
            container.appendChild(buttonDiv);
        }
        
        // Get buttons for edit welcome
        function getEditWelcomeButtons() {
            const buttons = [];
            document.querySelectorAll('#editWelcomeButtonsContainer .button-row').forEach(row => {
                const text = row.querySelector('.edit-welcome-button-text').value;
                const url = row.querySelector('.edit-welcome-button-url').value;
                if (text && url) {
                    buttons.push({ text, url });
                }
            });
            return buttons;
        }
        
        // Edit welcome function
        async function editWelcome(id) {
            try {
                // Fetch welcome data
                const response = await fetch(`api/welcome.php?action=get&id=${id}`);
                const result = await response.json();
                
                if (result && result.success) {
                    const welcome = result.data;
                    
                    // Fill form
                    document.getElementById('editWelcomeId').value = welcome.id;
                    document.getElementById('editWelcomeGroupId').value = welcome.group_id;
                    document.getElementById('editWelcomeMessage').value = welcome.message;
                    document.getElementById('editWelcomeDeleteAfter').value = welcome.delete_after_seconds || 30;
                    
                    // Show current image
                    const currentImageDiv = document.getElementById('editWelcomeCurrentImage');
                    if (welcome.image_url) {
                        currentImageDiv.innerHTML = `<img src="${welcome.image_url}" style="max-width: 200px; max-height: 200px; border: 1px solid #ddd; border-radius: 4px; margin-top: 5px;">`;
                    } else {
                        currentImageDiv.innerHTML = '<p style="color: #999;">无图片</p>';
                    }
                    
                    // Clear and populate buttons
                    const buttonsContainer = document.getElementById('editWelcomeButtonsContainer');
                    buttonsContainer.innerHTML = '';
                    
                    if (welcome.buttons) {
                        const buttons = JSON.parse(welcome.buttons);
                        buttons.forEach(button => {
                            addEditWelcomeButton();
                            const lastRow = buttonsContainer.lastElementChild;
                            lastRow.querySelector('.edit-welcome-button-text').value = button.text;
                            lastRow.querySelector('.edit-welcome-button-url').value = button.url;
                        });
                    }
                    
                    // Clear image preview
                    document.getElementById('editWelcomeImagePreview').innerHTML = '';
                    document.getElementById('editWelcomeImage').value = '';
                    
                    // Show modal
                    App.showModal('editWelcomeModal');
                } else {
                    App.showAlert('获取欢迎消息失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('获取欢迎消息失败：' + error.message, 'error');
            }
        }
        
        // Update welcome function
        async function updateWelcome() {
            const id = document.getElementById('editWelcomeId').value;
            const groupId = document.getElementById('editWelcomeGroupId').value;
            const message = document.getElementById('editWelcomeMessage').value;
            const imageFile = document.getElementById('editWelcomeImage')?.files[0];
            const buttons = getEditWelcomeButtons();
            const deleteAfter = document.getElementById('editWelcomeDeleteAfter').value;
            
            // Use FormData for file upload
            const formData = new FormData();
            formData.append('action', 'update');
            formData.append('id', id);
            formData.append('group_id', groupId);
            formData.append('message', message);
            if (buttons.length > 0) formData.append('buttons', JSON.stringify(buttons));
            if (imageFile) formData.append('image', imageFile);
            if (deleteAfter) formData.append('delete_after_seconds', deleteAfter);
            
            try {
                const response = await fetch('api/welcome.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result && result.success) {
                    App.showAlert('更新成功');
                    App.hideModal('editWelcomeModal');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    App.showAlert(result.message || '更新失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('更新失败：' + error.message, 'error');
            }
        }
        
        async function deleteWelcome(id) {
            if (!App.confirm('确定要删除这个欢迎消息吗？')) return;
            
            const result = await App.request('api/welcome.php', 'POST', {
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

