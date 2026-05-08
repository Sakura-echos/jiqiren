<?php
require_once 'config.php';
checkLogin();

$db = getDB();
$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>菜单按钮管理 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>📱 菜单按钮管理</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>菜单按钮列表</h2>
                    <button class="btn btn-primary" onclick="App.showModal('addButtonModal')">+ 添加按钮</button>
                </div>
                <div class="card-body">
                    <div id="buttonsList">
                        <div class="loading">加载中...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 添加按钮模态框 -->
    <div id="addButtonModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>添加菜单按钮</h3>
            </div>
            <form id="addButtonForm" onsubmit="event.preventDefault(); MenuButtons.add();">
                <div class="form-group">
                    <label>按钮文字</label>
                    <input type="text" id="buttonText" class="form-control" required maxlength="255" placeholder="输入按钮显示的文字">
                </div>
                <div class="form-group">
                    <label>跳转链接</label>
                    <input type="url" id="buttonUrl" class="form-control" required maxlength="255" placeholder="输入点击后跳转的链接，例如：https://example.com">
                </div>
                <div class="form-group">
                    <label>排序顺序</label>
                    <input type="number" id="sortOrder" class="form-control" value="0" min="0" required>
                    <small class="form-text">数字越小越靠前</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('addButtonModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">添加</button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
</body>
</html>
