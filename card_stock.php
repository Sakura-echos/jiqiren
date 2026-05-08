<?php
/**
 * 卡密库存管理页面
 */
require_once 'config.php';
checkLogin();

$page_title = '卡密库存管理';
$db = getDB();

// 获取所有商品
$products_stmt = $db->query("SELECT id, name FROM products ORDER BY id DESC");
$products = $products_stmt->fetchAll();

// 获取筛选条件
$filter_product_id = $_GET['product_id'] ?? '';
$filter_status = $_GET['status'] ?? '';

// 构建查询
$where = [];
$params = [];

if ($filter_product_id) {
    $where[] = "cs.product_id = ?";
    $params[] = $filter_product_id;
}

if ($filter_status) {
    $where[] = "cs.status = ?";
    $params[] = $filter_status;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT cs.*, p.name as product_name, o.order_no
    FROM card_stock cs
    LEFT JOIN products p ON cs.product_id = p.id
    LEFT JOIN orders o ON cs.order_id = o.id
    $where_sql
    ORDER BY cs.id DESC
    LIMIT 1000
");
$stmt->execute($params);
$cards = $stmt->fetchAll();
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
            <h1>📦 <?php echo $page_title; ?></h1>
            <div>
                <button class="btn btn-primary" onclick="showAddModal()">
                    <span>➕</span> 添加卡密
                </button>
                <button class="btn btn-success" onclick="showBatchAddModal()">
                    <span>📝</span> 批量导入
                </button>
            </div>
        </div>

        <!-- 筛选条件 -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-body">
                <form method="GET" style="display: flex; gap: 15px; align-items: flex-end;">
                    <div class="form-group" style="margin: 0; flex: 1;">
                        <label>商品</label>
                        <select name="product_id" class="form-control">
                            <option value="">全部商品</option>
                            <?php foreach ($products as $product): ?>
                            <option value="<?php echo $product['id']; ?>" <?php echo $filter_product_id == $product['id'] ? 'selected' : ''; ?>>
                                <?php echo escape($product['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <label>状态</label>
                        <select name="status" class="form-control">
                            <option value="">全部状态</option>
                            <option value="available" <?php echo $filter_status == 'available' ? 'selected' : ''; ?>>可用</option>
                            <option value="sold" <?php echo $filter_status == 'sold' ? 'selected' : ''; ?>>已售</option>
                            <option value="reserved" <?php echo $filter_status == 'reserved' ? 'selected' : ''; ?>>保留</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-info">筛选</button>
                    <button type="button" class="btn btn-secondary" onclick="location.href='card_stock.php'">重置</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>商品名称</th>
                            <th>卡密内容</th>
                            <th>状态</th>
                            <th>订单号</th>
                            <th>售出时间</th>
                            <th>添加时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cards)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px;">
                                暂无卡密库存
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($cards as $card): ?>
                        <tr>
                            <td><?php echo $card['id']; ?></td>
                            <td><?php echo escape($card['product_name']); ?></td>
                            <td>
                                <code style="font-size: 12px;">
                                    <?php 
                                    $content = $card['card_content'];
                                    echo escape(strlen($content) > 50 ? substr($content, 0, 50) . '...' : $content); 
                                    ?>
                                </code>
                            </td>
                            <td>
                                <?php if ($card['status'] == 'available'): ?>
                                <span class="badge badge-success">可用</span>
                                <?php elseif ($card['status'] == 'sold'): ?>
                                <span class="badge badge-secondary">已售</span>
                                <?php else: ?>
                                <span class="badge badge-warning">保留</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $card['order_no'] ? escape($card['order_no']) : '-'; ?></td>
                            <td><?php echo $card['sold_at'] ? date('Y-m-d H:i', strtotime($card['sold_at'])) : '-'; ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($card['created_at'])); ?></td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="viewCard(<?php echo htmlspecialchars(json_encode($card)); ?>)">查看</button>
                                <?php if ($card['status'] == 'available'): ?>
                                <button class="btn btn-sm btn-danger" onclick="deleteCard(<?php echo $card['id']; ?>)">删除</button>
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

    <!-- 添加卡密弹窗 -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>添加卡密</h2>
                <span class="close" onclick="closeAddModal()">&times;</span>
            </div>
            <form id="addForm">
                <div class="form-group">
                    <label>选择商品 *</label>
                    <select id="add_product_id" name="product_id" class="form-control" required>
                        <option value="">请选择商品</option>
                        <?php foreach ($products as $product): ?>
                        <option value="<?php echo $product['id']; ?>"><?php echo escape($product['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>卡密内容 *</label>
                    <textarea id="add_card_content" name="card_content" class="form-control" rows="4" required placeholder="输入卡密内容"></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeAddModal()">取消</button>
                    <button type="submit" class="btn btn-primary">添加</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 批量导入弹窗 -->
    <div id="batchModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>批量导入卡密</h2>
                <span class="close" onclick="closeBatchModal()">&times;</span>
            </div>
            <form id="batchForm">
                <div class="form-group">
                    <label>选择商品 *</label>
                    <select id="batch_product_id" name="product_id" class="form-control" required>
                        <option value="">请选择商品</option>
                        <?php foreach ($products as $product): ?>
                        <option value="<?php echo $product['id']; ?>"><?php echo escape($product['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>卡密列表 *</label>
                    <textarea id="batch_cards" name="cards" class="form-control" rows="10" required placeholder="每行一个卡密"></textarea>
                    <small>每行输入一个卡密，支持批量粘贴</small>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeBatchModal()">取消</button>
                    <button type="submit" class="btn btn-primary">导入</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 查看卡密弹窗 -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>查看卡密详情</h2>
                <span class="close" onclick="closeViewModal()">&times;</span>
            </div>
            <div id="viewContent"></div>
        </div>
    </div>

    <script>
        function showAddModal() {
            document.getElementById('addModal').style.display = 'block';
        }

        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
        }

        function showBatchAddModal() {
            document.getElementById('batchModal').style.display = 'block';
        }

        function closeBatchModal() {
            document.getElementById('batchModal').style.display = 'none';
        }

        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        function viewCard(card) {
            const content = `
                <div style="padding: 20px;">
                    <p><strong>商品：</strong>${card.product_name}</p>
                    <p><strong>卡密内容：</strong></p>
                    <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; white-space: pre-wrap; word-break: break-all;">${card.card_content}</pre>
                    <p><strong>状态：</strong>${card.status}</p>
                    ${card.order_no ? '<p><strong>订单号：</strong>' + card.order_no + '</p>' : ''}
                    <p><strong>添加时间：</strong>${card.created_at}</p>
                </div>
            `;
            document.getElementById('viewContent').innerHTML = content;
            document.getElementById('viewModal').style.display = 'block';
        }

        document.getElementById('addForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('api/card_stock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    product_id: parseInt(formData.get('product_id')),
                    card_content: formData.get('card_content')
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('添加成功');
                    location.reload();
                } else {
                    alert(data.message || '添加失败');
                }
            });
        });

        document.getElementById('batchForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const cards = formData.get('cards').split('\n').filter(c => c.trim());
            
            if (cards.length === 0) {
                alert('请输入卡密');
                return;
            }
            
            fetch('api/card_stock.php?action=batch', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    product_id: parseInt(formData.get('product_id')),
                    cards: cards
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`成功导入 ${data.count} 个卡密`);
                    location.reload();
                } else {
                    alert(data.message || '导入失败');
                }
            });
        });

        function deleteCard(id) {
            if (!confirm('确定要删除这个卡密吗？')) return;
            
            fetch('api/card_stock.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('删除成功');
                    location.reload();
                } else {
                    alert(data.message || '删除失败');
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

