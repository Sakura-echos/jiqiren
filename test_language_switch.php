<?php
/**
 * 语言切换诊断工具
 * 用于检查语言切换功能是否正常
 */

require_once 'config.php';

$db = getDB();

echo "<h1>🔧 语言切换诊断工具</h1>";
echo "<hr>";

// 1. 检查language_settings表
echo "<h2>1️⃣ 检查语言设置表</h2>";
try {
    $stmt = $db->query("SELECT id, name, code, status, is_default FROM language_settings ORDER BY sort_order");
    $languages = $stmt->fetchAll();
    
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f0f0f0;'>
            <th>ID</th>
            <th>语言名称</th>
            <th>语言代码</th>
            <th>状态</th>
            <th>默认</th>
            <th>机器人可见</th>
          </tr>";
    
    foreach ($languages as $lang) {
        $status_color = $lang['status'] == 'active' ? 'green' : 'red';
        $visible = $lang['status'] == 'active' ? '✅ 是' : '❌ 否';
        
        echo "<tr>";
        echo "<td>{$lang['id']}</td>";
        echo "<td>{$lang['name']}</td>";
        echo "<td><code>{$lang['code']}</code></td>";
        echo "<td style='color: {$status_color}; font-weight: bold;'>{$lang['status']}</td>";
        echo "<td>" . ($lang['is_default'] ? '⭐' : '-') . "</td>";
        echo "<td>{$visible}</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
    $active_count = count(array_filter($languages, function($l) { return $l['status'] == 'active'; }));
    echo "<p><strong>✅ 已启用语言数量: {$active_count}</strong></p>";
    
    if ($active_count < 2) {
        echo "<p style='color: red;'><strong>⚠️ 警告：只有 {$active_count} 个语言启用，用户无法切换！</strong></p>";
        echo "<p>解决方法：在后台语言设置页面点击「启用」按钮</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ 错误: " . $e->getMessage() . "</p>";
}

echo "<hr>";

// 2. 检查card_users表
echo "<h2>2️⃣ 检查用户表</h2>";
try {
    $stmt = $db->query("
        SELECT telegram_id, username, first_name, language, created_at 
        FROM card_users 
        ORDER BY id DESC 
        LIMIT 10
    ");
    $users = $stmt->fetchAll();
    
    if (empty($users)) {
        echo "<p style='color: orange;'>⚠️ 用户表为空，还没有用户使用过机器人</p>";
    } else {
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
        echo "<tr style='background: #f0f0f0;'>
                <th>Telegram ID</th>
                <th>用户名</th>
                <th>姓名</th>
                <th>当前语言</th>
                <th>注册时间</th>
              </tr>";
        
        foreach ($users as $user) {
            $lang_display = $user['language'] ?: '未设置';
            echo "<tr>";
            echo "<td>{$user['telegram_id']}</td>";
            echo "<td>" . ($user['username'] ?: '-') . "</td>";
            echo "<td>" . ($user['first_name'] ?: '-') . "</td>";
            echo "<td><code>{$lang_display}</code></td>";
            echo "<td>{$user['created_at']}</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        
        echo "<p><strong>✅ 最近10个用户</strong></p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ 错误: " . $e->getMessage() . "</p>";
}

echo "<hr>";

// 3. 检查语言包
echo "<h2>3️⃣ 检查语言包</h2>";
require_once 'includes/language_pack.php';

$test_codes = ['zh_CN', 'zh_TW', 'en_US', 'ru_RU'];
echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr style='background: #f0f0f0;'>
        <th>语言代码</th>
        <th>测试文本 (welcome)</th>
        <th>状态</th>
      </tr>";

foreach ($test_codes as $code) {
    $text = getLang('welcome', $code);
    $status = $text != 'welcome' ? '✅ 正常' : '❌ 缺失';
    
    echo "<tr>";
    echo "<td><code>{$code}</code></td>";
    echo "<td>{$text}</td>";
    echo "<td>{$status}</td>";
    echo "</tr>";
}

echo "</table>";

echo "<hr>";

// 4. 测试建议
echo "<h2>4️⃣ 测试步骤</h2>";
echo "<ol style='line-height: 2;'>";
echo "<li><strong>确保至少启用2个语言</strong>（如果上面只有1个语言是绿色的，去后台点击「启用」）</li>";
echo "<li>给机器人发送 <code>/start</code></li>";
echo "<li>点击 <strong>🌐 Language</strong> 按钮</li>";
echo "<li>选择一个语言（如 English）</li>";
echo "<li>返回主菜单，检查是否变成对应语言</li>";
echo "</ol>";

echo "<hr>";

// 5. 查看日志
echo "<h2>5️⃣ 查看调试日志</h2>";
$log_file = __DIR__ . '/bot/debug.log';

if (file_exists($log_file)) {
    echo "<p>日志文件位置: <code>{$log_file}</code></p>";
    
    $lines = file($log_file);
    $recent_lines = array_slice($lines, -50); // 最后50行
    
    echo "<h3>最近的日志（最后50行）：</h3>";
    echo "<pre style='background: #f5f5f5; padding: 15px; overflow: auto; max-height: 400px;'>";
    foreach ($recent_lines as $line) {
        // 高亮显示语言相关的日志
        if (strpos($line, 'Language') !== false) {
            echo "<span style='background: yellow;'>" . htmlspecialchars($line) . "</span>";
        } else {
            echo htmlspecialchars($line);
        }
    }
    echo "</pre>";
} else {
    echo "<p style='color: orange;'>⚠️ 日志文件不存在: {$log_file}</p>";
    echo "<p>日志会在用户与机器人交互后生成</p>";
}

echo "<hr>";
echo "<p style='color: #666;'><em>诊断完成 - " . date('Y-m-d H:i:s') . "</em></p>";
?>

<style>
    body {
        font-family: Arial, sans-serif;
        padding: 20px;
        max-width: 1200px;
        margin: 0 auto;
    }
    h1 { color: #333; }
    h2 { 
        color: #2196f3; 
        margin-top: 30px;
        padding-bottom: 10px;
        border-bottom: 2px solid #2196f3;
    }
    table {
        width: 100%;
        margin: 20px 0;
    }
    code {
        background: #f0f0f0;
        padding: 2px 6px;
        border-radius: 3px;
    }
</style>

