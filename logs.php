<?php
require_once 'config.php';
checkLogin();

$db = getDB();

// 分页参数
$page = $_GET['page'] ?? 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// 获取消息日志
$stmt = $db->prepare("
    SELECT ml.*, g.title as group_title, gm.username, gm.first_name
    FROM message_logs ml 
    JOIN groups g ON ml.group_id = g.id 
    LEFT JOIN group_members gm ON ml.user_id = gm.user_id AND ml.group_id = gm.group_id
    ORDER BY ml.created_at DESC 
    LIMIT ? OFFSET ?
");
$stmt->execute([$per_page, $offset]);
$logs = $stmt->fetchAll();

// 获取总数
$total = $db->query("SELECT COUNT(*) as count FROM message_logs")->fetch()['count'];
$total_pages = ceil($total / $per_page);

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>消息日志 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>📝 消息日志</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>最近消息 (第 <?php echo $page; ?> 页，共 <?php echo $total_pages; ?> 页)</h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>时间</th>
                                    <th>群组</th>
                                    <th>用户</th>
                                    <th>消息内容</th>
                                    <th>类型</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($logs)): ?>
                                    <tr>
                                        <td colspan="5" class="empty-state">暂无消息日志</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td><?php echo date('m-d H:i:s', strtotime($log['created_at'])); ?></td>
                                            <td><?php echo escape($log['group_title']); ?></td>
                                            <td>
                                                <?php 
                                                $user_display = $log['username'] ? '@' . $log['username'] : ($log['first_name'] ?? 'User ' . $log['user_id']);
                                                echo escape($user_display);
                                                ?>
                                            </td>
                                            <td><?php echo escape(substr($log['message_text'] ?? '', 0, 100)); ?></td>
                                            <td><span class="badge badge-primary"><?php echo $log['message_type']; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if ($total_pages > 1): ?>
                        <div style="margin-top: 20px; text-align: center;">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>" class="btn btn-sm btn-primary">上一页</a>
                            <?php endif; ?>
                            
                            <span style="margin: 0 15px;">第 <?php echo $page; ?> / <?php echo $total_pages; ?> 页</span>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>" class="btn btn-sm btn-primary">下一页</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
</body>
</html>

