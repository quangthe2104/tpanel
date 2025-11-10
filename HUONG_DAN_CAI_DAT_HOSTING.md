# Hướng dẫn cài đặt Tpanel trên Hostinger Hosting

## Bước 1: Upload code lên Hostinger

1. Upload tất cả files của Tpanel lên thư mục `public_html` hoặc thư mục website của bạn
2. Đảm bảo cấu trúc thư mục được giữ nguyên

## Lưu ý về API Key

**QUAN TRỌNG**: Hiện tại hệ thống **KHÔNG CẦN API KEY** để hoạt động!

Hệ thống Tpanel sử dụng **SFTP/FTP trực tiếp** để kết nối với Hostinger, không cần API key. Thông tin SFTP/FTP được cấu hình trong database khi bạn thêm website.

### Nếu Hostinger có API trong tương lai:

1. Tạo file `config/hostinger.php` từ `config/hostinger.php.example`
2. Điền API key vào file đó
3. API key sẽ được sử dụng cho các tính năng nâng cao (nếu có)

**Hiện tại bạn chỉ cần:**
- Thông tin SFTP/FTP từ Hostinger (khi thêm website)
- Thông tin MySQL database (khi thêm website)

## Bước 2: Cấu hình Database

### Tạo Database trên Hostinger

1. Đăng nhập vào **hPanel** của Hostinger
2. Vào **Databases** → **MySQL Databases**
3. Tạo database mới:
   - **Database Name**: `tpanel` (hoặc tên bạn muốn)
   - Ghi nhớ tên database đầy đủ (thường có dạng: `username_tpanel`)
4. Tạo MySQL User:
   - **Username**: Tạo user mới hoặc dùng user hiện có
   - **Password**: Tạo password mạnh
   - Ghi nhớ username đầy đủ (thường có dạng: `username_dbuser`)
5. Gán quyền cho user vào database (thường là ALL PRIVILEGES)

### Cấu hình file database.php

1. Vào **File Manager** trong hPanel
2. Tìm file `config/database.php.example`
3. Copy và đổi tên thành `config/database.php`
4. Chỉnh sửa file `config/database.php` với thông tin database vừa tạo:

```php
<?php
return [
    'host' => 'localhost', // Thường là localhost trên Hostinger
    'dbname' => 'username_tpanel', // Tên database đầy đủ từ Hostinger
    'username' => 'username_dbuser', // Username đầy đủ từ Hostinger
    'password' => 'your_password', // Password bạn đã tạo
    'charset' => 'utf8mb4'
];
```

**Lưu ý quan trọng:**
- Trên Hostinger, database name và username thường có prefix (ví dụ: `username_`)
- Host thường là `localhost`
- Copy chính xác tên database và username từ hPanel

## Bước 3: Chạy cài đặt qua trình duyệt

1. Mở trình duyệt
2. Truy cập: `https://yourdomain.com/install.php`
   (Thay `yourdomain.com` bằng domain của bạn)
3. Script sẽ tự động:
   - Kết nối database
   - Tạo các bảng cần thiết
   - Hiển thị thông báo thành công

## Bước 4: Xóa file cài đặt (Bảo mật)

**QUAN TRỌNG**: Sau khi cài đặt xong, XÓA hoặc ĐỔI TÊN file `install.php` để bảo mật!

```bash
# Đổi tên file
mv install.php install.php.bak

# Hoặc xóa file
rm install.php
```

## Bước 5: Đăng nhập và cấu hình

1. Truy cập: `https://yourdomain.com/login.php`
2. Đăng nhập với:
   - **Username**: `admin`
   - **Password**: `admin123`
3. **ĐỔI MẬT KHẨU NGAY** sau khi đăng nhập
4. Thêm website từ Hostinger
5. Phân quyền cho người dùng

## Bước 6: Cấu hình thư mục backups

Đảm bảo thư mục `backups/` có quyền ghi:

1. Vào File Manager
2. Tìm thư mục `backups/`
3. Set permissions: **755** hoặc **777** (tạm thời)
4. Tạo file `.htaccess` trong thư mục `backups/` để bảo vệ:

