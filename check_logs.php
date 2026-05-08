<?php
/**
 * 实时查看最新日志
 */

$log_file = __DIR__ . '/bot/debug.log';

header('Content-Type: text/html; charset=utf-8');
echo "<h1>🔍 机器人调试日志 - 实时查看</h1>";
echo "<p>日志文件: <code>{$log_file}</code></p>";
echo "<hr>";

if (!file_exists($log_file)) {
    echo "<p style='color: red;'>❌ 日志文件不存在</p>";
    echo "<p>请先与机器人交互以生成日志</p>";
    exit;
}

// 读取最后100行
$lines = file($log_file);
$total_lines = count($lines);
$show_lines = 100;
$recent_lines = array_slice($lines, -$show_lines);

echo "<p><strong>总共 {$total_lines} 行，显示最后 {$show_lines} 行</strong></p>";
echo "<p><button onclick='location.reload()'>🔄 刷新</button></p>";

echo "<div style='background: #f5f5f5; padding: 15px; border-radius: 5px;'>";
echo "<pre style='margin: 0; overflow: auto; max-height: 600px;'>";

foreach ($recent_lines as $line) {
    $line = htmlspecialchars($line);
    
    // 高亮显示重要的日志
    if (strpos($line, 'Language') !== false || strpos($line, 'Lang') !== false) {
        echo "<span style='background: yellow; font-weight: bold;'>{$line}</span>";
    } elseif (strpos($line, 'ERROR') !== false || strpos($line, 'error') !== false) {
        echo "<span style='color: red; font-weight: bold;'>{$line}</span>";
    } elseif (strpos($line, 'callback') !== false) {
        echo "<span style='color: blue;'>{$line}</span>";
    } else {
        echo $line;
    }
}

echo "</pre>";
echo "</div>";

echo "<hr>";
echo "<h2>📋 操作说明</h2>";
echo "<ol>";
echo "<li>打开这个页面</li>";
echo "<li>给机器人发送 /start</li>";
echo "<li>点击 Language 按钮</li>";
echo "<li>点击上面的「刷新」按钮查看日志</li>";
echo "<li>查找黄色高亮的 Language 相关日志</li>";
echo "</ol>";

echo "<style>
body { font-family: Arial, sans-serif; padding: 20px; max-width: 1200px; margin: 0 auto; }
button { padding: 10px 20px; background: #2196f3; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
button:hover { background: #1976d2; }
</style>";

echo "<script>
// 自动刷新（可选）
// setInterval(function(){ location.reload(); }, 5000);
</script>";
?>

