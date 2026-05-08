<?php
/**
 * 订单管理页面
 */
require_once 'config.php';
checkLogin();

$page_title = '订单管理';
$db = getDB();

// 获取筛选条件
$filter_status = $_GET['status'] ?? '';
$filter_date = $_GET['date'] ?? '';

// 构建查询
$where = [];
$params = [];

if ($filter_status) {
    $where[] = "o.status = ?";
    $params[] = $filter_status;
}

if ($filter_date) {
    $where[] = "DATE(o.created_at) = ?";
    $params[] = $filter_date;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT o.*, cu.username, cu.first_name
    FROM orders o
    LEFT JOIN card_users cu ON o.user_id = cu.id
    $where_sql
    ORDER BY o.id DESC
    LIMIT 500
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// 统计
$stats_stmt = $db->query("
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
        SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END) as total_revenue
    FROM orders
    WHERE DATE(created_at) = CURDATE()
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
            <h1>📧 <?php echo $page_title; ?></h1>
        </div>

        <!-- 统计卡片 -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
            <div class="card">
                <div class="card-body">
                    <h3>今日订单</h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 10px 0;"><?php echo $stats['total_orders']; ?></p>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <h3>待处理</h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 10px 0; color: #ff9800;"><?php echo $stats['pending_orders']; ?></p>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <h3>已完成</h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 10px 0; color: #4caf50;"><?php echo $stats['completed_orders']; ?></p>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <h3>今日收入</h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 10px 0; color: #2196f3;">$<?php echo number_format($stats['total_revenue'] ?? 0, 2); ?></p>
                </div>
            </div>
        </div>

        <!-- 筛选条件 -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-body">
                <form method="GET" style="display: flex; gap: 15px; align-items: flex-end;">
                    <div class="form-group" style="margin: 0;">
                        <label>订单状态</label>
                        <select name="status" class="form-control">
                            <option value="">全部状态</option>
                            <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>待支付</option>
                            <option value="paid" <?php echo $filter_status == 'paid' ? 'selected' : ''; ?>>已支付</option>
                            <option value="completed" <?php echo $filter_status == 'completed' ? 'selected' : ''; ?>>已完成</option>
                            <option value="cancelled" <?php echo $filter_status == 'cancelled' ? 'selected' : ''; ?>>已取消</option>
                            <option value="refunded" <?php echo $filter_status == 'refunded' ? 'selected' : ''; ?>>已退款</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <label>日期</label>
                        <input type="date" name="date" class="form-control" value="<?php echo $filter_date; ?>">
                    </div>
                    <button type="submit" class="btn btn-info">筛选</button>
                    <button type="button" class="btn btn-secondary" onclick="location.href='orders.php'">重置</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>订单号</th>
                            <th>用户</th>
                            <th>商品</th>
                            <th>数量</th>
                            <th>金额</th>
                            <th>支付方式</th>
                            <th>状态</th>
                            <th>下单时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px;">
                                暂无订单
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><code><?php echo escape($order['order_no']); ?></code></td>
                            <td>
                                <?php echo escape($order['first_name']); ?>
                                <?php if ($order['username']): ?>
                                <br><small>@<?php echo escape($order['username']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo escape($order['product_name']); ?></td>
                            <td><?php echo $order['quantity']; ?></td>
                            <td><strong>$<?php echo number_format($order['total_amount'] ?? 0, 2); ?></strong></td>
                            <td><?php echo $order['payment_method'] == 'balance' ? '余额' : '其他'; ?></td>
                            <td>
                                <?php
                                $badge_class = 'badge-secondary';
                                $status_text = '';
                                switch ($order['status']) {
                                    case 'pending':
                                        $badge_class = 'badge-warning';
                                        $status_text = '待支付';
                                        break;
                                    case 'paid':
                                        $badge_class = 'badge-info';
                                        $status_text = '已支付';
                                        break;
                                    case 'completed':
                                        $badge_class = 'badge-success';
                                        $status_text = '已完成';
                                        break;
                                    case 'cancelled':
                                        $badge_class = 'badge-secondary';
                                        $status_text = '已取消';
                                        break;
                                    case 'refunded':
                                        $badge_class = 'badge-danger';
                                        $status_text = '已退款';
                                        break;
                                }
                                ?>
                                <span class="badge <?php echo $badge_class; ?>"><?php echo $status_text; ?></span>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($order['created_at'])); ?></td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="viewOrder(<?php echo htmlspecialchars(json_encode($order)); ?>)">查看</button>
                                <?php if ($order['status'] == 'pending'): ?>
                                <button class="btn btn-sm btn-danger" onclick="cancelOrder(<?php echo $order['id']; ?>)">取消</button>
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
                    <p><strong>用户ID：</strong>${order.telegram_id}</p>
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

        function cancelOrder(id) {
            if (!confirm('确定要取消这个订单吗？')) return;
            
            fetch('api/orders.php?action=cancel', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('订单已取消');
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