```apache
# Deny all access
Order Deny,Allow
Deny from all
```

## Troubleshooting

### Lỗi: "Cannot connect to database"
- Kiểm tra lại thông tin trong `config/database.php`
- Đảm bảo database name và username có prefix đúng
- Kiểm tra password có đúng không
- Thử kết nối qua phpMyAdmin để xác nhận

### Lỗi: "Permission denied" khi tạo bảng
- Kiểm tra MySQL user có đủ quyền không
- Đảm bảo user đã được gán vào database

### Lỗi: "File not found" khi truy cập install.php
- Kiểm tra đường dẫn file có đúng không
- Đảm bảo file đã được upload đầy đủ

### Lỗi khi backup: "Cannot write to backups directory"
- Kiểm tra quyền thư mục `backups/`
- Set permissions 755 hoặc 777

### Lỗi HTTP 500 (Internal Server Error)

**Cách debug nhanh:**

1. **Truy cập file debug:**
   - Upload file `debug.php` lên server (nếu chưa có)
   - Truy cập: `https://yourdomain.com/debug.php`
   - File sẽ hiển thị tất cả thông tin lỗi

2. **Kiểm tra PHP Error Logs:**
   - Vào hPanel → **Advanced** → **Error Log**
   - Hoặc xem file `error_log` trong thư mục website

3. **Các nguyên nhân thường gặp:**
   - ❌ File `config/database.php` chưa được tạo
   - ❌ Thông tin database không đúng
   - ❌ Database chưa được tạo
   - ❌ Thiếu PHP extensions (PDO, PDO_MySQL)
   - ❌ Lỗi syntax trong code
   - ❌ File permissions không đúng
   - ❌ File .htaccess có lỗi

4. **Giải pháp:**
   ```bash
   # Kiểm tra file config
   ls -la config/database.php
   
   # Kiểm tra PHP version
   php -v
   
   # Kiểm tra PHP extensions
   php -m | grep pdo
   
   # Kiểm tra error logs
   tail -f error_log
   ```

5. **Tạm thời bật error display** (chỉ để debug):
   - Sửa `config/config.php`:
     ```php
     ini_set('display_errors', 1);
     ```
   - **NHỚ TẮT LẠI** sau khi fix xong!

## Deploy Code Mới (Quan Trọng)

Khi có code mới trên GitHub và muốn cập nhật lên server:

### Cách An Toàn (Không Ghi Đè File Config):

1. **Backup file config trước**:
   ```bash
   cp config/database.php config/database.php.backup
   ```

2. **Pull code mới**:
   ```bash
   git pull origin main
   ```

3. **Restore file config**:
   ```bash
   mv config/database.php.backup config/database.php
   ```

**Lưu ý**: File `config/database.php` đã được loại trừ khỏi Git (có trong .gitignore), nên thường sẽ không bị ghi đè. Nhưng nên backup để an toàn.

Xem file **`HUONG_DAN_DEPLOY_AN_TOAN.md`** để biết chi tiết.

## Lưu ý bảo mật

1. ✅ Xóa file `install.php` sau khi cài đặt
2. ✅ Đổi mật khẩu admin ngay sau khi đăng nhập
3. ✅ **KHÔNG commit** file `config/database.php` lên Git (đã có trong .gitignore)
4. ✅ Sử dụng HTTPS (SSL) cho website
5. ✅ Giới hạn quyền truy cập vào thư mục `backups/`
6. ✅ Cập nhật code thường xuyên
7. ✅ Backup file config trước khi deploy code mới

## Cấu trúc thư mục trên Hostinger

```
public_html/
├── config/
│   ├── config.php
│   ├── database.php (tạo từ .example)
│   └── database.php.example
├── includes/
├── database/
│   └── schema.sql
├── backups/ (tự động tạo)
├── install.php (xóa sau khi cài)
├── login.php
├── index.php
└── ...
```

## Hỗ trợ

Nếu gặp vấn đề, kiểm tra:
1. PHP error logs trong hPanel
2. MySQL error logs
3. File permissions
4. Database connection settings
