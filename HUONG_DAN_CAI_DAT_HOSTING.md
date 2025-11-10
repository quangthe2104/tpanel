# Hướng dẫn cài đặt Tpanel trên Hostinger Hosting

## Bước 1: Upload code lên Hostinger

1. Upload tất cả files của Tpanel lên thư mục `public_html` hoặc thư mục website của bạn
2. Đảm bảo cấu trúc thư mục được giữ nguyên

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

## Lưu ý bảo mật

1. ✅ Xóa file `install.php` sau khi cài đặt
2. ✅ Đổi mật khẩu admin ngay sau khi đăng nhập
3. ✅ Không commit file `config/database.php` lên Git
4. ✅ Sử dụng HTTPS (SSL) cho website
5. ✅ Giới hạn quyền truy cập vào thư mục `backups/`
6. ✅ Cập nhật code thường xuyên

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
