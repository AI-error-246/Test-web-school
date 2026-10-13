# InternBridge — Website tuyển dụng thực tập

Kết nối sinh viên tìm việc thực tập với doanh nghiệp tuyển dụng. Website hỗ trợ **tiếng Việt, tiếng Anh và tiếng Nhật**.

**Trạng thái:** chạy trên máy cá nhân, mã nguồn lưu tại [GitHub private](https://github.com/AI-error-246/Test-web-school). Chưa có bản online; đưa mã lên GitHub không tự khởi chạy website.

## Chức năng chính

| Người dùng | Chức năng |
|---|---|
| Khách | Tìm, lọc và xem tin thực tập; xem công ty và bản đồ |
| Ứng viên | Đăng ký/đăng nhập, sửa hồ sơ và ảnh, quản lý CV, ứng tuyển, theo dõi kết quả và thông báo |
| Nhà tuyển dụng | Quản lý công ty, tạo/sửa/đóng tin, xem hồ sơ ứng tuyển, cập nhật kết quả |
| Admin | Quản lý tài khoản, duyệt/ẩn tin và công ty, xử lý báo cáo, xem thống kê |

Giao diện có nền Sylva chuyển động, chế độ sáng/tối và menu responsive. Trợ lý nội bộ miễn phí hỗ trợ hướng dẫn website và tìm tin công khai; không gọi API AI trả phí.

## Công nghệ và nơi lưu dữ liệu

**Laravel 13 + PHP 8.4**, Blade/CSS/JavaScript, PostgreSQL và Supabase. React/Three.js chỉ dùng cho cảnh Sylva; Node.js 24 và Vite dùng để build frontend.

| Dữ liệu | Nơi lưu trên bản chạy hiện tại |
|---|---|
| Tài khoản, mật khẩu | Supabase Authentication |
| Hồ sơ, công ty, tin, đơn ứng tuyển, lịch sử | Supabase PostgreSQL, schema `internbridge` |
| Tệp CV PDF | Supabase Storage, bucket riêng tư `internship-cvs` |
| Ảnh hồ sơ, phiên đăng nhập, bản xem thử email | Thư mục riêng tư trên máy chạy Laravel, trong `storage/` |
| Logo công ty | Disk `public`, tại `storage/app/public/companies`; hiển thị theo quyền truy cập công ty |

PostgreSQL local dành cho kiểm thử và bản dữ liệu nguồn đã giữ lại. GitHub chỉ lưu mã nguồn; không chứa database, CV, ảnh hoặc thông tin đăng nhập.

## Chạy trong workspace hiện tại

Mở PowerShell tại `D:\code\DO_AN_PY`:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\PROJECT\04_Code\laravel\run.ps1 db-start
powershell -NoProfile -ExecutionPolicy Bypass -File .\PROJECT\04_Code\laravel\run.ps1 start
```

- Website: [http://127.0.0.1:3001](http://127.0.0.1:3001)
- Admin: [http://127.0.0.1:3001/admin/login](http://127.0.0.1:3001/admin/login)
- Nhấn **Ctrl+C** trong terminal chạy web để dừng server.

Workspace đã có runtime và cấu hình riêng. Nếu vừa lấy thay đổi của cảnh Sylva, chạy `npm ci` rồi `npm run build` trong thư mục Laravel.

<details>
<summary><strong>Cài đặt khi clone sang máy khác</strong></summary>

Cần PHP 8.4, Composer, Node.js 24 và kết nối PostgreSQL. Các extension PHP cần thiết được liệt kê trong [workflow CI](.github/workflows/ci.yml).

```powershell
git clone https://github.com/AI-error-246/Test-web-school.git
cd Test-web-school
composer install
Copy-Item .env.example .env
php artisan key:generate
npm ci
npm run build
```

Điền kết nối và khóa của môi trường được cấp quyền vào `.env`, theo [.env.example](.env.example):

- Supabase Auth: URL, publishable key và JWKS URL; secret key chỉ dùng ở backend.
- Database: dùng cấu hình `DB_*` cho PostgreSQL local; dùng `SUPABASE_DB_URL` và `SUPABASE_DATA_ENABLED=true` khi kết nối schema cloud đã chuẩn bị.
- CV: `SUPABASE_CV_DISK=supabase` khi đã có bucket riêng tư tương ứng; mặc định mẫu là local.

Với database mới và đã kiểm tra đúng kết nối, chạy `php artisan migrate --no-interaction`. Riêng database **local mới** tên `internship_dev`, chạy thêm `php artisan db:seed --no-interaction` để có danh mục ngành/địa điểm và dữ liệu mẫu. Không dùng seeder demo này trên Supabase. Sau đó khởi động:

```powershell
php artisan serve --host=127.0.0.1 --port=3001
```

`run.ps1` và các bài kiểm thử trình duyệt dùng runtime trong `.tools` của workspace gốc, không đi kèm bản clone.

</details>

## Tài khoản và Admin

Đăng ký công khai dành cho Ứng viên/Nhà tuyển dụng; xác nhận email Supabase trước khi đăng nhập. Admin dùng tài khoản được cấp quyền riêng, không có nút đăng nhập demo.

Bản chạy hiện tại dùng **email + mật khẩu Admin**, bỏ bước QR/mã 6 số qua `ADMIN_MFA_REQUIRED=false`. Phiên quản trị hết hạn sau 30 phút. Bản cấu hình mẫu mặc định `true`; đổi giá trị trong `.env` rồi chạy `php artisan config:clear` để áp dụng. Tùy chọn này không xóa MFA trên Supabase; khôi phục mật khẩu của tài khoản đã bật MFA vẫn cần mã.

Người quản lý có quyền CLI có thể cấp Admin bằng `php artisan app:grant-admin UUID` sau khi tài khoản đã xác nhận email và đăng nhập lần đầu.

## Quy trình dùng thử và kiểm thử

1. Nhà tuyển dụng tạo tin → gửi duyệt.
2. Admin đăng nhập → duyệt tin.
3. Ứng viên tìm tin → chọn CV → ứng tuyển.
4. Nhà tuyển dụng xem hồ sơ → cập nhật kết quả.
5. Ứng viên xem trạng thái và thông báo.

Chạy kiểm thử PostgreSQL từ workspace gốc:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\PROJECT\04_Code\laravel\run.ps1 test-pg
```

[GitHub Actions đã kiểm chứng commit `7d48152`](https://github.com/AI-error-246/Test-web-school/actions/runs/38467114609): **644 kiểm thử đạt, 5 kiểm thử đồng thời được bỏ qua**, build frontend thành công. CI dùng database riêng và mô phỏng dịch vụ ngoài; không ghi dữ liệu Supabase thật.

## Lưu ý khi sử dụng

- CV: PDF tối đa **5 MB**, tối đa **5 bản đang hoạt động**. Máy mới cần PHP `upload_max_filesize >= 5M`, `post_max_size >= 8M` và bộ quét Defender trên Windows hoặc ClamAV trên Linux. Không cho dùng CV nếu chưa quét sạch.
- Email trạng thái đang ở chế độ **xem thử**, chưa gửi SMTP thật. Chạy `php artisan schedule:work` để xử lý hàng chờ; bản xem thử nằm trong khu vực thông báo của ứng viên.
- Giữ `.env`, API key, tài khoản Admin, CV/ảnh, database backup và session/log ngoài Git.
- Giữ nguyên nguồn và thông báo tác giả ThreeUI trong [resources/vendor/threeui](resources/vendor/threeui). Quyền phân phối công khai toàn bộ bundle chưa được xác minh; repository hiện giữ private.
