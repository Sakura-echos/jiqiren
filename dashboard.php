<?php
require_once 'config.php';
checkLogin();

$db = getDB();

// 获取统计数据
$stmt = $db->query("SELECT 
    SUM(CASE WHEN type IN ('group', 'supergroup') THEN 1 ELSE 0 END) as group_count,
    SUM(CASE WHEN type = 'channel' THEN 1 ELSE 0 END) as channel_count,
    SUM(CASE WHEN COALESCE(source, 'bot') = 'bot' THEN 1 ELSE 0 END) as bot_groups,
    SUM(CASE WHEN COALESCE(source, 'bot') = 'user_account' THEN 1 ELSE 0 END) as user_groups,
    SUM(CASE WHEN COALESCE(source, 'bot') = 'both' THEN 1 ELSE 0 END) as both_groups
FROM groups WHERE is_active = 1");
$counts = $stmt->fetch();
$total_groups = $counts['group_count'] ?? 0;
$total_channels = $counts['channel_count'] ?? 0;
$bot_groups = $counts['bot_groups'] ?? 0;
$user_groups = $counts['user_groups'] ?? 0;
$both_groups = $counts['both_groups'] ?? 0;

$stmt = $db->query("SELECT COUNT(*) as count FROM group_members gm 
    INNER JOIN groups g ON g.id = gm.group_id 
    WHERE gm.status = 'member' AND g.is_active = 1 AND g.is_deleted = 0");
$total_members = $stmt->fetch()['count'] ?? 0;

$stmt = $db->query("SELECT COUNT(*) as count FROM message_logs ml 
    INNER JOIN groups g ON g.id = ml.group_id 
    WHERE DATE(ml.created_at) = CURDATE() AND g.is_active = 1 AND g.is_deleted = 0");
$today_messages = $stmt->fetch()['count'] ?? 0;

$stmt = $db->query("SELECT COUNT(*) as count FROM violation_logs vl 
    INNER JOIN groups g ON g.id = vl.group_id 
    WHERE DATE(vl.created_at) = CURDATE() AND g.is_active = 1 AND g.is_deleted = 0");
$today_violations = $stmt->fetch()['count'] ?? 0;

// 获取最近的日志
$stmt = $db->query("
    SELECT 
        sl.*,
        DATE_FORMAT(sl.created_at, '%Y-%m-%d %H:%i:%s') as log_time,
        sl.context as context_data
    FROM system_logs sl 
    ORDER BY sl.created_at DESC 
    LIMIT 10
");
$recent_logs = $stmt->fetchAll();

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>仪表板 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>📊 仪表板</h1>
                <div class="user-info">
                    <span>欢迎, <?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon primary">👥</div>
                    <div class="stat-info">
                        <h3><?php echo ($total_groups + $total_channels); ?></h3>
                        <p>活跃群组/频道</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon success">👤</div>
                    <div class="stat-info">
                        <h3><?php echo $total_members; ?></h3>
                        <p>总成员数</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon warning">💬</div>
                    <div class="stat-info">
                        <h3><?php echo $today_messages; ?></h3>
                        <p>今日消息</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon danger">⚠️</div>
                    <div class="stat-info">
                        <h3><?php echo $today_violations; ?></h3>
                        <p>今日违规</p>
                    </div>
                </div>
            </div>
            
            <!-- 群组来源统计 -->
            <div class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <h2>📊 群组来源统计</h2>
                </div>
                <div class="card-body">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon primary">🤖</div>
                            <div class="stat-info">
                                <h3><?php echo $bot_groups; ?></h3>
                                <p>机器人群组</p>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon info">👤</div>
                            <div class="stat-info">
                                <h3><?php echo $user_groups; ?></h3>
                                <p>真人账号群组</p>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon success">🤖👤</div>
                            <div class="stat-info">
                                <h3><?php echo $both_groups; ?></h3>
                                <p>两者都在</p>
                            </div>
                        </div>
                    </div>
                    <small style="color: #777; display: block; margin-top: 10px;">
                        💡 提示：点击"群组管理"页面的"🔄 同步真人群组"按钮，可以同步真人账号的群组列表
                    </small>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>📋 系统日志</h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>时间</th>
                                    <th>级别</th>
                                    <th>消息</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_logs)): ?>
                                    <tr>
                                        <td colspan="3" class="empty-state">暂无日志</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_logs as $log): ?>
                                        <tr>
                                            <td><?php echo escape($log['log_time']); ?></td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    echo $log['level'] == 'error' ? 'danger' : 
                                                        ($log['level'] == 'warning' ? 'warning' : 'success'); 
                                                ?>">
                                                    <?php echo escape($log['level']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                echo escape($log['message']);
                                                if ($log['context']) {
                                                    $context = json_decode($log['context'], true);
                                                    if ($context) {
                                                        echo '<br><small style="color: #666;">';
                                                        if (isset($context['chat_id'])) {
                                                            echo '群组ID: ' . escape($context['chat_id']) . '<br>';
                                                        }
                                                        if (isset($context['chat_type'])) {
                                                            echo '类型: ' . escape($context['chat_type']) . '<br>';
                                                        }
                                                        if (isset($context['text'])) {
                                                            echo '消息: ' . escape($context['text']) . '<br>';
                                                        }
                                                        if (isset($context['from'])) {
                                                            $from = is_array($context['from']) ? json_encode($context['from'], JSON_UNESCAPED_UNICODE) : $context['from'];
                                                            echo '发送者: ' . escape($from);
                                                        }
                                                        echo '</small>';
                                                    }
                                                }
                                                ?>
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
</body>
</html>

