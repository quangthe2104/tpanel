# Hướng dẫn cài đặt Git trên Windows

## Cách 1: Cài đặt Git (Khuyến nghị)

### Bước 1: Tải Git
1. Truy cập: https://git-scm.com/download/win
2. Tải file cài đặt (Git for Windows)
3. File sẽ có tên dạng: `Git-2.x.x-64-bit.exe`

### Bước 2: Cài đặt Git
1. **Double-click** vào file vừa tải
2. Click **Next** qua các bước
3. **Quan trọng**: Ở màn hình "Select Components", đảm bảo chọn:
   - ✅ **Git Bash Here**
   - ✅ **Git GUI Here**
   - ✅ **Associate .git* configuration files with the default text editor**
   - ✅ **Associate .sh files to be run with Bash**

4. Ở màn hình **"Choosing the default editor"**, chọn:
   - **Nano editor** (đơn giản) hoặc
   - **Notepad++** (nếu đã cài) hoặc
   - **Visual Studio Code** (nếu đã cài)

5. Ở màn hình **"Adjusting your PATH environment"**, chọn:
   - ✅ **Git from the command line and also from 3rd-party software** (QUAN TRỌNG!)
   - Điều này sẽ tự động thêm Git vào PATH

6. Ở màn hình **"Choosing HTTPS transport backend"**, chọn:
   - **Use the OpenSSL library** (mặc định)

7. Ở màn hình **"Configuring the line ending conversions"**, chọn:
   - **Checkout Windows-style, commit Unix-style line endings** (mặc định)

8. Click **Install** và chờ cài đặt hoàn tất

9. Click **Finish**

### Bước 3: Kiểm tra cài đặt
1. **Đóng tất cả** PowerShell/CMD đang mở
2. Mở **PowerShell mới** hoặc **Command Prompt mới**
3. Chạy lệnh:
   ```bash
   git --version
   ```
4. Nếu hiển thị version (ví dụ: `git version 2.42.0`), nghĩa là đã cài thành công!

---

## Cách 2: Thêm Git vào PATH thủ công (Nếu đã cài nhưng chưa có trong PATH)

### Tìm đường dẫn Git
Git thường được cài ở một trong các vị trí sau:
- `C:\Program Files\Git\cmd\`
- `C:\Program Files (x86)\Git\cmd\`
- `C:\Users\YourUsername\AppData\Local\Programs\Git\cmd\`

### Thêm vào PATH

#### Phương pháp 1: Qua System Properties (Dễ nhất)
1. Nhấn `Win + R`
2. Gõ: `sysdm.cpl` và nhấn Enter
3. Vào tab **Advanced**
4. Click **Environment Variables**
5. Trong phần **System variables**, tìm và chọn **Path**
6. Click **Edit**
7. Click **New**
8. Thêm đường dẫn: `C:\Program Files\Git\cmd\`
   (Thay bằng đường dẫn thực tế nếu khác)
9. Click **OK** ở tất cả các cửa sổ
10. **Đóng tất cả** PowerShell/CMD và mở lại

#### Phương pháp 2: Qua PowerShell (Nhanh hơn)
1. Mở **PowerShell as Administrator** (Right-click → Run as administrator)
2. Chạy lệnh:
   ```powershell
   [Environment]::SetEnvironmentVariable("Path", $env:Path + ";C:\Program Files\Git\cmd\", [EnvironmentVariableTarget]::Machine)
   ```
   (Thay đường dẫn nếu Git ở vị trí khác)
3. Đóng và mở lại PowerShell

#### Phương pháp 3: Qua Command Prompt
1. Mở **Command Prompt as Administrator**
2. Chạy lệnh:
   ```cmd
   setx PATH "%PATH%;C:\Program Files\Git\cmd\" /M
   ```
   (Thay đường dẫn nếu Git ở vị trí khác)
3. Đóng và mở lại Command Prompt

### Kiểm tra lại
Sau khi thêm vào PATH, mở PowerShell/CMD mới và chạy:
```bash
git --version
```

---

## Cách 3: Sử dụng Git Bash (Không cần PATH)

Nếu không muốn thêm vào PATH, bạn có thể dùng Git Bash:

1. Tìm **Git Bash** trong Start Menu
2. Right-click → **Pin to Start** (nếu muốn)
3. Mở Git Bash
4. Navigate đến thư mục project:
   ```bash
   cd /d/wamp64/www/tpanel
   ```
5. Chạy các lệnh Git bình thường

---

## Cấu hình Git (Sau khi cài đặt)

Mở PowerShell/CMD và chạy:

```bash
# Cấu hình tên
git config --global user.name "Your Name"

# Cấu hình email
git config --global user.email "your.email@example.com"

# Kiểm tra cấu hình
git config --list
```

---

## Troubleshooting

### Lỗi: "git is not recognized" (Sau khi đã thêm vào PATH)

**Nguyên nhân:** PowerShell/CMD đang mở từ TRƯỚC KHI thêm PATH, nên chưa nhận biết Git.

**Giải pháp:**
1. ✅ **QUAN TRỌNG**: ĐÓNG TẤT CẢ PowerShell/CMD đang mở
2. Mở PowerShell/CMD MỚI
3. Chạy lại: `git --version`

**Nếu vẫn lỗi:**
1. Chạy file `test_git_direct.bat` để kiểm tra Git có tồn tại không
2. Chạy file `refresh_path.bat` để kiểm tra PATH
3. Thử restart máy (ít khi cần)
4. Kiểm tra lại PATH trong System Properties:
   - Win + R → `sysdm.cpl` → Advanced → Environment Variables
   - Đảm bảo `C:\Program Files\Git\cmd` có trong System variables → Path

**Refresh PATH trong PowerShell hiện tại (tạm thời):**
```powershell
# Lấy PATH mới từ registry
$env:Path = [System.Environment]::GetEnvironmentVariable("Path","Machine") + ";" + [System.Environment]::GetEnvironmentVariable("Path","User")

# Kiểm tra lại
git --version
```

**Sử dụng Git trực tiếp (không cần PATH):**
```powershell
# Thay vì: git --version
& "C:\Program Files\Git\cmd\git.exe" --version
```

### Lỗi: "Permission denied"
**Giải pháp:**
- Chạy PowerShell/CMD **as Administrator**

### Tìm đường dẫn Git
Chạy lệnh này trong PowerShell:
```powershell
Get-ChildItem -Path "C:\Program Files" -Filter "git.exe" -Recurse -ErrorAction SilentlyContinue | Select-Object FullName
```

Hoặc tìm trong:
- `C:\Program Files\Git\bin\git.exe`
- `C:\Program Files (x86)\Git\bin\git.exe`

---

## Sau khi cài đặt xong

1. Mở PowerShell/CMD mới
2. Chạy: `git --version` để kiểm tra
3. Chạy file `push_to_github.bat` hoặc các lệnh Git trong thư mục project

---

## Liên kết hữu ích

- Download Git: https://git-scm.com/download/win
- Tài liệu Git: https://git-scm.com/doc
- GitHub Desktop (GUI): https://desktop.github.com/ (Nếu muốn dùng giao diện đồ họa)
