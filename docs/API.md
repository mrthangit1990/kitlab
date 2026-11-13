# API quản trị và tuyến nghiệp vụ

## Xác thực

Ứng dụng dùng session Laravel. Đăng nhập tại `POST /dang-nhap` với email, password và CSRF. Dùng cookie phiên cho các yêu cầu kế tiếp. POST yêu cầu token CSRF qua `_token` hoặc `X-CSRF-TOKEN`. Gửi `Accept: application/json` khi cần lỗi validation JSON (HTTP 422). Người dùng đăng nhập thiếu quyền nhận HTTP 403. Không dùng cookie của tài khoản demo cho dịch vụ bên ngoài.

## Endpoint đọc JSON

`GET /admin/report?json=1&from=2026-11-01&to=2026-11-30&method=fifo&group=day`

Quyền `reports`. Tham số tùy chọn `range`, `category_id`, `brand_id`, `product_id`, `warehouse_id`. Trả tổng hợp `revenue`, `cogs`, `gross`, `expense`, `operating`, `net`, `cash`, `products`, `series`. Giá trị tổng cấp báo cáo là VND nguyên. MySQL có thể trả DECIMAL trong các dòng dưới dạng chuỗi. Kỳ theo giờ Việt Nam.

## Endpoint biểu mẫu

| Tuyến | Phương thức | Dữ liệu chính | Quyền |
|---|---|---|---|
| /gio-hang | POST | variant_id, quantity, mode=add hoặc set | Khách |
| /thanh-toan | POST | name, phone, address, warehouse_id, payment_method, coupon, reference lấy từ giỏ | Đăng nhập |
| /admin/data/{table} | GET, POST | Trường theo config/admin.php, id khi sửa | Quyền module |
| /admin/purchasing | POST | action=create, state, shipment, expense hoặc receive. Dòng PO qua items, dòng GRN qua rows theo ID dòng PO | purchasing hoặc inventory |
| /admin/inventory | POST | action=in, out, transfer hoặc count. warehouse_id, lookup, quantity, unit_cost, destination | inventory |
| /admin/orders | POST | id, action=state, fulfill, return, payment hoặc refund. Kèm số lượng, giá trị, chứng từ tương ứng | orders hoặc finance |
| /admin/expenses | POST | action=create, approve hoặc other. Ngày, tỷ giá, số tiền và nhóm chi phí tương ứng | finance, duyệt cần approve_expense |
| /admin/excel/preview | POST | multipart file .xlsx, mode=insert hoặc update | catalog |
| /admin/excel/{id}/confirm | POST | Xác nhận phiên xem trước của tài khoản hiện tại | catalog |
| /admin/excel/{kind} | GET | template, products hoặc errors. errors cần job | catalog |
| /admin/report?export={kind} | GET | profit hoặc inventory | reports |

Tuyến biểu mẫu trả chuyển hướng sau khi thành công. Mã chứng từ ổn định dùng để tránh ghi trùng ở checkout, GRN, xuất hàng, hàng trả và đối soát. Không công bố các tuyến ghi trên như API tích hợp bên thứ ba. Xem `php artisan route:list` và form tương ứng để biết trường chi tiết.
