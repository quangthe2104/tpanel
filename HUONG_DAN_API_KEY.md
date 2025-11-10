# Hướng dẫn về API Key

## ⚠️ QUAN TRỌNG: Hiện tại KHÔNG CẦN API KEY!

Hệ thống Tpanel **hoạt động hoàn toàn không cần API key** của Hostinger.

## Cách hệ thống hoạt động

Tpanel sử dụng **SFTP/FTP trực tiếp** để kết nối với Hostinger, không qua API. Thông tin kết nối được lưu trong database khi bạn thêm website.

### Khi thêm website, bạn chỉ cần:

1. **Thông tin SFTP/FTP** từ Hostinger:
   - SFTP/FTP Host (ví dụ: `ftp.yourdomain.com`)
   - SFTP/FTP Username
   - SFTP/FTP Password
   - Port (22 cho SFTP, 21 cho FTP)

2. **Thông tin MySQL Database** (nếu có):
   - Database Host (thường là `localhost`)
   - Database Name
   - Database Username
   - Database Password

**Tất cả thông tin này được cấu hình trong admin panel khi thêm website, KHÔNG cần API key!**

## Nếu Hostinger có API trong tương lai

Nếu Hostinger cung cấp API chính thức, bạn có thể cấu hình như sau:

### Cách 1: Cấu hình global (cho tất cả website)

1. Tạo file `config/hostinger.php` từ `config/hostinger.php.example`:
   ```bash
   cp config/hostinger.php.example config/hostinger.php
   ```

2. Chỉnh sửa `config/hostinger.php`:
   ```php
   <?php
   return [
       'api_url' => 'https://api.hostinger.com/v1',
       'api_key' => 'your_api_key_here',
       'api_secret' => 'your_api_secret_here',
   ];
   ```

### Cách 2: Cấu hình riêng cho từng website

1. Vào **Quản lý Website** → Chọn website → **Sửa**
2. Điền **Hostinger API Key** vào trường tương ứng
3. API key sẽ được lưu trong database (field `hostinger_api_key`)

## Lấy API Key từ Hostinger (nếu có)

1. Đăng nhập vào **hPanel** của Hostinger
2. Vào **Settings** hoặc **API** (nếu có)
3. Tạo hoặc xem API key
4. Copy API key và secret

**Lưu ý**: Hiện tại Hostinger có thể chưa cung cấp API công khai. Hệ thống Tpanel hoạt động hoàn toàn ổn định mà không cần API key.

## Tóm tắt

✅ **Hiện tại**: Không cần API key, chỉ cần SFTP/FTP credentials  
✅ **Tương lai**: Nếu Hostinger có API, có thể cấu hình trong `config/hostinger.php` hoặc trong database  
✅ **Hoạt động**: Hệ thống hoạt động bình thường mà không cần API key
