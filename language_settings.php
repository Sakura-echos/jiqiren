<?php
/**
 * 语言设置管理页面
 */
session_start();
require_once 'config.php';

// 检查登录
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// 获取数据库连接
$db = getDB();

// 获取所有语言设置
try {
    $stmt = $db->query("
        SELECT * FROM language_settings 
        ORDER BY sort_order ASC, id ASC
    ");
    $languages = $stmt->fetchAll();
} catch (Exception $e) {
    $languages = [];
}

// 获取统计信息
try {
    $stmt = $db->query("
        SELECT 
            COUNT(DISTINCT language) as total_languages,
            COUNT(*) as total_users,
            language,
            COUNT(*) as user_count
        FROM card_users 
        WHERE language IS NOT NULL
        GROUP BY language
        ORDER BY user_count DESC
    ");
    $user_languages = $stmt->fetchAll();
} catch (Exception $e) {
    $user_languages = [];
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>语言设置 - TG机器人管理系统</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .page-header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .page-header h1 {
            color: #343a40;
            margin-bottom: 5px;
        }
        
        .page-header p {
            color: #666;
            margin: 0;
        }
        
        
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-active {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }
        
        .status-inactive {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        .flag {
            font-size: 20px;
            margin-right: 8px;
        }
        
        .actions {
            display: flex;
            gap: 10px;
        }
        
        .modal {
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .close {
            font-size: 28px;
            cursor: pointer;
            color: #999;
        }
        
        .usage-list {
            list-style: none;
        }
        
        .usage-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .usage-bar {
            width: 100px;
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
            margin-left: 10px;
        }
        
        .usage-fill {
            height: 100%;
            background: #2196f3;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h1>🌐 语言设置管理</h1>
            <p>管理系统支持的语言和用户语言偏好</p>
            <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin-top: 15px; border-left: 4px solid #2196f3;">
                <strong>💡 说明：</strong>
                <ul style="margin: 10px 0 0 20px; line-height: 1.8;">
                    <li><strong>"已启用"</strong> 的语言会在机器人中显示，用户可以选择切换</li>
                    <li>用户选择语言后，<strong>机器人界面会立即切换为对应语言</strong></li>
                    <li><strong>"已禁用"</strong> 的语言不会在机器人中显示，但保留配置</li>
                    <li>后台管理界面始终为中文（不受此设置影响）</li>
                    <li>添加语言时需要配置对应的翻译文本（在 includes/language_pack.php 中）</li>
                </ul>
            </div>
        </div>
        
        <!-- 统计卡片 -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon primary">📚</div>
                <div class="stat-info">
                    <h3><?php echo count($languages); ?></h3>
                    <p>系统语言</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon success">✅</div>
                <div class="stat-info">
                    <h3><?php echo count(array_filter($languages, function($l) { return $l['status'] == 'active'; })); ?></h3>
                    <p>启用语言</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon warning">👥</div>
                <div class="stat-info">
                    <h3><?php echo array_sum(array_column($user_languages, 'user_count')); ?></h3>
                    <p>用户总数</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon primary">🇨🇳</div>
                <div class="stat-info">
                    <h3 style="font-size: 18px;">简体中文</h3>
                    <p>默认语言</p>
                </div>
            </div>
        </div>
        
        <!-- 使用指南 -->
        <div style="background: #d4edda; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #28a745;">
            <strong>📖 完整使用流程：</strong>
            <ol style="margin: 10px 0 0 20px; line-height: 1.8;">
                <li><strong>添加语言：</strong>点击下方"+ 添加语言"，填写语言信息（建议先设为"已禁用"）</li>
                <li><strong>配置翻译：</strong>点击语言右侧的"📝 翻译"按钮，进入翻译管理页面</li>
                <li><strong>复制模板：</strong>如果该语言没有翻译，点击"从简体中文复制"快速创建模板</li>
                <li><strong>修改翻译：</strong>将中文翻译修改为对应语言，逐条保存</li>
                <li><strong>启用语言：</strong>确认翻译无误后，点击"启用"按钮</li>
                <li><strong>测试验证：</strong>给机器人发送 <code>/start</code> → 点击 🌐 Language → 选择新语言 → 验证翻译效果</li>
            </ol>
        </div>
        
        <!-- 用户语言分布 -->
        <div class="card">
            <div class="card-header">
                <h2>📊 用户语言分布</h2>
            </div>
            <?php if (empty($user_languages)): ?>
                <p style="color: #999; text-align: center; padding: 20px;">暂无用户语言数据</p>
            <?php else: ?>
                <ul class="usage-list">
                    <?php 
                    $total_users = array_sum(array_column($user_languages, 'user_count'));
                    foreach ($user_languages as $lang): 
                        $percentage = $total_users > 0 ? ($lang['user_count'] / $total_users * 100) : 0;
                    ?>
                        <li class="usage-item">
                            <span>
                                <strong><?php echo htmlspecialchars($lang['language'] ?? '未设置'); ?></strong>
                                - <?php echo $lang['user_count']; ?> 用户
                            </span>
                            <span style="display: flex; align-items: center;">
                                <span style="margin-right: 10px;"><?php echo number_format($percentage, 1); ?>%</span>
                                <div class="usage-bar">
                                    <div class="usage-fill" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        
        <!-- 语言列表 -->
        <div class="card">
            <div class="card-header">
                <h2>🤖 机器人语言配置</h2>
                <button class="btn btn-primary" onclick="showAddModal()">+ 添加语言</button>
            </div>
            <div style="padding: 15px; background: #fff3cd; border-radius: 4px; margin: 0 20px 15px;">
                <strong>⚡ 快速操作：</strong> 点击"启用"按钮后，该语言立即在机器人中可用
            </div>
            
            <?php if (empty($languages)): ?>
                <p style="color: #999; text-align: center; padding: 20px;">
                    暂无语言配置，请先初始化语言数据
                    <br><br>
                    <button class="btn btn-primary" onclick="initializeLanguages()">初始化默认语言</button>
                </p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>语言</th>
                            <th>代码</th>
                            <th>状态 <small style="color: #666;">(机器人可见性)</small></th>
                            <th>排序</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($languages as $lang): ?>
                            <tr>
                                <td>
                                    <span class="flag"><?php echo $lang['flag']; ?></span>
                                    <strong><?php echo htmlspecialchars($lang['name']); ?></strong>
                                    <?php if ($lang['is_default']): ?>
                                        <span style="color: #ff9800; margin-left: 5px;">★ 默认</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo htmlspecialchars($lang['code']); ?></code></td>
                                <td>
                                    <?php
                                    $status_class = $lang['status'] == 'active' ? 'status-active' : 'status-inactive';
                                    $status_text = $lang['status'] == 'active' ? '✅ 已启用' : '❌ 已禁用';
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                </td>
                                <td><?php echo $lang['sort_order']; ?></td>
                                <td>
                                    <div class="actions">
                                        <a href="language_translations.php?lang=<?php echo $lang['code']; ?>" class="btn btn-sm" style="background: #ff9800; color: white;">
                                            📝 翻译
                                        </a>
                                        <button class="btn btn-sm btn-success" onclick='editLanguage(<?php echo json_encode($lang); ?>)'>编辑</button>
                                        <?php if ($lang['status'] == 'active'): ?>
                                            <button class="btn btn-sm btn-warning" onclick="toggleStatus(<?php echo $lang['id']; ?>, 'inactive')">禁用</button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-success" onclick="toggleStatus(<?php echo $lang['id']; ?>, 'active')">启用</button>
                                        <?php endif; ?>
                                        <?php if (!$lang['is_default']): ?>
                                            <button class="btn btn-sm btn-danger" onclick="deleteLanguage(<?php echo $lang['id']; ?>)">删除</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 添加/编辑语言模态框 -->
    <div id="languageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">添加语言</h2>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <form id="languageForm">
                <input type="hidden" id="lang_id" name="id">
                
                <div class="form-group">
                    <label>语言名称 *</label>
                    <input type="text" id="name" name="name" class="form-control" required placeholder="如：简体中文">
                </div>
                
                <div class="form-group">
                    <label>语言代码 *</label>
                    <input type="text" id="code" name="code" class="form-control" required placeholder="如：zh_CN">
                    <small style="color: #666;">格式：语言_地区，如 en_US, zh_CN, zh_TW</small>
                </div>
                
                <div class="form-group">
                    <label>国旗emoji</label>
                    <input type="text" id="flag" name="flag" class="form-control" placeholder="如：🇨🇳">
                    <small style="color: #666;">常用：🇨🇳 🇹🇼 🇺🇸 🇬🇧 🇷🇺 🇯🇵 🇰🇷 🇫🇷 🇩🇪</small>
                </div>
                
                <div class="form-group">
                    <label>状态</label>
                    <select id="status" name="status" class="form-control">
                        <option value="inactive">已禁用 (添加后需手动启用)</option>
                        <option value="active">已启用 (立即在机器人中显示)</option>
                    </select>
                    <small style="color: #666;">建议先禁用，配置好翻译后再启用</small>
                </div>
                
                <div class="form-group">
                    <label>排序顺序</label>
                    <input type="number" id="sort_order" name="sort_order" class="form-control" value="0">
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="is_default" name="is_default" value="1">
                        设为默认语言
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
            document.getElementById('modalTitle').textContent = '添加语言';
            document.getElementById('languageForm').reset();
            document.getElementById('lang_id').value = '';
            document.getElementById('languageModal').style.display = 'flex';
        }
        
        function editLanguage(lang) {
            document.getElementById('modalTitle').textContent = '编辑语言';
            document.getElementById('lang_id').value = lang.id;
            document.getElementById('name').value = lang.name;
            document.getElementById('code').value = lang.code;
            document.getElementById('flag').value = lang.flag || '';
            document.getElementById('status').value = lang.status;
            document.getElementById('sort_order').value = lang.sort_order;
            document.getElementById('is_default').checked = lang.is_default == 1;
            document.getElementById('languageModal').style.display = 'flex';
        }
        
        function closeModal() {
            document.getElementById('languageModal').style.display = 'none';
        }
        
        document.getElementById('languageForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const id = document.getElementById('lang_id').value;
            
            const data = {
                name: formData.get('name'),
                code: formData.get('code'),
                flag: formData.get('flag'),
                status: formData.get('status'),
                sort_order: formData.get('sort_order'),
                is_default: formData.get('is_default') ? 1 : 0
            };
            
            if (id) {
                data.id = id;
            }
            
            try {
                const response = await fetch('api/language_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('保存成功！');
                    location.reload();
                } else {
                    alert('保存失败：' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('操作失败，请重试');
            }
        });
        
        async function toggleStatus(id, status) {
            if (!confirm('确定要修改状态吗？')) return;
            
            console.log('正在修改语言状态:', { id, status });
            
            try {
                const response = await fetch('api/language_settings.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, status })
                });
                
                console.log('Response status:', response.status);
                
                const result = await response.json();
                console.log('Response data:', result);
                
                if (result.success) {
                    alert('✅ 状态修改成功！语言已' + (status === 'active' ? '启用' : '禁用'));
                    location.reload();
                } else {
                    alert('❌ 操作失败：' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('❌ 操作失败，请重试。错误：' + error.message);
            }
        }
        
        async function deleteLanguage(id) {
            if (!confirm('确定要删除这个语言吗？此操作不可恢复！')) return;
            
            try {
                const response = await fetch('api/language_settings.php?id=' + id, {
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
        
        async function initializeLanguages() {
            if (!confirm('确定要初始化默认语言吗？')) return;
            
            try {
                const response = await fetch('api/language_settings.php?action=initialize', {
                    method: 'POST'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('初始化成功！');
                    location.reload();
                } else {
                    alert('初始化失败：' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('操作失败，请重试');
            }
        }
    </script>
</body>
</html>

