# KITLAB

Website tiếng Việt cho đồ chơi lắp ráp, STEM, robot và Montessori. Dự án dùng Laravel 12, Blade, JavaScript và MySQL 8. Bản bàn giao tập trung vào luồng bán hàng và vận hành có giao dịch thật trong MySQL. Xem [kiến trúc và quy tắc giá vốn](docs/ARCHITECTURE.md), [ERD](docs/ERD.md) và [phạm vi đã triển khai](docs/SCOPE.md).

## Chạy localhost bằng Docker Compose

Cần Docker Desktop hoặc Docker Engine có Compose v2, kết nối để tải image và gói Composer. Giải nén ZIP rồi mở terminal tại thư mục `kitlab`.

```bash
cp .env.example .env
docker compose -f compose.yaml -f compose.app.yaml up -d mysql
docker compose -f compose.yaml -f compose.app.yaml build app
docker compose -f compose.yaml -f compose.app.yaml run --rm app composer install
docker compose -f compose.yaml -f compose.app.yaml run --rm app php artisan key:generate
docker compose -f compose.yaml -f compose.app.yaml run --rm app php artisan migrate --seed
docker compose -f compose.yaml -f compose.app.yaml run --rm app php artisan storage:link
docker compose -f compose.yaml -f compose.app.yaml up -d app
```

PowerShell dùng `Copy-Item .env.example .env` thay cho `cp`. Nếu Windows không tạo được symlink từ container, chạy bằng WSL2 với dự án trên filesystem Linux để bật ảnh upload. Nếu cổng 3306 bị chiếm, đổi cổng phía máy host trong `compose.yaml`. Container app vẫn dùng cổng MySQL 3306. Kiểm tra trạng thái với `docker compose -f compose.yaml -f compose.app.yaml ps`.

## Chạy bằng PHP cài sẵn

Cần PHP 8.2+ 64-bit, Composer 2, MySQL 8, extension `pdo_mysql`, `bcmath`, `mbstring`, `zip`, `gd`, `fileinfo` và họ extension XML theo yêu cầu Composer. Tạo database trống và user MySQL bằng Compose hoặc chạy với quyền quản trị trên MySQL riêng.

```sql
CREATE DATABASE kitlab CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'kitlab'@'localhost' IDENTIFIED BY 'kitlab_local';
GRANT ALL PRIVILEGES ON kitlab.* TO 'kitlab'@'localhost';
```

Cập nhật `DB_HOST`, `DB_PORT` và tài khoản trong `.env` theo máy. Các lệnh tiếp theo chạy tại thư mục dự án.

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve --host=127.0.0.1 --port=8000
```

Dùng migration để Laravel ghi lịch sử migration. `database/schema.sql` là DDL MySQL được migration dùng trực tiếp. Khi dùng migration, bỏ qua bước import SQL thủ công.

## Tài khoản và quy trình thử

- Cửa hàng tại [localhost:8000](http://127.0.0.1:8000).
- Quản trị tại [localhost:8000/admin](http://127.0.0.1:8000/admin). Đăng nhập `admin@kitlab.test`.
- Khách hàng demo dùng `demo@kitlab.test`. Cả hai dùng mật khẩu mặc định `Kitlab-Demo-2026!` từ `.env.example`.
- Seed tạo 4 bộ kit, 2 kho, tồn nhiều lô, 1 đơn đã giao và đã thu tiền, 1 mã `HELLOKIT`, 1 bài blog. Tài khoản và giao dịch mẫu chỉ được seed trong `local` hoặc `testing`.
- Khách đăng nhập, thêm SKU vào giỏ, chọn kho và đặt COD. Quản trị vào **Đơn hàng**, xác nhận, xuất hàng, rồi hoàn thành. Người có quyền tài chính ghi mã đối soát thu tiền.
- Thử PO với nhà cung cấp mẫu. Tạo PO, gửi duyệt, duyệt, đặt hàng, lập lô và thêm phí trước khi ghi GRN. Nhập một phần rồi tiếp tục bằng GRN mới. Khi nhập nhiều chuyến, tạo lô riêng cho từng chuyến có chi phí riêng.
- Đổi FIFO và WAC trong **Báo cáo** để so sánh. Chọn kỳ bao gồm ngày giao dịch. Tồn hiện tại được định giá riêng theo hai phương pháp.
- Vào **Excel**, tải mẫu, điền tên danh mục/thương hiệu đúng như hệ thống rồi tải lên. Kiểm tra các dòng lỗi và xác nhận các dòng hợp lệ. Chế độ cập nhật hiện áp dụng giá, tồn tối thiểu, khối lượng và barcode bổ sung. Tồn thực tế thay đổi qua giao dịch kho.

## Kiểm thử

Tạo database riêng `kitlab_test`, cấp quyền cho tài khoản thử nghiệm. PHPUnit tự áp dụng migration. Suite sử dụng `RefreshDatabase`, cần database trống dành riêng cho kiểm thử.

```sql
CREATE DATABASE kitlab_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON kitlab_test.* TO 'kitlab'@'localhost';
```

Nếu dùng Docker, đăng nhập MySQL root qua `docker compose exec mysql mysql -uroot -p`, dùng mật khẩu root trong Compose và cấp quyền cho `'kitlab'@'%'` thay cho `'kitlab'@'localhost'`.

```bash
vendor/bin/phpunit
# Hoặc dùng runtime Docker
docker compose -f compose.yaml -f compose.app.yaml run --rm app vendor/bin/phpunit
```

Xem [hướng dẫn kiểm thử chấp nhận](docs/SCOPE.md#kiểm-thử-và-nghiệm-thu). Thư mục `vendor` được tạo từ Composer khi cài. Gói bàn giao không kèm lockfile đã giải dependency, nên tạo và lưu `composer.lock` từ lần cài thành công đầu tiên trước khi dùng cho staging hoặc production.

## Staging và triển khai

Đặt webroot ở `public/`, dùng HTTPS, app key riêng, `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, MySQL riêng và mật khẩu khác mẫu. Sao lưu database và `storage/app` trước khi migration, thử phục hồi trên staging. Chạy `php artisan migrate --force`, `php artisan db:seed --force` để nạp vai trò, `php artisan storage:link` và `php artisan config:cache`. Tạo tài khoản quản trị production trong phiên vận hành bảo mật hoặc triển khai cơ chế bootstrap tài khoản riêng trước go-live. Chart.js tải từ CDN nếu trình duyệt kết nối được, bảng số liệu vẫn hiển thị độc lập.

Bản bàn giao chưa triển khai public. Cần nghiệm thu trên staging, kiểm thử tải và hoàn thiện các phần còn thiếu trong [phạm vi](docs/SCOPE.md) trước khi vận hành thương mại.
