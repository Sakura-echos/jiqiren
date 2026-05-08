#!/usr/bin/env php
<?php
/**
 * 自动充值检测脚本
 * 定时运行，自动检测待确认的充值订单
 * 
 * 使用方法：
 * 1. 手动运行：php auto_verify_recharge.php
 * 2. 定时任务（每5分钟）：在crontab中添加
 *    星号/5 * * * * /usr/bin/php /path/to/auto_verify_recharge.php >> /path/to/logs/auto_verify.log 2>&1
 */

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 引入配置
require_once dirname(__DIR__) . '/config.php';

// 日志函数
function logMessage($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] [$level] $message\n";
}

// 检测USDT TRC20充值
function checkUsdtTrc20($wallet_address, $expected_amount, $api_key = null) {
    // 使用TronScan API检测
    $api_url = "https://apilist.tronscan.org/api/token_trc20/transfers";
    $params = [
        'limit' => 20,
        'start' => 0,
        'sort' => '-timestamp',
        'count' => true,
        'filterTokenValue' => 0,
        'relatedAddress' => $wallet_address
    ];
    
    $url = $api_url . '?' . http_build_query($params);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        return ['success' => false, 'error' => 'API请求失败: HTTP ' . $http_code];
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['token_transfers'])) {
        return ['success' => false, 'error' => 'API返回数据格式错误'];
    }
    
    // 检查最近的交易（24小时内）
    $time_limit = time() - 86400; // 24小时前
    
    foreach ($data['token_transfers'] as $transfer) {
        // 检查是否是接收交易
        if (strtolower($transfer['to_address']) !== strtolower($wallet_address)) {
            continue;
        }
        
        // 检查时间
        $tx_time = intval($transfer['block_ts'] / 1000);
        if ($tx_time < $time_limit) {
            continue;
        }
        
        // 检查金额（USDT精度为6位小数）
        $amount = floatval($transfer['quant']) / 1000000;
        $tolerance = 0.01; // 允许0.01 USDT的误差
        
        if (abs($amount - $expected_amount) <= $tolerance) {
            return [
                'success' => true,
                'found' => true,
                'amount' => $amount,
                'transaction_hash' => $transfer['transaction_id'],
                'confirmations' => isset($transfer['confirmed']) ? ($transfer['confirmed'] ? 1 : 0) : 1,
                'timestamp' => $tx_time
            ];
        }
    }
    
    return [
        'success' => true,
        'found' => false,
        'message' => '未找到匹配的交易'
    ];
}

// 检测充值（根据不同类型调用不同的检测函数）
function detectRecharge($payment_method, $wallet_address, $expected_amount, $crypto_amount = null) {
    switch ($payment_method['api_type']) {
        case 'tronscan':
            // USDT TRC20
            $amount = $crypto_amount ?? $expected_amount;
            return checkUsdtTrc20($wallet_address, $amount, $payment_method['api_key']);
            
        // 可以在这里添加其他支付方式的检测
        case 'etherscan':
            // TODO: ETH/USDT ERC20检测
            return ['success' => false, 'error' => 'ETH检测功能开发中'];
            
        case 'blockchain_info':
            // TODO: BTC检测
            return ['success' => false, 'error' => 'BTC检测功能开发中'];
            
        default:
            return ['success' => false, 'error' => '不支持的API类型: ' . $payment_method['api_type']];
    }
}

