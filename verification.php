<?php
require_once 'config.php';
checkLogin();

$db = getDB();

// 获取所有群组
$stmt = $db->query("SELECT id, title FROM groups WHERE is_active = 1 ORDER BY title");
$groups = $stmt->fetchAll();

// 获取验证设置列表
$stmt = $db->query("SELECT vs.*, COALESCE(g.title, '所有群组') as group_title FROM verification_settings vs LEFT JOIN groups g ON vs.group_id = g.id ORDER BY vs.id DESC");
$verifications = $stmt->fetchAll();

$admin_username = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>进群验证 - Telegram Bot 管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <h1>✅ 进群验证</h1>
                <div class="user-info">
                    <span><?php echo escape($admin_username); ?></span>
                    <a href="api/auth.php?action=logout" class="btn btn-sm btn-danger">退出</a>
                </div>
            </div>
            
            <div class="alert alert-info">
                <strong>功能说明：</strong>
                <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                    <li>支持按钮验证、数学题验证</li>
                    <li>📢 <strong>可要求用户先关注指定频道</strong>（两步验证）</li>
                    <li>新成员加入后会被限制发言，验证通过后恢复权限</li>
                    <li>可设置验证超时时间和超时后的处理方式</li>
                    <li>验证通过后可选择是否发送欢迎消息</li>
                </ul>
                <div style="margin-top: 10px; padding: 10px; background: rgba(79, 70, 229, 0.1); border-radius: 6px;">
                    <strong>⚠️ 频道订阅验证注意：</strong>机器人必须是频道管理员才能检测用户是否已订阅！
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>验证设置列表</h2>
                    <button class="btn btn-primary" onclick="App.showModal('addVerificationModal')">+ 添加验证设置</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>群组</th>
                                    <th>验证类型</th>
                                    <th>关注频道</th>
                                    <th>超时时间</th>
                                    <th>超时处理</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($verifications)): ?>
                                    <tr>
                                        <td colspan="8" class="empty-state">暂无验证设置</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($verifications as $v): ?>
                                        <?php 
                                        $typeMap = [
                                            'button' => '按钮验证',
                                            'math' => '数学题',
                                            'text' => '文字验证'
                                        ];
                                        ?>
                                        <tr>
                                            <td><?php echo $v['id']; ?></td>
                                            <td><?php echo escape($v['group_title']); ?></td>
                                            <td><?php echo $typeMap[$v['verification_type']] ?? $v['verification_type']; ?></td>
                                            <td>
                                                <?php if (!empty($v['require_channel']) && !empty($v['required_channel'])): ?>
                                                    <span class="badge badge-info"><?php echo escape($v['required_channel']); ?></span>
                                                <?php else: ?>
                                                    <span style="color: #888;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $v['timeout_seconds']; ?> 秒</td>
                                            <td><?php echo $v['kick_on_fail'] ? '踢出' : '保持限制'; ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $v['is_active'] ? 'success' : 'danger'; ?>">
                                                    <?php echo $v['is_active'] ? '启用' : '禁用'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-primary" onclick="editVerification(<?php echo $v['id']; ?>)">编辑</button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteVerification(<?php echo $v['id']; ?>)">删除</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 添加验证设置模态框 -->
    <div id="addVerificationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>添加验证设置</h3>
            </div>
            <form onsubmit="event.preventDefault(); addVerification();">
                <div class="form-group">
                    <label>选择群组 *</label>
                    <select id="verificationGroupId" class="form-control" required>
                        <option value="">请选择群组</option>
                        <option value="0">📢 所有群组</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"><?php echo escape($group['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>验证类型 *</label>
                    <select id="verificationType" class="form-control" required>
                        <option value="button">按钮验证（点击按钮验证）</option>
                        <option value="math">数学题验证（回答简单算术题）</option>
                    </select>
                    <small class="form-text">按钮验证最简单，数学题可以更好地防止机器人</small>
                </div>
                
                <div class="form-group">
                    <label>超时时间（秒） *</label>
                    <input type="number" id="verificationTimeout" class="form-control" min="30" max="600" value="60" required>
                    <small class="form-text">用户需要在此时间内完成验证，建议 60-120 秒</small>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="verificationKickOnFail" checked> 验证失败后踢出用户
                    </label>
                    <small class="form-text">如果不勾选，用户将保持禁言状态</small>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="verificationWelcomeAfter" checked> 验证通过后发送欢迎消息
                    </label>
                    <small class="form-text">建议勾选，验证通过后发送欢迎消息</small>
                </div>
                
                <div class="form-group" style="margin-top: 15px; padding: 15px; background: rgba(79, 70, 229, 0.1); border-radius: 8px; border: 1px solid rgba(79, 70, 229, 0.3);">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: bold; color: #4f46e5;">
                        <input type="checkbox" id="verificationRequireChannel" onchange="toggleChannelInput()"> 
                        📢 需要关注指定频道
                    </label>
                    <small class="form-text">开启后，用户需要先关注指定频道才能通过验证</small>
                    
                    <div id="channelInputGroup" style="margin-top: 12px; display: none;">
                        <label>频道用户名 *</label>
                        <input type="text" id="verificationRequiredChannel" class="form-control" placeholder="@channelname 或 https://t.me/channelname">
                        <small class="form-text">输入频道用户名（如 @mychannel）或频道链接</small>
                        
                        <div style="margin-top: 10px;">
                            <label>第一步按钮文字</label>
                            <input type="text" id="verificationChannelBtnText" class="form-control" value="第一步 【请先订阅频道】🙋‍♀️" placeholder="第一步 【请先订阅频道】🙋‍♀️">
                        </div>
                        
                        <div style="margin-top: 10px;">
                            <label>第二步按钮文字</label>
                            <input type="text" id="verificationVerifyBtnText" class="form-control" value="第二步 【点击解除禁言】👍" placeholder="第二步 【点击解除禁言】👍">
                        </div>
                    </div>
                </div>
                
                <script>
                    function toggleChannelInput() {
                        const checked = document.getElementById('verificationRequireChannel').checked;
                        document.getElementById('channelInputGroup').style.display = checked ? 'block' : 'none';
                    }
                </script>
                
                <div class="form-group">
                    <label>自定义验证消息（可选）</label>
                    <textarea id="verificationMessage" class="form-control" rows="4" placeholder="留空使用默认消息"></textarea>
                    <small class="form-text verification-message-help">
                        <div class="button-help">可用变量：{name}（用户名）, {timeout}（超时秒数）</div>
                        <div class="math-help" style="display: none;">可用变量：{name}（用户名）, {num1}（第一个数字）, {num2}（第二个数字）, {timeout}（超时秒数）</div>
                    </small>
                </div>
                
                <script>
                    // 根据验证类型显示不同的变量说明
                    document.getElementById('verificationType').addEventListener('change', function() {
                        const type = this.value;
                        const buttonHelp = document.querySelector('.button-help');
                        const mathHelp = document.querySelector('.math-help');
                        
                        if (type === 'math') {
                            buttonHelp.style.display = 'none';
                            mathHelp.style.display = 'block';
                        } else {
                            buttonHelp.style.display = 'block';
                            mathHelp.style.display = 'none';
                        }
                    });
                </script>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('addVerificationModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">添加</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 编辑验证设置模态框 -->
    <div id="editVerificationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>编辑验证设置</h3>
            </div>
            <form onsubmit="event.preventDefault(); updateVerification();">
                <input type="hidden" id="editVerificationId">
                
                <div class="form-group">
                    <label>选择群组 *</label>
                    <select id="editVerificationGroupId" class="form-control" required>
                        <option value="">请选择群组</option>
                        <option value="0">📢 所有群组</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"><?php echo escape($group['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>验证类型 *</label>
                    <select id="editVerificationType" class="form-control" required>
                        <option value="button">按钮验证（点击按钮验证）</option>
                        <option value="math">数学题验证（回答简单算术题）</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>超时时间（秒） *</label>
                    <input type="number" id="editVerificationTimeout" class="form-control" min="30" max="600" required>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="editVerificationKickOnFail"> 验证失败后踢出用户
                    </label>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="editVerificationWelcomeAfter"> 验证通过后发送欢迎消息
                    </label>
                </div>
                
                <div class="form-group" style="margin-top: 15px; padding: 15px; background: rgba(79, 70, 229, 0.1); border-radius: 8px; border: 1px solid rgba(79, 70, 229, 0.3);">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: bold; color: #4f46e5;">
                        <input type="checkbox" id="editVerificationRequireChannel" onchange="toggleEditChannelInput()"> 
                        📢 需要关注指定频道
                    </label>
                    <small class="form-text">开启后，用户需要先关注指定频道才能通过验证</small>
                    
                    <div id="editChannelInputGroup" style="margin-top: 12px; display: none;">
                        <label>频道用户名 *</label>
                        <input type="text" id="editVerificationRequiredChannel" class="form-control" placeholder="@channelname 或 https://t.me/channelname">
                        <small class="form-text">输入频道用户名（如 @mychannel）或频道链接</small>
                        
                        <div style="margin-top: 10px;">
                            <label>第一步按钮文字</label>
                            <input type="text" id="editVerificationChannelBtnText" class="form-control" placeholder="第一步 【请先订阅频道】🙋‍♀️">
                        </div>
                        
                        <div style="margin-top: 10px;">
                            <label>第二步按钮文字</label>
                            <input type="text" id="editVerificationVerifyBtnText" class="form-control" placeholder="第二步 【点击解除禁言】👍">
                        </div>
                    </div>
                </div>
                
                <script>
                    function toggleEditChannelInput() {
                        const checked = document.getElementById('editVerificationRequireChannel').checked;
                        document.getElementById('editChannelInputGroup').style.display = checked ? 'block' : 'none';
                    }
                </script>
                
                <div class="form-group">
                    <label>自定义验证消息（可选）</label>
                    <textarea id="editVerificationMessage" class="form-control" rows="4" placeholder="留空使用默认消息"></textarea>
                    <small class="form-text edit-verification-message-help">
                        <div class="button-help">可用变量：{name}（用户名）, {timeout}（超时秒数）</div>
                        <div class="math-help" style="display: none;">可用变量：{name}（用户名）, {num1}（第一个数字）, {num2}（第二个数字）, {timeout}（超时秒数）</div>
                    </small>
                </div>
                
                <script>
                    // 编辑模态框：根据验证类型显示不同的变量说明
                    document.getElementById('editVerificationType').addEventListener('change', function() {
                        const type = this.value;
                        const helpContainer = this.closest('.form-group').querySelector('.edit-verification-message-help');
                        const buttonHelp = helpContainer.querySelector('.button-help');
                        const mathHelp = helpContainer.querySelector('.math-help');
                        
                        if (type === 'math') {
                            buttonHelp.style.display = 'none';
                            mathHelp.style.display = 'block';
                        } else {
                            buttonHelp.style.display = 'block';
                            mathHelp.style.display = 'none';
                        }
                    });
                    
                    // 编辑时初始化变量说明
                    function initEditVerificationHelp() {
                        const typeSelect = document.getElementById('editVerificationType');
                        if (typeSelect) {
                            const event = new Event('change');
                            typeSelect.dispatchEvent(event);
                        }
                    }
                </script>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-danger" onclick="App.hideModal('editVerificationModal')">取消</button>
                    <button type="submit" class="btn btn-sm btn-success">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
    <script>
        async function addVerification() {
            const groupId = document.getElementById('verificationGroupId').value;
            const type = document.getElementById('verificationType').value;
            const timeout = document.getElementById('verificationTimeout').value;
            const kickOnFail = document.getElementById('verificationKickOnFail').checked ? 1 : 0;
            const welcomeAfter = document.getElementById('verificationWelcomeAfter').checked ? 1 : 0;
            const message = document.getElementById('verificationMessage').value;
            const requireChannel = document.getElementById('verificationRequireChannel').checked ? 1 : 0;
            const requiredChannel = document.getElementById('verificationRequiredChannel').value;
            const channelBtnText = document.getElementById('verificationChannelBtnText').value;
            const verifyBtnText = document.getElementById('verificationVerifyBtnText').value;
            
            // 验证频道设置
            if (requireChannel && !requiredChannel) {
                App.showAlert('请填写频道用户名', 'error');
                return;
            }
            
            const result = await App.request('api/verification.php', 'POST', {
                action: 'add',
                group_id: groupId,
                verification_type: type,
                timeout_seconds: timeout,
                kick_on_fail: kickOnFail,
                welcome_after_verify: welcomeAfter,
                verification_message: message || null,
                require_channel: requireChannel,
                required_channel: requiredChannel || null,
                channel_btn_text: channelBtnText || '第一步 【请先订阅频道】🙋‍♀️',
                verify_btn_text: verifyBtnText || '第二步 【点击解除禁言】👍'
            });
            
            if (result && result.success) {
                App.showAlert('添加成功');
                App.hideModal('addVerificationModal');
                setTimeout(() => location.reload(), 1000);
            }
        }
        
        async function editVerification(id) {
            try {
                const response = await fetch(`api/verification.php?action=get&id=${id}`);
                const result = await response.json();
                
                if (result && result.success) {
                    const v = result.data;
                    
                    document.getElementById('editVerificationId').value = v.id;
                    document.getElementById('editVerificationGroupId').value = v.group_id || 0;
                    document.getElementById('editVerificationType').value = v.verification_type;
                    document.getElementById('editVerificationTimeout').value = v.timeout_seconds;
                    document.getElementById('editVerificationKickOnFail').checked = v.kick_on_fail == 1;
                    document.getElementById('editVerificationWelcomeAfter').checked = v.welcome_after_verify == 1;
                    document.getElementById('editVerificationMessage').value = v.verification_message || '';
                    
                    // 频道关注设置
                    document.getElementById('editVerificationRequireChannel').checked = v.require_channel == 1;
                    document.getElementById('editVerificationRequiredChannel').value = v.required_channel || '';
                    document.getElementById('editVerificationChannelBtnText').value = v.channel_btn_text || '第一步 【请先订阅频道】🙋‍♀️';
                    document.getElementById('editVerificationVerifyBtnText').value = v.verify_btn_text || '第二步 【点击解除禁言】👍';
                    toggleEditChannelInput();
                    
                    // 初始化变量说明
                    setTimeout(initEditVerificationHelp, 100);
                    
                    App.showModal('editVerificationModal');
                } else {
                    App.showAlert('获取验证设置失败', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                App.showAlert('获取验证设置失败：' + error.message, 'error');
            }
        }
        
        async function updateVerification() {
            const id = document.getElementById('editVerificationId').value;
            const groupId = document.getElementById('editVerificationGroupId').value;
            const type = document.getElementById('editVerificationType').value;
            const timeout = document.getElementById('editVerificationTimeout').value;
            const kickOnFail = document.getElementById('editVerificationKickOnFail').checked ? 1 : 0;
            const welcomeAfter = document.getElementById('editVerificationWelcomeAfter').checked ? 1 : 0;
            const message = document.getElementById('editVerificationMessage').value;
            const requireChannel = document.getElementById('editVerificationRequireChannel').checked ? 1 : 0;
            const requiredChannel = document.getElementById('editVerificationRequiredChannel').value;
            const channelBtnText = document.getElementById('editVerificationChannelBtnText').value;
            const verifyBtnText = document.getElementById('editVerificationVerifyBtnText').value;
            
            // 验证频道设置
            if (requireChannel && !requiredChannel) {
                App.showAlert('请填写频道用户名', 'error');
                return;
            }
            
            const result = await App.request('api/verification.php', 'POST', {
                action: 'update',
                id: id,
                group_id: groupId,
                verification_type: type,
                timeout_seconds: timeout,
                kick_on_fail: kickOnFail,
                welcome_after_verify: welcomeAfter,
                verification_message: message || null,
                require_channel: requireChannel,
                required_channel: requiredChannel || null,
                channel_btn_text: channelBtnText || '第一步 【请先订阅频道】🙋‍♀️',
                verify_btn_text: verifyBtnText || '第二步 【点击解除禁言】👍'
            });
            
            if (result && result.success) {
                App.showAlert('更新成功');
                App.hideModal('editVerificationModal');
                setTimeout(() => location.reload(), 1000);
            }
        }
        
        async function deleteVerification(id) {
            if (!App.confirm('确定要删除这个验证设置吗？')) return;
            
            const result = await App.request('api/verification.php', 'POST', {
                action: 'delete',
                id: id
            });
            
            if (result && result.success) {
                App.showAlert('删除成功');
                setTimeout(() => location.reload(), 1000);
            }
        }
    </script>
</body>
</html>

