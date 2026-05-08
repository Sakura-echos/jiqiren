<?php
/**
 * 商品分类管理页面
 */
require_once 'config.php';
checkLogin();

$page_title = '商品分类管理';
$db = getDB();

// 获取所有分类
$stmt = $db->query("SELECT * FROM product_categories ORDER BY sort_order ASC, id ASC");
$categories = $stmt->fetchAll();
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
            <h1>📂 <?php echo $page_title; ?></h1>
            <button class="btn btn-primary" onclick="showAddModal()">
                <span>➕</span> 添加分类
            </button>
        </div>

        <div class="card">
            <div class="card-body">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>图标</th>
                            <th>分类名称</th>
                            <th>描述</th>
                            <th>排序</th>
                            <th>商品数量</th>
                            <th>状态</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px;">
                                暂无数据，点击右上角添加分类
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($categories as $category): ?>
                        <?php
                        // 获取分类下的商品数量
                        $stmt = $db->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = ?");
                        $stmt->execute([$category['id']]);
                        $product_count = $stmt->fetch()['count'];
                        ?>
                        <tr>
                            <td><?php echo $category['id']; ?></td>
                            <td style="font-size: 24px;"><?php echo escape($category['icon']); ?></td>
                            <td><strong><?php echo escape($category['name']); ?></strong></td>
                            <td><?php echo escape($category['description'] ?? '-'); ?></td>
                            <td><?php echo $category['sort_order']; ?></td>
                            <td><span class="badge badge-info"><?php echo $product_count; ?></span></td>
                            <td>
                                <?php if ($category['is_active']): ?>
                                <span class="badge badge-success">启用</span>
                                <?php else: ?>
                                <span class="badge badge-secondary">禁用</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($category['created_at'])); ?></td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="editCategory(<?php echo htmlspecialchars(json_encode($category)); ?>)">编辑</button>
                                <button class="btn btn-sm btn-danger" onclick="deleteCategory(<?php echo $category['id']; ?>)">删除</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 添加/编辑分类弹窗 -->
    <div id="categoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">添加分类</h2>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <form id="categoryForm">
                <input type="hidden" id="category_id" name="id">
                
                <div class="form-group">
                    <label>分类名称 *</label>
                    <input type="text" id="name" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>分类描述</label>
                    <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label>图标 *</label>
                    <input type="text" id="icon" name="icon" class="form-control" value="📦" placeholder="输入emoji图标">
                    <small>常用图标：📦 💳 🌐 🎮 🔑 📱 💻 🎁</small>
                </div>

                <div class="form-group">
                    <label>排序</label>
                    <input type="number" id="sort_order" name="sort_order" class="form-control" value="0">
                    <small>数字越小越靠前</small>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="is_active" name="is_active" value="1" checked>
                        启用该分类
                    </label>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showAddModal() {
            document.getElementById('modalTitle').textContent = '添加分类';
            document.getElementById('categoryForm').reset();
            document.getElementById('category_id').value = '';
            document.getElementById('categoryModal').style.display = 'block';
        }

        function editCategory(category) {
            document.getElementById('modalTitle').textContent = '编辑分类';
            document.getElementById('category_id').value = category.id;
            document.getElementById('name').value = category.name;
            document.getElementById('description').value = category.description || '';
            document.getElementById('icon').value = category.icon;
            document.getElementById('sort_order').value = category.sort_order;
            document.getElementById('is_active').checked = category.is_active == 1;
            document.getElementById('categoryModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('categoryModal').style.display = 'none';
        }

        document.getElementById('categoryForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const id = document.getElementById('category_id').value;
            
            fetch('api/product_categories.php', {
                method: id ? 'PUT' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    id: id || undefined,
                    name: formData.get('name'),
                    description: formData.get('description'),
                    icon: formData.get('icon'),
                    sort_order: parseInt(formData.get('sort_order')),
                    is_active: formData.get('is_active') ? 1 : 0
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message || '操作成功');
                    location.reload();
                } else {
                    alert(data.message || '操作失败');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('操作失败，请重试');
            });
        });

        function deleteCategory(id) {
            if (!confirm('确定要删除这个分类吗？删除后该分类下的所有商品也会被删除！')) {
                return;
            }
            
            fetch('api/product_categories.php', {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ id: id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message || '删除成功');
                    location.reload();
                } else {
                    alert(data.message || '删除失败');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('删除失败，请重试');
            });
        }

        // 点击模态框外部关闭
        window.onclick = function(event) {
            const modal = document.getElementById('categoryModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>

