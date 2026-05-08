<?php
/**
 * 用户详情页面
 */
require_once 'config.php';
checkLogin();

$page_title = '用户详情';
$db = getDB();

$user_id = $_GET['id'] ?? 0;

if (!$user_id) {
    header('Location: card_users.php');
    exit;
}

// 获取用户信息
$stmt = $db->prepare("SELECT * FROM card_users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: card_users.php');
    exit;
}

// 获取用户订单
$stmt = $db->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 50");
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll();

// 获取余额变动记录
$stmt = $db->prepare("SELECT * FROM balance_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 50");
$stmt->execute([$user_id]);
$transactions = $stmt->fetchAll();
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
            <h1>👤 用户详情</h1>
            <button class="btn btn-secondary" onclick="history.back()">返回</button>
        </div>

        <!-- 用户基本信息 -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-body">
                <h3>📋 基本信息</h3>
                <table class="info-table" style="width: 100%; margin-top: 15px;">
                    <tr>
                        <td style="width: 150px; font-weight: bold;">用户ID：</td>
                        <td><?php echo $user['id']; ?></td>
                        <td style="width: 150px; font-weight: bold;">Telegram ID：</td>
                        <td><code><?php echo $user['telegram_id']; ?></code></td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">用户名：</td>
                        <td>
                            <?php 
                            if ($user['username']) {
                                echo '<span style="color: #2196f3;">@' . escape($user['username']) . '</span>';
                            } else {
                                echo '<span style="color: #999;">未设置</span>';
                            }
                            ?>
                        </td>
                        <td style="font-weight: bold;">姓名：</td>
                        <td>
                            <?php 
                            $full_name = trim($user['first_name'] . ' ' . ($user['last_name'] ?? ''));
                            if ($full_name && $full_name != 'User') {
                                echo escape($full_name);
                            } else {
                                echo '<span style="color: #999;">用户 ' . $user['telegram_id'] . '</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">当前余额：</td>
                        <td><strong style="color: #4caf50; font-size: 18px;">$<?php echo number_format($user['balance'] ?? 0, 2); ?></strong></td>
                        <td style="font-weight: bold;">累计消费：</td>
                        <td><strong style="color: #2196f3;">$<?php echo number_format($user['total_spent'] ?? 0, 2); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">订单数量：</td>
                        <td><?php echo $user['total_orders']; ?></td>
                        <td style="font-weight: bold;">语言：</td>
                        <td><?php echo $user['language'] ?? 'zh'; ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">账户状态：</td>
                        <td>
                            <?php if ($user['is_blocked']): ?>
                            <span class="badge badge-danger">已封禁</span>
                            <?php else: ?>
                            <span class="badge badge-success">正常</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight: bold;">最后活跃：</td>
                        <td><?php echo $user['last_active'] ? date('Y-m-d H:i', strtotime($user['last_active'])) : '-'; ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">注册时间：</td>
                        <td><?php echo date('Y-m-d H:i', strtotime($user['created_at'])); ?></td>
                        <td style="font-weight: bold;">更新时间：</td>
                        <td><?php echo date('Y-m-d H:i', strtotime($user['updated_at'])); ?></td>
                    </tr>
                </table>
                
                <div style="margin-top: 20px;">
                    <button class="btn btn-warning" onclick="adjustBalance(<?php echo $user['id']; ?>, '<?php echo escape($user['first_name']); ?>')">调整余额</button>
                    <?php if ($user['is_blocked']): ?>
                    <button class="btn btn-success" onclick="toggleBlock(<?php echo $user['id']; ?>, 0)">解封用户</button>
                    <?php else: ?>
                    <button class="btn btn-danger" onclick="toggleBlock(<?php echo $user['id']; ?>, 1)">封禁用户</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 余额变动记录 -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-body">
                <h3>💰 余额变动记录</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>类型</th>
                            <th>金额</th>
                            <th>变动前</th>
                            <th>变动后</th>
                            <th>描述</th>
                            <th>时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px;">暂无记录</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($transactions as $trans): ?>
                        <tr>
                            <td><?php echo $trans['id']; ?></td>
                            <td>
                                <?php
                                $type_badge = [
                                    'recharge' => '<span class="badge badge-success">充值</span>',
                                    'purchase' => '<span class="badge badge-info">购买</span>',
                                    'refund' => '<span class="badge badge-warning">退款</span>',
                                    'admin_adjust' => '<span class="badge badge-secondary">管理员调整</span>'
                                ];
                                echo $type_badge[$trans['type']] ?? $trans['type'];
                                ?>
                            </td>
                            <td>
                                <strong style="color: <?php echo $trans['amount'] >= 0 ? '#4caf50' : '#f44336'; ?>;">
                                    <?php echo $trans['amount'] >= 0 ? '+' : ''; ?>$<?php echo number_format($trans['amount'], 2); ?>
                                </strong>
                            </td>
                            <td>$<?php echo number_format($trans['balance_before'], 2); ?></td>
                            <td>$<?php echo number_format($trans['balance_after'], 2); ?></td>
                            <td><?php echo escape($trans['description'] ?? '-'); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($trans['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 订单记录 -->
        <div class="card">
            <div class="card-body">
                <h3>📧 订单记录</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>订单号</th>
                            <th>商品</th>
                            <th>数量</th>
                            <th>金额</th>
                            <th>状态</th>
                            <th>下单时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px;">暂无订单</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><code><?php echo escape($order['order_no']); ?></code></td>
                            <td><?php echo escape($order['product_name']); ?></td>
                            <td><?php echo $order['quantity']; ?></td>
                            <td><strong>$<?php echo number_format($order['total_amount'] ?? 0, 2); ?></strong></td>
                            <td>
                                <?php
                                $status_badge = [
                                    'pending' => '<span class="badge badge-warning">待支付</span>',
                                    'paid' => '<span class="badge badge-info">已支付</span>',
                                    'completed' => '<span class="badge badge-success">已完成</span>',
                                    'cancelled' => '<span class="badge badge-secondary">已取消</span>',
                                    'refunded' => '<span class="badge badge-danger">已退款</span>'
                                ];
                                echo $status_badge[$order['status']] ?? $order['status'];
                                ?>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($order['created_at'])); ?></td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="viewOrder(<?php echo htmlspecialchars(json_encode($order)); ?>)">查看</button>
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

    <!-- 查看订单详情弹窗 -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>订单详情</h2>
                <span class="close" onclick="closeViewModal()">&times;</span>
            </div>
            <div id="viewContent"></div>
        </div>
    </div>

    <script>
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

        function viewOrder(order) {
            let cardsHtml = '';
            if (order.cards_delivered) {
                try {
                    const cards = JSON.parse(order.cards_delivered);
                    cardsHtml = '<p><strong>已发放卡密：</strong></p><pre style="background: #f5f5f5; padding: 10px; border-radius: 4px;">' + cards.join('\n') + '</pre>';
                } catch (e) {
                    cardsHtml = '<p><strong>已发放卡密：</strong></p><pre style="background: #f5f5f5; padding: 10px; border-radius: 4px;">' + order.cards_delivered + '</pre>';
                }
            }
            
            const content = `
                <div style="padding: 20px;">
                    <p><strong>订单号：</strong>${order.order_no}</p>
                    <p><strong>商品名称：</strong>${order.product_name}</p>
                    <p><strong>购买数量：</strong>${order.quantity}</p>
                    <p><strong>单价：</strong>$${parseFloat(order.unit_price).toFixed(2)}</p>
                    <p><strong>总金额：</strong>$${parseFloat(order.total_amount).toFixed(2)}</p>
                    <p><strong>支付方式：</strong>${order.payment_method == 'balance' ? '余额' : '其他'}</p>
                    <p><strong>订单状态：</strong>${order.status}</p>
                    ${cardsHtml}
                    ${order.remark ? '<p><strong>备注：</strong>' + order.remark + '</p>' : ''}
                    <p><strong>下单时间：</strong>${order.created_at}</p>
                    ${order.paid_at ? '<p><strong>支付时间：</strong>' + order.paid_at + '</p>' : ''}
                    ${order.completed_at ? '<p><strong>完成时间：</strong>' + order.completed_at + '</p>' : ''}
                </div>
            `;
            document.getElementById('viewContent').innerHTML = content;
            document.getElementById('viewModal').style.display = 'block';
        }

        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>

