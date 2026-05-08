<?php
require_once 'config.php';
checkLogin();

$db = getDB();

// 获取所有群组
$stmt = $db->query("SELECT id, title FROM groups WHERE is_active = 1 ORDER BY title");
$groups = $stmt->fetchAll();

// 获取自定义命令列表
$stmt = $db->query("SELECT cc.*, g.title as group_title FROM custom_commands cc LEFT JOIN groups g ON cc.group_id = g.id ORDER BY cc.id DESC");
$commands = $stmt->fetchAll();

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>自定义命令 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>⚙️ 自定义命令</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>命令列表</h2>
                    <button class="btn btn-primary" onclick="App.showModal('addCommandModal')">+ 添加命令</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>命令</th>
                                    <th>说明</th>
                                    <th>回复内容</th>
                                    <th>应用群组</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($commands)): ?>
                                    <tr>
                                        <td colspan="7" class="empty-state">暂无自定义命令</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($commands as $cmd): ?>
                                        <tr>
                                            <td><?php echo $cmd['id']; ?></td>
                                            <td><code><?php echo escape($cmd['command']); ?></code></td>
                                            <td><?php echo escape($cmd['description'] ?? '-'); ?></td>
                                            <td><?php echo escape(substr($cmd['response'], 0, 30)) . (strlen($cmd['response']) > 30 ? '...' : ''); ?></td>
                                            <td><?php echo $cmd['group_title'] ? escape($cmd['group_title']) : '所有群组'; ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $cmd['is_active'] ? 'success' : 'danger'; ?>">
                                                    <?php echo $cmd['is_active'] ? '启用' : '禁用'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-danger" onclick="deleteCommand(<?php echo $cmd['id']; ?>)">删除</button>
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
    
    <!-- 添加命令模态框 -->
    <div id="addCommandModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>添加自定义命令</h3>
            </div>
            <form onsubmit="event.preventDefault(); addCommand();">
                <div class="form-group">
                    <label>命令 *</label>
                    <input type="text" id="commandName" class="form-control" required placeholder="/mycommand">
                    <small style="color: #777;">命令必须以 / 开头</small>
                </div>
                
                <div class="form-group">
                    <label>说明</label>
                    <input type="text" id="commandDesc" class="form-control" placeholder="命令说明">
                </div>
                
                <div class="form-group">
                    <label>回复内容 *</label>
                    <textarea id="commandResponse" class="form-control" rows="4" required placeholder="执行命令后的回复内容..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>应用群组</label>
                    <select id="commandGroupId" class="form-control">
                        <option value="">所有群组</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"><?php echo escape($group['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('addCommandModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">添加</button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
    <script>
        async function addCommand() {
            const command = document.getElementById('commandName').value;
            const description = document.getElementById('commandDesc').value;
            const response = document.getElementById('commandResponse').value;
            const groupId = document.getElementById('commandGroupId').value;
            
            if (!command.startsWith('/')) {
                App.showAlert('命令必须以 / 开头', 'error');
                return;
            }
            
            const result = await App.request('api/commands.php', 'POST', {
                action: 'add',
                command: command,
                description: description,
                response: response,
                group_id: groupId || null
            });
            
            if (result && result.success) {
                App.showAlert('添加成功');
                App.hideModal('addCommandModal');
                setTimeout(() => location.reload(), 1000);
            }
        }
        
        async function deleteCommand(id) {
            if (!App.confirm('确定要删除这个命令吗？')) return;
            
            const result = await App.request('api/commands.php', 'POST', {
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

