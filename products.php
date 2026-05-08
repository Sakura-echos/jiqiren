<?php
/**
 * 商品管理页面
 */
require_once 'config.php';
checkLogin();

$page_title = '商品管理';
$db = getDB();

// 获取所有分类
$categories_stmt = $db->query("SELECT * FROM product_categories WHERE is_active = 1 ORDER BY sort_order ASC");
$categories = $categories_stmt->fetchAll();

// 获取所有商品
$stmt = $db->query("
    SELECT p.*, pc.name as category_name, pc.icon as category_icon,
    (SELECT COUNT(*) FROM card_stock WHERE product_id = p.id AND status = 'available') as actual_stock
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    ORDER BY p.sort_order ASC, p.id DESC
");
$products = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Telegram Bot</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .product-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 4px;
        }
        .stock-warning {
            color: #ff9800;
        }
        .stock-danger {
            color: #f44336;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h1>📋 <?php echo $page_title; ?></h1>
            <div>
                <button class="btn btn-success" onclick="location.href='card_stock.php'">
                    <span>📦</span> 卡密库存管理
                </button>
                <button class="btn btn-primary" onclick="showAddModal()">
                    <span>➕</span> 添加商品
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>图片</th>
                            <th>商品名称</th>
                            <th>分类</th>
                            <th>价格(USD)</th>
                            <th>库存</th>
                            <th>销量</th>
                            <th>排序</th>
                            <th>状态</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="11" style="text-align: center; padding: 40px;">
                                暂无商品，点击右上角添加商品
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?php echo $product['id']; ?></td>
                            <td>
                                <?php if ($product['image_url']): ?>
                                <img src="<?php echo escape($product['image_url']); ?>" class="product-image" alt="">
                                <?php else: ?>
                                <div class="product-image" style="background: #eee; display: flex; align-items: center; justify-content: center;">📦</div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo escape($product['name']); ?></strong></td>
                            <td>
                                <span style="font-size: 18px;"><?php echo $product['category_icon']; ?></span>
                                <?php echo escape($product['category_name']); ?>
                            </td>
                            <td><strong>$<?php echo number_format($product['price'] ?? 0, 2); ?></strong></td>
                            <td>
                                <?php 
                                $stock = $product['actual_stock'];
                                $stockClass = '';
                                if ($stock == 0) {
                                    $stockClass = 'stock-danger';
                                } elseif ($stock < 10) {
                                    $stockClass = 'stock-warning';
                                }
                                ?>
                                <span class="<?php echo $stockClass; ?>">
                                    <strong><?php echo $stock; ?></strong>
                                </span>
                            </td>
                            <td><?php echo $product['sales_count']; ?></td>
                            <td><?php echo $product['sort_order']; ?></td>
                            <td>
                                <?php if ($product['is_active']): ?>
                                <span class="badge badge-success">上架</span>
                                <?php else: ?>
                                <span class="badge badge-secondary">下架</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($product['created_at'])); ?></td>
                            <td>
                                <button class="btn btn-sm btn-success" onclick="manageStock(<?php echo $product['id']; ?>)">库存</button>
                                <button class="btn btn-sm btn-info" onclick="editProduct(<?php echo htmlspecialchars(json_encode($product)); ?>)">编辑</button>
                                <button class="btn btn-sm btn-danger" onclick="deleteProduct(<?php echo $product['id']; ?>)">删除</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 添加/编辑商品弹窗 -->
    <div id="productModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h2 id="modalTitle">添加商品</h2>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <form id="productForm">
                <input type="hidden" id="product_id" name="id">
                
                <div class="form-group">
                    <label>商品分类 *</label>
                    <select id="category_id" name="category_id" class="form-control" required>
                        <option value="">请选择分类</option>
                        <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['id']; ?>">
                            <?php echo $category['icon'] . ' ' . escape($category['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>商品名称 *</label>
                    <input type="text" id="name" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>商品描述</label>
                    <textarea id="description" name="description" class="form-control" rows="4" placeholder="商品详细描述，支持换行"></textarea>
                </div>

                <div class="form-group">
                    <label>价格（USD） *</label>
                    <input type="number" id="price" name="price" class="form-control" step="0.01" min="0" required>
                </div>

                <div class="form-group">
                    <label>商品图片URL</label>
                    <input type="text" id="image_url" name="image_url" class="form-control" placeholder="https://example.com/image.jpg">
                    <small>或上传图片：</small>
                    <input type="file" id="image_file" accept="image/*" class="form-control">
                </div>

                <div class="form-group">
                    <label>卡密类型</label>
                    <select id="card_type" name="card_type" class="form-control">
                        <option value="text">文本卡密</option>
                        <option value="file">文件卡密</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>排序</label>
                    <input type="number" id="sort_order" name="sort_order" class="form-control" value="0">
                    <small>数字越小越靠前</small>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="is_active" name="is_active" value="1" checked>
                        上架该商品
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
            document.getElementById('modalTitle').textContent = '添加商品';
            document.getElementById('productForm').reset();
            document.getElementById('product_id').value = '';
            document.getElementById('productModal').style.display = 'block';
        }

        function editProduct(product) {
            document.getElementById('modalTitle').textContent = '编辑商品';
            document.getElementById('product_id').value = product.id;
            document.getElementById('category_id').value = product.category_id;
            document.getElementById('name').value = product.name;
            document.getElementById('description').value = product.description || '';
            document.getElementById('price').value = product.price;
            document.getElementById('image_url').value = product.image_url || '';
            document.getElementById('card_type').value = product.card_type;
            document.getElementById('sort_order').value = product.sort_order;
            document.getElementById('is_active').checked = product.is_active == 1;
            document.getElementById('productModal').style.display = 'block';
        }

        function manageStock(productId) {
            location.href = 'card_stock.php?product_id=' + productId;
        }

        function closeModal() {
            document.getElementById('productModal').style.display = 'none';
        }

        document.getElementById('productForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const id = document.getElementById('product_id').value;
            
            // 处理图片上传
            let imageUrl = formData.get('image_url');
            const imageFile = document.getElementById('image_file').files[0];
            
            if (imageFile) {
                const uploadFormData = new FormData();
                uploadFormData.append('image', imageFile);
                uploadFormData.append('type', 'product');
                
                try {
                    const uploadResponse = await fetch('api/upload.php', {
                        method: 'POST',
                        body: uploadFormData
                    });
                    const uploadResult = await uploadResponse.json();
                    if (uploadResult.success) {
                        imageUrl = uploadResult.url;
                    }
                } catch (error) {
                    console.error('Image upload error:', error);
                }
            }
            
            fetch('api/products.php', {
                method: id ? 'PUT' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    id: id || undefined,
                    category_id: parseInt(formData.get('category_id')),
                    name: formData.get('name'),
                    description: formData.get('description'),
                    price: parseFloat(formData.get('price')),
                    image_url: imageUrl,
                    card_type: formData.get('card_type'),
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

        function deleteProduct(id) {
            if (!confirm('确定要删除这个商品吗？删除后该商品的所有库存也会被删除！')) {
                return;
            }
            
            fetch('api/products.php', {
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

        window.onclick = function(event) {
            const modal = document.getElementById('productModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>

