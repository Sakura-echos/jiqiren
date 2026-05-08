<?php
require_once 'config.php';
checkLogin();

$db = getDB();

// 获取所有群组用于下拉选择
$stmt = $db->query("SELECT id, title FROM groups WHERE is_active = 1 ORDER BY title");
$groups = $stmt->fetchAll();

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>违禁词管理 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>🚫 违禁词管理</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>违禁词列表</h2>
                    <button class="btn btn-primary" onclick="App.showModal('addWordModal')">+ 添加违禁词</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>违禁词</th>
                                    <th>匹配方式</th>
                                    <th>应用群组</th>
                                    <th>处理动作</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="bannedWordsTable">
                                <tr>
                                    <td colspan="6" class="loading">加载中...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 添加违禁词模态框 -->
    <div id="addWordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>添加违禁词</h3>
            </div>
            <form onsubmit="event.preventDefault(); BannedWords.add();" id="addBannedWordForm">
                <div class="form-group">
                    <label>违禁词/正则表达式</label>
                    <input type="text" id="newWord" class="form-control" required placeholder="输入违禁词或正则表达式">
                </div>
                
                <div class="form-group">
                    <label>应用群组</label>
                    <select id="newGroupId" class="form-control">
                        <option value="">所有群组</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"><?php echo escape($group['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>处理动作</label>
                    <div class="checkbox-group">
                        <label>
                            <input type="checkbox" id="newDeleteMessage" checked>
                            删除消息
                        </label>
                        <label>
                            <input type="checkbox" id="newWarnUser">
                            警告用户
                        </label>
                        <label>
                            <input type="checkbox" id="newKickUser">
                            踢出用户
                        </label>
                        <label>
                            <input type="checkbox" id="newBanUser">
                            封禁用户
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>匹配方式</label>
                    <select id="newMatchType" class="form-control">
                        <option value="exact">精确匹配</option>
                        <option value="contains">包含匹配</option>
                        <option value="starts_with">开头匹配</option>
                        <option value="ends_with">结尾匹配</option>
                        <option value="regex">正则表达式</option>
                    </select>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('addWordModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">添加</button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
</body>
</html>

