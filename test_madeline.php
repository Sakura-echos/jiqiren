<?php
// 引入 Composer 自动加载
require_once 'vendor/autoload.php';

// 引入 MadelineProto
use danog\MadelineProto\API;

try {
    echo "========================================\n";
    echo "MadelineProto 测试\n";
    echo "========================================\n\n";
    echo "MadelineProto 版本: " . API::RELEASE . "\n";
    echo "PHP 版本: " . PHP_VERSION . "\n";
    echo "\n✅ 安装成功！\n";
    echo "========================================\n";
} catch (Exception $e) {
    echo "❌ 错误: " . $e->getMessage() . "\n";
    exit(1);
}
