<?php
require_once 'config.php';
checkLogin();

$db = getDB();
$page_title = 'TRC地址查询';

// 初始化查询记录表
 try {
    $db->exec("CREATE TABLE IF NOT EXISTS `address_query_logs` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `chat_id` bigint(20) NOT NULL COMMENT '群组ID',
        `user_id` bigint(20) DEFAULT NULL COMMENT '查询用户ID',
        `username` varchar(100) DEFAULT NULL COMMENT '用户名',
        `address` varchar(100) NOT NULL COMMENT 'TRC地址',
        `usdt_balance` decimal(20,8) DEFAULT NULL COMMENT 'USDT余额',
        `trx_balance` decimal(20,8) DEFAULT NULL COMMENT 'TRX余额',
        `query_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '查询时间',
        PRIMARY KEY (`id`),
        KEY `idx_address` (`address`),
        KEY `idx_chat_id` (`chat_id`),
        KEY `idx_query_time` (`query_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='TRC地址查询记录'");
} catch (PDOException $e) {
    error_log("Init address_query_logs table error: " . $e->getMessage());
}

// 获取统计数据
try {
    $stmt = $db->query("
        SELECT 
            COUNT(DISTINCT address) as total_addresses,
            COUNT(*) as total_queries,
            COUNT(DISTINCT chat_id) as total_groups
        FROM address_query_logs
    ");
    $stats = $stmt->fetch();
    
    // 获取今日统计
    $stmt = $db->query("
        SELECT 
            COUNT(*) as today_queries,
            COUNT(DISTINCT address) as today_addresses
        FROM address_query_logs
        WHERE DATE(query_time) = CURDATE()
    ");
    $todayStats = $stmt->fetch();
    
    // 获取最近查询记录
    $stmt = $db->query("
        SELECT 
            address,
            COUNT(*) as query_count,
            MAX(query_time) as last_query_time,
            MAX(usdt_balance) as last_usdt_balance,
            MAX(trx_balance) as last_trx_balance
        FROM address_query_logs
        GROUP BY address
        ORDER BY last_query_time DESC
        LIMIT 50
    ");
    $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Get address query data error: " . $e->getMessage());
    $stats = ['total_addresses' => 0, 'total_queries' => 0, 'total_groups' => 0];
    $todayStats = ['today_queries' => 0, 'today_addresses' => 0];
    $addresses = [];
}

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #f8f9fa;
            color: #333;
            padding: 25px;
            border-radius: 8px;
            border-left: 4px solid #6c757d;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }
        .stat-card.primary {
            border-left-color: #007bff;
        }
        .stat-card.success {
            border-left-color: #28a745;
        }
        .stat-card.danger {
            border-left-color: #dc3545;
        }
        .stat-card.info {
            border-left-color: #17a2b8;
        }
        .stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #6c757d;
            font-weight: 500;
        }
        .stat-card .value {
            font-size: 28px;
            font-weight: bold;
            color: #333;
        }
        .address-grid {
            display: grid;
            gap: 15px;
        }
        .address-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .address-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        .address-code {
            font-family: monospace;
            font-size: 16px;
            font-weight: bold;
            color: #333;
            word-break: break-all;
        }
        .address-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }
        .address-stat {
            text-align: center;
        }
        .address-stat .label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }
        .address-stat .value {
            font-size: 18px;
            font-weight: bold;
        }
        .address-stat.income .value {
            color: #38ef7d;
        }
        .address-stat.outcome .value {
            color: #f45c43;
        }
        .address-stat.balance .value {
            color: #4facfe;
        }
        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .modal-content {
            background-color: white;
            margin: 80px auto;
            padding: 0;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            animation: slideDown 0.3s;
        }
        @keyframes slideDown {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: #999;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: all 0.2s;
        }
        .modal-close:hover {
            background-color: #f8f9fa;
            color: #333;
        }
        .modal-body {
            padding: 25px;
            max-height: 60vh;
            overflow-y: auto;
        }
        .modal-footer {
            padding: 15px 25px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }
        .form-group label .required {
            color: #dc3545;
            margin-left: 2px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }
        .form-group .form-help {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }
        .record-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .record-table th,
        .record-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .record-table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        .record-income {
            color: #38ef7d;
            font-weight: bold;
        }
        .record-outcome {
            color: #f45c43;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <header>
                <h1><?php echo $page_title; ?></h1>
                <div class="user-info">
                    <span>欢迎, <?php echo htmlspecialchars($admin_username); ?></span>
                    <a href="index.php?logout=1" class="btn-logout">退出</a>
                </div>
            </header>
            
            <div class="content">
                <!-- 统计卡片 -->
                <div class="stats-grid">
                    <div class="stat-card primary">
                        <h3>查询地址数</h3>
                        <div class="value"><?php echo number_format($stats['total_addresses'] ?? 0); ?></div>
                    </div>
                    <div class="stat-card success">
                        <h3>总查询次数</h3>
                        <div class="value"><?php echo number_format($stats['total_queries'] ?? 0); ?></div>
                    </div>
                    <div class="stat-card info">
                        <h3>今日查询</h3>
                        <div class="value"><?php echo number_format($todayStats['today_queries'] ?? 0); ?></div>
                    </div>
                    <div class="stat-card danger">
                        <h3>使用群组</h3>
                        <div class="value"><?php echo number_format($stats['total_groups'] ?? 0); ?></div>
                    </div>
                </div>

                <div style="background: #e7f3ff; border-left: 4px solid #2196F3; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                    <strong style="color: #1976D2;">💡 使用说明：</strong>
                    <span style="color: #555;">用户在群里发送TRC地址即可自动查询，不需要后台添加。以下是最近查询过的地址记录。</span>
                </div>

                <!-- 地址查询记录 -->
                <div class="address-grid">
                    <?php if (empty($addresses)): ?>
                        <div class="no-data">暂无查询记录</div>
                    <?php else: ?>
                        <?php foreach ($addresses as $addr): ?>
                            <div class="address-card">
                                <div class="address-header">
                                    <div>
                                        <div class="address-code"><?php echo htmlspecialchars($addr['address']); ?></div>
                                        <div style="color: #666; font-size: 14px; margin-top: 5px;">
                                            最后查询：<?php echo date('Y-m-d H:i:s', strtotime($addr['last_query_time'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="address-stats">
                                    <div class="address-stat income">
                                        <div class="label">USDT余额</div>
                                        <div class="value"><?php echo number_format($addr['last_usdt_balance'] ?? 0, 6); ?></div>
                                    </div>
                                    <div class="address-stat balance">
                                        <div class="label">TRX余额</div>
                                        <div class="value"><?php echo number_format($addr['last_trx_balance'] ?? 0, 6); ?></div>
                                    </div>
                                    <div class="address-stat outcome">
                                        <div class="label">查询次数</div>
                                        <div class="value"><?php echo number_format($addr['query_count']); ?></div>
                                    </div>
                                </div>
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-primary" onclick="viewAddressDetails('<?php echo htmlspecialchars($addr['address']); ?>')">查看详情</button>
                                    <button class="btn btn-sm btn-secondary" onclick="copyAddress('<?php echo htmlspecialchars($addr['address']); ?>')">复制地址</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>


    <!-- 查看详情模态框 -->
    <div id="viewDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="detailsTitle">地址详情</h2>
                <button type="button" class="modal-close" onclick="closeModal('viewDetailsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="detailsContent"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('viewDetailsModal')">关闭</button>
            </div>
        </div>
    </div>

    <script>
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // 查看地址详情
        async function viewAddressDetails(address) {
            try {
                const response = await fetch(`api/address_query.php?action=get_query_history&address=${encodeURIComponent(address)}`);
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('detailsTitle').textContent = '查询历史 - ' + address;
                    
                    let html = '<div class="mb-3">';
                    html += '<p><strong>地址：</strong><code>' + address + '</code></p>';
                    html += '<p><strong>总查询次数：</strong>' + result.total_queries + ' 次</p>';
                    html += '</div>';
                    
                    html += '<table class="record-table">';
                    html += '<thead><tr><th>查询时间</th><th>用户</th><th>USDT余额</th><th>TRX余额</th></tr></thead>';
                    html += '<tbody>';
                    
                    if (result.data.length === 0) {
                        html += '<tr><td colspan="4" style="text-align:center;">暂无记录</td></tr>';
                    } else {
                        result.data.forEach(record => {
                            html += '<tr>';
                            html += '<td>' + record.query_time + '</td>';
                            html += '<td>' + (record.username || '匿名') + '</td>';
                            html += '<td>' + (record.usdt_balance ? parseFloat(record.usdt_balance).toFixed(6) : '-') + '</td>';
                            html += '<td>' + (record.trx_balance ? parseFloat(record.trx_balance).toFixed(6) : '-') + '</td>';
                            html += '</tr>';
                        });
                    }
                    
                    html += '</tbody></table>';
                    document.getElementById('detailsContent').innerHTML = html;
                    document.getElementById('viewDetailsModal').style.display = 'block';
                } else {
                    alert('获取详情失败：' + result.message);
                }
            } catch (error) {
                alert('操作失败：' + error.message);
            }
        }

        // 复制地址
        function copyAddress(address) {
            navigator.clipboard.writeText(address).then(() => {
                alert('地址已复制！');
            }).catch(() => {
                alert('复制失败，请手动复制');
            });
        }

        // 点击模态框外部关闭
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
