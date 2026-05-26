#!/bin/bash
# GEO+AI 智能内容生成系统 - 启动脚本
# 
# @author AI Assistant
# @version 2.0
# @date 2026-02-02

clear
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  🚀 GEO+AI 智能内容生成系统"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# 获取脚本所在目录
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
cd "$SCRIPT_DIR"

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 1. 检查PHP是否安装
echo "📋 环境检查..."
echo ""

if ! command -v php &> /dev/null; then
    echo -e "${RED}❌ 错误：未找到PHP${NC}"
    echo "请先安装PHP 7.4或更高版本"
    echo ""
    echo "macOS安装方法："
    echo "  brew install php"
    echo ""
    exit 1
fi

# 显示PHP版本
PHP_VERSION=$(php --version | head -n 1)
echo -e "${GREEN}✅ PHP版本：${NC}$PHP_VERSION"

# 2. 检查PostgreSQL扩展
if ! php -m | grep -q "pdo_pgsql"; then
    echo -e "${RED}❌ 错误：未找到PostgreSQL扩展${NC}"
    echo "请安装pdo_pgsql扩展"
    exit 1
fi
echo -e "${GREEN}✅ PostgreSQL扩展：${NC}已安装"

# 3. 检查必要的PHP扩展
REQUIRED_EXTENSIONS=("json" "mbstring" "session")
for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if php -m | grep -q "$ext"; then
        echo -e "${GREEN}✅ $ext扩展：${NC}已安装"
    else
        echo -e "${YELLOW}⚠️  $ext扩展：${NC}未安装（可选）"
    fi
done

echo ""

# 4. 检查PostgreSQL环境变量
DB_HOST_VALUE="${DB_HOST:-127.0.0.1}"
DB_PORT_VALUE="${DB_PORT:-5432}"
DB_NAME_VALUE="${DB_NAME:-geo_system}"
DB_USER_VALUE="${DB_USER:-geo_user}"
echo -e "${GREEN}✅ 数据库配置：${NC}${DB_USER_VALUE}@${DB_HOST_VALUE}:${DB_PORT_VALUE}/${DB_NAME_VALUE}"

# 5. 检查关键目录
DIRS=("data/backups" "logs" "uploads/images" "uploads/knowledge")
for dir in "${DIRS[@]}"; do
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
        echo -e "${GREEN}✅ 创建目录：${NC}$dir"
    fi
done

# 6. 检查目录权限
if [ ! -w "data" ]; then
    echo -e "${YELLOW}⚠️  警告：${NC}data 目录不可写"
    echo "   执行: chmod -R 755 data"
fi

if [ ! -w "logs" ]; then
    echo -e "${YELLOW}⚠️  警告：${NC}logs 目录不可写"
    echo "   执行: chmod -R 755 logs"
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  🌐 服务器信息"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo -e "${BLUE}🏠 前台首页：${NC}http://localhost:8080"
echo -e "${BLUE}⚙️  管理后台：${NC}http://localhost:8080/geo_admin/"
echo -e "${BLUE}📊 系统诊断：${NC}http://localhost:8080/geo_admin/system_diagnostics.php"
echo ""
echo -e "${YELLOW}🔐 默认管理员账户：${NC}"
echo "   用户名: admin"
echo "   密码: 请设置 INITIAL_ADMIN_PASSWORD；未设置时读取 /tmp/geoflow_initial_admin_credentials.txt"
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo -e "${GREEN}💡 提示：${NC}"
echo "   • 按 Ctrl+C 停止服务器"
echo "   • 日志文件位于 logs/ 目录"
echo "   • 首次使用请修改管理员密码并设置强 APP_SECRET_KEY"
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "🚀 正在启动服务器..."
echo ""

# 启动服务器（使用路由器支持URL重写）
php -S localhost:8080 router.php
