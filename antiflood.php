<?php
require_once 'config.php';
checkLogin();

$db = getDB();

// 获取所有群组和防洪水设置
$stmt = $db->query("
    SELECT g.id, g.title, g.chat_id, af.max_messages, af.time_window, af.action, af.mute_duration, af.is_active, af.id as setting_id
    FROM groups g
    LEFT JOIN antiflood_settings af ON g.id = af.group_id
    WHERE g.is_active = 1
    ORDER BY g.title
");
$groups = $stmt->fetchAll();

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>防洪水设置 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>🛡️ 防洪水设置</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <div class="alert alert-success">
                <strong>防洪水机制：</strong>检测用户在指定时间窗口内的消息数量，超过阈值则执行相应处理动作。
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>群组防洪水设置</h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>群组名称</th>
                                    <th>最大消息数</th>
                                    <th>时间窗口(秒)</th>
                                    <th>处理动作</th>
                                    <th>禁言时长(秒)</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($groups)): ?>
                                    <tr>
                                        <td colspan="7" class="empty-state">暂无群组</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($groups as $group): ?>
                                        <tr>
                                            <td><?php echo escape($group['title']); ?></td>
                                            <td><?php echo $group['max_messages'] ?? '-'; ?></td>
                                            <td><?php echo $group['time_window'] ?? '-'; ?></td>
                                            <td>
                                                <?php if ($group['action']): ?>
                                                    <span class="badge badge-warning"><?php echo escape($group['action']); ?></span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $group['mute_duration'] ?? '-'; ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $group['is_active'] ? 'success' : 'danger'; ?>">
                                                    <?php echo $group['is_active'] ? '启用' : '未设置'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-primary" onclick="editAntiFlood(<?php echo $group['id']; ?>, '<?php echo escape($group['title']); ?>', <?php echo $group['max_messages'] ?? 5; ?>, <?php echo $group['time_window'] ?? 5; ?>, '<?php echo $group['action'] ?? 'warn'; ?>', <?php echo $group['mute_duration'] ?? 300; ?>)">
                                                    <?php echo $group['setting_id'] ? '编辑' : '设置'; ?>
                                                </button>
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
    
    <!-- 编辑防洪水设置模态框 -->
    <div id="editAntiFloodModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">防洪水设置</h3>
            </div>
            <form onsubmit="event.preventDefault(); saveAntiFlood();">
                <input type="hidden" id="groupId">
                
                <div class="form-group">
                    <label>最大消息数 *</label>
                    <input type="number" id="maxMessages" class="form-control" min="1" required>
                    <small style="color: #777;">在指定时间窗口内允许的最大消息数</small>
                </div>
                
                <div class="form-group">
                    <label>时间窗口（秒）*</label>
                    <input type="number" id="timeWindow" class="form-control" min="1" required>
                    <small style="color: #777;">检测消息数量的时间范围</small>
                </div>
                
                <div class="form-group">
                    <label>处理动作</label>
                    <select id="action" class="form-control">
                        <option value="warn">警告</option>
                        <option value="mute">禁言</option>
                        <option value="kick">踢出</option>
                        <option value="ban">封禁</option>
                    </select>
                </div>
                
                <div class="form-group" id="muteDurationGroup">
                    <label>禁言时长（秒）</label>
                    <input type="number" id="muteDuration" class="form-control" min="1" value="300">
                    <small style="color: #777;">仅在处理动作为"禁言"时生效</small>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('editAntiFloodModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
    <script>
        function editAntiFlood(groupId, groupTitle, maxMessages, timeWindow, action, muteDuration) {
            document.getElementById('modalTitle').textContent = groupTitle + ' - 防洪水设置';
            document.getElementById('groupId').value = groupId;
            document.getElementById('maxMessages').value = maxMessages;
            document.getElementById('timeWindow').value = timeWindow;
            document.getElementById('action').value = action;
            document.getElementById('muteDuration').value = muteDuration;
            App.showModal('editAntiFloodModal');
        }
        
        async function saveAntiFlood() {
            const groupId = document.getElementById('groupId').value;
            const maxMessages = document.getElementById('maxMessages').value;
            const timeWindow = document.getElementById('timeWindow').value;
            const action = document.getElementById('action').value;
            const muteDuration = document.getElementById('muteDuration').value;
            
            const result = await App.request('api/antiflood.php', 'POST', {
                action: 'save',
                group_id: groupId,
                max_messages: maxMessages,
                time_window: timeWindow,
                action_type: action,
                mute_duration: muteDuration
            });
            
            if (result && result.success) {
                App.showAlert('保存成功');
                App.hideModal('editAntiFloodModal');
                setTimeout(() => location.reload(), 1000);
            }
        }
    </script>
</body>
</html>

