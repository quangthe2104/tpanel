# Tpanel - Quản lý Website trên Hostinger

Hệ thống quản lý website tích hợp với Hostinger, cho phép quản lý nhiều website thông qua SFTP/FTP và MySQL.

> **Lưu ý cho WAMP users**: Nếu PHP không có trong PATH, hãy chạy file `install.bat` thay vì `php install.php`

## Tính năng

- ✅ **Quản lý người dùng**: Tạo tài khoản và phân quyền cho từng website
- ✅ **File Manager**: Quản lý files qua SFTP/FTP kết nối với Hostinger
- ✅ **Database Manager**: Quản lý MySQL database
- ✅ **Backup & Restore**: Tạo và download backup files và database
- ✅ **Thống kê dung lượng**: Hiển thị dung lượng sử dụng của website

## Yêu cầu hệ thống

- PHP 7.4 trở lên
- MySQL 5.7 trở lên hoặc MariaDB
- PHP Extensions:
  - PDO
  - PDO_MySQL
  - SSH2 (cho SFTP) - `php-ssh2` extension (khuyến nghị)
  - FTP (cho FTP - có sẵn trong PHP)
  - ZipArchive (có sẵn trong PHP)
  - cURL (cho API nếu có - có sẵn trong PHP)

## Cài đặt

### 1. Cài đặt Database

**Cách 1: Sử dụng script tự động (Khuyến nghị)**

**Trên Windows với WAMP:**
- Chạy file `install.bat` (double-click hoặc chạy từ Command Prompt)
- Script sẽ tự động tìm PHP trong WAMP và chạy cài đặt

**Hoặc chạy thủ công:**
```bash
# Tìm đường dẫn PHP trong WAMP (thường là)
C:\wamp64\bin\php\php8.2.0\php.exe install.php

# Hoặc nếu PHP đã có trong PATH
php install.php
```

