# Hướng dẫn Deploy An Toàn - Không Ghi Đè File Config

## Vấn đề

Khi push code lên GitHub và tự động deploy lên server, các file cấu hình riêng trên server (như `config/database.php`) có thể bị ghi đè bởi code mới.

## Giải pháp

### Cách 1: Sử dụng .gitignore (Đã có sẵn) ✅

Các file config đã được loại trừ khỏi Git:
- `config/database.php` - **KHÔNG được commit**
- `config/hostinger.php` - **KHÔNG được commit**
- `config/config.php` - **KHÔNG được commit** (chứa BASE_URL riêng cho mỗi môi trường)

Chỉ có file `.example` được commit:
- `config/database.php.example` ✅
- `config/hostinger.php.example` ✅
- `config/config.php.example` ✅

**Kết quả**: Khi pull code mới, file config trên server sẽ **KHÔNG bị ghi đè**.

### Cách 2: Sử dụng Script Deploy An Toàn

#### Trên Linux/Unix Server:

1. Tạo file `deploy_safe.sh` (đã có sẵn trong repo)
2. Set quyền thực thi:
   ```bash
   chmod +x deploy_safe.sh
   ```
3. Chạy script khi deploy:
   ```bash
   ./deploy_safe.sh
   ```

Script sẽ:
- Backup file config trước khi pull
- Pull code mới từ Git
- Restore file config sau khi pull

#### Trên Windows Server (PowerShell):

Tạo file `deploy_safe.ps1`:

```powershell
# Backup config files
if (Test-Path "config\database.php") {
    Copy-Item "config\database.php" "config\database.php.backup"
    Write-Host "✓ Đã backup config/database.php"
}
if (Test-Path "config\config.php") {
    Copy-Item "config\config.php" "config\config.php.backup"
    Write-Host "✓ Đã backup config/config.php"
}
if (Test-Path "config\hostinger.php") {
    Copy-Item "config\hostinger.php" "config\hostinger.php.backup"
    Write-Host "✓ Đã backup config/hostinger.php"
}

# Pull code
git pull origin main

# Restore config files
if (Test-Path "config\database.php.backup") {
    Move-Item "config\database.php.backup" "config\database.php" -Force
    Write-Host "✓ Đã restore config/database.php"
}
if (Test-Path "config\config.php.backup") {
    Move-Item "config\config.php.backup" "config\config.php" -Force
    Write-Host "✓ Đã restore config/config.php"
}
if (Test-Path "config\hostinger.php.backup") {
    Move-Item "config\hostinger.php.backup" "config\hostinger.php" -Force
    Write-Host "✓ Đã restore config/hostinger.php"
}
```

### Cách 3: Sử dụng Git Hooks (Tự động)

Tạo file `.git/hooks/post-merge` trên server:

```bash
#!/bin/bash
# Script tự động chạy sau mỗi lần git pull/merge

# Kiểm tra file config có tồn tại không
if [ ! -f "config/database.php" ]; then
    if [ -f "config/database.php.example" ]; then
        cp config/database.php.example config/database.php
        echo "Đã tạo config/database.php từ .example"
    fi
fi

if [ ! -f "config/config.php" ]; then
    if [ -f "config/config.php.example" ]; then
        cp config/config.php.example config/config.php
        echo "Đã tạo config/config.php từ .example"
        echo "⚠️ NHỚ SỬA BASE_URL trong config/config.php!"
    fi
fi

# Đảm bảo file config không bị ghi đè
git update-index --assume-unchanged config/database.php 2>/dev/null
git update-index --assume-unchanged config/hostinger.php 2>/dev/null
git update-index --assume-unchanged config/config.php 2>/dev/null
```

Set quyền thực thi:
```bash
chmod +x .git/hooks/post-merge
```

### Cách 4: Sử dụng GitHub Actions với Deploy Script

Nếu bạn dùng GitHub Actions để auto-deploy, thêm step backup/restore:

```yaml
- name: Backup config files
  run: |
    if [ -f "config/database.php" ]; then
      cp config/database.php config/database.php.backup
    fi

- name: Deploy code
  run: git pull origin main

- name: Restore config files
  run: |
    if [ -f "config/database.php.backup" ]; then
      mv config/database.php.backup config/database.php
    fi
```

## Kiểm tra File Config Có Bị Ghi Đè Không

Sau khi deploy, kiểm tra:

```bash
# Xem file config có tồn tại không
ls -la config/database.php

# Xem nội dung (đảm bảo vẫn là config của server)
cat config/database.php
```

## Best Practices

1. ✅ **Luôn backup** file config trước khi deploy
2. ✅ **Sử dụng .gitignore** để loại trừ file config
3. ✅ **Tạo file .example** để người khác biết cách cấu hình
4. ✅ **Document** cách deploy an toàn
5. ✅ **Test** trên staging trước khi deploy production

## Lưu Ý Quan Trọng

⚠️ **KHÔNG BAO GIỜ** commit các file config sau lên Git:
- `config/database.php`
- `config/hostinger.php`
- `config/config.php`

Nếu vô tình commit, xóa ngay:
```bash
git rm --cached config/database.php
git rm --cached config/config.php
git rm --cached config/hostinger.php
git commit -m "Remove config files from Git"
git push
```

## Troubleshooting

### File config bị ghi đè sau khi pull

**Nguyên nhân**: File config có thể đã được commit trước đó

**Giải pháp**:
1. Kiểm tra Git history:
   ```bash
   git log --all --full-history -- config/database.php
   ```
2. Nếu file đã được commit, xóa khỏi Git:
   ```bash
   git rm --cached config/database.php
   git commit -m "Remove config from Git"
   ```
3. Thêm vào .gitignore (đã có sẵn)
4. Restore file config từ backup hoặc tạo lại

### File config bị xóa sau khi pull

**Nguyên nhân**: File không tồn tại trong Git và bị xóa

**Giải pháp**:
1. Tạo lại từ .example:
   ```bash
   cp config/database.php.example config/database.php
   ```
2. Điền thông tin cấu hình
3. File sẽ không bị xóa lần sau vì đã có .gitignore

## Tóm Tắt

✅ **File config đã được bảo vệ** bởi .gitignore  
✅ **Chỉ file .example được commit**  
✅ **File config trên server sẽ không bị ghi đè** khi pull code  
✅ **Có script deploy an toàn** nếu cần thêm bảo vệ
