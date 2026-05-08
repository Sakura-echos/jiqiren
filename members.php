<?php
require_once 'config.php';
checkLogin();

$db = getDB();

// 获取群组ID（如果指定）
$group_id = $_GET['group_id'] ?? 0;
$group_title = '所有群组';

if ($group_id) {
    $stmt = $db->prepare("SELECT title FROM groups WHERE id = ?");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch();
    if ($group) {
        $group_title = $group['title'];
    }
}

// 获取成员列表
if ($group_id) {
    $stmt = $db->prepare("
        SELECT gm.*, g.title as group_title, g.chat_id 
        FROM group_members gm 
        JOIN groups g ON gm.group_id = g.id 
        WHERE gm.group_id = ? 
        ORDER BY gm.id DESC
    ");
    $stmt->execute([$group_id]);
} else {
    $stmt = $db->query("
        SELECT gm.*, g.title as group_title, g.chat_id 
        FROM group_members gm 
        JOIN groups g ON gm.group_id = g.id 
        ORDER BY gm.id DESC 
        LIMIT 100
    ");
}
$members = $stmt->fetchAll();

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>成员管理 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>👤 成员管理</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2><?php echo escape($group_title); ?> - 成员列表</h2>
                    <div style="display: flex; gap: 10px;">
                        <?php if ($group_id): ?>
                            <a href="members.php" class="btn btn-sm btn-primary">查看所有成员</a>
                        <?php endif; ?>
                        <div class="dropdown" style="position: relative;">
                            <button class="btn btn-sm btn-success" onclick="toggleExportMenu()">📥 导出</button>
                            <div id="exportMenu" style="display: none; position: absolute; right: 0; top: 100%; background: #1e293b; border: 1px solid #334155; border-radius: 6px; min-width: 150px; z-index: 100; margin-top: 5px;">
                                <a href="#" onclick="exportMembers('csv')" style="display: block; padding: 10px 15px; color: #e2e8f0; text-decoration: none; border-bottom: 1px solid #334155;">📄 CSV 格式</a>
                                <a href="#" onclick="exportMembers('txt')" style="display: block; padding: 10px 15px; color: #e2e8f0; text-decoration: none; border-bottom: 1px solid #334155;">📝 TXT 格式</a>
                                <a href="#" onclick="exportMembers('json')" style="display: block; padding: 10px 15px; color: #e2e8f0; text-decoration: none;">📋 JSON 格式</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>用户ID</th>
                                    <th>用户名</th>
                                    <th>姓名</th>
                                    <th>群组</th>
                                    <th>状态</th>
                                    <th>加入时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($members)): ?>
                                    <tr>
                                        <td colspan="8" class="empty-state">暂无成员</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($members as $member): ?>
                                        <tr>
                                            <td><?php echo $member['id']; ?></td>
                                            <td><?php echo $member['user_id']; ?></td>
                                            <td><?php echo $member['username'] ? '@' . escape($member['username']) : '-'; ?></td>
                                            <td><?php echo escape($member['first_name'] . ' ' . ($member['last_name'] ?? '')); ?></td>
                                            <td><?php echo escape($member['group_title']); ?></td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    echo $member['status'] == 'member' ? 'success' : 
                                                        ($member['status'] == 'administrator' ? 'primary' : 'danger'); 
                                                ?>">
                                                    <?php echo escape($member['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($member['joined_at'])); ?></td>
                                            <td>
                                                <?php if ($member['status'] == 'member'): ?>
                                                    <button class="btn btn-sm btn-warning" onclick="muteMember(<?php echo $member['id']; ?>)">禁言</button>
                                                    <button class="btn btn-sm btn-danger" onclick="kickMember(<?php echo $member['id']; ?>)">踢出</button>
                                                    <button class="btn btn-sm btn-danger" onclick="banMember(<?php echo $member['id']; ?>)">封禁</button>
                                                <?php endif; ?>
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
    
    <script src="assets/script.js"></script>
    <script>
        // 导出菜单切换
        function toggleExportMenu() {
            const menu = document.getElementById('exportMenu');
            menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        }
        
        // 点击其他地方关闭菜单
        document.addEventListener('click', function(e) {
            const menu = document.getElementById('exportMenu');
            if (!e.target.closest('.dropdown')) {
                menu.style.display = 'none';
            }
        });
        
        // 导出成员
        function exportMembers(format) {
            const groupId = <?php echo $group_id ? $group_id : 0; ?>;
            window.location.href = 'api/members.php?action=export&format=' + format + '&group_id=' + groupId;
            document.getElementById('exportMenu').style.display = 'none';
        }
        
        async function muteMember(memberId) {
            const duration = prompt('请输入禁言时长（秒）', '3600');
            if (!duration) return;
            
            const result = await App.request('api/members.php', 'POST', {
                action: 'mute',
                member_id: memberId,
                duration: parseInt(duration)
            });
            
            if (result && result.success) {
                App.showAlert('操作成功');
                setTimeout(() => location.reload(), 1000);
            }
        }
        
        async function kickMember(memberId) {
            if (!App.confirm('确定要踢出这个成员吗？')) return;
            
            const result = await App.request('api/members.php', 'POST', {
                action: 'kick',
                member_id: memberId
            });
            
            if (result && result.success) {
                App.showAlert('操作成功');
                setTimeout(() => location.reload(), 1000);
            }
        }
        
        async function banMember(memberId) {
            if (!App.confirm('确定要永久封禁这个成员吗？')) return;
            
            const result = await App.request('api/members.php', 'POST', {
                action: 'ban',
                member_id: memberId
            });
            
            if (result && result.success) {
                App.showAlert('操作成功');
                setTimeout(() => location.reload(), 1000);
            }
        }
    </script>
</body>
</html>

