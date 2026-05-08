<?php
require_once __DIR__ . '/config.php';
checkLogin();

$db = getDB();

// 获取活跃且未删除的群组
$stmt = $db->query("SELECT g.*, 
    (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id AND gm.status = 'member') as member_count,
    COALESCE(g.source, 'bot') as source
    FROM groups g 
    WHERE g.is_active = 1 AND g.is_deleted = 0 
    ORDER BY g.id DESC");
$groups = $stmt->fetchAll();

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>群组管理 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>👥 群组管理</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>群组列表</h2>
                    <button class="btn btn-primary" onclick="syncUserGroups()">🔄 同步真人群组</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>名称</th>
                                    <th>类型</th>
                                    <th>Chat ID</th>
                                    <th>成员数</th>
                                    <th>来源</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="groupsTable">
                                <tr>
                                    <td colspan="8" class="loading">加载中...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
    <script>
        // 同步真人账号群组
        async function syncUserGroups() {
            if (!confirm('确定要同步真人账号的群组列表吗？\n\n这将从真人账号获取所有超级群组，并添加到群组列表中。')) {
                return;
            }
            
            try {
                // 显示加载提示
                App.showAlert('正在同步，请稍候...', 'info');
                
                const response = await fetch('api/sync_user_groups.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
                
                const result = await response.json();
                
                if (result && result.success) {
                    App.showAlert(result.message, 'success');
                    // 重新加载群组列表
                    Groups.load();
                } else {
                    App.showAlert(result.message || '同步失败', 'error');
                }
            } catch (error) {
                console.error('Sync error:', error);
                App.showAlert('同步失败：' + error.message, 'error');
            }
        }
    </script>
</body>
</html>

