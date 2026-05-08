<?php
require_once 'config.php';
checkLogin();

$db = getDB();

// 获取所有分类
$categories = [];
try {
    $stmt = $db->query("SELECT * FROM video_categories WHERE is_active = 1 ORDER BY sort_order, name");
    $categories = $stmt->fetchAll();
} catch (Exception $e) {
    // 表可能不存在
}

// 获取所有群组（用于选择采集源和转发目标）
$groups = [];
try {
    $stmt = $db->query("SELECT id, chat_id, title FROM groups WHERE is_active = 1 AND is_deleted = 0 ORDER BY title");
    $groups = $stmt->fetchAll();
} catch (Exception $e) {
    // 表可能不存在
}

// 获取群组分类（用于选择转发目标分类）
$groupCategories = [];
try {
    $stmt = $db->query("SELECT gc.*, COUNT(g.id) as group_count 
        FROM group_categories gc 
        LEFT JOIN groups g ON g.category_id = gc.id AND g.is_active = 1 AND g.is_deleted = 0
        WHERE gc.is_active = 1 
        GROUP BY gc.id 
        ORDER BY gc.sort_order, gc.name");
    $groupCategories = $stmt->fetchAll();
} catch (Exception $e) {
    // 表可能不存在
}

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>视频数据库 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        .tab-btn { padding: 10px 20px; border: none; background: #f5f5f5; cursor: pointer; border-radius: 4px 4px 0 0; }
        .tab-btn.active { background: #007bff; color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .category-tag { display: inline-block; padding: 2px 8px; background: #e3f2fd; border-radius: 12px; font-size: 12px; margin-right: 5px; }
        .batch-upload-area { border: 2px dashed #ccc; padding: 40px; text-align: center; border-radius: 8px; margin-bottom: 20px; transition: all 0.3s; }
        .batch-upload-area:hover, .batch-upload-area.dragover { border-color: #007bff; background: #f8f9ff; }
        .upload-progress { margin-top: 20px; }
        .progress-item { display: flex; align-items: center; gap: 10px; padding: 8px; background: #f5f5f5; border-radius: 4px; margin-bottom: 5px; }
        .progress-bar { flex: 1; height: 20px; background: #e0e0e0; border-radius: 10px; overflow: hidden; }
        .progress-fill { height: 100%; background: #4caf50; transition: width 0.3s; }
        .progress-status { width: 80px; text-align: right; font-size: 12px; }
        .filter-bar { display: flex; gap: 15px; margin-bottom: 20px; align-items: center; flex-wrap: wrap; }
        .filter-bar select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>📹 视频数据库管理</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <!-- 选项卡 -->
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('videos')">📹 视频列表</button>
                <button class="tab-btn" onclick="switchTab('categories')">📁 分类管理</button>
                <button class="tab-btn" onclick="switchTab('upload')">⬆️ 批量上传</button>
                <button class="tab-btn" onclick="switchTab('collect')">🔗 视频采集</button>
            </div>

            <!-- 视频列表 -->
            <div id="tab-videos" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h2>视频列表</h2>
                        <button class="btn btn-primary" onclick="App.showModal('addVideoModal')">+ 添加视频</button>
                    </div>
                    <div class="card-body">
                        <div class="filter-bar">
                            <label>筛选分类：</label>
                            <select id="filterCategory" onchange="Videos.load()">
                                <option value="">全部分类</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo escape($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAll" onchange="Videos.selectAll()"></th>
                                        <th>ID</th>
                                        <th>视频名称</th>
                                        <th>分类</th>
                                        <th>关键词</th>
                                        <th>状态</th>
                                        <th>搜索次数</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody id="videosTable">
                                    <tr><td colspan="8" class="empty-state">加载中...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div style="margin-top: 15px;">
                            <button class="btn btn-sm btn-danger" onclick="Videos.batchDelete()">🗑️ 批量删除</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 分类管理 -->
            <div id="tab-categories" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2>分类管理</h2>
                        <button class="btn btn-primary" onclick="App.showModal('addCategoryModal')">+ 添加分类</button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>分类名称</th>
                                        <th>描述</th>
                                        <th>视频数量</th>
                                        <th>排序</th>
                                        <th>状态</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody id="categoriesTable">
                                    <tr><td colspan="7" class="empty-state">加载中...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 批量上传 -->
            <div id="tab-upload" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2>批量上传视频</h2>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label>选择分类 *</label>
                            <select id="batchCategory" class="form-control" required>
                                <option value="">选择分类...</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo escape($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>默认关键词（可选）</label>
                            <input type="text" id="batchKeywords" class="form-control" placeholder="所有视频共用的关键词，用逗号分隔">
                            <small class="form-text">上传时会自动以文件名作为关键词，此处可添加额外关键词</small>
                        </div>
                        
                        <div class="batch-upload-area" id="dropZone" onclick="document.getElementById('batchFiles').click()">
                            <h3>📁 点击或拖拽视频文件到这里</h3>
                            <p>支持MP4、AVI、MOV等格式，可同时选择多个文件</p>
                            <input type="file" id="batchFiles" multiple accept="video/*" style="display:none" onchange="BatchUpload.filesSelected(this.files)">
                        </div>
                        
                        <div id="uploadProgress" class="upload-progress" style="display:none;">
                            <h4>上传进度</h4>
                            <div id="progressList"></div>
                            <div style="margin-top: 15px;">
                                <span id="uploadStats">准备上传...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 视频采集 -->
            <div id="tab-collect" class="tab-content">
                <div class="alert alert-info">
                    <strong>💡 视频采集说明：</strong>
                    <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                        <li>机器人需要是目标群组的成员才能采集视频</li>
                        <li>采集到的视频会保存file_id和消息链接，可用于后续发送</li>
                        <li>开启自动转发后，采集到的视频会自动转发到指定群组</li>
                        <li>用户可以通过关键词搜索获取采集到的视频</li>
                    </ul>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h2>采集源管理</h2>
                        <div>
                            <button class="btn btn-primary" onclick="App.showModal('addSourceModal')">+ 添加采集源</button>
                            <button class="btn btn-secondary" onclick="App.showModal('selectGroupModal')">📋 从群组添加</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>群组/频道</th>
                                        <th>Chat ID</th>
                                        <th>归类到</th>
                                        <th>已采集</th>
                                        <th>自动转发</th>
                                        <th>状态</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody id="sourcesTable">
                                    <tr><td colspan="8" class="empty-state">加载中...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<!-- 添加视频模态框 -->
<div id="addVideoModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>添加视频</h3>
        </div>
        <form onsubmit="event.preventDefault(); Videos.add();">
            <div class="form-group">
                <label>视频分类 *</label>
                <select id="videoCategory" class="form-control" required>
                    <option value="">选择分类...</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>"><?php echo escape($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>视频名称 *</label>
                <input type="text" id="videoTitle" class="form-control" required placeholder="输入视频名称">
            </div>
            
            <div class="form-group">
                <label>搜索关键词 *</label>
                <textarea id="videoKeywords" class="form-control" rows="2" placeholder="输入搜索关键词，用逗号、空格或换行分隔" required></textarea>
                <small class="form-text">用户发送关键词即可搜索到此视频</small>
            </div>
            
            <div class="form-group">
                <label>视频文案（可选）</label>
                <textarea id="videoCaption" class="form-control" rows="2" placeholder="发送视频时附带的文案"></textarea>
            </div>
            
            <div class="form-group">
                <label>上传方式 *</label>
                <select id="uploadType" class="form-control" onchange="toggleUploadMethod()">
                    <option value="file">📁 上传本地文件</option>
                    <option value="url">🔗 指定外部链接</option>
                </select>
            </div>
            
            <div class="form-group" id="fileUploadGroup">
                <label>选择视频文件 *</label>
                <input type="file" id="videoFile" class="form-control" accept="video/*">
            </div>
            
            <div class="form-group" id="urlInputGroup" style="display:none;">
                <label>外部链接 *</label>
                <input type="text" id="videoPath" class="form-control" placeholder="输入视频链接或Telegram消息链接">
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('addVideoModal')">取消</button>
                <button type="submit" class="btn btn-sm btn-success">保存</button>
            </div>
        </form>
    </div>
</div>

<!-- 编辑视频模态框 -->
<div id="editVideoModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>编辑视频</h3>
        </div>
        <form onsubmit="event.preventDefault(); Videos.update();">
            <input type="hidden" id="editVideoId">
            
            <div class="form-group">
                <label>视频分类 *</label>
                <select id="editVideoCategory" class="form-control" required>
                    <option value="">选择分类...</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>"><?php echo escape($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>视频名称 *</label>
                <input type="text" id="editVideoTitle" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label>搜索关键词 *</label>
                <textarea id="editVideoKeywords" class="form-control" rows="2" required></textarea>
            </div>
            
            <div class="form-group">
                <label>视频文案</label>
                <textarea id="editVideoCaption" class="form-control" rows="2"></textarea>
            </div>
            
            <div class="form-group">
                <label>当前视频</label>
                <div id="currentVideoInfo" style="padding: 10px; background: #f5f5f5; border-radius: 4px;"></div>
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" id="editChangeVideo" onchange="toggleEditVideoUpload()" style="margin-right: 5px;">
                    更换视频文件
                </label>
            </div>
            
            <div id="editVideoUploadGroup" style="display:none;">
                <div class="form-group">
                    <label>上传方式</label>
                    <select id="editUploadType" class="form-control" onchange="toggleEditUploadMethod()">
                        <option value="file">📁 上传本地文件</option>
                        <option value="url">🔗 指定外部链接</option>
                    </select>
                </div>
                
                <div class="form-group" id="editFileUploadGroup">
                    <input type="file" id="editVideoFile" class="form-control" accept="video/*">
                </div>
                
                <div class="form-group" id="editUrlInputGroup" style="display:none;">
                    <input type="text" id="editVideoPath" class="form-control" placeholder="输入视频链接">
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('editVideoModal')">取消</button>
                <button type="submit" class="btn btn-sm btn-success">保存</button>
            </div>
        </form>
    </div>
</div>

<!-- 添加分类模态框 -->
<div id="addCategoryModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>添加分类</h3>
        </div>
        <form onsubmit="event.preventDefault(); Categories.add();">
            <div class="form-group">
                <label>分类名称 *</label>
                <input type="text" id="categoryName" class="form-control" required placeholder="输入分类名称">
            </div>
            
            <div class="form-group">
                <label>分类描述</label>
                <input type="text" id="categoryDesc" class="form-control" placeholder="可选的分类描述">
            </div>
            
            <div class="form-group">
                <label>排序顺序</label>
                <input type="number" id="categorySortOrder" class="form-control" value="0" min="0">
                <small class="form-text">数字越小排序越靠前</small>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('addCategoryModal')">取消</button>
                <button type="submit" class="btn btn-sm btn-success">保存</button>
            </div>
        </form>
    </div>
</div>

<!-- 编辑分类模态框 -->
<div id="editCategoryModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>编辑分类</h3>
        </div>
        <form onsubmit="event.preventDefault(); Categories.update();">
            <input type="hidden" id="editCategoryId">
            
            <div class="form-group">
                <label>分类名称 *</label>
                <input type="text" id="editCategoryName" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label>分类描述</label>
                <input type="text" id="editCategoryDesc" class="form-control">
            </div>
            
            <div class="form-group">
                <label>排序顺序</label>
                <input type="number" id="editCategorySortOrder" class="form-control" min="0">
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('editCategoryModal')">取消</button>
                <button type="submit" class="btn btn-sm btn-success">保存</button>
            </div>
        </form>
    </div>
</div>

<!-- 添加采集源模态框 -->
<div id="addSourceModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>添加采集源</h3>
        </div>
        <form onsubmit="event.preventDefault(); Sources.add();">
            <div class="form-group">
                <label>群组/频道 Chat ID *</label>
                <input type="text" id="sourceChatId" class="form-control" required placeholder="例如：-1001234567890">
                <small class="form-text">超级群组ID通常以 -100 开头，可以从群组管理页面复制</small>
            </div>
            
            <div class="form-group">
                <label>名称备注</label>
                <input type="text" id="sourceChatTitle" class="form-control" placeholder="方便识别的名称">
            </div>
            
            <div class="form-group">
                <label>归类到分类</label>
                <select id="sourceCategoryId" class="form-control">
                    <option value="">不分类</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>"><?php echo escape($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text">采集到的视频自动归类到指定分类</small>
            </div>
            
            <div class="form-group">
                <label>默认关键词</label>
                <input type="text" id="sourceDefaultKeywords" class="form-control" placeholder="多个关键词用逗号分隔">
                <small class="form-text">采集视频时自动添加这些关键词</small>
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" id="sourceAutoForward" onchange="toggleForwardSettings()">
                    启用自动转发
                </label>
                <small class="form-text">采集到视频后自动转发到指定群组或分类下的所有群组</small>
            </div>
            
            <div class="form-group" id="forwardSettingsGroup" style="display:none;">
                <label>转发方式</label>
                <select id="sourceForwardType" class="form-control" onchange="toggleForwardTypeSettings()">
                    <option value="category">转发到群组分类（批量）</option>
                    <option value="single">转发到单个群组</option>
                </select>
            </div>
            
            <div class="form-group" id="forwardCategoryGroup" style="display:none;">
                <label>选择群组分类</label>
                <select id="sourceForwardToCategory" class="form-control">
                    <option value="">选择目标分类...</option>
                    <?php foreach ($groupCategories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" style="color: <?php echo $cat['color']; ?>">
                        <?php echo escape($cat['name']); ?> (<?php echo $cat['group_count']; ?>个群)
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text">采集到的视频会转发到该分类下的所有群组</small>
            </div>
            
            <div class="form-group" id="forwardSingleGroup" style="display:none;">
                <label>转发到群组</label>
                <select id="sourceForwardTo" class="form-control">
                    <option value="">选择目标群组...</option>
                    <?php foreach ($groups as $group): ?>
                    <option value="<?php echo $group['chat_id']; ?>"><?php echo escape($group['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('addSourceModal')">取消</button>
                <button type="submit" class="btn btn-sm btn-success">添加</button>
            </div>
        </form>
    </div>
</div>

<!-- 编辑采集源模态框 -->
<div id="editSourceModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>编辑采集源</h3>
        </div>
        <form onsubmit="event.preventDefault(); Sources.update();">
            <input type="hidden" id="editSourceId">
            
            <div class="form-group">
                <label>Chat ID</label>
                <input type="text" id="editSourceChatId" class="form-control" readonly>
            </div>
            
            <div class="form-group">
                <label>名称备注</label>
                <input type="text" id="editSourceChatTitle" class="form-control">
            </div>
            
            <div class="form-group">
                <label>归类到分类</label>
                <select id="editSourceCategoryId" class="form-control">
                    <option value="">不分类</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>"><?php echo escape($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>默认关键词</label>
                <input type="text" id="editSourceDefaultKeywords" class="form-control">
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" id="editSourceAutoForward" onchange="toggleEditForwardSettings()">
                    启用自动转发
                </label>
            </div>
            
            <div class="form-group" id="editForwardSettingsGroup" style="display:none;">
                <label>转发方式</label>
                <select id="editSourceForwardType" class="form-control" onchange="toggleEditForwardTypeSettings()">
                    <option value="category">转发到群组分类（批量）</option>
                    <option value="single">转发到单个群组</option>
                </select>
            </div>
            
            <div class="form-group" id="editForwardCategoryGroup" style="display:none;">
                <label>选择群组分类</label>
                <select id="editSourceForwardToCategory" class="form-control">
                    <option value="">选择目标分类...</option>
                    <?php foreach ($groupCategories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" style="color: <?php echo $cat['color']; ?>">
                        <?php echo escape($cat['name']); ?> (<?php echo $cat['group_count']; ?>个群)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group" id="editForwardSingleGroup" style="display:none;">
                <label>转发到群组</label>
                <select id="editSourceForwardTo" class="form-control">
                    <option value="">选择目标群组...</option>
                    <?php foreach ($groups as $group): ?>
                    <option value="<?php echo $group['chat_id']; ?>"><?php echo escape($group['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('editSourceModal')">取消</button>
                <button type="submit" class="btn btn-sm btn-success">保存</button>
            </div>
        </form>
    </div>
</div>

<!-- 从群组选择模态框 -->
<div id="selectGroupModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>从群组添加采集源</h3>
        </div>
        <form onsubmit="event.preventDefault(); Sources.addFromGroup();">
            <div class="form-group">
                <label>选择群组 *</label>
                <select id="selectGroupId" class="form-control" required>
                    <option value="">选择群组...</option>
                    <?php foreach ($groups as $group): ?>
                    <option value="<?php echo $group['id']; ?>"><?php echo escape($group['title']); ?> (<?php echo $group['chat_id']; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>归类到分类</label>
                <select id="selectGroupCategoryId" class="form-control">
                    <option value="">不分类</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>"><?php echo escape($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('selectGroupModal')">取消</button>
                <button type="submit" class="btn btn-sm btn-success">添加</button>
            </div>
        </form>
    </div>
</div>

<script>
// 切换选项卡
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    
    document.querySelector(`[onclick="switchTab('${tab}')"]`).classList.add('active');
    document.getElementById('tab-' + tab).classList.add('active');
    
    if (tab === 'categories') Categories.load();
    if (tab === 'videos') Videos.load();
    if (tab === 'collect') Sources.load();
}

// 切换转发设置显示
function toggleForwardSettings() {
    const isChecked = document.getElementById('sourceAutoForward').checked;
    document.getElementById('forwardSettingsGroup').style.display = isChecked ? 'block' : 'none';
    if (isChecked) {
        toggleForwardTypeSettings();
    } else {
        document.getElementById('forwardCategoryGroup').style.display = 'none';
        document.getElementById('forwardSingleGroup').style.display = 'none';
    }
}

function toggleForwardTypeSettings() {
    const forwardType = document.getElementById('sourceForwardType').value;
    document.getElementById('forwardCategoryGroup').style.display = forwardType === 'category' ? 'block' : 'none';
    document.getElementById('forwardSingleGroup').style.display = forwardType === 'single' ? 'block' : 'none';
}

function toggleEditForwardSettings() {
    const isChecked = document.getElementById('editSourceAutoForward').checked;
    document.getElementById('editForwardSettingsGroup').style.display = isChecked ? 'block' : 'none';
    if (isChecked) {
        toggleEditForwardTypeSettings();
    } else {
        document.getElementById('editForwardCategoryGroup').style.display = 'none';
        document.getElementById('editForwardSingleGroup').style.display = 'none';
    }
}

function toggleEditForwardTypeSettings() {
    const forwardType = document.getElementById('editSourceForwardType').value;
    document.getElementById('editForwardCategoryGroup').style.display = forwardType === 'category' ? 'block' : 'none';
    document.getElementById('editForwardSingleGroup').style.display = forwardType === 'single' ? 'block' : 'none';
}

// 视频管理
const Videos = {
    selectedIds: [],
    
    async load() {
        const categoryId = document.getElementById('filterCategory').value;
        let url = 'api/videos.php?action=list';
        if (categoryId) url += '&category_id=' + categoryId;
        
        const result = await App.request(url);
        if (result && result.success) {
            this.render(result.data);
        }
    },
    
    render(videos) {
        const tbody = document.getElementById('videosTable');
        if (!videos || videos.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="empty-state">暂无视频</td></tr>';
            return;
        }
        
        tbody.innerHTML = videos.map(video => {
            const statusBadge = video.is_active ? 
                '<span class="badge badge-success">启用</span>' : 
                '<span class="badge badge-secondary">禁用</span>';
            
            let keywordsDisplay = '-';
            if (video.keywords) {
                const kwArr = video.keywords.split(',').map(k => k.trim()).filter(k => k);
                keywordsDisplay = kwArr.length > 3 ? kwArr.slice(0, 3).join(', ') + '...' : kwArr.join(', ');
            }
            
            return `
                <tr>
                    <td><input type="checkbox" class="video-checkbox" value="${video.id}" onchange="Videos.updateSelection()"></td>
                    <td>${video.id}</td>
                    <td><strong>${video.title}</strong></td>
                    <td><span class="category-tag">${video.category_name || '未分类'}</span></td>
                    <td><span style="color:#666">${keywordsDisplay}</span></td>
                    <td>${statusBadge}</td>
                    <td>${video.view_count || 0}</td>
                    <td>
                        <button class="btn btn-sm btn-primary" onclick="Videos.edit(${video.id})">编辑</button>
                        <button class="btn btn-sm btn-${video.is_active ? 'warning' : 'success'}" 
                                onclick="Videos.toggle(${video.id}, ${video.is_active})">
                            ${video.is_active ? '禁用' : '启用'}
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="Videos.delete(${video.id})">删除</button>
                    </td>
                </tr>
            `;
        }).join('');
    },
    
    selectAll() {
        const checked = document.getElementById('selectAll').checked;
        document.querySelectorAll('.video-checkbox').forEach(cb => cb.checked = checked);
        this.updateSelection();
    },
    
    updateSelection() {
        this.selectedIds = Array.from(document.querySelectorAll('.video-checkbox:checked')).map(cb => cb.value);
    },
    
    async add() {
        const categoryId = document.getElementById('videoCategory').value;
        const title = document.getElementById('videoTitle').value;
        const keywords = document.getElementById('videoKeywords').value;
        const caption = document.getElementById('videoCaption').value;
        const uploadType = document.getElementById('uploadType').value;
        
        if (!categoryId) { App.showAlert('请选择分类', 'error'); return; }
        if (!title) { App.showAlert('请填写视频名称', 'error'); return; }
        if (!keywords.trim()) { App.showAlert('请填写搜索关键词', 'error'); return; }
        
        const formData = new FormData();
        formData.append('action', 'add');
        formData.append('category_id', categoryId);
        formData.append('title', title);
        formData.append('keywords', keywords);
        formData.append('caption', caption);
        formData.append('upload_type', uploadType);
        
        if (uploadType === 'file') {
            const fileInput = document.getElementById('videoFile');
            if (!fileInput.files[0]) { App.showAlert('请选择视频文件', 'error'); return; }
            formData.append('video', fileInput.files[0]);
        } else {
            const path = document.getElementById('videoPath').value;
            if (!path) { App.showAlert('请输入视频链接', 'error'); return; }
            formData.append('video_url', path);
        }
        
        try {
            const response = await fetch('api/videos.php', { method: 'POST', body: formData });
            const result = await response.json();
            
            if (result.success) {
                App.showAlert('添加成功');
                App.hideModal('addVideoModal');
                this.load();
                document.getElementById('videoTitle').value = '';
                document.getElementById('videoKeywords').value = '';
                document.getElementById('videoCaption').value = '';
                document.getElementById('videoFile').value = '';
            } else {
                App.showAlert(result.message || '添加失败', 'error');
            }
        } catch (error) {
            App.showAlert('添加失败：' + error.message, 'error');
        }
    },
    
    async edit(id) {
        const result = await App.request('api/videos.php?action=get&id=' + id);
        if (result && result.success) {
            const video = result.data;
            document.getElementById('editVideoId').value = video.id;
            document.getElementById('editVideoCategory').value = video.category_id || '';
            document.getElementById('editVideoTitle').value = video.title || '';
            document.getElementById('editVideoKeywords').value = video.keywords || '';
            document.getElementById('editVideoCaption').value = video.caption || '';
            
            let videoInfo = video.video_path ? 
                (video.video_path.match(/^https?:\/\//) ? '🔗 ' + video.video_path : '📁 ' + video.video_path) : 
                (video.telegram_file_id ? '📱 已缓存' : '⚠️ 无视频');
            document.getElementById('currentVideoInfo').innerHTML = videoInfo;
            
            document.getElementById('editChangeVideo').checked = false;
            document.getElementById('editVideoUploadGroup').style.display = 'none';
            
            App.showModal('editVideoModal');
        }
    },
    
    async update() {
        const id = document.getElementById('editVideoId').value;
        const categoryId = document.getElementById('editVideoCategory').value;
        const title = document.getElementById('editVideoTitle').value;
        const keywords = document.getElementById('editVideoKeywords').value;
        const caption = document.getElementById('editVideoCaption').value;
        const changeVideo = document.getElementById('editChangeVideo').checked;
        
        if (!title) { App.showAlert('请填写视频名称', 'error'); return; }
        if (!keywords.trim()) { App.showAlert('请填写搜索关键词', 'error'); return; }
        
        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('id', id);
        formData.append('category_id', categoryId);
        formData.append('title', title);
        formData.append('keywords', keywords);
        formData.append('caption', caption);
        
        if (changeVideo) {
            const uploadType = document.getElementById('editUploadType').value;
            formData.append('change_video', '1');
            formData.append('upload_type', uploadType);
            
            if (uploadType === 'file') {
                const fileInput = document.getElementById('editVideoFile');
                if (fileInput.files[0]) formData.append('video', fileInput.files[0]);
            } else {
                formData.append('video_url', document.getElementById('editVideoPath').value);
            }
        }
        
        try {
            const response = await fetch('api/videos.php', { method: 'POST', body: formData });
            const result = await response.json();
            
            if (result.success) {
                App.showAlert('更新成功');
                App.hideModal('editVideoModal');
                this.load();
            } else {
                App.showAlert(result.message || '更新失败', 'error');
            }
        } catch (error) {
            App.showAlert('更新失败：' + error.message, 'error');
        }
    },
    
    async delete(id) {
        if (!confirm('确定要删除这个视频吗？')) return;
        
        const result = await App.request('api/videos.php', 'POST', { action: 'delete', id: id });
        if (result && result.success) {
            App.showAlert('删除成功');
            this.load();
        }
    },
    
    async toggle(id, currentStatus) {
        const result = await App.request('api/videos.php', 'POST', {
            action: 'toggle', id: id, is_active: currentStatus ? 0 : 1
        });
        if (result && result.success) {
            App.showAlert('状态更新成功');
            this.load();
        }
    },
    
    async batchDelete() {
        if (this.selectedIds.length === 0) {
            App.showAlert('请先选择要删除的视频', 'error');
            return;
        }
        if (!confirm(`确定要删除选中的 ${this.selectedIds.length} 个视频吗？`)) return;
        
        const result = await App.request('api/videos.php', 'POST', {
            action: 'batch_delete', ids: this.selectedIds
        });
        if (result && result.success) {
            App.showAlert('批量删除成功');
            this.selectedIds = [];
            document.getElementById('selectAll').checked = false;
            this.load();
        }
    }
};

// 采集源管理
const Sources = {
    async load() {
        const result = await App.request('api/videos.php?action=list_sources');
        if (result && result.success) {
            this.render(result.data);
        }
    },
    
    render(sources) {
        const tbody = document.getElementById('sourcesTable');
        if (!sources || sources.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="empty-state">暂无采集源，点击"添加采集源"开始</td></tr>';
            return;
        }
        
        tbody.innerHTML = sources.map(source => {
            const statusBadge = source.is_active ? 
                '<span class="badge badge-success">采集中</span>' : 
                '<span class="badge badge-secondary">已暂停</span>';
            
            let forwardInfo = '<span class="badge badge-secondary">关闭</span>';
            if (source.auto_forward) {
                if (source.forward_to_category_id && source.forward_to_category_name) {
                    forwardInfo = `<span class="badge" style="background:${source.forward_to_category_color || '#17a2b8'};color:#fff">📁 ${source.forward_to_category_name}</span>`;
                } else if (source.forward_to_chat_id) {
                    forwardInfo = `<span class="badge badge-info">→ ${source.forward_to_title || source.forward_to_chat_id}</span>`;
                } else {
                    forwardInfo = '<span class="badge badge-warning">未设置目标</span>';
                }
            }
            
            return `
                <tr>
                    <td>${source.id}</td>
                    <td><strong>${source.chat_title || '未命名'}</strong></td>
                    <td><code style="font-size:11px">${source.chat_id}</code></td>
                    <td>${source.category_name ? '<span class="category-tag">' + source.category_name + '</span>' : '-'}</td>
                    <td><strong>${source.collected_count || 0}</strong> 个</td>
                    <td>${forwardInfo}</td>
                    <td>${statusBadge}</td>
                    <td>
                        <button class="btn btn-sm btn-primary" onclick="Sources.edit(${source.id})">编辑</button>
                        <button class="btn btn-sm btn-${source.is_active ? 'warning' : 'success'}" 
                                onclick="Sources.toggle(${source.id}, ${source.is_active})">
                            ${source.is_active ? '暂停' : '启用'}
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="Sources.delete(${source.id})">删除</button>
                    </td>
                </tr>
            `;
        }).join('');
    },
    
    async add() {
        const chatId = document.getElementById('sourceChatId').value;
        const chatTitle = document.getElementById('sourceChatTitle').value;
        const categoryId = document.getElementById('sourceCategoryId').value;
        const defaultKeywords = document.getElementById('sourceDefaultKeywords').value;
        const autoForward = document.getElementById('sourceAutoForward').checked ? 1 : 0;
        const forwardType = document.getElementById('sourceForwardType').value;
        const forwardToCategory = document.getElementById('sourceForwardToCategory').value;
        const forwardTo = document.getElementById('sourceForwardTo').value;
        
        if (!chatId) {
            App.showAlert('请输入Chat ID', 'error');
            return;
        }
        
        const result = await App.request('api/videos.php', 'POST', {
            action: 'add_source',
            chat_id: chatId,
            chat_title: chatTitle,
            category_id: categoryId || null,
            default_keywords: defaultKeywords,
            auto_forward: autoForward,
            forward_to_chat_id: forwardType === 'single' ? (forwardTo || null) : null,
            forward_to_category_id: forwardType === 'category' ? (forwardToCategory || null) : null
        });
        
        if (result && result.success) {
            App.showAlert('添加成功');
            App.hideModal('addSourceModal');
            this.load();
            // 清空表单
            document.getElementById('sourceChatId').value = '';
            document.getElementById('sourceChatTitle').value = '';
            document.getElementById('sourceCategoryId').value = '';
            document.getElementById('sourceDefaultKeywords').value = '';
            document.getElementById('sourceAutoForward').checked = false;
            document.getElementById('sourceForwardType').value = 'category';
            document.getElementById('sourceForwardToCategory').value = '';
            document.getElementById('sourceForwardTo').value = '';
            toggleForwardSettings();
        }
    },
    
    async edit(id) {
        const result = await App.request('api/videos.php?action=get_source&id=' + id);
        if (result && result.success) {
            const source = result.data;
            document.getElementById('editSourceId').value = source.id;
            document.getElementById('editSourceChatId').value = source.chat_id;
            document.getElementById('editSourceChatTitle').value = source.chat_title || '';
            document.getElementById('editSourceCategoryId').value = source.category_id || '';
            document.getElementById('editSourceDefaultKeywords').value = source.default_keywords || '';
            document.getElementById('editSourceAutoForward').checked = source.auto_forward == 1;
            
            // 设置转发方式
            if (source.forward_to_category_id) {
                document.getElementById('editSourceForwardType').value = 'category';
                document.getElementById('editSourceForwardToCategory').value = source.forward_to_category_id;
                document.getElementById('editSourceForwardTo').value = '';
            } else {
                document.getElementById('editSourceForwardType').value = 'single';
                document.getElementById('editSourceForwardToCategory').value = '';
                document.getElementById('editSourceForwardTo').value = source.forward_to_chat_id || '';
            }
            
            toggleEditForwardSettings();
            App.showModal('editSourceModal');
        }
    },
    
    async update() {
        const id = document.getElementById('editSourceId').value;
        const chatTitle = document.getElementById('editSourceChatTitle').value;
        const categoryId = document.getElementById('editSourceCategoryId').value;
        const defaultKeywords = document.getElementById('editSourceDefaultKeywords').value;
        const autoForward = document.getElementById('editSourceAutoForward').checked ? 1 : 0;
        const forwardType = document.getElementById('editSourceForwardType').value;
        const forwardToCategory = document.getElementById('editSourceForwardToCategory').value;
        const forwardTo = document.getElementById('editSourceForwardTo').value;
        
        const result = await App.request('api/videos.php', 'POST', {
            action: 'update_source',
            id: id,
            chat_title: chatTitle,
            category_id: categoryId || null,
            default_keywords: defaultKeywords,
            auto_forward: autoForward,
            forward_to_chat_id: forwardType === 'single' ? (forwardTo || null) : null,
            forward_to_category_id: forwardType === 'category' ? (forwardToCategory || null) : null
        });
        
        if (result && result.success) {
            App.showAlert('更新成功');
            App.hideModal('editSourceModal');
            this.load();
        }
    },
    
    async toggle(id, currentStatus) {
        const result = await App.request('api/videos.php', 'POST', {
            action: 'toggle_source', id: id, is_active: currentStatus ? 0 : 1
        });
        if (result && result.success) {
            App.showAlert('状态更新成功');
            this.load();
        }
    },
    
    async delete(id) {
        if (!confirm('确定要删除这个采集源吗？')) return;
        
        const result = await App.request('api/videos.php', 'POST', { action: 'delete_source', id: id });
        if (result && result.success) {
            App.showAlert('删除成功');
            this.load();
        }
    },
    
    async addFromGroup() {
        const groupId = document.getElementById('selectGroupId').value;
        const categoryId = document.getElementById('selectGroupCategoryId').value;
        
        if (!groupId) {
            App.showAlert('请选择群组', 'error');
            return;
        }
        
        const result = await App.request('api/videos.php', 'POST', {
            action: 'add_source_from_group',
            group_id: groupId,
            category_id: categoryId || null
        });
        
        if (result && result.success) {
            App.showAlert('添加成功');
            App.hideModal('selectGroupModal');
            this.load();
        }
    }
};

// 分类管理
const Categories = {
    async load() {
        const result = await App.request('api/videos.php?action=list_categories');
        if (result && result.success) {
            this.render(result.data);
        }
    },
    
    render(categories) {
        const tbody = document.getElementById('categoriesTable');
        if (!categories || categories.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="empty-state">暂无分类</td></tr>';
            return;
        }
        
        tbody.innerHTML = categories.map(cat => {
            const statusBadge = cat.is_active ? 
                '<span class="badge badge-success">启用</span>' : 
                '<span class="badge badge-secondary">禁用</span>';
            
            return `
                <tr>
                    <td>${cat.id}</td>
                    <td><strong>${cat.name}</strong></td>
                    <td>${cat.description || '-'}</td>
                    <td>${cat.video_count || 0}</td>
                    <td>${cat.sort_order}</td>
                    <td>${statusBadge}</td>
                    <td>
                        <button class="btn btn-sm btn-primary" onclick="Categories.edit(${cat.id})">编辑</button>
                        <button class="btn btn-sm btn-danger" onclick="Categories.delete(${cat.id})">删除</button>
                    </td>
                </tr>
            `;
        }).join('');
    },
    
    async add() {
        const name = document.getElementById('categoryName').value;
        const description = document.getElementById('categoryDesc').value;
        const sortOrder = document.getElementById('categorySortOrder').value;
        
        if (!name) { App.showAlert('请填写分类名称', 'error'); return; }
        
        const result = await App.request('api/videos.php', 'POST', {
            action: 'add_category', name, description, sort_order: sortOrder
        });
        
        if (result && result.success) {
            App.showAlert('添加成功');
            App.hideModal('addCategoryModal');
            this.load();
            document.getElementById('categoryName').value = '';
            document.getElementById('categoryDesc').value = '';
            document.getElementById('categorySortOrder').value = '0';
            location.reload(); // 刷新页面更新分类下拉框
        }
    },
    
    async edit(id) {
        const result = await App.request('api/videos.php?action=get_category&id=' + id);
        if (result && result.success) {
            const cat = result.data;
            document.getElementById('editCategoryId').value = cat.id;
            document.getElementById('editCategoryName').value = cat.name;
            document.getElementById('editCategoryDesc').value = cat.description || '';
            document.getElementById('editCategorySortOrder').value = cat.sort_order;
            App.showModal('editCategoryModal');
        }
    },
    
    async update() {
        const id = document.getElementById('editCategoryId').value;
        const name = document.getElementById('editCategoryName').value;
        const description = document.getElementById('editCategoryDesc').value;
        const sortOrder = document.getElementById('editCategorySortOrder').value;
        
        if (!name) { App.showAlert('请填写分类名称', 'error'); return; }
        
        const result = await App.request('api/videos.php', 'POST', {
            action: 'update_category', id, name, description, sort_order: sortOrder
        });
        
        if (result && result.success) {
            App.showAlert('更新成功');
            App.hideModal('editCategoryModal');
            this.load();
            location.reload();
        }
    },
    
    async delete(id) {
        if (!confirm('确定要删除这个分类吗？分类下的视频将变为"未分类"')) return;
        
        const result = await App.request('api/videos.php', 'POST', { action: 'delete_category', id });
        if (result && result.success) {
            App.showAlert('删除成功');
            this.load();
            location.reload();
        }
    }
};

// 批量上传
const BatchUpload = {
    queue: [],
    uploading: false,
    
    filesSelected(files) {
        if (!files.length) return;
        
        const categoryId = document.getElementById('batchCategory').value;
        if (!categoryId) {
            App.showAlert('请先选择分类', 'error');
            return;
        }
        
        this.queue = Array.from(files).map(file => ({
            file, status: 'pending', progress: 0,
            name: file.name.replace(/\.[^/.]+$/, '') // 去掉扩展名作为名称
        }));
        
        this.renderProgress();
        this.startUpload();
    },
    
    renderProgress() {
        document.getElementById('uploadProgress').style.display = 'block';
        const list = document.getElementById('progressList');
        
        list.innerHTML = this.queue.map((item, index) => `
            <div class="progress-item" id="progress-${index}">
                <span style="width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${item.name}</span>
                <div class="progress-bar">
                    <div class="progress-fill" style="width:${item.progress}%"></div>
                </div>
                <span class="progress-status">${this.getStatusText(item.status)}</span>
            </div>
        `).join('');
        
        this.updateStats();
    },
    
    getStatusText(status) {
        const texts = { pending: '等待中', uploading: '上传中...', success: '✅ 完成', error: '❌ 失败' };
        return texts[status] || status;
    },
    
    updateStats() {
        const total = this.queue.length;
        const done = this.queue.filter(i => i.status === 'success').length;
        const failed = this.queue.filter(i => i.status === 'error').length;
        document.getElementById('uploadStats').textContent = `总计: ${total} | 完成: ${done} | 失败: ${failed}`;
    },
    
    async startUpload() {
        if (this.uploading) return;
        this.uploading = true;
        
        const categoryId = document.getElementById('batchCategory').value;
        const defaultKeywords = document.getElementById('batchKeywords').value;
        
        for (let i = 0; i < this.queue.length; i++) {
            const item = this.queue[i];
            if (item.status !== 'pending') continue;
            
            item.status = 'uploading';
            this.updateProgressItem(i);
            
            try {
                const formData = new FormData();
                formData.append('action', 'add');
                formData.append('category_id', categoryId);
                formData.append('title', item.name);
                formData.append('keywords', defaultKeywords ? `${item.name},${defaultKeywords}` : item.name);
                formData.append('upload_type', 'file');
                formData.append('video', item.file);
                
                const response = await fetch('api/videos.php', { method: 'POST', body: formData });
                const result = await response.json();
                
                item.status = result.success ? 'success' : 'error';
                item.progress = 100;
            } catch (e) {
                item.status = 'error';
            }
            
            this.updateProgressItem(i);
        }
        
        this.uploading = false;
        App.showAlert('批量上传完成');
        Videos.load();
    },
    
    updateProgressItem(index) {
        const item = this.queue[index];
        const el = document.getElementById(`progress-${index}`);
        if (el) {
            el.querySelector('.progress-fill').style.width = item.progress + '%';
            el.querySelector('.progress-status').textContent = this.getStatusText(item.status);
        }
        this.updateStats();
    }
};

// 拖拽上传
const dropZone = document.getElementById('dropZone');
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    BatchUpload.filesSelected(e.dataTransfer.files);
});

// 切换上传方式
function toggleUploadMethod() {
    const uploadType = document.getElementById('uploadType').value;
    document.getElementById('fileUploadGroup').style.display = uploadType === 'file' ? 'block' : 'none';
    document.getElementById('urlInputGroup').style.display = uploadType === 'url' ? 'block' : 'none';
}

function toggleEditVideoUpload() {
    document.getElementById('editVideoUploadGroup').style.display = 
        document.getElementById('editChangeVideo').checked ? 'block' : 'none';
}

function toggleEditUploadMethod() {
    const uploadType = document.getElementById('editUploadType').value;
    document.getElementById('editFileUploadGroup').style.display = uploadType === 'file' ? 'block' : 'none';
    document.getElementById('editUrlInputGroup').style.display = uploadType === 'url' ? 'block' : 'none';
}

// 页面加载
document.addEventListener('DOMContentLoaded', () => Videos.load());
</script>

<script src="assets/script.js?v=<?php echo time(); ?>"></script>
</body>
</html>