**Cách 2: Import qua phpMyAdmin (Dễ nhất)**
1. Mở phpMyAdmin (http://localhost/phpmyadmin)
2. Tạo database mới tên `tpanel` (nếu chưa có)
3. Chọn database `tpanel`
4. Vào tab **Import**
5. Chọn file `database/schema.sql`
6. Click **Go**

**Cách 3: Sử dụng MySQL Command Line**
```bash
# Trên Command Prompt (cmd), không phải PowerShell
mysql -u root -p < database\schema.sql

# Hoặc trên PowerShell
Get-Content database\schema.sql | mysql -u root -p
```

### 2. Cấu hình

#### Cấu hình Database (`config/database.php`)
```php
return [
    'host' => 'localhost',
    'dbname' => 'tpanel',
    'username' => 'root',
    'password' => 'your_password',
];
```

#### Cấu hình Hostinger (`config/hostinger.php`)
```php
return [
    'api_url' => 'https://api.hostinger.com/v1', // Nếu Hostinger có API
    'api_key' => '', // API Key từ Hostinger
    'api_secret' => '',
];
```

### 3. Cài đặt PHP Extensions

#### Trên Windows (WAMP):
1. Tải `php_ssh2.dll` từ PECL hoặc từ internet
2. Copy vào thư mục `php/ext/`
3. Thêm vào `php.ini`:
```ini
extension=ssh2
```

#### Trên Linux:
```bash
sudo apt-get install libssh2-1-dev
sudo pecl install ssh2
# Thêm extension=ssh2 vào php.ini
```

### 4. Cấu hình

#### Cấu hình Database (`config/database.php`)
```php
return [
    'host' => 'localhost',
    'dbname' => 'tpanel',
    'username' => 'root',
    'password' => 'your_password',
    'charset' => 'utf8mb4'
];
```

#### Cấu hình Hostinger API (Tùy chọn - `config/hostinger.php`)
**LƯU Ý**: Hiện tại **KHÔNG CẦN** cấu hình API key!

Hệ thống hoạt động qua SFTP/FTP được cấu hình trực tiếp trong database khi thêm website. File `config/hostinger.php` chỉ dự phòng cho tương lai nếu Hostinger cung cấp API.

**Bạn chỉ cần:**
- Thông tin SFTP/FTP từ Hostinger (khi thêm website trong admin panel)
- Thông tin MySQL database (khi thêm website)

### 5. Thêm Website

1. Đăng nhập với tài khoản admin (mặc định: `admin` / `admin123`)
2. Vào **Quản lý Website** → **Thêm Website**
3. Điền thông tin:
   - **Tên website**: Tên hiển thị
   - **Domain**: Domain của website
   - **Đường dẫn**: Đường dẫn trên Hostinger (ví dụ: `/public_html` hoặc `/domains/domain.com/public_html`)
   - **Loại kết nối**: SFTP (khuyến nghị) hoặc FTP
   - **SFTP/FTP Host**: Host từ Hostinger (ví dụ: `ftp.yourdomain.com` hoặc IP)
   - **SFTP/FTP Port**: 22 cho SFTP, 21 cho FTP
   - **SFTP/FTP Username**: Username từ Hostinger
   - **SFTP/FTP Password**: Password từ Hostinger
   - **Database**: Thông tin MySQL (nếu có)
     - **DB Host**: Thường là `localhost`
     - **DB Name**: Tên database
     - **DB User**: Username database
     - **DB Password**: Password database

### 6. Phân quyền người dùng

1. Vào **Quản lý Người dùng** → Tạo user mới
2. Click **Phân quyền** cho user
3. Chọn website và quyền:
   - Quản lý Files
   - Quản lý Database
   - Backup
   - Xem thống kê

## Lấy thông tin SFTP/FTP từ Hostinger

1. Đăng nhập vào **hPanel** của Hostinger
2. Vào **Files** → **FTP Accounts**
3. Tạo hoặc xem thông tin FTP account
4. Copy các thông tin:
   - **Host**: Thường là `ftp.yourdomain.com` hoặc IP
   - **Username**: Username FTP
   - **Password**: Password FTP
   - **Port**: 21 cho FTP, 22 cho SFTP

## Sử dụng

### Đăng nhập
- URL: `http://localhost/tpanel/login.php`
- Admin mặc định: 
  - Username: `admin`
  - Password: `admin123`
- ⚠️ **QUAN TRỌNG**: Đổi mật khẩu admin ngay sau khi đăng nhập lần đầu!

### Quản lý Website
- Admin có thể thêm, sửa, xóa website
- Mỗi website cần cấu hình thông tin SFTP/FTP từ Hostinger

### File Manager
- Xem, tạo, sửa, xóa files và thư mục
- Upload files
- Chỉnh sửa file trực tiếp

### Database Manager
- Xem danh sách tables
- Xem cấu trúc và dữ liệu table
- Thực thi SQL queries

### Backup
- Tạo backup full (files + database)
- Tạo backup chỉ files
- Tạo backup chỉ database
- Download backup đã tạo

## Bảo mật

⚠️ **Lưu ý quan trọng**:
- Đổi mật khẩu admin ngay sau khi cài đặt
- Sử dụng HTTPS trong môi trường production
- Bảo vệ file `config/` không được truy cập trực tiếp
- Giới hạn quyền truy cập database
- Backup thường xuyên

## Troubleshooting

### Lỗi kết nối SFTP
- Kiểm tra extension `ssh2` đã được cài đặt: `php -m | grep ssh2`
- Kiểm tra thông tin SFTP từ Hostinger
- Thử kết nối bằng FTP nếu SFTP không hoạt động

### Lỗi kết nối Database
- Kiểm tra thông tin database từ Hostinger
- Đảm bảo MySQL user có quyền truy cập từ xa (nếu cần)

### Lỗi Backup
- Kiểm tra quyền ghi vào thư mục `backups/`
- Kiểm tra dung lượng đĩa còn trống
- Đảm bảo kết nối SFTP/FTP hoạt động bình thường
- Kiểm tra thông tin database nếu backup database thất bại

## Hỗ trợ

Nếu gặp vấn đề, vui lòng kiểm tra:
1. Logs trong database table `activity_logs`
2. PHP error logs
3. Cấu hình SFTP/FTP từ Hostinger

## Cài đặt Git (Nếu chưa có)

Nếu gặp lỗi "git is not recognized", xem file **`HUONG_DAN_CAI_GIT.md`** để biết cách:
- Cài đặt Git trên Windows
- Thêm Git vào PATH
- Cấu hình Git

Hoặc chạy file `kiem_tra_git.bat` để kiểm tra Git đã cài chưa.

## Cài đặt trên Hostinger Hosting

Xem file **`HUONG_DAN_CAI_DAT_HOSTING.md`** để biết cách:
- Upload code lên Hostinger
- Tạo database trên Hostinger
- Chạy cài đặt qua trình duyệt
- Cấu hình và bảo mật

**Tóm tắt nhanh:**
1. Upload code lên `public_html`
2. Tạo database trong hPanel
3. Tạo file `config/database.php` từ `.example`
4. Truy cập `https://yourdomain.com/install.php` qua trình duyệt
5. Xóa file `install.php` sau khi cài xong

## Cài đặt từ GitHub (Local Development)

```bash
# Clone repository
git clone https://github.com/quangthe2104/tpanel.git
cd tpanel

# Copy file cấu hình mẫu
copy config\database.php.example config\database.php
copy config\hostinger.php.example config\hostinger.php

# Chỉnh sửa cấu hình
# Sửa config/database.php với thông tin MySQL của bạn

# Chạy cài đặt
install.bat
# hoặc
php install.php
```

## Đóng góp

Mọi đóng góp đều được chào đón! Vui lòng:
1. Fork repository
2. Tạo branch mới (`git checkout -b feature/AmazingFeature`)
3. Commit changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to branch (`git push origin feature/AmazingFeature`)
5. Mở Pull Request

## Deploy Code Mới Lên Server

Khi có code mới trên GitHub và muốn cập nhật lên server:

### ⚠️ Quan Trọng: Bảo Vệ File Config

File `config/database.php` đã được loại trừ khỏi Git (có trong `.gitignore`), nên sẽ **KHÔNG bị ghi đè** khi pull code.

**Cách deploy an toàn:**
```bash
# Backup (để an toàn)
cp config/database.php config/database.php.backup

# Pull code mới
git pull origin main

# Restore nếu cần (thường không cần vì file đã được ignore)
# mv config/database.php.backup config/database.php
```

Xem file **`HUONG_DAN_DEPLOY_AN_TOAN.md`** để biết chi tiết về:
- Cách deploy an toàn
- Script tự động backup/restore
- Git hooks để tự động bảo vệ
- Troubleshooting

## License

MIT License
