<?php
/**
 * 支付方式配置页面
 */
require_once 'config.php';
checkLogin();

$page_title = '支付方式配置';
$db = getDB();

// 获取所有支付方式
$stmt = $db->query("SELECT * FROM payment_methods ORDER BY sort_order ASC, id ASC");
$methods = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Telegram Bot</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h1>💳 <?php echo $page_title; ?></h1>
            <button class="btn btn-primary" onclick="showAddModal()">
                <span>➕</span> 添加支付方式
            </button>
        </div>

        <div class="card">
            <div class="card-body">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>图标</th>
                            <th>支付方式</th>
                            <th>类型</th>
                            <th>网络</th>
                            <th>收款地址</th>
                            <th>最小金额</th>
                            <th>排序</th>
                            <th>状态</th>
                            <th>自动检测</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($methods)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 40px;">
                                暂无支付方式，点击右上角添加
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($methods as $method): ?>
                        <tr>
                            <td><?php echo $method['id']; ?></td>
                            <td style="font-size: 24px;"><?php echo $method['icon']; ?></td>
                            <td><strong><?php echo escape($method['name']); ?></strong></td>
                            <td><code><?php echo $method['type']; ?></code></td>
                            <td><?php echo escape($method['network'] ?? '-'); ?></td>
                            <td>
                                <code style="font-size: 11px;">
                                    <?php 
                                    $addr = $method['wallet_address'];
                                    echo escape(strlen($addr) > 20 ? substr($addr, 0, 20) . '...' : $addr); 
                                    ?>
                                </code>
                            </td>
                            <td>$<?php echo number_format($method['min_amount'] ?? 0, 2); ?></td>
                            <td><?php echo $method['sort_order']; ?></td>
                            <td>
                                <?php if ($method['is_active']): ?>
                                <span class="badge badge-success">启用</span>
                                <?php else: ?>
                                <span class="badge badge-secondary">禁用</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($method['auto_verify'] ?? 0): ?>
                                <span class="badge badge-info" title="API: <?php echo escape($method['api_type'] ?? 'N/A'); ?>">🤖 已启用</span>
                                <?php else: ?>
                                <span class="badge badge-secondary">未启用</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="editMethod(<?php echo htmlspecialchars(json_encode($method)); ?>)">编辑</button>
                                <button class="btn btn-sm btn-danger" onclick="deleteMethod(<?php echo $method['id']; ?>)">删除</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 添加/编辑支付方式弹窗 -->
    <div id="methodModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h2 id="modalTitle">添加支付方式</h2>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <form id="methodForm">
                <input type="hidden" id="method_id" name="id">
                
                <div class="form-group">
                    <label>支付方式名称 *</label>
                    <input type="text" id="name" name="name" class="form-control" required placeholder="如：USDT (TRC20)">
                </div>

                <div class="form-group">
                    <label>类型标识 *</label>
                    <input type="text" id="type" name="type" class="form-control" required placeholder="如：usdt_trc20">
                    <small>用于系统识别，建议使用英文小写加下划线</small>
                </div>

                <div class="form-group">
                    <label>图标</label>
                    <input type="text" id="icon" name="icon" class="form-control" value="💰" placeholder="输入emoji图标">
                    <small>常用图标：💰 💵 💴 💶 💷 ₿ 💳</small>
                </div>

                <div class="form-group">
                    <label>网络</label>
                    <input type="text" id="network" name="network" class="form-control" placeholder="如：TRC20、ERC20、Bitcoin">
                </div>

                <div class="form-group">
                    <label>收款地址 *</label>
                    <input type="text" id="wallet_address" name="wallet_address" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>收款二维码URL</label>
                    <input type="text" id="qr_code_url" name="qr_code_url" class="form-control" placeholder="https://example.com/qrcode.png">
                    <small>或上传二维码：</small>
                    <input type="file" id="qr_file" accept="image/*" class="form-control">
                </div>

                <div class="form-group">
                    <label>最小充值金额（USD）</label>
                    <input type="number" id="min_amount" name="min_amount" class="form-control" step="0.01" value="1.00">
                </div>

                <div class="form-group">
                    <label>汇率（相对USD）</label>
                    <input type="number" id="exchange_rate" name="exchange_rate" class="form-control" step="0.0001" value="1.0000">
                    <small>1 USD = ? 单位货币</small>
                </div>

                <div class="form-group">
                    <label>充值说明</label>
                    <textarea id="instructions" name="instructions" class="form-control" rows="3" placeholder="向用户展示的充值说明"></textarea>
                </div>

                <div class="form-group">
                    <label>排序</label>
                    <input type="number" id="sort_order" name="sort_order" class="form-control" value="0">
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="is_active" name="is_active" value="1" checked>
                        启用该支付方式
                    </label>
                </div>

                <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">
                <h3 style="margin-bottom: 15px; font-size: 16px; color: #2196f3;">🤖 自动检测配置</h3>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="auto_verify" name="auto_verify" value="1">
                        启用自动充值检测
                    </label>
                    <small style="display: block; margin-top: 5px; color: #666;">
                        启用后，系统将自动检测该支付方式的到账情况并自动通过充值
                    </small>
                </div>

                <div id="autoVerifySettings" style="display: none; padding: 15px; background: #f5f5f5; border-radius: 4px;">
                    <div class="form-group">
                        <label>API类型 *</label>
                        <select id="api_type" name="api_type" class="form-control">
                            <option value="">选择API类型</option>
                            <option value="tronscan">TronScan (USDT TRC20)</option>
                            <option value="etherscan">EtherScan (ETH/USDT ERC20) - 开发中</option>
                            <option value="blockchain_info">Blockchain.info (BTC) - 开发中</option>
                        </select>
                        <small>根据支付方式选择对应的区块链API</small>
                    </div>

                    <div class="form-group">
                        <label>API密钥</label>
                        <input type="text" id="api_key" name="api_key" class="form-control" placeholder="如需要，填写API Key">
                        <small>TronScan暂不需要API密钥</small>
                    </div>

                    <div class="form-group">
                        <label>检测间隔（秒）</label>
                        <input type="number" id="check_interval" name="check_interval" class="form-control" value="300" min="60">
                        <small>定时任务检测间隔，建议300秒（5分钟）</small>
                    </div>

                    <div class="form-group">
                        <label>最小确认数</label>
                        <input type="number" id="min_confirmations" name="min_confirmations" class="form-control" value="1" min="1">
                        <small>交易需要达到的最小确认数才自动通过，建议1-3</small>
                    </div>
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
            document.getElementById('modalTitle').textContent = '添加支付方式';
            document.getElementById('methodForm').reset();
            document.getElementById('method_id').value = '';
            document.getElementById('methodModal').style.display = 'block';
        }

        function editMethod(method) {
            document.getElementById('modalTitle').textContent = '编辑支付方式';
            document.getElementById('method_id').value = method.id;
            document.getElementById('name').value = method.name;
            document.getElementById('type').value = method.type;
            document.getElementById('icon').value = method.icon;
            document.getElementById('network').value = method.network || '';
            document.getElementById('wallet_address').value = method.wallet_address;
            document.getElementById('qr_code_url').value = method.qr_code_url || '';
            document.getElementById('min_amount').value = method.min_amount;
            document.getElementById('exchange_rate').value = method.exchange_rate;
            document.getElementById('instructions').value = method.instructions || '';
            document.getElementById('sort_order').value = method.sort_order;
            document.getElementById('is_active').checked = method.is_active == 1;
            
            // 自动检测配置
            const autoVerify = method.auto_verify == 1;
            document.getElementById('auto_verify').checked = autoVerify;
            document.getElementById('autoVerifySettings').style.display = autoVerify ? 'block' : 'none';
            document.getElementById('api_type').value = method.api_type || '';
            document.getElementById('api_key').value = method.api_key || '';
            document.getElementById('check_interval').value = method.check_interval || 300;
            document.getElementById('min_confirmations').value = method.min_confirmations || 1;
            
            document.getElementById('methodModal').style.display = 'block';
        }
        
        // 监听自动检测复选框变化
        document.getElementById('auto_verify').addEventListener('change', function() {
            document.getElementById('autoVerifySettings').style.display = this.checked ? 'block' : 'none';
        });

        function closeModal() {
            document.getElementById('methodModal').style.display = 'none';
        }

        document.getElementById('methodForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const id = document.getElementById('method_id').value;
            
            // 处理二维码上传
            let qrCodeUrl = formData.get('qr_code_url');
            const qrFile = document.getElementById('qr_file').files[0];
            
            if (qrFile) {
                const uploadFormData = new FormData();
                uploadFormData.append('image', qrFile);
                uploadFormData.append('type', 'payment_qr');
                
                try {
                    const uploadResponse = await fetch('api/upload.php', {
                        method: 'POST',
                        body: uploadFormData
                    });
                    const uploadResult = await uploadResponse.json();
                    if (uploadResult.success) {
                        qrCodeUrl = uploadResult.url;
                    }
                } catch (error) {
                    console.error('QR code upload error:', error);
                }
            }
            
            fetch('api/payment_methods.php', {
                method: id ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: id || undefined,
                    name: formData.get('name'),
                    type: formData.get('type'),
                    icon: formData.get('icon'),
                    network: formData.get('network'),
                    wallet_address: formData.get('wallet_address'),
                    qr_code_url: qrCodeUrl,
                    min_amount: parseFloat(formData.get('min_amount')),
                    exchange_rate: parseFloat(formData.get('exchange_rate')),
                    instructions: formData.get('instructions'),
                    sort_order: parseInt(formData.get('sort_order')),
                    is_active: formData.get('is_active') ? 1 : 0,
                    auto_verify: formData.get('auto_verify') ? 1 : 0,
                    api_type: formData.get('api_type'),
                    api_key: formData.get('api_key'),
                    check_interval: parseInt(formData.get('check_interval')),
                    min_confirmations: parseInt(formData.get('min_confirmations'))
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
            });
        });

        function deleteMethod(id) {
            if (!confirm('确定要删除这个支付方式吗？')) return;
            
            fetch('api/payment_methods.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('删除成功');
                    location.reload();
                } else {
                    alert(data.message || '删除失败');
                }
            });
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>

