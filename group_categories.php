<?php
require_once 'config.php';
checkLogin();

$db = getDB();

// 获取所有群组
$stmt = $db->query("SELECT g.*, gc.name as category_name, gc.color as category_color
    FROM groups g 
    LEFT JOIN group_categories gc ON g.category_id = gc.id 
    WHERE g.is_active = 1 AND g.is_deleted = 0 
    ORDER BY g.title");
$groups = $stmt->fetchAll();

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>群组分类 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .category-card {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 15px;
            overflow: hidden;
        }
        .category-header {
            padding: 12px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        .category-header:hover {
            filter: brightness(0.95);
        }
        .category-title {
            font-weight: bold;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .category-count {
            background: rgba(255,255,255,0.3);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
        }
        .category-body {
            padding: 15px;
            background: #f9f9f9;
            display: none;
        }
        .category-body.show {
            display: block;
        }
        .group-item {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            background: #fff;
            border-radius: 4px;
            margin-bottom: 8px;
            border: 1px solid #e0e0e0;
        }
        .group-item:hover {
            background: #f5f5f5;
        }
        .group-item input[type="checkbox"] {
            margin-right: 10px;
        }
        .color-picker {
            width: 50px;
            height: 30px;
            border: none;
            cursor: pointer;
            border-radius: 4px;
        }
        .badge-category {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            color: #fff;
        }
        .action-bar {
            background: #fff;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .category-list-table {
            width: 100%;
        }
        .category-list-table th, .category-list-table td {
            text-align: left;
            padding: 12px;
        }
        .drag-handle {
            cursor: move;
            color: #999;
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>📁 群组分类管理</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <div class="alert alert-info">
                <strong>💡 提示：</strong>通过分类功能，可以更方便地管理大量群组。在自动广告中可以按分类批量选择群组发送。
            </div>
            
            <!-- 标签页切换 -->
            <div style="margin-bottom: 20px;">
                <div class="btn-group">
                    <button class="btn btn-primary" id="tabCategories" onclick="switchTab('categories')">分类管理</button>
                    <button class="btn" id="tabGroups" onclick="switchTab('groups')">群组分类</button>
                </div>
            </div>
            
            <!-- 分类管理 -->
            <div class="card" id="categoriesCard">
                <div class="card-header">
                    <h2>分类列表</h2>
                    <button class="btn btn-primary" onclick="showAddCategoryModal()">+ 添加分类</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="category-list-table">
                            <thead>
                                <tr>
                                    <th width="50">排序</th>
                                    <th>分类名称</th>
                                    <th>颜色</th>
                                    <th>群组数量</th>
                                    <th>描述</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="categoriesTable">
                                <tr>
                                    <td colspan="7" class="loading">加载中...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- 群组分类 -->
            <div class="card" id="groupsCard" style="display: none;">
                <div class="card-header">
                    <h2>设置群组分类</h2>
                </div>
                <div class="card-body">
                    <div class="action-bar">
                        <span>批量操作：</span>
                        <select id="batchCategorySelect" class="form-control" style="width: 200px;">
                            <option value="">选择分类...</option>
                        </select>
                        <button class="btn btn-primary" onclick="batchSetCategory()">应用到选中群组</button>
                        <button class="btn btn-secondary" onclick="selectAllGroups()">全选</button>
                        <button class="btn btn-secondary" onclick="deselectAllGroups()">取消全选</button>
                        <span style="margin-left: auto; color: #666;" id="selectedCount">已选 0 个群组</span>
                    </div>
                    
                    <div id="groupsCategoryList">
                        <p style="text-align: center; padding: 20px; color: #999;">加载中...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 添加分类模态框 -->
    <div id="addCategoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>添加分类</h3>
            </div>
            <form onsubmit="event.preventDefault(); addCategory();">
                <div class="form-group">
                    <label>分类名称 *</label>
                    <input type="text" id="categoryName" class="form-control" required placeholder="例如：交易群、聊天群">
                </div>
                
                <div class="form-group">
                    <label>颜色</label>
                    <input type="color" id="categoryColor" class="color-picker" value="#3498db">
                </div>
                
                <div class="form-group">
                    <label>排序</label>
                    <input type="number" id="categorySortOrder" class="form-control" value="0" min="0">
                    <small class="form-text">数字越小越靠前</small>
                </div>
                
                <div class="form-group">
                    <label>描述</label>
                    <textarea id="categoryDescription" class="form-control" rows="2" placeholder="可选的分类描述..."></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('addCategoryModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">添加</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 编辑分类模态框 -->
    <div id="editCategoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>编辑分类</h3>
            </div>
            <form onsubmit="event.preventDefault(); updateCategory();">
                <input type="hidden" id="editCategoryId">
                
                <div class="form-group">
                    <label>分类名称 *</label>
                    <input type="text" id="editCategoryName" class="form-control" required placeholder="例如：交易群、聊天群">
                </div>
                
                <div class="form-group">
                    <label>颜色</label>
                    <input type="color" id="editCategoryColor" class="color-picker" value="#3498db">
                </div>
                
                <div class="form-group">
                    <label>排序</label>
                    <input type="number" id="editCategorySortOrder" class="form-control" value="0" min="0">
                    <small class="form-text">数字越小越靠前</small>
                </div>
                
                <div class="form-group">
                    <label>描述</label>
                    <textarea id="editCategoryDescription" class="form-control" rows="2" placeholder="可选的分类描述..."></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('editCategoryModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
    <script>
        let currentTab = 'categories';
        let categoriesData = [];
        let groupsData = <?php echo json_encode($groups); ?>;
        
        // 切换标签页
        function switchTab(tab) {
            currentTab = tab;
            
            if (tab === 'categories') {
                document.getElementById('categoriesCard').style.display = 'block';
                document.getElementById('groupsCard').style.display = 'none';
                document.getElementById('tabCategories').className = 'btn btn-primary';
                document.getElementById('tabGroups').className = 'btn';
                loadCategories();
            } else {
                document.getElementById('categoriesCard').style.display = 'none';
                document.getElementById('groupsCard').style.display = 'block';
                document.getElementById('tabCategories').className = 'btn';
                document.getElementById('tabGroups').className = 'btn btn-primary';
                loadGroupsWithCategories();
            }
        }
        
        // 加载分类列表
        async function loadCategories() {
            try {
                const response = await fetch('api/group_categories.php?action=list');
                const result = await response.json();
                
                if (result.success) {
                    categoriesData = result.data;
                    renderCategories(result.data);
                    updateBatchCategorySelect();
                } else {
                    App.showAlert('加载失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('加载失败：' + error.message, 'error');
            }
        }
        
        // 渲染分类列表
        function renderCategories(categories) {
            const tbody = document.getElementById('categoriesTable');
            
            if (categories.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="no-data">暂无分类，点击"添加分类"创建</td></tr>';
                return;
            }
            
            tbody.innerHTML = categories.map(cat => `
                <tr>
                    <td>
                        <span class="drag-handle">☰</span>
                        ${cat.sort_order}
                    </td>
                    <td>
                        <span class="badge-category" style="background: ${cat.color}">${cat.name}</span>
                    </td>
                    <td>
                        <div style="width: 30px; height: 20px; background: ${cat.color}; border-radius: 4px;"></div>
                    </td>
                    <td>${cat.group_count || 0} 个群组</td>
                    <td>${cat.description || '-'}</td>
                    <td>
                        <span class="badge ${cat.is_active ? 'badge-success' : 'badge-secondary'}">
                            ${cat.is_active ? '启用' : '禁用'}
                        </span>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-warning" onclick="editCategory(${cat.id})">编辑</button>
                        <button class="btn btn-sm ${cat.is_active ? 'btn-secondary' : 'btn-success'}" 
                                onclick="toggleCategory(${cat.id}, ${cat.is_active ? 0 : 1})">
                            ${cat.is_active ? '禁用' : '启用'}
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteCategory(${cat.id})">删除</button>
                    </td>
                </tr>
            `).join('');
        }
        
        // 加载群组及分类信息
        async function loadGroupsWithCategories() {
            try {
                const response = await fetch('api/group_categories.php?action=list_with_groups');
                const result = await response.json();
                
                if (result.success) {
                    renderGroupsWithCategories(result.data);
                    updateBatchCategorySelect();
                } else {
                    App.showAlert('加载失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('加载失败：' + error.message, 'error');
            }
        }
        
        // 渲染群组分类视图
        function renderGroupsWithCategories(categories) {
            const container = document.getElementById('groupsCategoryList');
            
            if (categories.length === 0) {
                container.innerHTML = '<p style="text-align: center; padding: 20px; color: #999;">暂无群组</p>';
                return;
            }
            
            container.innerHTML = categories.map(cat => `
                <div class="category-card">
                    <div class="category-header" style="background: ${cat.color}" onclick="toggleCategoryBody(${cat.id})">
                        <div class="category-title">
                            <span>📁 ${cat.name}</span>
                            <span class="category-count">${cat.groups.length} 个群组</span>
                        </div>
                        <span style="color: #fff;">▼</span>
                    </div>
                    <div class="category-body" id="categoryBody_${cat.id}">
                        ${cat.groups.length > 0 ? cat.groups.map(g => `
                            <div class="group-item">
                                <input type="checkbox" class="group-checkbox" value="${g.id}" onchange="updateSelectedCount()">
                                <span style="flex: 1;">${g.title}</span>
                                <span style="color: #999; font-size: 12px;">${g.chat_id}</span>
                                <select class="form-control" style="width: 150px; margin-left: 10px;" onchange="setGroupCategory(${g.id}, this.value)">
                                    <option value="0">未分类</option>
                                    ${categoriesData.map(c => `
                                        <option value="${c.id}" ${cat.id == c.id ? 'selected' : ''}>${c.name}</option>
                                    `).join('')}
                                </select>
                            </div>
                        `).join('') : '<p style="color: #999; text-align: center; padding: 10px;">该分类下暂无群组</p>'}
                    </div>
                </div>
            `).join('');
        }
        
        // 切换分类展开/折叠
        function toggleCategoryBody(id) {
            const body = document.getElementById(`categoryBody_${id}`);
            body.classList.toggle('show');
        }
        
        // 更新批量分类下拉框
        function updateBatchCategorySelect() {
            const select = document.getElementById('batchCategorySelect');
            select.innerHTML = '<option value="">选择分类...</option><option value="0">未分类</option>';
            
            categoriesData.forEach(cat => {
                if (cat.is_active) {
                    select.innerHTML += `<option value="${cat.id}">${cat.name}</option>`;
                }
            });
        }
        
        // 显示添加分类模态框
        function showAddCategoryModal() {
            document.getElementById('categoryName').value = '';
            document.getElementById('categoryColor').value = '#3498db';
            document.getElementById('categorySortOrder').value = '0';
            document.getElementById('categoryDescription').value = '';
            App.showModal('addCategoryModal');
        }
        
        // 添加分类
        async function addCategory() {
            const name = document.getElementById('categoryName').value;
            const color = document.getElementById('categoryColor').value;
            const sort_order = document.getElementById('categorySortOrder').value;
            const description = document.getElementById('categoryDescription').value;
            
            try {
                const response = await fetch('api/group_categories.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'add',
                        name,
                        color,
                        sort_order,
                        description
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    App.showAlert('添加成功');
                    App.hideModal('addCategoryModal');
                    loadCategories();
                } else {
                    App.showAlert(result.message || '添加失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('添加失败：' + error.message, 'error');
            }
        }
        
        // 编辑分类
        async function editCategory(id) {
            try {
                const response = await fetch(`api/group_categories.php?action=get&id=${id}`);
                const result = await response.json();
                
                if (result.success) {
                    const cat = result.data;
                    document.getElementById('editCategoryId').value = cat.id;
                    document.getElementById('editCategoryName').value = cat.name;
                    document.getElementById('editCategoryColor').value = cat.color;
                    document.getElementById('editCategorySortOrder').value = cat.sort_order;
                    document.getElementById('editCategoryDescription').value = cat.description || '';
                    App.showModal('editCategoryModal');
                } else {
                    App.showAlert('获取分类信息失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('获取分类信息失败：' + error.message, 'error');
            }
        }
        
        // 更新分类
        async function updateCategory() {
            const id = document.getElementById('editCategoryId').value;
            const name = document.getElementById('editCategoryName').value;
            const color = document.getElementById('editCategoryColor').value;
            const sort_order = document.getElementById('editCategorySortOrder').value;
            const description = document.getElementById('editCategoryDescription').value;
            
            try {
                const response = await fetch('api/group_categories.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update',
                        id,
                        name,
                        color,
                        sort_order,
                        description
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    App.showAlert('更新成功');
                    App.hideModal('editCategoryModal');
                    loadCategories();
                } else {
                    App.showAlert(result.message || '更新失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('更新失败：' + error.message, 'error');
            }
        }
        
        // 切换分类状态
        async function toggleCategory(id, isActive) {
            try {
                const response = await fetch('api/group_categories.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'toggle',
                        id,
                        is_active: isActive
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    App.showAlert('状态更新成功');
                    loadCategories();
                } else {
                    App.showAlert(result.message || '更新失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('更新失败：' + error.message, 'error');
            }
        }
        
        // 删除分类
        async function deleteCategory(id) {
            if (!confirm('确定要删除这个分类吗？该分类下的群组将变为"未分类"。')) {
                return;
            }
            
            try {
                const response = await fetch('api/group_categories.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete',
                        id
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    App.showAlert('删除成功');
                    loadCategories();
                } else {
                    App.showAlert(result.message || '删除失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('删除失败：' + error.message, 'error');
            }
        }
        
        // 设置单个群组的分类
        async function setGroupCategory(groupId, categoryId) {
            try {
                const response = await fetch('api/group_categories.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'set_group_category',
                        group_id: groupId,
                        category_id: categoryId || null
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    App.showAlert('分类设置成功');
                    // 不重新加载，保持当前状态
                } else {
                    App.showAlert(result.message || '设置失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('设置失败：' + error.message, 'error');
            }
        }
        
        // 批量设置分类
        async function batchSetCategory() {
            const categoryId = document.getElementById('batchCategorySelect').value;
            if (categoryId === '') {
                App.showAlert('请选择要设置的分类', 'error');
                return;
            }
            
            const checkboxes = document.querySelectorAll('.group-checkbox:checked');
            if (checkboxes.length === 0) {
                App.showAlert('请选择要设置的群组', 'error');
                return;
            }
            
            const groupIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
            
            try {
                const response = await fetch('api/group_categories.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'batch_set_category',
                        group_ids: groupIds,
                        category_id: categoryId || null
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    App.showAlert(result.message);
                    loadGroupsWithCategories();
                } else {
                    App.showAlert(result.message || '批量设置失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('批量设置失败：' + error.message, 'error');
            }
        }
        
        // 全选群组
        function selectAllGroups() {
            document.querySelectorAll('.group-checkbox').forEach(cb => cb.checked = true);
            updateSelectedCount();
        }
        
        // 取消全选
        function deselectAllGroups() {
            document.querySelectorAll('.group-checkbox').forEach(cb => cb.checked = false);
            updateSelectedCount();
        }
        
        // 更新选中数量
        function updateSelectedCount() {
            const count = document.querySelectorAll('.group-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = `已选 ${count} 个群组`;
        }
        
        // 页面加载
        document.addEventListener('DOMContentLoaded', function() {
            loadCategories();
        });
    </script>
</body>
</html>

