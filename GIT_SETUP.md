# Hướng dẫn đẩy code lên GitHub

## Bước 1: Cài đặt Git (nếu chưa có)

Tải và cài đặt Git từ: https://git-scm.com/download/win

Sau khi cài đặt, mở lại PowerShell hoặc Command Prompt.

## Bước 2: Cấu hình Git (lần đầu tiên)

```bash
git config --global user.name "Your Name"
git config --global user.email "your.email@example.com"
```

## Bước 3: Khởi tạo repository và đẩy code

Mở PowerShell hoặc Command Prompt trong thư mục `D:\wamp64\www\tpanel` và chạy các lệnh sau:

```bash
# Khởi tạo git repository
git init

# Thêm tất cả files
git add .

# Commit code
git commit -m "Initial commit: Tpanel - Website management system for Hostinger"

# Đổi tên branch thành main
git branch -M main

# Thêm remote repository
git remote add origin https://github.com/quangthe2104/tpanel.git

# Đẩy code lên GitHub
git push -u origin main
```

## Lưu ý

- Lần đầu push, GitHub sẽ yêu cầu đăng nhập
- Nếu dùng Personal Access Token, bạn cần tạo token tại: https://github.com/settings/tokens
- Token cần quyền `repo`

## Các lệnh Git hữu ích

```bash
# Xem trạng thái
git status

# Xem các thay đổi
git diff

# Thêm file cụ thể
git add filename.php

# Commit với message
git commit -m "Your commit message"

# Push code lên
git push

# Pull code về
git pull

# Xem lịch sử commit
git log
```

## Nếu gặp lỗi

### Lỗi: "remote origin already exists"
```bash
git remote remove origin
git remote add origin https://github.com/quangthe2104/tpanel.git
```

### Lỗi: "failed to push some refs"
```bash
git pull origin main --allow-unrelated-histories
git push -u origin main
```
