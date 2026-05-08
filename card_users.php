<?php
/**
 * 用户管理页面
 */
require_once 'config.php';
checkLogin();

$page_title = '用户管理';
$db = getDB();

// 获取所有用户
$stmt = $db->query("
    SELECT * FROM card_users 
    ORDER BY created_at DESC 
    LIMIT 500
");
$users = $stmt->fetchAll();

// 统计
$stats_stmt = $db->query("
    SELECT 
        COUNT(*) as total_users,
        SUM(balance) as total_balance,
        SUM(total_spent) as total_spent,
        SUM(total_orders) as total_orders
    FROM card_users
");
$stats = $stats_stmt->fetch();
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Telegram Bot</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h1>👤 <?php echo $page_title; ?></h1>
        </div>

        <!-- 统计卡片 -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
            <div class="card">
                <div class="card-body">
                    <h3>总用户数</h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 10px 0;"><?php echo $stats['total_users']; ?></p>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <h3>总余额</h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 10px 0; color: #4caf50;">$<?php echo number_format($stats['total_balance'] ?? 0, 2); ?></p>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <h3>累计消费</h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 10px 0; color: #2196f3;">$<?php echo number_format($stats['total_spent'] ?? 0, 2); ?></p>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <h3>总订单数</h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 10px 0; color: #ff9800;"><?php echo $stats['total_orders']; ?></p>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Telegram ID</th>
                            <th>用户名</th>
                            <th>姓名</th>
                            <th>余额</th>
                            <th>累计消费</th>
                            <th>订单数</th>
                            <th>状态</th>
                            <th>注册时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 40px;">
                                暂无用户
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo $user['id']; ?></td>
                            <td><code><?php echo $user['telegram_id']; ?></code></td>
                            <td><?php echo $user['username'] ? '@' . escape($user['username']) : '-'; ?></td>
                            <td><?php echo escape($user['first_name'] . ' ' . ($user['last_name'] ?? '')); ?></td>
                            <td><strong>$<?php echo number_format($user['balance'] ?? 0, 2); ?></strong></td>
                            <td>$<?php echo number_format($user['total_spent'] ?? 0, 2); ?></td>
                            <td><?php echo $user['total_orders']; ?></td>
                            <td>
                                <?php if ($user['is_blocked']): ?>
                                <span class="badge badge-danger">已封禁</span>
                                <?php else: ?>
                                <span class="badge badge-success">正常</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($user['created_at'])); ?></td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="viewUser(<?php echo $user['id']; ?>)">查看</button>
                                <button class="btn btn-sm btn-warning" onclick="adjustBalance(<?php echo $user['id']; ?>, '<?php echo escape($user['first_name']); ?>')">调整余额</button>
                                <?php if ($user['is_blocked']): ?>
                                <button class="btn btn-sm btn-success" onclick="toggleBlock(<?php echo $user['id']; ?>, 0)">解封</button>
                                <?php else: ?>
                                <button class="btn btn-sm btn-danger" onclick="toggleBlock(<?php echo $user['id']; ?>, 1)">封禁</button>
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

    <!-- 调整余额弹窗 -->
    <div id="adjustModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>调整用户余额</h2>
                <span class="close" onclick="closeAdjustModal()">&times;</span>
            </div>
            <form id="adjustForm">
                <input type="hidden" id="adjust_user_id">
                
                <div class="form-group">
                    <label>用户</label>
                    <input type="text" id="adjust_user_name" class="form-control" readonly>
                </div>

                <div class="form-group">
                    <label>调整类型</label>
                    <select id="adjust_type" class="form-control">
                        <option value="add">增加余额</option>
                        <option value="subtract">减少余额</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>金额（USD）</label>
                    <input type="number" id="adjust_amount" class="form-control" step="0.01" min="0.01" required>
                </div>

                <div class="form-group">
                    <label>备注</label>
                    <input type="text" id="adjust_remark" class="form-control" placeholder="调整原因">
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeAdjustModal()">取消</button>
                    <button type="submit" class="btn btn-primary">确认调整</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function viewUser(userId) {
            location.href = 'user_detail.php?id=' + userId;
        }

        function adjustBalance(userId, userName) {
            document.getElementById('adjust_user_id').value = userId;
            document.getElementById('adjust_user_name').value = userName;
            document.getElementById('adjustModal').style.display = 'block';
        }

        function closeAdjustModal() {
            document.getElementById('adjustModal').style.display = 'none';
            document.getElementById('adjustForm').reset();
        }

        document.getElementById('adjustForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const userId = document.getElementById('adjust_user_id').value;
            const type = document.getElementById('adjust_type').value;
            const amount = parseFloat(document.getElementById('adjust_amount').value);
            const remark = document.getElementById('adjust_remark').value;
            
            fetch('api/card_users.php?action=adjust_balance', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: parseInt(userId),
                    type: type,
                    amount: amount,
                    remark: remark
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('余额调整成功');
                    location.reload();
                } else {
                    alert(data.message || '操作失败');
                }
            });
        });

        function toggleBlock(userId, blocked) {
            const action = blocked ? '封禁' : '解封';
            if (!confirm(`确定要${action}这个用户吗？`)) return;
            
            fetch('api/card_users.php?action=toggle_block', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: userId,
                    is_blocked: blocked
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`用户已${action}`);
                    location.reload();
                } else {
                    alert(data.message || '操作失败');
                }
            });
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>

