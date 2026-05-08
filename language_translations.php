<?php
/**
 * 语言翻译管理页面
 */
session_start();
require_once 'config.php';

// 检查登录
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$db = getDB();

// 获取所有语言
$stmt = $db->query("SELECT * FROM language_settings WHERE status = 'active' ORDER BY sort_order ASC");
$languages = $stmt->fetchAll();

// 获取当前选择的语言（默认为简体中文）
$current_lang = $_GET['lang'] ?? 'zh_CN';

// 获取该语言的所有翻译
$stmt = $db->prepare("SELECT * FROM language_translations WHERE language_code = ? ORDER BY category, trans_key");
$stmt->execute([$current_lang]);
$translations = $stmt->fetchAll();

// 按分类组织翻译
$grouped = [];
foreach ($translations as $trans) {
    $category = $trans['category'] ?? 'other';
    $grouped[$category][] = $trans;
}

// 分类名称映射
$category_names = [
    'menu' => '📋 菜单文本',
    'button' => '🔘 按钮文本',
    'message' => '💬 消息文本',
    'error' => '❌ 错误提示',
    'other' => '📦 其他'
];

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>翻译管理 - TG机器人管理系统</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .lang-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .lang-tab {
            padding: 10px 20px;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: #333;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .lang-tab:hover {
            border-color: #2196f3;
            background: #f5f5f5;
        }
        
        .lang-tab.active {
            background: #2196f3;
            color: white;
            border-color: #2196f3;
        }
        
        .translation-group {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .translation-group h3 {
            color: #343a40;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .trans-item {
            display: grid;
            grid-template-columns: 200px 1fr 100px;
            gap: 15px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 8px;
            margin-bottom: 10px;
            align-items: center;
        }
        
        .trans-key {
            font-family: 'Courier New', monospace;
            color: #666;
            font-size: 13px;
            word-break: break-all;
        }
        
        .trans-value {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .trans-actions {
            display: flex;
            gap: 5px;
        }
        
        .quick-actions {
            background: #fff3cd;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #ffc107;
        }
        
        .add-trans-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: #4caf50;
            color: white;
            border: none;
            font-size: 24px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.4);
            transition: all 0.3s;
        }
        
        .add-trans-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 16px rgba(76, 175, 80, 0.6);
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h1>🌐 语言翻译管理</h1>
            <p>管理每个语言的翻译文本</p>
        </div>
        
        <!-- 语言切换标签 -->
        <div class="lang-tabs">
            <?php foreach ($languages as $lang): ?>
                <a href="?lang=<?php echo $lang['code']; ?>" 
                   class="lang-tab <?php echo $current_lang == $lang['code'] ? 'active' : ''; ?>">
                    <span style="font-size: 20px;"><?php echo $lang['flag']; ?></span>
                    <strong><?php echo htmlspecialchars($lang['name']); ?></strong>
                    <small>(<?php echo $lang['code']; ?>)</small>
                </a>
            <?php endforeach; ?>
        </div>
        
        <!-- 快速操作提示 -->
        <div class="quick-actions">
            <strong>⚡ 快速操作：</strong>
            <ul style="margin: 10px 0 0 20px; line-height: 1.8;">
                <li>修改翻译后点击"保存"按钮即时生效</li>
                <li>点击右下角"+"按钮添加新的翻译键</li>
                <li>建议先复制简体中文的翻译，再修改为对应语言</li>
                <li>翻译键（Key）必须保持一致，不要修改</li>
            </ul>
        </div>
        
        <?php if (empty($translations)): ?>
            <div class="card">
                <p style="text-align: center; padding: 40px; color: #999;">
                    📝 该语言暂无翻译数据
                    <br><br>
                    <button class="btn btn-primary" onclick="copyFromChinese()">从简体中文复制</button>
                    <button class="btn btn-success" onclick="showAddModal()">手动添加</button>
                </p>
            </div>
        <?php else: ?>
            <!-- 按分类显示翻译 -->
            <?php foreach ($grouped as $category => $items): ?>
                <div class="translation-group">
                    <h3><?php echo $category_names[$category] ?? $category; ?> <small style="color: #999;">(<?php echo count($items); ?> 条)</small></h3>
                    
                    <?php foreach ($items as $trans): ?>
                        <div class="trans-item" data-id="<?php echo $trans['id']; ?>">
                            <div class="trans-key">
                                <strong><?php echo htmlspecialchars($trans['trans_key']); ?></strong>
                            </div>
                            <input type="text" 
                                   class="trans-value" 
                                   value="<?php echo htmlspecialchars($trans['trans_value']); ?>"
                                   data-id="<?php echo $trans['id']; ?>"
                                   data-key="<?php echo htmlspecialchars($trans['trans_key']); ?>">
                            <div class="trans-actions">
                                <button class="btn btn-sm btn-primary" onclick="saveTranslation(<?php echo $trans['id']; ?>)">保存</button>
                                <button class="btn btn-sm btn-danger" onclick="deleteTranslation(<?php echo $trans['id']; ?>)">删除</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- 添加翻译按钮 -->
    <button class="add-trans-btn" onclick="showAddModal()" title="添加新翻译">+</button>
    
    <!-- 添加翻译模态框 -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>添加翻译</h2>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <form id="addForm">
                <div class="form-group">
                    <label>翻译键 (Key) *</label>
                    <input type="text" id="new_key" class="form-control" required placeholder="例如：btn_new_feature">
                    <small style="color: #666;">只能使用字母、数字和下划线，如：btn_shop, msg_welcome</small>
                </div>
                
                <div class="form-group">
                    <label>翻译文本 (Value) *</label>
                    <input type="text" id="new_value" class="form-control" required placeholder="例如：新功能">
                </div>
                
                <div class="form-group">
                    <label>分类</label>
                    <select id="new_category" class="form-control">
                        <option value="button">按钮文本</option>
                        <option value="menu">菜单文本</option>
                        <option value="message">消息文本</option>
                        <option value="error">错误提示</option>
                        <option value="other">其他</option>
                    </select>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn" onclick="closeModal()">取消</button>
                    <button type="submit" class="btn btn-success">添加</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        const currentLang = '<?php echo $current_lang; ?>';
        
        // 保存单个翻译
        async function saveTranslation(id) {
            const input = document.querySelector(`input[data-id="${id}"]`);
            const value = input.value.trim();
            
            if (!value) {
                alert('翻译文本不能为空！');
                return;
            }
            
            try {
                const response = await fetch('api/language_translations.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, trans_value: value })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ 保存成功！');
                    input.style.borderColor = '#4caf50';
                    setTimeout(() => { input.style.borderColor = '#ddd'; }, 2000);
                } else {
                    alert('❌ 保存失败：' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('操作失败，请重试');
            }
        }
        
        // 删除翻译
        async function deleteTranslation(id) {
            if (!confirm('确定要删除这条翻译吗？')) return;
            
            try {
                const response = await fetch(`api/language_translations.php?id=${id}`, {
                    method: 'DELETE'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('删除成功！');
                    location.reload();
                } else {
                    alert('删除失败：' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('操作失败，请重试');
            }
        }
        
        // 显示添加模态框
        function showAddModal() {
            document.getElementById('addModal').style.display = 'flex';
        }
        
        // 关闭模态框
        function closeModal() {
            document.getElementById('addModal').style.display = 'none';
        }
        
        // 添加新翻译
        document.getElementById('addForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const key = document.getElementById('new_key').value.trim();
            const value = document.getElementById('new_value').value.trim();
            const category = document.getElementById('new_category').value;
            
            try {
                const response = await fetch('api/language_translations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        language_code: currentLang,
                        trans_key: key,
                        trans_value: value,
                        category: category
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ 添加成功！');
                    location.reload();
                } else {
                    alert('❌ 添加失败：' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('操作失败，请重试');
            }
        });
        
        // 从简体中文复制
        async function copyFromChinese() {
            if (!confirm('确定要从简体中文复制所有翻译吗？')) return;
            
            try {
                const response = await fetch('api/language_translations.php?action=copy', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        from: 'zh_CN',
                        to: currentLang
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ 复制成功！请修改为对应语言的翻译');
                    location.reload();
                } else {
                    alert('❌ 复制失败：' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('操作失败，请重试');
            }
        }
        
        // 点击模态框外部关闭
        window.onclick = function(event) {
            const modal = document.getElementById('addModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>

