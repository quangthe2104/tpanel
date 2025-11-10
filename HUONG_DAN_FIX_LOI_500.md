# Hướng dẫn Fix Lỗi HTTP 500

## Lỗi HTTP 500 là gì?

HTTP 500 (Internal Server Error) là lỗi server, thường do:
- Lỗi PHP code
- Lỗi kết nối database
- Thiếu file hoặc extension
- Lỗi cấu hình

## Cách Debug Nhanh

### Bước 1: Sử dụng Debug Script

1. Upload file `debug.php` lên server (nếu chưa có)
2. Truy cập: `https://yourdomain.com/debug.php`
3. File sẽ hiển thị:
   - PHP version
   - PHP extensions
   - File configuration
   - Database connection
   - File permissions
   - Syntax errors

### Bước 2: Kiểm tra Error Logs

**Trong hPanel:**
1. Vào **Advanced** → **Error Log**
2. Xem các lỗi gần nhất

**Hoặc xem file error_log:**
```bash
# Trong File Manager, tìm file error_log
# Hoặc dùng SSH:
tail -f error_log
```

### Bước 3: Bật Error Display (Tạm thời)

Sửa file `config/config.php`:
```php
ini_set('display_errors', 1);  // Thay vì 0
```

**⚠️ QUAN TRỌNG**: Tắt lại sau khi fix xong!

## Các Lỗi Thường Gặp và Cách Fix

### 1. Lỗi: "File config/database.php không tồn tại"

**Nguyên nhân**: Chưa tạo file config

**Giải pháp**:
1. Vào File Manager
2. Copy `config/database.php.example` → `config/database.php`
3. Chỉnh sửa với thông tin database của bạn

### 2. Lỗi: "Cannot connect to database"

**Nguyên nhân**: Thông tin database sai

**Giải pháp**:
1. Kiểm tra lại thông tin trong `config/database.php`
2. Đảm bảo database name và username có prefix đúng (ví dụ: `username_tpanel`)
3. Test kết nối qua phpMyAdmin

### 3. Lỗi: "Class 'PDO' not found"

**Nguyên nhân**: Thiếu PHP extension PDO

**Giải pháp**:
- Liên hệ Hostinger support để bật PDO extension
- Hoặc kiểm tra trong hPanel → PHP Settings

### 4. Lỗi: "Call to undefined function"

**Nguyên nhân**: Thiếu PHP extension hoặc file

**Giải pháp**:
- Kiểm tra file có tồn tại không
- Kiểm tra extension đã được load chưa (xem debug.php)

### 5. Lỗi: "Permission denied"

**Nguyên nhân**: File permissions không đúng

**Giải pháp**:
```bash
# Set permissions cho thư mục
chmod 755 config/
chmod 755 backups/
chmod 644 config/*.php
```

### 6. Lỗi: "Parse error" hoặc "Syntax error"

**Nguyên nhân**: Lỗi cú pháp PHP

**Giải pháp**:
1. Xem chi tiết lỗi trong error log
2. Sửa file bị lỗi
3. Hoặc download lại code từ GitHub

## Checklist Debug

- [ ] File `config/database.php` đã được tạo chưa?
- [ ] Thông tin database có đúng không?
- [ ] Database đã được tạo trên Hostinger chưa?
- [ ] PHP version >= 7.4?
- [ ] PHP extensions (PDO, PDO_MySQL) đã được bật?
- [ ] File permissions đúng chưa?
- [ ] Có lỗi syntax trong code không?
- [ ] File .htaccess có lỗi không?

## Cách Test Từng Bước

1. **Test config file:**
   ```php
   <?php
   require 'config/database.php';
   var_dump($dbConfig);
   ```

2. **Test database connection:**
   ```php
   <?php
   $dbConfig = require 'config/database.php';
   $pdo = new PDO("mysql:host={$dbConfig['host']}", $dbConfig['username'], $dbConfig['password']);
   echo "Connected!";
   ```

3. **Test includes:**
   ```php
   <?php
   require 'includes/Database.php';
   echo "OK";
   ```

## Sau Khi Fix

1. ✅ Tắt `display_errors` trong `config/config.php`
2. ✅ Xóa file `debug.php`
3. ✅ Kiểm tra lại website hoạt động bình thường

## Liên Hệ Hỗ Trợ

Nếu vẫn không fix được:
1. Chụp screenshot lỗi từ `debug.php`
2. Copy error log
3. Liên hệ support hoặc tạo issue trên GitHub
