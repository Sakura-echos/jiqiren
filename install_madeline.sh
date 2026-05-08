#!/bin/bash
# MadelineProto 自动安装脚本
# 使用方法: bash install_madeline.sh

echo "================================"
echo "MadelineProto 安装脚本"
echo "================================"
echo ""

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 检查 PHP 版本
echo "正在检查 PHP 版本..."
PHP_VERSION=$(php -v | head -n 1 | cut -d " " -f 2 | cut -d "." -f 1,2)
echo "当前 PHP 版本: $PHP_VERSION"

if [ "$(echo "$PHP_VERSION < 7.4" | bc)" -eq 1 ]; then
    echo -e "${RED}错误: PHP 版本必须 >= 7.4${NC}"
    exit 1
fi
echo -e "${GREEN}✓ PHP 版本符合要求${NC}"
echo ""

# 检查必需扩展
echo "正在检查 PHP 扩展..."
REQUIRED_EXTENSIONS=("openssl" "mbstring" "curl" "json")
MISSING_EXTENSIONS=()

for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if ! php -m | grep -q "^$ext$"; then
        MISSING_EXTENSIONS+=("$ext")
    fi
done

if [ ${#MISSING_EXTENSIONS[@]} -gt 0 ]; then
    echo -e "${YELLOW}警告: 缺少以下扩展: ${MISSING_EXTENSIONS[*]}${NC}"
    echo "请先安装缺失的扩展："
    echo "Ubuntu/Debian: sudo apt install php-${MISSING_EXTENSIONS[0]}"
    echo "CentOS/RHEL: sudo yum install php-${MISSING_EXTENSIONS[0]}"
    exit 1
fi
echo -e "${GREEN}✓ 所有必需扩展已安装${NC}"
echo ""

# 检查 Composer
echo "正在检查 Composer..."
if ! command -v composer &> /dev/null; then
    echo -e "${YELLOW}Composer 未安装，正在安装...${NC}"
    
    # 下载 Composer
    curl -sS https://getcomposer.org/installer | php
    
    # 移动到系统路径
    sudo mv composer.phar /usr/local/bin/composer
    
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ Composer 安装成功${NC}"
    else
        echo -e "${RED}错误: Composer 安装失败${NC}"
        exit 1
    fi
else
    echo -e "${GREEN}✓ Composer 已安装${NC}"
    composer --version
fi
echo ""

# 更新 Composer
echo "正在更新 Composer..."
composer self-update
composer clear-cache

# 安装 MadelineProto 8.x
echo "正在安装 MadelineProto 8.x..."
echo "这可能需要几分钟，请耐心等待..."
echo ""

# 增加内存限制
export COMPOSER_MEMORY_LIMIT=-1

# 安装 8.x 版本
composer require "danog/madelineproto:^8.0" --ignore-platform-reqs -vvv

if [ $? -eq 0 ]; then
    echo ""
    echo -e "${GREEN}✓ MadelineProto 安装成功！${NC}"
else
    echo ""
    echo -e "${RED}错误: MadelineProto 安装失败${NC}"
    echo "请尝试手动安装："
    echo "composer require danog/madelineproto"
    exit 1
fi
echo ""

# 创建 sessions 目录
echo "正在创建 sessions 目录..."
mkdir -p sessions
chmod 700 sessions

# 创建 .htaccess 保护 sessions 目录
cat > sessions/.htaccess << 'EOF'
Deny from all
EOF

# 创建 index.php 防止目录浏览
cat > sessions/index.php << 'EOF'
<?php
// Silence is golden
EOF

echo -e "${GREEN}✓ sessions 目录创建成功${NC}"
echo ""

# 创建测试脚本
echo "正在创建测试脚本..."
cat > test_madeline.php << 'EOF'
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
EOF

echo -e "${GREEN}✓ 测试脚本创建成功${NC}"
echo ""

# 运行测试
echo "正在运行测试..."
php test_madeline.php

if [ $? -eq 0 ]; then
    echo ""
    echo "================================"
    echo -e "${GREEN}✅ 安装完成！${NC}"
    echo "================================"
    echo ""
    echo "下一步："
    echo "1. 查看完整文档: cat MADELINEPROTO_INSTALL_GUIDE.md"
    echo "2. 集成到项目: 修改 api/user_account.php"
    echo "3. 测试登录: php login_user.php"
    echo ""
else
    echo ""
    echo -e "${RED}测试失败，请检查错误信息${NC}"
    exit 1
fi

