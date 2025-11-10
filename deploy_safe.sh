#!/bin/bash
# Script deploy an toàn - không ghi đè file config trên server

echo "=== Tpanel Safe Deploy ==="
echo ""

# Backup các file config hiện có
echo "Đang backup file config..."
if [ -f "config/database.php" ]; then
    cp config/database.php config/database.php.backup
    echo "✓ Đã backup config/database.php"
fi

if [ -f "config/hostinger.php" ]; then
    cp config/hostinger.php config/hostinger.php.backup
    echo "✓ Đã backup config/hostinger.php"
fi

# Pull code mới
echo ""
echo "Đang pull code mới từ Git..."
git pull origin main

# Restore các file config
echo ""
echo "Đang restore file config..."
if [ -f "config/database.php.backup" ]; then
    mv config/database.php.backup config/database.php
    echo "✓ Đã restore config/database.php"
fi

if [ -f "config/hostinger.php.backup" ]; then
    mv config/hostinger.php.backup config/hostinger.php
    echo "✓ Đã restore config/hostinger.php"
fi

echo ""
echo "=== Deploy hoàn tất! ==="
