<?php
require_once 'config.php';
checkLogin();

$db = getDB();

// 确保分类表存在
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS group_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            color VARCHAR(20) DEFAULT '#3498db',
            sort_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // 检查groups表是否有category_id字段，没有则添加
    $stmt = $db->query("SHOW COLUMNS FROM groups LIKE 'category_id'");
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE groups ADD COLUMN category_id INT DEFAULT NULL");
        $db->exec("ALTER TABLE groups ADD INDEX idx_category_id (category_id)");
    }
} catch (Exception $e) {
    error_log("Init group_categories table error: " . $e->getMessage());
}

// 获取所有群组
$stmt = $db->query("SELECT g.id, g.title, g.category_id, gc.name as category_name, gc.color as category_color 
    FROM groups g 
    LEFT JOIN group_categories gc ON g.category_id = gc.id 
    WHERE g.is_active = 1 AND g.is_deleted = 0 
    ORDER BY gc.sort_order, gc.name, g.title");
$groups = $stmt->fetchAll();

// 获取所有分类
$stmt = $db->query("SELECT * FROM group_categories WHERE is_active = 1 ORDER BY sort_order, name");
$categories = $stmt->fetchAll();

// 按分类组织群组
$groupsByCategory = [];
foreach ($groups as $group) {
    $catId = $group['category_id'] ?? 0;
    if (!isset($groupsByCategory[$catId])) {
        $groupsByCategory[$catId] = [
            'id' => $catId,
            'name' => $group['category_name'] ?? '未分类',
            'color' => $group['category_color'] ?? '#95a5a6',
            'groups' => []
        ];
    }
    $groupsByCategory[$catId]['groups'][] = $group;
}

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>自动广告 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>📢 自动广告</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <div class="alert alert-success">
                <strong>提示：</strong>自动广告会按设定的时间间隔循环发送。请确保已设置定时任务（cron）以执行 bot/cron.php
            </div>
            
            <!-- 标签页切换 -->
            <div style="margin-bottom: 20px;">
                <div class="btn-group">
                    <button class="btn btn-primary" id="tabNormalAds" onclick="switchTab('normal')">单条广告</button>
                    <button class="btn" id="tabTemplateAds" onclick="switchTab('template')">循环广告模板</button>
                </div>
            </div>
            
            <!-- 单条广告列表 -->
            <div class="card" id="normalAdsCard">
                <div class="card-header">
                    <h2>单条广告列表</h2>
                    <button class="btn btn-primary" onclick="App.showModal('addAdModal')">+ 添加广告</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>群组</th>
                                    <th>广告内容</th>
                                    <th>图片</th>
                                    <th>按钮</th>
                                    <th>间隔时间</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="autoAdsTable">
                                <tr>
                                    <td colspan="6" class="loading">加载中...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- 循环广告模板列表 -->
            <div class="card" id="templateAdsCard" style="display: none;">
                <div class="card-header">
                    <h2>循环广告模板列表</h2>
                    <button class="btn btn-primary" onclick="App.showModal('addTemplateModal')">+ 创建模板</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>模板名称</th>
                                    <th>群组</th>
                                    <th>广告数量</th>
                                    <th>当前索引</th>
                                    <th>间隔时间</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="templatesTable">
                                <tr>
                                    <td colspan="8" class="loading">加载中...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 添加广告模态框 -->
    <div id="addAdModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>添加自动广告</h3>
            </div>
            <form onsubmit="event.preventDefault(); AutoAds.add();">
                <div class="form-group">
                    <label>选择群组 *</label>
                    <div class="group-selector-actions" style="margin-bottom: 10px; display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="selectAllAdGroups()">全选</button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="deselectAllAdGroups()">取消全选</button>
                        <?php foreach ($categories as $cat): ?>
                        <button type="button" class="btn btn-sm" style="background: <?php echo $cat['color']; ?>; color: #fff;" onclick="selectCategoryGroups('ad', <?php echo $cat['id']; ?>)">
                            选择"<?php echo escape($cat['name']); ?>"
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 4px; background: #fff;">
                        <label style="display: block; margin-bottom: 8px; cursor: pointer;">
                            <input type="checkbox" id="adGroupAll" value="0" onchange="toggleAllGroups(this, 'ad')" style="margin-right: 8px;">
                            <strong>📢 所有群组</strong>
                        </label>
                        <hr style="margin: 8px 0; border: none; border-top: 1px solid #eee;">
                        <?php foreach ($groupsByCategory as $catId => $catData): ?>
                        <div class="category-group-section" style="margin-bottom: 15px;">
                            <div style="display: flex; align-items: center; margin-bottom: 8px; cursor: pointer;" onclick="toggleCategorySection('ad', <?php echo $catId; ?>)">
                                <span style="display: inline-block; width: 12px; height: 12px; background: <?php echo $catData['color']; ?>; border-radius: 2px; margin-right: 8px;"></span>
                                <strong style="flex: 1;"><?php echo escape($catData['name']); ?></strong>
                                <span style="color: #999; font-size: 12px;">(<?php echo count($catData['groups']); ?>个群组)</span>
                                <input type="checkbox" class="ad-category-checkbox" data-category="<?php echo $catId; ?>" onclick="event.stopPropagation(); toggleCategoryCheckbox(this, 'ad', <?php echo $catId; ?>)" style="margin-left: 8px;">
                            </div>
                            <div class="category-groups" id="ad-category-groups-<?php echo $catId; ?>" style="padding-left: 20px;">
                                <?php foreach ($catData['groups'] as $group): ?>
                            <label style="display: block; margin-bottom: 5px; cursor: pointer;">
                                    <input type="checkbox" class="ad-group-checkbox" data-category="<?php echo $catId; ?>" value="<?php echo $group['id']; ?>" style="margin-right: 8px;">
                                <?php echo escape($group['title']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <small class="form-text">选择"所有群组"将自动取消其他选择；也可以按分类快速选择</small>
                </div>
                
                <div class="form-group">
                    <label>广告内容 *</label>
                    <textarea id="adMessage" class="form-control" rows="5" required placeholder="输入要发送的广告内容..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>🏷️ 关键词标签库 (可选)</label>
                    <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                        <input type="file" id="adKeywordsFile" class="form-control" accept=".txt" style="flex: 1;" onchange="loadKeywordsFromFile(this, 'adKeywords')">
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('adKeywordsFile').click()">📁 导入TXT文件</button>
                    </div>
                    <textarea id="adKeywords" class="form-control" rows="4" placeholder="每行一个关键词，或用空格/逗号分隔，例如：&#10;#梁溪&#10;#杭州&#10;#WINKY诗&#10;&#10;支持导入TXT文件（每行一个关键词）"></textarea>
                    <small class="form-text">
                        <span id="adKeywordsCount" style="color: #666; font-weight: bold;">已导入 0 个关键词</span>
                        <br>每次发送广告时会从关键词库中按顺序取出指定数量的关键词附加到消息末尾
                    </small>
                </div>
                
                <div class="form-group">
                    <label>每次发送关键词数量</label>
                    <input type="number" id="adKeywordsPerSend" class="form-control" value="3" min="1" max="20">
                    <small class="form-text">每次发送广告时附带的关键词数量（如截图中的3个标签）</small>
                </div>
                
                <div class="form-group">
                    <label>图片 (可选)</label>
                    <input type="file" id="adImage" class="form-control" accept="image/*">
                    <small class="form-text">支持 JPG, PNG, GIF 格式。GIF最大50MB，其他格式最大5MB</small>
                    <div id="adImagePreview" style="margin-top: 10px;"></div>
                </div>
                
                <div class="form-group">
                    <label>按钮配置 (可选)</label>
                    <div id="adButtonsContainer"></div>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="addButtonRow('adButtonsContainer')">+ 添加按钮行</button>
                    <small class="form-text">每行可配置1-3个按钮，每个按钮可设置二级菜单</small>
                </div>
                
                <div class="form-group">
                    <label>发送间隔（分钟）*</label>
                    <input type="number" id="adInterval" class="form-control" value="60" min="1" required>
                    <small style="color: #777;">建议设置不少于30分钟，避免刷屏</small>
                </div>
                
                <div class="form-group">
                    <label>消息自毁时间（秒）</label>
                    <input type="number" id="adDeleteAfter" class="form-control" value="0" min="0" max="86400">
                    <small style="color: #777;">设置消息发送后自动删除的时间（秒），0表示不自毁，最大86400秒（24小时）</small>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('addAdModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">添加</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 编辑广告模态框 -->
    <div id="editAdModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>编辑自动广告</h3>
            </div>
            <form onsubmit="event.preventDefault(); updateAd();">
                <input type="hidden" id="editAdId">
                
                <div class="form-group">
                    <label>选择群组 *</label>
                    <select id="editAdGroupId" class="form-control" required>
                        <option value="0">📢 所有群组</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"><?php echo escape($group['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>广告内容 *</label>
                    <textarea id="editAdMessage" class="form-control" rows="5" required placeholder="输入要发送的广告内容..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>🏷️ 关键词标签库 (可选)</label>
                    <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                        <input type="file" id="editAdKeywordsFile" class="form-control" accept=".txt" style="flex: 1;" onchange="loadKeywordsFromFile(this, 'editAdKeywords')">
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('editAdKeywordsFile').click()">📁 导入TXT文件</button>
                    </div>
                    <textarea id="editAdKeywords" class="form-control" rows="4" placeholder="每行一个关键词，或用空格/逗号分隔"></textarea>
                    <small class="form-text">
                        <span id="editAdKeywordsCount" style="color: #666; font-weight: bold;">已导入 0 个关键词</span>
                        <br>每次发送广告时会从关键词库中按顺序取出指定数量的关键词附加到消息末尾
                    </small>
                </div>
                
                <div class="form-group">
                    <label>每次发送关键词数量</label>
                    <input type="number" id="editAdKeywordsPerSend" class="form-control" value="3" min="1" max="20">
                    <small class="form-text">每次发送广告时附带的关键词数量</small>
                </div>
                
                <div class="form-group">
                    <label>当前图片</label>
                    <div id="editCurrentImage"></div>
                </div>
                
                <div class="form-group">
                    <label>更换图片 (可选)</label>
                    <input type="file" id="editAdImage" class="form-control" accept="image/*">
                    <small class="form-text">留空则保持原图片不变</small>
                    <div id="editAdImagePreview" style="margin-top: 10px;"></div>
                </div>
                
                <div class="form-group">
                    <label>按钮配置 (可选)</label>
                    <div id="editAdButtonsContainer"></div>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="addButtonRow('editAdButtonsContainer')">+ 添加按钮行</button>
                    <small class="form-text">每行可配置1-3个按钮，每个按钮可设置二级菜单</small>
                </div>
                
                <div class="form-group">
                    <label>发送间隔（分钟）*</label>
                    <input type="number" id="editAdInterval" class="form-control" value="60" min="1" required>
                    <small style="color: #777;">建议设置不少于30分钟，避免刷屏</small>
                </div>
                
                <div class="form-group">
                    <label>消息自毁时间（秒）</label>
                    <input type="number" id="editAdDeleteAfter" class="form-control" value="0" min="0" max="86400">
                    <small style="color: #777;">设置消息发送后自动删除的时间（秒），0表示不自毁，最大86400秒（24小时）</small>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('editAdModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 添加循环广告模板模态框 -->
    <div id="addTemplateModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3>创建循环广告模板</h3>
            </div>
            <form onsubmit="event.preventDefault(); saveTemplate();">
                <div class="form-group">
                    <label>模板名称 *</label>
                    <input type="text" id="templateName" class="form-control" required placeholder="例如：每日推广方案">
                </div>
                
                <div class="form-group">
                    <label>选择群组 *</label>
                    <div class="group-selector-actions" style="margin-bottom: 10px; display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="selectAllTemplateGroups()">全选</button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="deselectAllTemplateGroups()">取消全选</button>
                        <?php foreach ($categories as $cat): ?>
                        <button type="button" class="btn btn-sm" style="background: <?php echo $cat['color']; ?>; color: #fff;" onclick="selectCategoryGroups('template', <?php echo $cat['id']; ?>)">
                            选择"<?php echo escape($cat['name']); ?>"
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 4px; background: #fff;">
                        <label style="display: block; margin-bottom: 8px; cursor: pointer;">
                            <input type="checkbox" id="templateGroupAll" value="0" onchange="toggleAllGroups(this, 'template')" style="margin-right: 8px;">
                            <strong>📢 所有群组</strong>
                        </label>
                        <hr style="margin: 8px 0; border: none; border-top: 1px solid #eee;">
                        <?php foreach ($groupsByCategory as $catId => $catData): ?>
                        <div class="category-group-section" style="margin-bottom: 15px;">
                            <div style="display: flex; align-items: center; margin-bottom: 8px; cursor: pointer;" onclick="toggleCategorySection('template', <?php echo $catId; ?>)">
                                <span style="display: inline-block; width: 12px; height: 12px; background: <?php echo $catData['color']; ?>; border-radius: 2px; margin-right: 8px;"></span>
                                <strong style="flex: 1;"><?php echo escape($catData['name']); ?></strong>
                                <span style="color: #999; font-size: 12px;">(<?php echo count($catData['groups']); ?>个群组)</span>
                                <input type="checkbox" class="template-category-checkbox" data-category="<?php echo $catId; ?>" onclick="event.stopPropagation(); toggleCategoryCheckbox(this, 'template', <?php echo $catId; ?>)" style="margin-left: 8px;">
                            </div>
                            <div class="category-groups" id="template-category-groups-<?php echo $catId; ?>" style="padding-left: 20px;">
                                <?php foreach ($catData['groups'] as $group): ?>
                            <label style="display: block; margin-bottom: 5px; cursor: pointer;">
                                    <input type="checkbox" class="template-group-checkbox" data-category="<?php echo $catId; ?>" value="<?php echo $group['id']; ?>" style="margin-right: 8px;">
                                <?php echo escape($group['title']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <small class="form-text">选择"所有群组"将自动取消其他选择；也可以按分类快速选择</small>
                </div>
                
                <div class="form-group">
                    <label>发送间隔（分钟）*</label>
                    <input type="number" id="templateInterval" class="form-control" value="60" min="1" required>
                    <small style="color: #777;">每条广告之间的发送间隔时间</small>
                </div>
                
                <div class="form-group">
                    <label>循环间隔（分钟）</label>
                    <input type="number" id="templateCycleInterval" class="form-control" value="0" min="0">
                    <small style="color: #777;">循环完所有广告后，等待多久再开始下一轮循环（0表示立即循环，不等待）</small>
                </div>
                
                <div class="form-group">
                    <label>消息自毁时间（秒）</label>
                    <input type="number" id="templateDeleteAfter" class="form-control" value="0" min="0" max="86400">
                    <small style="color: #777;">设置消息发送后自动删除的时间（秒），0表示不自毁，最大86400秒（24小时）</small>
                </div>
                
                <div class="form-group">
                    <label>广告内容列表 *</label>
                    <div id="templateAdsContainer" style="margin-top: 10px;">
                        <!-- 广告项会动态添加到这里 -->
                    </div>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="addTemplateAdItem()" style="margin-top: 10px;">+ 添加一条广告</button>
                    <small class="form-text">添加多条广告内容，机器人会按顺序循环发送</small>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('addTemplateModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 查看循环广告模板模态框 -->
    <div id="viewTemplateModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3>查看循环广告模板</h3>
                <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('viewTemplateModal')" style="padding: 5px 15px;">关闭</button>
            </div>
            <div class="modal-body" id="viewTemplateContent" style="max-height: 600px; overflow-y: auto;">
                <p style="text-align: center; padding: 20px; color: #999;">加载中...</p>
            </div>
        </div>
    </div>
    
    <!-- 编辑循环广告模板模态框 -->
    <div id="editTemplateModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3>编辑循环广告模板</h3>
            </div>
            <form onsubmit="event.preventDefault(); updateTemplate();">
                <input type="hidden" id="editTemplateId">
                
                <div class="form-group">
                    <label>模板名称 *</label>
                    <input type="text" id="editTemplateName" class="form-control" required placeholder="例如：每日推广方案">
                </div>
                
                <div class="form-group">
                    <label>选择群组 *</label>
                    <select id="editTemplateGroupId" class="form-control" required>
                        <option value="0">📢 所有群组</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"><?php echo escape($group['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>发送间隔（分钟）*</label>
                    <input type="number" id="editTemplateInterval" class="form-control" value="60" min="1" required>
                    <small style="color: #777;">每条广告之间的发送间隔时间</small>
                </div>
                
                <div class="form-group">
                    <label>循环间隔（分钟）</label>
                    <input type="number" id="editTemplateCycleInterval" class="form-control" value="0" min="0">
                    <small style="color: #777;">循环完所有广告后，等待多久再开始下一轮循环（0表示立即循环，不等待）</small>
                </div>
                
                <div class="form-group">
                    <label>消息自毁时间（秒）</label>
                    <input type="number" id="editTemplateDeleteAfter" class="form-control" value="0" min="0" max="86400">
                    <small style="color: #777;">设置消息发送后自动删除的时间（秒），0表示不自毁，最大86400秒（24小时）</small>
                </div>
                
                <div class="form-group">
                    <label>广告内容列表 *</label>
                    <div id="editTemplateAdsContainer" style="margin-top: 10px;">
                        <!-- 广告项会动态添加到这里 -->
                    </div>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="addEditTemplateAdItem()" style="margin-top: 10px;">+ 添加一条广告</button>
                    <small class="form-text">添加多条广告内容，机器人会按顺序循环发送</small>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('editTemplateModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="assets/script.js?v=<?php echo time(); ?>"></script>
    <script>
        // ========== 关键词标签处理函数 ==========
        
        // 解析关键词（支持空格、逗号、换行、#号分隔，保留#号前缀）
        function parseKeywords(text) {
            if (!text || !text.trim()) return [];
            // 在 # 前面加空格（处理 #标签1#标签2 这种格式，但保留#号）
            text = text.replace(/#/g, ' #');
            // 将逗号、换行替换为空格，然后按空格分割
            const keywords = text
                .replace(/[,，\n\r]+/g, ' ')  // 逗号、中文逗号、换行替换为空格
                .split(/\s+/)                  // 按空格分割
                .map(kw => kw.trim())          // 去除前后空格
                .filter(kw => kw.length > 0);  // 过滤空关键词
            return keywords;
        }
        
        // 更新关键词计数显示
        function updateKeywordsCount(inputId, countId) {
            const input = document.getElementById(inputId);
            const countSpan = document.getElementById(countId);
            if (!input || !countSpan) return;
            
            const keywords = parseKeywords(input.value);
            const count = keywords.length;
            
            if (count > 0) {
                countSpan.innerHTML = `已导入 <strong style="color: #27ae60;">${count.toLocaleString()}</strong> 个关键词`;
            } else {
                countSpan.innerHTML = `已导入 0 个关键词`;
            }
        }
        
        // 从TXT文件加载关键词
        function loadKeywordsFromFile(input, textareaId) {
            const file = input.files[0];
            if (!file) return;
            
            const reader = new FileReader();
            reader.onload = function(e) {
                const content = e.target.result;
                const textarea = document.getElementById(textareaId);
                if (textarea) {
                    textarea.value = content;
                    // 触发计数更新
                    const countId = textareaId + 'Count';
                    updateKeywordsCount(textareaId, countId);
                    
                    const keywords = parseKeywords(content);
                    App.showAlert(`成功导入 ${keywords.length.toLocaleString()} 个关键词`, 'success');
                }
            };
            reader.onerror = function() {
                App.showAlert('文件读取失败', 'error');
            };
            reader.readAsText(file, 'UTF-8');
        }
        
        // 监听关键词输入变化
        document.addEventListener('DOMContentLoaded', function() {
            const adKeywords = document.getElementById('adKeywords');
            const editAdKeywords = document.getElementById('editAdKeywords');
            
            if (adKeywords) {
                adKeywords.addEventListener('input', () => updateKeywordsCount('adKeywords', 'adKeywordsCount'));
            }
            if (editAdKeywords) {
                editAdKeywords.addEventListener('input', () => updateKeywordsCount('editAdKeywords', 'editAdKeywordsCount'));
            }
        });
        
        // ========== 群组选择处理 ==========
        
        // 切换"所有群组"选项
        function toggleAllGroups(checkbox, prefix) {
            const checkboxes = document.querySelectorAll(`.${prefix}-group-checkbox`);
            const categoryCheckboxes = document.querySelectorAll(`.${prefix}-category-checkbox`);
            if (checkbox.checked) {
                // 如果勾选"所有群组"，取消其他所有选项
                checkboxes.forEach(cb => cb.checked = false);
                categoryCheckboxes.forEach(cb => cb.checked = false);
            }
        }
        
        // 按分类选择群组
        function selectCategoryGroups(prefix, categoryId) {
            // 取消"所有群组"
            const allCheckbox = document.getElementById(`${prefix}GroupAll`);
            if (allCheckbox) allCheckbox.checked = false;
            
            // 选中该分类下的所有群组
            const checkboxes = document.querySelectorAll(`.${prefix}-group-checkbox[data-category="${categoryId}"]`);
            checkboxes.forEach(cb => cb.checked = true);
            
            // 选中分类复选框
            const categoryCheckbox = document.querySelector(`.${prefix}-category-checkbox[data-category="${categoryId}"]`);
            if (categoryCheckbox) categoryCheckbox.checked = true;
        }
        
        // 切换分类复选框
        function toggleCategoryCheckbox(checkbox, prefix, categoryId) {
            const isChecked = checkbox.checked;
            const checkboxes = document.querySelectorAll(`.${prefix}-group-checkbox[data-category="${categoryId}"]`);
            checkboxes.forEach(cb => cb.checked = isChecked);
            
            if (isChecked) {
                // 取消"所有群组"
                const allCheckbox = document.getElementById(`${prefix}GroupAll`);
                if (allCheckbox) allCheckbox.checked = false;
            }
        }
        
        // 切换分类展开/收起
        function toggleCategorySection(prefix, categoryId) {
            const container = document.getElementById(`${prefix}-category-groups-${categoryId}`);
            if (container) {
                if (container.style.display === 'none') {
                    container.style.display = 'block';
                } else {
                    container.style.display = 'none';
                }
            }
        }
        
        // 全选广告群组
        function selectAllAdGroups() {
            document.getElementById('adGroupAll').checked = false;
            document.querySelectorAll('.ad-group-checkbox').forEach(cb => cb.checked = true);
            document.querySelectorAll('.ad-category-checkbox').forEach(cb => cb.checked = true);
        }
        
        // 取消全选广告群组
        function deselectAllAdGroups() {
            document.getElementById('adGroupAll').checked = false;
            document.querySelectorAll('.ad-group-checkbox').forEach(cb => cb.checked = false);
            document.querySelectorAll('.ad-category-checkbox').forEach(cb => cb.checked = false);
        }
        
        // 全选模板群组
        function selectAllTemplateGroups() {
            document.getElementById('templateGroupAll').checked = false;
            document.querySelectorAll('.template-group-checkbox').forEach(cb => cb.checked = true);
            document.querySelectorAll('.template-category-checkbox').forEach(cb => cb.checked = true);
        }
        
        // 取消全选模板群组
        function deselectAllTemplateGroups() {
            document.getElementById('templateGroupAll').checked = false;
            document.querySelectorAll('.template-group-checkbox').forEach(cb => cb.checked = false);
            document.querySelectorAll('.template-category-checkbox').forEach(cb => cb.checked = false);
        }
        
        // 当选择具体群组时，取消"所有群组"，并更新分类复选框状态
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('ad-group-checkbox')) {
                if (e.target.checked) {
                    document.getElementById('adGroupAll').checked = false;
                }
                updateCategoryCheckboxState('ad', e.target.dataset.category);
            }
            if (e.target.classList.contains('editAd-group-checkbox')) {
                if (e.target.checked) {
                    document.getElementById('editAdGroupAll').checked = false;
                }
            }
            if (e.target.classList.contains('template-group-checkbox')) {
                if (e.target.checked) {
                    document.getElementById('templateGroupAll').checked = false;
                }
                updateCategoryCheckboxState('template', e.target.dataset.category);
            }
            if (e.target.classList.contains('editTemplate-group-checkbox')) {
                if (e.target.checked) {
                    document.getElementById('editTemplateGroupAll').checked = false;
                }
            }
        });
        
        // 更新分类复选框状态
        function updateCategoryCheckboxState(prefix, categoryId) {
            if (!categoryId) return;
            
            const allInCategory = document.querySelectorAll(`.${prefix}-group-checkbox[data-category="${categoryId}"]`);
            const checkedInCategory = document.querySelectorAll(`.${prefix}-group-checkbox[data-category="${categoryId}"]:checked`);
            const categoryCheckbox = document.querySelector(`.${prefix}-category-checkbox[data-category="${categoryId}"]`);
            
            if (categoryCheckbox) {
                categoryCheckbox.checked = (allInCategory.length === checkedInCategory.length && allInCategory.length > 0);
            }
        }
        
        // 获取选中的群组ID
        function getSelectedGroupIds(prefix) {
            const allCheckbox = document.getElementById(`${prefix}GroupAll`);
            if (allCheckbox && allCheckbox.checked) {
                return ['0'];
            }
            
            const selectedIds = [];
            const checkboxes = document.querySelectorAll(`.${prefix}-group-checkbox:checked`);
            checkboxes.forEach(cb => selectedIds.push(cb.value));
            return selectedIds;
        }
        
        // 当前标签页
        let currentTab = 'normal';
        
        // 标签页切换
        function switchTab(tab) {
            currentTab = tab;
            
            if (tab === 'normal') {
                document.getElementById('normalAdsCard').style.display = 'block';
                document.getElementById('templateAdsCard').style.display = 'none';
                document.getElementById('tabNormalAds').className = 'btn btn-primary';
                document.getElementById('tabTemplateAds').className = 'btn';
                AutoAds.load();
            } else {
                document.getElementById('normalAdsCard').style.display = 'none';
                document.getElementById('templateAdsCard').style.display = 'block';
                document.getElementById('tabNormalAds').className = 'btn';
                document.getElementById('tabTemplateAds').className = 'btn btn-primary';
                loadTemplates();
            }
        }
        
        // 加载循环广告模板列表
        async function loadTemplates() {
            try {
                const response = await fetch('api/auto_ad_templates.php?action=list');
                const result = await response.json();
                
                if (result.success) {
                    renderTemplates(result.data);
                } else {
                    App.showAlert('加载失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('加载失败：' + error.message, 'error');
            }
        }
        
        // 渲染模板列表
        function renderTemplates(templates) {
            const tbody = document.getElementById('templatesTable');
            
            if (templates.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="no-data">暂无数据</td></tr>';
                return;
            }
            
            tbody.innerHTML = templates.map(template => `
                <tr>
                    <td>${template.id}</td>
                    <td><strong>${template.template_name}</strong></td>
                    <td>${template.group_title}</td>
                    <td>${template.ad_count} 条</td>
                    <td>第 ${(template.current_index || 0) + 1} 条</td>
                    <td>${template.interval_minutes} 分钟</td>
                    <td>
                        <span class="badge ${template.is_active ? 'badge-success' : 'badge-secondary'}">
                            ${template.is_active ? '启用' : '禁用'}
                        </span>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="viewTemplate(${template.id})">查看</button>
                        <button class="btn btn-sm btn-warning" onclick="editTemplate(${template.id})">编辑</button>
                        <button class="btn btn-sm ${template.is_active ? 'btn-secondary' : 'btn-success'}" 
                                onclick="toggleTemplate(${template.id}, ${template.is_active ? 0 : 1})">
                            ${template.is_active ? '禁用' : '启用'}
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteTemplate(${template.id})">删除</button>
                    </td>
                </tr>
            `).join('');
        }
        
        // 添加模板广告项
        let templateAdCounter = 0;
        function addTemplateAdItem(message = '', imageUrl = '', buttons = '') {
            templateAdCounter++;
            const container = document.getElementById('templateAdsContainer');
            const itemDiv = document.createElement('div');
            itemDiv.className = 'template-ad-item';
            itemDiv.style.cssText = 'border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 5px; background: #f9f9f9;';
            itemDiv.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h4 style="margin: 0;">第 ${templateAdCounter} 条广告</h4>
                    <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">删除</button>
                </div>
                <div class="form-group">
                    <label>广告内容 *</label>
                    <textarea class="form-control template-ad-message" rows="3" required placeholder="输入广告内容...">${message}</textarea>
                </div>
                <div class="form-group">
                    <label>图片（可选）</label>
                    <input type="file" class="form-control template-ad-image-file" accept="image/*" onchange="previewTemplateImage(this)">
                    <input type="hidden" class="template-ad-image-url" value="${imageUrl}">
                    <small class="form-text">支持 JPG, PNG, GIF 格式。GIF最大50MB，其他格式最大5MB</small>
                    <div class="template-ad-image-preview" style="margin-top: 10px;">
                        ${imageUrl ? '<img src="' + imageUrl + '" style="max-width: 200px; max-height: 200px; border: 1px solid #ddd; border-radius: 4px;">' : ''}
                    </div>
                </div>
                <div class="form-group">
                    <label>按钮配置（可选）</label>
                    <div class="template-ad-buttons-container"></div>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="addTemplateButtonRow(this)">+ 添加按钮行</button>
                    <small class="form-text" style="display: block; margin-top: 5px;">每行可配置1-3个按钮，每个按钮可设置二级菜单</small>
                </div>
            `;
            container.appendChild(itemDiv);
            
            // 如果有按钮配置，添加按钮
            if (buttons) {
                try {
                    const buttonsData = typeof buttons === 'string' ? JSON.parse(buttons) : buttons;
                    const buttonsContainer = itemDiv.querySelector('.template-ad-buttons-container');
                    if (Array.isArray(buttonsData) && buttonsData.length > 0) {
                        if (Array.isArray(buttonsData[0])) {
                            // 新格式
                            buttonsData.forEach(rowData => {
                                addTemplateButtonRowWithData(buttonsContainer, rowData);
                            });
                        } else {
                            // 旧格式：每个按钮一行
                            buttonsData.forEach(btn => {
                                addTemplateButtonRowWithData(buttonsContainer, [btn]);
                            });
                        }
                    }
                } catch (e) {
                    console.error('Error parsing buttons:', e);
                }
            }
        }
        
        // 添加编辑模板广告项
        let editTemplateAdCounter = 0;
        function addEditTemplateAdItem(message = '', imageUrl = '', buttons = '') {
            editTemplateAdCounter++;
            const container = document.getElementById('editTemplateAdsContainer');
            const itemDiv = document.createElement('div');
            itemDiv.className = 'template-ad-item';
            itemDiv.style.cssText = 'border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 5px; background: #f9f9f9;';
            itemDiv.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h4 style="margin: 0;">第 ${editTemplateAdCounter} 条广告</h4>
                    <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">删除</button>
                </div>
                <div class="form-group">
                    <label>广告内容 *</label>
                    <textarea class="form-control template-ad-message" rows="3" required placeholder="输入广告内容...">${message}</textarea>
                </div>
                <div class="form-group">
                    <label>图片（可选）</label>
                    <input type="file" class="form-control template-ad-image-file" accept="image/*" onchange="previewTemplateImage(this)">
                    <input type="hidden" class="template-ad-image-url" value="${imageUrl}">
                    <small class="form-text">支持 JPG, PNG, GIF 格式。GIF最大50MB，其他格式最大5MB</small>
                    <div class="template-ad-image-preview" style="margin-top: 10px;">
                        ${imageUrl ? '<img src="' + imageUrl + '" style="max-width: 200px; max-height: 200px; border: 1px solid #ddd; border-radius: 4px;">' : ''}
                    </div>
                </div>
                <div class="form-group">
                    <label>按钮配置（可选）</label>
                    <div class="template-ad-buttons-container"></div>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="addTemplateButtonRow(this)">+ 添加按钮行</button>
                    <small class="form-text" style="display: block; margin-top: 5px;">每行可配置1-3个按钮，每个按钮可设置二级菜单</small>
                </div>
            `;
            container.appendChild(itemDiv);
            
            // 如果有按钮配置，添加按钮
            if (buttons) {
                try {
                    const buttonsData = typeof buttons === 'string' ? JSON.parse(buttons) : buttons;
                    const buttonsContainer = itemDiv.querySelector('.template-ad-buttons-container');
                    if (Array.isArray(buttonsData) && buttonsData.length > 0) {
                        if (Array.isArray(buttonsData[0])) {
                            // 新格式
                            buttonsData.forEach(rowData => {
                                addTemplateButtonRowWithData(buttonsContainer, rowData);
                            });
                        } else {
                            // 旧格式：每个按钮一行
                            buttonsData.forEach(btn => {
                                addTemplateButtonRowWithData(buttonsContainer, [btn]);
                            });
                        }
                    }
                } catch (e) {
                    console.error('Error parsing buttons:', e);
                }
            }
        }
        
        // ========== 模板按钮行相关函数 ==========
        let templateButtonRowCounter = 0;
        
        // 添加模板按钮行
        function addTemplateButtonRow(btn) {
            const container = btn.previousElementSibling;
            addTemplateButtonRowWithData(container, null);
        }
        
        // 添加模板按钮行（带数据）
        function addTemplateButtonRowWithData(container, rowData) {
            templateButtonRowCounter++;
            const rowDiv = document.createElement('div');
            rowDiv.className = 'template-button-row-wrapper';
            rowDiv.style.cssText = 'border: 1px solid #ddd; padding: 12px; margin-bottom: 10px; border-radius: 6px; background: #fff;';
            
            const buttonCount = rowData ? rowData.length : 1;
            
            rowDiv.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-weight: bold; color: #555; font-size: 12px;">📌 按钮行</span>
                        <select class="form-control template-row-button-count" style="width: auto; padding: 2px 6px; height: auto; font-size: 12px;" onchange="updateTemplateButtonCount(this)">
                            <option value="1" ${buttonCount === 1 ? 'selected' : ''}>1个按钮</option>
                            <option value="2" ${buttonCount === 2 ? 'selected' : ''}>2个按钮</option>
                            <option value="3" ${buttonCount === 3 ? 'selected' : ''}>3个按钮</option>
                        </select>
                    </div>
                    <button type="button" class="btn btn-sm btn-danger" style="padding: 2px 8px; font-size: 11px;" onclick="this.closest('.template-button-row-wrapper').remove()">删除行</button>
                </div>
                <div class="template-row-buttons-container" style="display: flex; gap: 8px; flex-wrap: wrap;">
                </div>
            `;
            
            container.appendChild(rowDiv);
            
            // 添加初始按钮
            const buttonsContainer = rowDiv.querySelector('.template-row-buttons-container');
            if (rowData && rowData.length > 0) {
                rowData.forEach(btn => addTemplateButtonToRow(buttonsContainer, btn));
            } else {
                addTemplateButtonToRow(buttonsContainer, null);
            }
        }
        
        // 更新模板行内按钮数量
        function updateTemplateButtonCount(select) {
            const rowWrapper = select.closest('.template-button-row-wrapper');
            const container = rowWrapper.querySelector('.template-row-buttons-container');
            const currentButtons = container.querySelectorAll('.template-single-button-config');
            const newCount = parseInt(select.value);
            const currentCount = currentButtons.length;
            
            if (newCount > currentCount) {
                for (let i = currentCount; i < newCount; i++) {
                    addTemplateButtonToRow(container, null);
                }
            } else if (newCount < currentCount) {
                for (let i = currentCount - 1; i >= newCount; i--) {
                    currentButtons[i].remove();
                }
            }
        }
        
        // 添加单个按钮到模板行
        function addTemplateButtonToRow(container, buttonData) {
            const buttonDiv = document.createElement('div');
            buttonDiv.className = 'template-single-button-config';
            buttonDiv.style.cssText = 'flex: 1; min-width: 180px; background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 4px; padding: 10px;';
            
            const text = buttonData?.text || '';
            const url = buttonData?.url || '';
            const hasSubButtons = buttonData?.sub_buttons && buttonData.sub_buttons.length > 0;
            
            buttonDiv.innerHTML = `
                <div style="margin-bottom: 6px;">
                    <input type="text" class="form-control template-btn-text" placeholder="按钮文字" value="${escapeHtml(text)}" style="font-size: 12px; padding: 4px 8px;">
                </div>
                <div style="margin-bottom: 6px;">
                    <input type="url" class="form-control template-btn-url" placeholder="链接 (有二级菜单可留空)" value="${escapeHtml(url)}" style="font-size: 12px; padding: 4px 8px;">
                </div>
                <div style="border-top: 1px dashed #ddd; padding-top: 8px; margin-top: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <span style="font-size: 11px; color: #666;">🔽 二级菜单</span>
                        <button type="button" class="btn btn-sm btn-secondary" style="padding: 1px 6px; font-size: 10px;" onclick="addTemplateSubButton(this)">+ 子按钮</button>
                    </div>
                    <div class="template-sub-buttons-container" style="display: flex; flex-direction: column; gap: 4px;">
                    </div>
                </div>
            `;
            
            container.appendChild(buttonDiv);
            
            if (hasSubButtons) {
                const subContainer = buttonDiv.querySelector('.template-sub-buttons-container');
                buttonData.sub_buttons.forEach(sub => addTemplateSubButtonWithData(subContainer, sub));
            }
        }
        
        // 添加模板子按钮
        function addTemplateSubButton(btn) {
            const container = btn.closest('.template-single-button-config').querySelector('.template-sub-buttons-container');
            addTemplateSubButtonWithData(container, null);
        }
        
        // 添加模板子按钮（带数据）
        function addTemplateSubButtonWithData(container, subData) {
            const subDiv = document.createElement('div');
            subDiv.className = 'template-sub-button-item';
            subDiv.style.cssText = 'display: flex; gap: 4px; align-items: center; background: #fff; padding: 4px; border-radius: 3px;';
            
            const text = subData?.text || '';
            const url = subData?.url || '';
            
            subDiv.innerHTML = `
                <input type="text" class="form-control template-sub-btn-text" placeholder="文字" value="${escapeHtml(text)}" style="flex: 1; font-size: 11px; padding: 2px 6px;">
                <input type="url" class="form-control template-sub-btn-url" placeholder="链接" value="${escapeHtml(url)}" style="flex: 1; font-size: 11px; padding: 2px 6px;">
                <button type="button" class="btn btn-sm btn-danger" style="padding: 1px 4px; font-size: 9px;" onclick="this.parentElement.remove()">×</button>
            `;
            
            container.appendChild(subDiv);
        }
        
        // 预览模板广告图片
        function previewTemplateImage(input) {
            const preview = input.parentElement.querySelector('.template-ad-image-preview');
            const file = input.files[0];
            
            if (file) {
                // 检查文件大小：GIF最大50MB，其他格式最大5MB
                const maxSize = file.type === 'image/gif' ? 50 * 1024 * 1024 : 5 * 1024 * 1024;
                const maxSizeText = file.type === 'image/gif' ? '50MB' : '5MB';
                
                if (file.size > maxSize) {
                    App.showAlert('图片大小不能超过 ' + maxSizeText, 'error');
                    input.value = '';
                    preview.innerHTML = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = '<img src="' + e.target.result + '" style="max-width: 200px; max-height: 200px; border: 1px solid #ddd; border-radius: 4px;">';
                };
                reader.readAsDataURL(file);
            } else {
                const hiddenUrl = input.parentElement.querySelector('.template-ad-image-url').value;
                if (hiddenUrl) {
                    preview.innerHTML = '<img src="' + hiddenUrl + '" style="max-width: 200px; max-height: 200px; border: 1px solid #ddd; border-radius: 4px;">';
                } else {
                    preview.innerHTML = '';
                }
            }
        }
        
        // 获取模板广告数据（包含图片文件）- 支持新按钮格式
        function getTemplateAds(containerId) {
            const container = document.getElementById(containerId);
            const items = container.querySelectorAll('.template-ad-item');
            const ads = [];
            
            items.forEach(item => {
                const message = item.querySelector('.template-ad-message').value;
                const imageFile = item.querySelector('.template-ad-image-file').files[0];
                const existingImageUrl = item.querySelector('.template-ad-image-url').value;
                
                // 获取按钮（新格式：二维数组）
                const buttonsData = [];
                const rowWrappers = item.querySelectorAll('.template-button-row-wrapper');
                
                rowWrappers.forEach(rowWrapper => {
                    const rowButtons = [];
                    const buttonConfigs = rowWrapper.querySelectorAll('.template-single-button-config');
                    
                    buttonConfigs.forEach(config => {
                        const text = config.querySelector('.template-btn-text').value.trim();
                        const url = config.querySelector('.template-btn-url').value.trim();
                        
                        // 获取子按钮
                        const subButtons = [];
                        const subItems = config.querySelectorAll('.template-sub-button-item');
                        subItems.forEach(sub => {
                            const subText = sub.querySelector('.template-sub-btn-text').value.trim();
                            const subUrl = sub.querySelector('.template-sub-btn-url').value.trim();
                            if (subText && subUrl) {
                                subButtons.push({ text: subText, url: subUrl });
                            }
                        });
                        
                        if (text) {
                            const buttonObj = { text };
                            if (url) buttonObj.url = url;
                            if (subButtons.length > 0) buttonObj.sub_buttons = subButtons;
                            rowButtons.push(buttonObj);
                        }
                    });
                    
                    if (rowButtons.length > 0) {
                        buttonsData.push(rowButtons);
                    }
                });
                
                if (message) {
                    ads.push({
                        message: message,
                        image_file: imageFile || null,
                        existing_image_url: existingImageUrl || null,
                        buttons: buttonsData.length > 0 ? JSON.stringify(buttonsData) : null
                    });
                }
            });
            
            return ads;
        }
        
        // 保存模板
        async function saveTemplate() {
            const templateName = document.getElementById('templateName').value;
            const selectedGroupIds = getSelectedGroupIds('template');
            const interval = document.getElementById('templateInterval').value;
            const cycleInterval = document.getElementById('templateCycleInterval').value || 0;
            const deleteAfter = document.getElementById('templateDeleteAfter').value || 0;
            const ads = getTemplateAds('templateAdsContainer');
            
            if (!templateName || selectedGroupIds.length === 0) {
                App.showAlert('请填写所有必填字段', 'error');
                return;
            }
            
            if (ads.length === 0) {
                App.showAlert('请至少添加一条广告', 'error');
                return;
            }
            
            try {
                // 先上传所有图片
                const adsWithImages = [];
                for (const ad of ads) {
                    let imageUrl = ad.existing_image_url || null;
                    
                    // 如果有新上传的图片，先上传
                    if (ad.image_file) {
                        const formData = new FormData();
                        formData.append('action', 'upload_image');
                        formData.append('image', ad.image_file);
                        
                        const uploadResponse = await fetch('api/auto_ad_templates.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const uploadResult = await uploadResponse.json();
                        
                        if (uploadResult.success) {
                            imageUrl = uploadResult.image_url;
                        } else {
                            App.showAlert('图片上传失败：' + uploadResult.message, 'error');
                            return;
                        }
                    }
                    
                    adsWithImages.push({
                        message: ad.message,
                        image_url: imageUrl,
                        buttons: ad.buttons,
                        delete_after_seconds: deleteAfter
                    });
                }
                
                // 为每个群组保存模板
                for (const groupId of selectedGroupIds) {
                    const response = await fetch('api/auto_ad_templates.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'add',
                            template_name: templateName,
                            group_id: groupId,
                            interval_minutes: interval,
                            cycle_interval_minutes: cycleInterval,
                            delete_after_seconds: deleteAfter,
                            ads: adsWithImages
                        })
                    });
                    
                    const result = await response.json();
                    
                    if (!result.success) {
                        App.showAlert(result.message || '创建失败', 'error');
                        return;
                    }
                }
                
                App.showAlert(`创建成功，共创建 ${selectedGroupIds.length} 个模板`);
                App.hideModal('addTemplateModal');
                // 清空表单
                document.getElementById('templateName').value = '';
                document.getElementById('templateInterval').value = '60';
                document.getElementById('templateCycleInterval').value = '0';
                document.getElementById('templateDeleteAfter').value = '0';
                document.getElementById('templateAdsContainer').innerHTML = '';
                templateAdCounter = 0;
                // 清空复选框
                document.querySelectorAll('.template-group-checkbox').forEach(cb => cb.checked = false);
                const allCheckbox = document.getElementById('templateGroupAll');
                if (allCheckbox) allCheckbox.checked = false;
                // 重新加载列表
                loadTemplates();
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('创建失败：' + error.message, 'error');
            }
        }
        
        // 查看模板
        async function viewTemplate(id) {
            try {
                App.showModal('viewTemplateModal');
                document.getElementById('viewTemplateContent').innerHTML = '<p style="text-align: center; padding: 20px; color: #999;">加载中...</p>';
                
                const response = await fetch(`api/auto_ad_templates.php?action=get&id=${id}`);
                const result = await response.json();
                
                if (result.success) {
                    const template = result.data;
                    
                    let adsHtml = template.ads.map((ad, idx) => {
                        let buttonsHtml = '';
                        if (ad.buttons) {
                            try {
                                const buttons = JSON.parse(ad.buttons);
                                if (buttons && buttons.length > 0) {
                                    buttonsHtml = '<div style="margin-top: 10px;"><strong>按钮：</strong><ul style="margin: 5px 0; padding-left: 20px;">';
                                    buttons.forEach(btn => {
                                        buttonsHtml += `<li>${btn.text} - <a href="${btn.url}" target="_blank">${btn.url}</a></li>`;
                                    });
                                    buttonsHtml += '</ul></div>';
                                }
                            } catch (e) {
                                buttonsHtml = `<div style="margin-top: 10px;"><strong>按钮：</strong> ${ad.buttons}</div>`;
                            }
                        }
                        
                        return `
                            <div style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 5px; background: #fff;">
                                <h4 style="margin-top: 0; color: #2196F3;">📢 第 ${idx + 1} 条广告</h4>
                                <div style="margin-bottom: 10px;">
                                    <strong>内容：</strong>
                                    <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin-top: 5px; white-space: pre-wrap;">${ad.message}</div>
                                </div>
                                ${ad.image_url ? `
                                    <div style="margin-bottom: 10px;">
                                        <strong>图片：</strong><br>
                                        <img src="${ad.image_url}" style="max-width: 300px; max-height: 300px; border: 1px solid #ddd; border-radius: 4px; margin-top: 5px;">
                                    </div>
                                ` : ''}
                                ${buttonsHtml}
                            </div>
                        `;
                    }).join('');
                    
                    const cycleIntervalText = template.cycle_interval_minutes > 0 
                        ? `${template.cycle_interval_minutes} 分钟` 
                        : '立即循环';
                    
                    const statusBadge = template.is_active 
                        ? '<span class="badge badge-success">启用中</span>' 
                        : '<span class="badge badge-secondary">已禁用</span>';
                    
                    const html = `
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                            <h3 style="margin: 0 0 15px 0; color: #333;">🎯 ${template.template_name} ${statusBadge}</h3>
                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; line-height: 1.8;">
                                <div><strong>📱 群组：</strong> ${template.group_title || '所有群组'}</div>
                                <div><strong>⏱️ 发送间隔：</strong> ${template.interval_minutes} 分钟</div>
                                <div><strong>🔄 循环间隔：</strong> ${cycleIntervalText}</div>
                                <div><strong>📍 当前进度：</strong> 第 ${(template.current_index || 0) + 1} 条</div>
                                <div><strong>📊 广告数量：</strong> ${template.ads.length} 条</div>
                                <div><strong>📅 创建时间：</strong> ${template.created_at || 'N/A'}</div>
                            </div>
                        </div>
                        
                        <h4 style="margin: 20px 0 15px 0; padding-bottom: 10px; border-bottom: 2px solid #2196F3;">📋 广告内容列表</h4>
                        ${adsHtml}
                    `;
                    
                    document.getElementById('viewTemplateContent').innerHTML = html;
                } else {
                    document.getElementById('viewTemplateContent').innerHTML = '<p style="text-align: center; color: red; padding: 20px;">获取失败</p>';
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('viewTemplateContent').innerHTML = '<p style="text-align: center; color: red; padding: 20px;">获取失败：' + error.message + '</p>';
            }
        }
        
        // 编辑模板
        async function editTemplate(id) {
            try {
                const response = await fetch(`api/auto_ad_templates.php?action=get&id=${id}`);
                const result = await response.json();
                
                if (result.success) {
                    const template = result.data;
                    
                    // 填充表单
                    document.getElementById('editTemplateId').value = template.id;
                    document.getElementById('editTemplateName').value = template.template_name;
                    document.getElementById('editTemplateGroupId').value = template.group_id || 0;
                    document.getElementById('editTemplateInterval').value = template.interval_minutes;
                    document.getElementById('editTemplateCycleInterval').value = template.cycle_interval_minutes || 0;
                    document.getElementById('editTemplateDeleteAfter').value = template.delete_after_seconds || 0;
                    
                    // 清空并填充广告列表
                    document.getElementById('editTemplateAdsContainer').innerHTML = '';
                    editTemplateAdCounter = 0;
                    
                    template.ads.forEach(ad => {
                        addEditTemplateAdItem(ad.message, ad.image_url || '', ad.buttons || '');
                    });
                    
                    App.showModal('editTemplateModal');
                } else {
                    App.showAlert('获取模板失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('获取模板失败：' + error.message, 'error');
            }
        }
        
        // 更新模板
        async function updateTemplate() {
            const id = document.getElementById('editTemplateId').value;
            const templateName = document.getElementById('editTemplateName').value;
            const groupId = document.getElementById('editTemplateGroupId').value;
            const interval = document.getElementById('editTemplateInterval').value;
            const cycleInterval = document.getElementById('editTemplateCycleInterval').value || 0;
            const deleteAfter = document.getElementById('editTemplateDeleteAfter').value || 0;
            const ads = getTemplateAds('editTemplateAdsContainer');
            
            if (!templateName || !groupId) {
                App.showAlert('请填写所有必填字段', 'error');
                return;
            }
            
            if (ads.length === 0) {
                App.showAlert('请至少添加一条广告', 'error');
                return;
            }
            
            try {
                // 先上传所有图片
                const adsWithImages = [];
                for (const ad of ads) {
                    let imageUrl = ad.existing_image_url || null;
                    
                    // 如果有新上传的图片，先上传
                    if (ad.image_file) {
                        const formData = new FormData();
                        formData.append('action', 'upload_image');
                        formData.append('image', ad.image_file);
                        
                        const uploadResponse = await fetch('api/auto_ad_templates.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const uploadResult = await uploadResponse.json();
                        
                        if (uploadResult.success) {
                            imageUrl = uploadResult.image_url;
                        } else {
                            App.showAlert('图片上传失败：' + uploadResult.message, 'error');
                            return;
                        }
                    }
                    
                    adsWithImages.push({
                        message: ad.message,
                        image_url: imageUrl,
                        buttons: ad.buttons,
                        delete_after_seconds: deleteAfter
                    });
                }
                
                // 更新模板
                const response = await fetch('api/auto_ad_templates.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update',
                        id: id,
                        template_name: templateName,
                        group_id: groupId,
                        interval_minutes: interval,
                        cycle_interval_minutes: cycleInterval,
                        delete_after_seconds: deleteAfter,
                        ads: adsWithImages
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    App.showAlert('更新成功');
                    App.hideModal('editTemplateModal');
                    loadTemplates();
                } else {
                    App.showAlert(result.message || '更新失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('更新失败：' + error.message, 'error');
            }
        }
        
        // 切换模板状态
        async function toggleTemplate(id, isActive) {
            try {
                const response = await fetch('api/auto_ad_templates.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'toggle',
                        id: id,
                        is_active: isActive
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    App.showAlert('状态更新成功');
                    loadTemplates();
                } else {
                    App.showAlert(result.message || '更新失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('更新失败：' + error.message, 'error');
            }
        }
        
        // 删除模板
        async function deleteTemplate(id) {
            if (!confirm('确定要删除这个模板及其所有广告吗？')) {
                return;
            }
            
            try {
                const response = await fetch('api/auto_ad_templates.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete',
                        id: id
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    App.showAlert('删除成功');
                    loadTemplates();
                } else {
                    App.showAlert(result.message || '删除失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('删除失败：' + error.message, 'error');
            }
        }
        
        // Image preview for ads
        document.addEventListener('DOMContentLoaded', function() {
            // 添加广告图片预览
            const imageInput = document.getElementById('adImage');
            if (imageInput) {
                imageInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    const preview = document.getElementById('adImagePreview');
                    
                    if (file) {
                        // 检查文件大小：GIF最大50MB，其他格式最大5MB
                        const maxSize = file.type === 'image/gif' ? 50 * 1024 * 1024 : 5 * 1024 * 1024;
                        const maxSizeText = file.type === 'image/gif' ? '50MB' : '5MB';
                        
                        if (file.size > maxSize) {
                            App.showAlert('图片大小不能超过 ' + maxSizeText, 'error');
                            e.target.value = '';
                            preview.innerHTML = '';
                            return;
                        }
                        
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            preview.innerHTML = `<img src="${e.target.result}" style="max-width: 200px; max-height: 200px; border: 1px solid #ddd; border-radius: 4px;">`;
                        };
                        reader.readAsDataURL(file);
                    } else {
                        preview.innerHTML = '';
                    }
                });
            }
            
            // 编辑广告图片预览
            const editImageInput = document.getElementById('editAdImage');
            if (editImageInput) {
                editImageInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    const preview = document.getElementById('editAdImagePreview');
                    
                    if (file) {
                        // 检查文件大小：GIF最大50MB，其他格式最大5MB
                        const maxSize = file.type === 'image/gif' ? 50 * 1024 * 1024 : 5 * 1024 * 1024;
                        const maxSizeText = file.type === 'image/gif' ? '50MB' : '5MB';
                        
                        if (file.size > maxSize) {
                            App.showAlert('图片大小不能超过 ' + maxSizeText, 'error');
                            e.target.value = '';
                            preview.innerHTML = '';
                            return;
                        }
                        
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            preview.innerHTML = `<img src="${e.target.result}" style="max-width: 200px; max-height: 200px; border: 1px solid #ddd; border-radius: 4px;">`;
                        };
                        reader.readAsDataURL(file);
                    } else {
                        preview.innerHTML = '';
                    }
                });
            }
        });
        
        // ========== 新版按钮配置（支持每行多按钮和二级菜单）==========
        
        let buttonRowCounter = 0;
        
        // 添加按钮行
        function addButtonRow(containerId, rowData = null) {
            buttonRowCounter++;
            const container = document.getElementById(containerId);
            const rowDiv = document.createElement('div');
            rowDiv.className = 'button-row-wrapper';
            rowDiv.dataset.rowId = buttonRowCounter;
            rowDiv.style.cssText = 'border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 8px; background: #f9f9f9;';
            
            const buttonCount = rowData ? rowData.length : 1;
            
            rowDiv.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-weight: bold; color: #333;">📌 按钮行</span>
                        <label style="display: flex; align-items: center; gap: 5px; margin: 0; font-size: 13px;">
                            每行按钮数量：
                            <select class="form-control row-button-count" style="width: auto; padding: 4px 8px; height: auto;" onchange="updateButtonCount(this)">
                                <option value="1" ${buttonCount === 1 ? 'selected' : ''}>1个</option>
                                <option value="2" ${buttonCount === 2 ? 'selected' : ''}>2个</option>
                                <option value="3" ${buttonCount === 3 ? 'selected' : ''}>3个</option>
                            </select>
                        </label>
                    </div>
                    <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('.button-row-wrapper').remove()">删除行</button>
                </div>
                <div class="row-buttons-container" style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <!-- 按钮会动态添加到这里 -->
                </div>
            `;
            
            container.appendChild(rowDiv);
            
            // 添加初始按钮
            const buttonsContainer = rowDiv.querySelector('.row-buttons-container');
            if (rowData && rowData.length > 0) {
                rowData.forEach(btn => addButtonToRow(buttonsContainer, btn));
            } else {
                addButtonToRow(buttonsContainer, null);
            }
        }
        
        // 更新行内按钮数量
        function updateButtonCount(select) {
            const rowWrapper = select.closest('.button-row-wrapper');
            const container = rowWrapper.querySelector('.row-buttons-container');
            const currentButtons = container.querySelectorAll('.single-button-config');
            const newCount = parseInt(select.value);
            const currentCount = currentButtons.length;
            
            if (newCount > currentCount) {
                // 添加按钮
                for (let i = currentCount; i < newCount; i++) {
                    addButtonToRow(container, null);
                }
            } else if (newCount < currentCount) {
                // 删除多余按钮
                for (let i = currentCount - 1; i >= newCount; i--) {
                    currentButtons[i].remove();
                }
            }
        }
        
        // 添加单个按钮到行
        function addButtonToRow(container, buttonData) {
            const buttonDiv = document.createElement('div');
            buttonDiv.className = 'single-button-config';
            buttonDiv.style.cssText = 'flex: 1; min-width: 200px; background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 12px;';
            
            const text = buttonData?.text || '';
            const url = buttonData?.url || '';
            const hasSubButtons = buttonData?.sub_buttons && buttonData.sub_buttons.length > 0;
            
            buttonDiv.innerHTML = `
                <div style="margin-bottom: 8px;">
                    <input type="text" class="form-control btn-text" placeholder="按钮文字" value="${escapeHtml(text)}" style="font-size: 13px;">
                </div>
                <div style="margin-bottom: 8px;">
                    <input type="url" class="form-control btn-url" placeholder="链接 (如有二级菜单可留空)" value="${escapeHtml(url)}" style="font-size: 13px;">
                </div>
                <div style="border-top: 1px dashed #ddd; padding-top: 10px; margin-top: 10px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 12px; color: #666;">🔽 二级菜单 (可选)</span>
                        <button type="button" class="btn btn-sm btn-secondary" style="padding: 2px 8px; font-size: 11px;" onclick="addSubButton(this)">+ 子按钮</button>
                    </div>
                    <div class="sub-buttons-container" style="display: flex; flex-direction: column; gap: 6px;">
                        <!-- 子按钮会动态添加到这里 -->
                    </div>
                </div>
            `;
            
            container.appendChild(buttonDiv);
            
            // 如果有子按钮数据，添加它们
            if (hasSubButtons) {
                const subContainer = buttonDiv.querySelector('.sub-buttons-container');
                buttonData.sub_buttons.forEach(sub => addSubButtonWithData(subContainer, sub));
            }
        }
        
        // 添加子按钮
        function addSubButton(btn) {
            const container = btn.closest('.single-button-config').querySelector('.sub-buttons-container');
            addSubButtonWithData(container, null);
        }
        
        // 添加子按钮（带数据）
        function addSubButtonWithData(container, subData) {
            const subDiv = document.createElement('div');
            subDiv.className = 'sub-button-item';
            subDiv.style.cssText = 'display: flex; gap: 6px; align-items: center; background: #f5f5f5; padding: 6px; border-radius: 4px;';
            
            const text = subData?.text || '';
            const url = subData?.url || '';
            
            subDiv.innerHTML = `
                <input type="text" class="form-control sub-btn-text" placeholder="子按钮文字" value="${escapeHtml(text)}" style="flex: 1; font-size: 12px; padding: 4px 8px;">
                <input type="url" class="form-control sub-btn-url" placeholder="链接" value="${escapeHtml(url)}" style="flex: 1; font-size: 12px; padding: 4px 8px;">
                <button type="button" class="btn btn-sm btn-danger" style="padding: 2px 6px; font-size: 10px;" onclick="this.parentElement.remove()">×</button>
            `;
            
            container.appendChild(subDiv);
        }
        
        // HTML转义
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // 获取按钮配置数据
        function getButtonsData(containerId) {
            const container = document.getElementById(containerId);
            const rows = container.querySelectorAll('.button-row-wrapper');
            const buttonsData = [];
            
            rows.forEach(row => {
                const rowButtons = [];
                const buttonConfigs = row.querySelectorAll('.single-button-config');
                
                buttonConfigs.forEach(config => {
                    const text = config.querySelector('.btn-text').value.trim();
                    const url = config.querySelector('.btn-url').value.trim();
                    
                    // 获取子按钮
                    const subButtons = [];
                    const subItems = config.querySelectorAll('.sub-button-item');
                    subItems.forEach(sub => {
                        const subText = sub.querySelector('.sub-btn-text').value.trim();
                        const subUrl = sub.querySelector('.sub-btn-url').value.trim();
                        if (subText && subUrl) {
                            subButtons.push({ text: subText, url: subUrl });
                        }
                    });
                    
                    // 只有文字不为空时才添加
                    if (text) {
                        const buttonObj = { text };
                        if (url) buttonObj.url = url;
                        if (subButtons.length > 0) buttonObj.sub_buttons = subButtons;
                        rowButtons.push(buttonObj);
                    }
                });
                
                if (rowButtons.length > 0) {
                    buttonsData.push(rowButtons);
                }
            });
            
            return buttonsData;
        }
        
        // 从数据加载按钮配置
        function loadButtonsFromData(containerId, buttonsData) {
            const container = document.getElementById(containerId);
            container.innerHTML = '';
            buttonRowCounter = 0;
            
            if (!buttonsData || !Array.isArray(buttonsData)) return;
            
            buttonsData.forEach(rowData => {
                if (Array.isArray(rowData)) {
                    addButtonRow(containerId, rowData);
                }
            });
        }
        
        // 兼容旧版本：从旧格式按钮数据加载
        function loadButtonsFromLegacyData(containerId, buttons) {
            const container = document.getElementById(containerId);
            container.innerHTML = '';
            buttonRowCounter = 0;
            
            if (!buttons || !Array.isArray(buttons)) return;
            
            // 旧格式：每个按钮一行
            buttons.forEach(btn => {
                addButtonRow(containerId, [btn]);
            });
        }
        
        // 获取按钮用于API（保持兼容性）
        function getAdButtons() {
            return getButtonsData('adButtonsContainer');
        }
        
        function getEditAdButtons() {
            return getButtonsData('editAdButtonsContainer');
        }
        
        // Edit ad function
        async function editAd(id) {
            try {
                // Fetch ad data
                const response = await fetch(`api/auto_ads.php?action=get&id=${id}`);
                const result = await response.json();
                
                if (result && result.success) {
                    const ad = result.data;
                    
                    // Fill form
                    document.getElementById('editAdId').value = ad.id;
                    document.getElementById('editAdGroupId').value = ad.group_id;
                    document.getElementById('editAdMessage').value = ad.message;
                    
                    // 处理关键词 - 从JSON数组转回文本格式
                    let keywordsText = '';
                    if (ad.keywords) {
                        try {
                            const keywordsArray = JSON.parse(ad.keywords);
                            if (Array.isArray(keywordsArray)) {
                                keywordsText = keywordsArray.join('\n');
                            }
                        } catch(e) {
                            keywordsText = ad.keywords;
                        }
                    }
                    document.getElementById('editAdKeywords').value = keywordsText;
                    document.getElementById('editAdKeywordsPerSend').value = ad.keywords_per_send || 3;
                    // 更新关键词计数
                    updateKeywordsCount('editAdKeywords', 'editAdKeywordsCount');
                    
                    document.getElementById('editAdInterval').value = ad.interval_minutes;
                    document.getElementById('editAdDeleteAfter').value = ad.delete_after_seconds || 0;
                    
                    // Show current image
                    const currentImageDiv = document.getElementById('editCurrentImage');
                    if (ad.image_url) {
                        currentImageDiv.innerHTML = `<img src="${ad.image_url}" style="max-width: 200px; max-height: 200px; border: 1px solid #ddd; border-radius: 4px; margin-top: 5px;">`;
                    } else {
                        currentImageDiv.innerHTML = '<p style="color: #999;">无图片</p>';
                    }
                    
                    // Clear and populate buttons
                    const buttonsContainer = document.getElementById('editAdButtonsContainer');
                    buttonsContainer.innerHTML = '';
                    buttonRowCounter = 0;
                    
                    if (ad.buttons) {
                        try {
                            const buttons = JSON.parse(ad.buttons);
                            // 检查是否为新格式（二维数组）
                            if (Array.isArray(buttons) && buttons.length > 0) {
                                if (Array.isArray(buttons[0])) {
                                    // 新格式：每个元素是一行按钮数组
                                    loadButtonsFromData('editAdButtonsContainer', buttons);
                                } else {
                                    // 旧格式：每个元素是一个按钮对象
                                    loadButtonsFromLegacyData('editAdButtonsContainer', buttons);
                                }
                            }
                        } catch (e) {
                            console.error('Error parsing buttons:', e);
                        }
                    }
                    
                    // Clear image preview
                    document.getElementById('editAdImagePreview').innerHTML = '';
                    document.getElementById('editAdImage').value = '';
                    
                    // Show modal
                    App.showModal('editAdModal');
                } else {
                    App.showAlert('获取广告信息失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('获取广告信息失败：' + error.message, 'error');
            }
        }
        
        // Update ad function
        async function updateAd() {
            const id = document.getElementById('editAdId').value;
            const groupId = document.getElementById('editAdGroupId').value;
            const message = document.getElementById('editAdMessage').value;
            const keywords = document.getElementById('editAdKeywords')?.value || '';
            const keywordsPerSend = document.getElementById('editAdKeywordsPerSend')?.value || 3;
            const imageFile = document.getElementById('editAdImage')?.files[0];
            const interval = document.getElementById('editAdInterval').value;
            const deleteAfter = document.getElementById('editAdDeleteAfter')?.value || 0;
            const buttons = getEditAdButtons();
            
            // Use FormData for file upload
            const formData = new FormData();
            formData.append('action', 'update');
            formData.append('id', id);
            formData.append('group_id', groupId);
            formData.append('message', message);
            formData.append('keywords', keywords);
            formData.append('keywords_per_send', keywordsPerSend);
            formData.append('interval_minutes', interval);
            formData.append('delete_after_seconds', deleteAfter);
            if (buttons.length > 0) formData.append('buttons', JSON.stringify(buttons));
            if (imageFile) formData.append('image', imageFile);
            
            try {
                const response = await fetch('api/auto_ads.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result && result.success) {
                    App.showAlert('更新成功');
                    App.hideModal('editAdModal');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    App.showAlert(result.message || '更新失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('更新失败：' + error.message, 'error');
            }
        }
    </script>
</body>
</html>

