# Phạm vi triển khai và nghiệm thu

| Miền | Có trong mã nguồn | Cần bổ sung hoặc nghiệm thu |
|---|---|---|
| Kiến trúc | Laravel 12, MySQL 8, migration, ERD vật lý, RBAC, audit, seed | Composer lockfile, chạy migration thật, kiểm tra cấu hình staging |
| Storefront | Trang chủ, lọc/tìm sản phẩm, chi tiết, biến thể, giỏ, tài khoản, checkout, theo dõi đơn, blog, bản tin, chính sách cơ bản | Mega menu, xem nhanh, đánh giá, flash sale, trang đích danh mục riêng, chuyên mục homepage nâng cao, địa chỉ đã lưu |
| Catalog | Thêm/sửa, ẩn sản phẩm, quản lý biến thể và giá, ảnh, SKU tự sinh theo ULID, tra và in barcode | Xóa mềm theo workflow, thuộc tính động qua UI, bulk SKU, in nhãn theo lô, ảnh riêng cho biến thể, chuyển đổi ảnh tự động |
| Mua hàng | NCC và nguồn hàng, PO nhiều dòng, duyệt, nhiều lô, GRN từng phần, chi phí và 4 kiểu phân bổ, chi tiết chứng từ | Sửa PO draft, đầy đủ trạng thái vận chuyển, chứng từ upload, đối soát nhà cung cấp, xuất PO PDF, điều chỉnh giá vốn sau ghi sổ |
| Kho | Đa kho, vị trí kho, scan USB qua ô SKU/barcode, điều chỉnh, chuyển, kiểm đếm, giữ chỗ, ledger, định giá hai phương pháp | Kiểm đếm nhiều dòng và luồng duyệt, tồn cấp vị trí, báo cáo aging/slow-moving, kiểm thử cạnh tranh bằng hai kết nối MySQL |
| Bán hàng | COD/chuyển khoản thủ công, mã giảm cố định, chốt giá, giao từng phần, hủy trước xuất, nhập lại hàng trả đạt kiểm định, đối soát hoàn tiền | VNPay/MoMo, carrier API, ghi chú hỗ trợ, hóa đơn điện tử, đổi trả và hủy phần còn lại sau giao một phần, hoàn cước và hoàn tiền không kèm hàng trả |
| Tài chính | COGS và lợi nhuận quản trị, chi phí duyệt, dòng tiền và thu/chi khác, snapshot FIFO/WAC | Đính kèm chi phí, lịch chi phí định kỳ, sổ kép/VAT đầu ra và kế toán pháp định, đối soát đầy đủ tiền hàng và cước |
| Excel | Mẫu thật, preview, lỗi theo dòng, xác nhận, lịch sử, cập nhật SKU và xuất XLSX | Queue cho tệp lớn, import kho/NCC/danh mục, hỗ trợ thêm trường, xuất tất cả bộ báo cáo. Xử lý đồng bộ tối đa 2.000 dòng |
| CMS/SEO | Draft/publish/hẹn ngày, số lượt đọc, metadata, Open Graph cơ bản, Product/Article JSON-LD, sitemap/robots | Rich editor, tag UI, lịch sử xuất bản, SEO danh mục đầy đủ, cấu hình GA4/Search Console, breadcrumb và Organization schema |
| Dashboard | Số đơn theo trạng thái, tồn hiện tại, cảnh báo tồn thấp, lãi SKU, biểu đồ theo kỳ, lọc kho/danh mục, XLSX | Báo cáo NCC, khách hàng và lô chuyên sâu, tồn theo thời gian, biểu đồ và bộ lọc còn lại |
| Thư viện | Laravel, Blade, CSS tự viết, ES6, Chart.js, PhpSpreadsheet qua Laravel Excel, barcode/QR | Bootstrap/Tailwind, DataTables, SweetAlert2 và rich editor chưa tích hợp |
| Triển khai | Cấu hình localhost, Docker Compose, hướng dẫn staging | Chạy stack và PHPUnit trên MySQL thật, kiểm thử tải, nghiệm thu bảo mật, cấu hình vận hành production |

## Quyết định và giới hạn nghiệp vụ

- Ghi nhận doanh thu quản trị và COGS ở sự kiện xuất hàng. Hoàn tất đơn xác nhận đã xuất đủ. Ghi giảm doanh thu ở sự kiện nhận trả, ghi hoàn tiền ở ngày đối soát. Hai sự kiện dùng cho hai báo cáo riêng.
- Cước thu khách nằm trên đơn và nằm trong tiền đối soát. Doanh thu hàng loại phần cước. Có thể ghi phí COD, phí thanh toán và phí giao hàng vào chi phí hoạt động.
- Mỗi lô vận chuyển có một GRN ghi sổ chi phí. PO có thể có nhiều GRN. Chi phí thuế được đánh dấu khấu trừ giữ lại trên chứng từ và loại khỏi giá vốn. Bản hiện tại khóa sửa lô đã ghi nhận và chưa có bút toán điều chỉnh giá vốn hồi tố.
- FIFO khi chuyển kho dùng giá trị FIFO của hàng chuyển, ghi thành lô mới ở kho nhận. WAC chuyển theo giá trị xuất WAC. Khi trả hàng đạt kiểm định, chi phí gốc được phân bổ theo lượng trả của từng lần xuất.
- Đơn hàng chọn một kho giao. Báo cáo quản trị dùng chi phí cấp công ty ngay cả khi người dùng lọc doanh thu theo danh mục hoặc kho.
- Một số bảng trong ERD dành cho giai đoạn tiếp theo. Sự hiện diện của bảng không đồng nghĩa với luồng UI hoàn chỉnh.

## Kiểm thử và nghiệm thu

Đã kiểm tra cấu trúc package, JavaScript bằng Node, tính nhất quán tham chiếu khóa ngoại và khả năng mở mẫu XLSX. PHP, Composer, Docker và MySQL không có trong môi trường tạo bản bàn giao. PHPUnit, migration và thao tác trình duyệt với ứng dụng thật cần chạy trên localhost hoặc CI có MySQL 8.

| Kiểm tra trên MySQL | Kết quả mong đợi |
|---|---|
| Chạy migration và seed | Trang chủ có 4 bộ kit, /admin đăng nhập được, báo cáo có đơn mẫu |
| Chạy PHPUnit | Qua các kiểm tra SKU/barcode, RBAC, audit, PO, tỷ giá, GRN, FIFO/WAC, đặt và trả hàng, chi phí, báo cáo, blog và endpoint Excel |
| Tạo 2 đơn đồng thời cho 1 SKU còn 1 sản phẩm | Một đơn thành công, đơn kia nhận lỗi hết tồn, ledger và giữ chỗ khớp |
| Nhập XLSX có dòng đúng, SKU trùng và danh mục thiếu | Preview báo đúng lỗi, chỉ dòng hợp lệ được ghi sau xác nhận |
| Chuyển kho có đơn đang giữ chỗ | Chỉ phần khả dụng được chuyển, tồn không âm, tổng giá trị hai kho đối soát được |
| Nhập một PO qua 2 chuyến, một chuyến có phí và VAT được khấu trừ | Chi phí lô phân bổ đúng và VAT được giữ riêng |
| Giao hai lần và trả một phần, đối soát tiền hoàn vào ngày khác | Hai snapshot COGS riêng, giảm doanh thu ngày trả, dòng tiền ngày hoàn |