// 主函数
function main() {
    try {
        $db = getDB();
        
        logMessage("========== 开始自动充值检测 ==========");
        
        // 获取启用了自动检测的支付方式
        $stmt = $db->query("
            SELECT * FROM payment_methods 
            WHERE is_active = 1 AND auto_verify = 1
        ");
        $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($payment_methods)) {
            logMessage("没有启用自动检测的支付方式", 'INFO');
            return;
        }
        
        logMessage("找到 " . count($payment_methods) . " 个启用自动检测的支付方式");
        
        // 获取待确认的充值记录（状态为pending或confirming，且未过期）
        $stmt = $db->query("
            SELECT r.*, u.username, u.first_name 
            FROM recharge_records r
            LEFT JOIN card_users u ON r.user_id = u.id
            WHERE r.status IN ('pending', 'confirming')
            AND (r.expires_at IS NULL OR r.expires_at > NOW())
            AND r.wallet_address IS NOT NULL
            ORDER BY r.created_at DESC
        ");
        $recharges = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($recharges)) {
            logMessage("没有待检测的充值记录", 'INFO');
            return;
        }
        
        logMessage("找到 " . count($recharges) . " 条待检测的充值记录");
        
        // 创建支付方式ID到对象的映射
        $payment_method_map = [];
        foreach ($payment_methods as $pm) {
            $payment_method_map[$pm['id']] = $pm;
        }
        
        $checked = 0;
        $verified = 0;
        
        foreach ($recharges as $recharge) {
            // 检查该充值的支付方式是否启用了自动检测
            if (!isset($payment_method_map[$recharge['payment_method_id']])) {
                continue;
            }
            
            $payment_method = $payment_method_map[$recharge['payment_method_id']];
            $checked++;
            
            logMessage("检测充值 #{$recharge['recharge_no']}, 用户: {$recharge['username']}, 金额: \${$recharge['amount']}, 地址: {$recharge['wallet_address']}");
            
            // 执行检测
            $result = detectRecharge(
                $payment_method,
                $recharge['wallet_address'],
                $recharge['amount'],
                $recharge['crypto_amount']
            );
            
            // 记录检测日志
            $log_stmt = $db->prepare("
                INSERT INTO recharge_check_logs 
                (recharge_id, api_response, status, found_amount, confirmations, error_message)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $log_stmt->execute([
                $recharge['id'],
                json_encode($result, JSON_UNESCII_UNICODE),
                $result['success'] ? ($result['found'] ?? false ? 'found' : 'not_found') : 'error',
                $result['amount'] ?? null,
                $result['confirmations'] ?? 0,
                $result['error'] ?? null
            ]);
            
            // 如果检测成功且找到交易
            if ($result['success'] && ($result['found'] ?? false)) {
                $confirmations = $result['confirmations'] ?? 0;
                $min_confirmations = $payment_method['min_confirmations'] ?? 1;
                
                if ($confirmations >= $min_confirmations) {
                    // 确认数足够，自动通过
                    $db->beginTransaction();
                    
                    try {
                        // 更新充值记录状态
                        $update_stmt = $db->prepare("
                            UPDATE recharge_records 
                            SET status = 'completed', 
                                transaction_hash = ?,
                                confirmed_at = NOW(),
                                admin_remark = CONCAT(IFNULL(admin_remark, ''), '\n[自动检测] 交易已确认')
                            WHERE id = ?
                        ");
                        $update_stmt->execute([
                            $result['transaction_hash'] ?? '',
                            $recharge['id']
                        ]);
                        
                        // 给用户加余额
                        $balance_stmt = $db->prepare("
                            UPDATE card_users 
                            SET balance = balance + ? 
                            WHERE id = ?
                        ");
                        $balance_stmt->execute([
                            $recharge['amount'],
                            $recharge['user_id']
                        ]);
                        
                        // 记录余额变动
                        $trans_stmt = $db->prepare("
                            INSERT INTO balance_transactions 
                            (user_id, telegram_id, type, amount, balance_after, related_order, description, created_at)
                            SELECT id, telegram_id, 'recharge', ?, balance, ?, '自动充值', NOW()
                            FROM card_users WHERE id = ?
                        ");
                        $trans_stmt->execute([
                            $recharge['amount'],
                            $recharge['recharge_no'],
                            $recharge['user_id']
                        ]);
                        
                        $db->commit();
                        
                        logMessage("✅ 充值 #{$recharge['recharge_no']} 自动通过，金额: \${$recharge['amount']}", 'SUCCESS');
                        $verified++;
                        
                        // 发送Telegram通知给用户
                        try {
                            require_once dirname(__DIR__) . '/bot/TelegramBot.php';
                            $bot = new TelegramBot(BOT_TOKEN);
                            
                            $message = "✅ <b>充值成功</b>\n\n";
                            $message .= "充值金额：$" . number_format($recharge['amount'], 2) . "\n";
                            $message .= "充值单号：" . $recharge['recharge_no'] . "\n";
                            $message .= "交易哈希：" . ($result['transaction_hash'] ?? 'N/A') . "\n";
                            $message .= "处理方式：自动检测\n";
                            $message .= "时间：" . date('Y-m-d H:i:s') . "\n\n";
                            $message .= "您的余额已更新，感谢使用！";
                            
                            $bot->sendMessage($recharge['telegram_id'], $message, 'HTML');
                            
                        } catch (Exception $e) {
                            logMessage("发送Telegram通知失败: " . $e->getMessage(), 'WARNING');
                        }
                        
                    } catch (Exception $e) {
                        $db->rollBack();
                        logMessage("处理充值失败: " . $e->getMessage(), 'ERROR');
                    }
                    
                } else {
                    // 确认数不够，更新状态为确认中
                    $update_stmt = $db->prepare("
                        UPDATE recharge_records 
                        SET status = 'confirming',
                            transaction_hash = ?
                        WHERE id = ?
                    ");
                    $update_stmt->execute([
                        $result['transaction_hash'] ?? '',
                        $recharge['id']
                    ]);
                    
                    logMessage("⏳ 充值 #{$recharge['recharge_no']} 找到交易，确认中 ({$confirmations}/{$min_confirmations})", 'INFO');
                }
            } else if (!$result['success']) {
                logMessage("❌ 检测充值 #{$recharge['recharge_no']} 失败: " . ($result['error'] ?? '未知错误'), 'ERROR');
            }
            
            // 避免API请求过快
            sleep(2);
        }
        
        logMessage("========== 检测完成 ==========");
        logMessage("总计检测: $checked 条，自动通过: $verified 条", 'INFO');
        
    } catch (Exception $e) {
        logMessage("发生错误: " . $e->getMessage(), 'ERROR');
        logMessage($e->getTraceAsString(), 'ERROR');
    }
}

// 执行主函数
main();
?>

