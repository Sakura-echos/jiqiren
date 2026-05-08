<?php
/**
 * 客服管理页面
 */
session_start();
require_once 'config.php';

// 检查登录
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$db = getDB();

// 获取所有客服联系方式
try {
    $stmt = $db->query("SELECT * FROM customer_service ORDER BY sort_order ASC, id ASC");
    $contacts = $stmt->fetchAll();
} catch (Exception $e) {
    $contacts = [];
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>客服管理 - TG机器人管理系统</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h1>📱 客服管理</h1>
            <p>配置机器人显示的客服联系方式</p>
        </div>
        
        <!-- 使用说明 -->
        <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #2196f3;">
            <strong>💡 使用说明：</strong>
            <ul style="margin: 10px 0 0 20px; line-height: 1.8;">
                <li>用户在机器人中点击"联系客服"按钮时，会显示这里配置的联系方式</li>
                <li>支持多种联系方式：Telegram、WhatsApp、邮箱、电话、微信等</li>
                <li>可以设置多个客服，按排序顺序显示（最多显示5个）</li>
                <li>只有"已启用"的客服会显示给用户</li>
            </ul>
        </div>
        
        <!-- 客服列表 -->
        <div class="card">
            <div class="card-header">
                <h2>客服列表</h2>
                <button class="btn btn-primary" onclick="showAddModal()">+ 添加客服</button>
            </div>
            
            <?php if (empty($contacts)): ?>
                <p style="text-align: center; padding: 40px; color: #999;">
                    暂无客服配置，请点击上方"添加客服"按钮
                </p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>排序</th>
                            <th>类型</th>
                            <th>名称</th>
                            <th>联系方式</th>
                            <th>链接</th>
                            <th>状态</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contacts as $contact): ?>
                            <tr>
                                <td><?php echo $contact['sort_order']; ?></td>
                                <td>
                                    <?php 
                                    $type_icons = [
                                        'telegram' => '✈️ Telegram',
                                        'whatsapp' => '💬 WhatsApp',
                                        'email' => '📧 邮箱',
                                        'phone' => '📞 电话',
                                        'wechat' => '💚 微信',
                                        'other' => '📱 其他'
                                    ];
                                    echo $type_icons[$contact['type']] ?? $contact['type'];
                                    ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars($contact['name']); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($contact['contact']); ?></code></td>
                                <td>
                                    <?php if ($contact['url']): ?>
                                        <a href="<?php echo htmlspecialchars($contact['url']); ?>" target="_blank" style="color: #2196f3;">🔗 查看</a>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($contact['is_active']): ?>
                                        <span style="color: #4caf50;">✅ 已启用</span>
                                    <?php else: ?>
                                        <span style="color: #999;">❌ 已禁用</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick='editContact(<?php echo json_encode($contact); ?>)'>编辑</button>
                                    <?php if ($contact['is_active']): ?>
                                        <button class="btn btn-sm btn-warning" onclick="toggleStatus(<?php echo $contact['id']; ?>, 0)">禁用</button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-success" onclick="toggleStatus(<?php echo $contact['id']; ?>, 1)">启用</button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-danger" onclick="deleteContact(<?php echo $contact['id']; ?>)">删除</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 添加/编辑模态框 -->
    <div id="contactModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">添加客服</h2>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <form id="contactForm">
                <input type="hidden" id="contact_id" name="id">
                
                <div class="form-group">
                    <label>客服名称 *</label>
                    <input type="text" id="name" name="name" class="form-control" required placeholder="如：Telegram客服、客服小王">
                </div>
                
                <div class="form-group">
                    <label>联系方式类型 *</label>
                    <select id="type" name="type" class="form-control" required>
                        <option value="telegram">✈️ Telegram</option>
                        <option value="whatsapp">💬 WhatsApp</option>
                        <option value="email">📧 邮箱</option>
                        <option value="phone">📞 电话</option>
                        <option value="wechat">💚 微信</option>
                        <option value="other">📱 其他</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>联系方式 *</label>
                    <input type="text" id="contact" name="contact" class="form-control" required placeholder="如：@YourSupport 或 support@example.com">
                    <small style="color: #666;">根据类型填写：Telegram用户名、邮箱地址、电话号码等</small>
                </div>
                
                <div class="form-group">
                    <label>链接地址</label>
                    <input type="url" id="url" name="url" class="form-control" placeholder="如：https://t.me/YourSupport">
                    <small style="color: #666;">可选：用户可以直接点击的链接（如Telegram链接、WhatsApp链接）</small>
                </div>
                
                <div class="form-group">
                    <label>说明</label>
                    <textarea id="description" name="description" class="form-control" rows="3" placeholder="如：工作时间、语言、特殊说明等"></textarea>
                </div>
                
                <div class="form-group">
                    <label>排序顺序</label>
                    <input type="number" id="sort_order" name="sort_order" class="form-control" value="0" min="0">
                    <small style="color: #666;">数字越小越靠前显示</small>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="is_active" name="is_active" value="1" checked>
                        立即启用
                    </label>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn" onclick="closeModal()">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function showAddModal() {
            document.getElementById('modalTitle').textContent = '添加客服';
            document.getElementById('contactForm').reset();
            document.getElementById('contact_id').value = '';
            document.getElementById('is_active').checked = true;
            document.getElementById('contactModal').style.display = 'flex';
        }
        
        function editContact(contact) {
            document.getElementById('modalTitle').textContent = '编辑客服';
            document.getElementById('contact_id').value = contact.id;
            document.getElementById('name').value = contact.name;
            document.getElementById('type').value = contact.type;
            document.getElementById('contact').value = contact.contact;
            document.getElementById('url').value = contact.url || '';
            document.getElementById('description').value = contact.description || '';
            document.getElementById('sort_order').value = contact.sort_order;
            document.getElementById('is_active').checked = contact.is_active == 1;
            document.getElementById('contactModal').style.display = 'flex';
        }
        
        function closeModal() {
            document.getElementById('contactModal').style.display = 'none';
        }
        
        document.getElementById('contactForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const id = document.getElementById('contact_id').value;
            
            const data = {
                name: formData.get('name'),
                type: formData.get('type'),
                contact: formData.get('contact'),
                url: formData.get('url'),
                description: formData.get('description'),
                sort_order: formData.get('sort_order'),
                is_active: formData.get('is_active') ? 1 : 0
            };
            
            if (id) {
                data.id = id;
            }
            
            try {
                const response = await fetch('api/customer_service.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ 保存成功！');
                    location.reload();
                } else {
                    alert('❌ 保存失败：' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('操作失败，请重试');
            }
        });
        
        async function toggleStatus(id, status) {
            if (!confirm('确定要修改状态吗？')) return;
            
            try {
                const response = await fetch('api/customer_service.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, is_active: status })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ 状态修改成功！');
                    location.reload();
                } else {
                    alert('❌ 操作失败：' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('操作失败，请重试');
            }
        }
        
        async function deleteContact(id) {
            if (!confirm('确定要删除这个客服吗？')) return;
            
            try {
                const response = await fetch('api/customer_service.php?id=' + id, {
                    method: 'DELETE'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ 删除成功！');
                    location.reload();
                } else {
                    alert('❌ 删除失败：' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('操作失败，请重试');
            }
        }
        
        // 点击模态框外部关闭
        window.onclick = function(event) {
            const modal = document.getElementById('contactModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>

