<?php
namespace Database\Seeders;

use App\Models\User;
use App\Services\Inventory;
use App\Services\Sales;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class DatabaseSeeder extends Seeder
{
    public function run(Inventory $inventory, Sales $sales): void
    {
        DB::transaction(function () use ($inventory,$sales) {
            $roles = [
                'Super Admin'=>['*'],
                'Administrator'=>['admin','users','catalog','purchasing','inventory','orders','finance','content','reports','approve_po','approve_expense'],
                'Product Manager'=>['admin','catalog'],
                'Purchasing Manager'=>['admin','purchasing'],
                'Inventory Manager'=>['admin','inventory'],
                'Order Manager'=>['admin','orders'],
                'Accountant'=>['admin','finance','reports','approve_expense'],
                'Content Editor'=>['admin','content'],
                'Customer Support'=>['admin','support'],
            ];
            foreach ($roles as $name=>$permissions) {
                DB::table('roles')->insertOrIgnore(['name'=>$name]);
                $role = DB::table('roles')->where('name',$name)->value('id');
                foreach ($permissions as $permission) {
                    DB::table('permissions')->insertOrIgnore(['name'=>$permission]);
                    DB::table('permission_role')->insertOrIgnore(['role_id'=>$role,'permission_id'=>DB::table('permissions')->where('name',$permission)->value('id')]);
                }
            }
            if (!app()->environment(['local','testing'])) {
                return;
            }
            $password = config('kitlab.demo_password');
            if (!$password || User::where('email','admin@kitlab.test')->exists()) {
                return;
            }
            $admin = User::create(['name'=>'Quản trị KITLAB','email'=>'admin@kitlab.test','password'=>Hash::make($password)]);
            $customer = User::create(['name'=>'Khách demo KITLAB','email'=>'demo@kitlab.test','password'=>Hash::make($password)]);
            DB::table('customers')->insert(['user_id'=>$customer->id]);
            DB::table('role_user')->insert(['user_id'=>$admin->id,'role_id'=>DB::table('roles')->where('name','Super Admin')->value('id')]);
            $warehouse = DB::table('warehouses')->insertGetId(['code'=>'HCM','name'=>'Kho TP. Hồ Chí Minh']);
            $secondary = DB::table('warehouses')->insertGetId(['code'=>'HN','name'=>'Kho Hà Nội']);
            $brand = DB::table('brands')->insertGetId(['name'=>'KITLAB']);
            $supplier = DB::table('suppliers')->insertGetId(['code'=>'DEMO-CN','name'=>'Nhà cung cấp demo','currency'=>'CNY','country'=>'Trung Quốc']);
            $items = [
                ['Bộ thí nghiệm cầu nâng','cau-nang','STEM','STEM','KIT-STEM-001',329000,8,118,120000],
                ['Robot dò đường Mini Rover','mini-rover','Robot','robot','KIT-ROB-001',549000,10,204,220000],
                ['Bộ lắp ghép Xe cứu hỏa','xe-cuu-hoa','Lắp ghép','lap-ghep','KIT-LG-001',289000,6,163,110000],
                ['Bộ Montessori Phân loại màu','phan-loai-mau','Montessori','montessori','KIT-MON-001',199000,3,30,70000],
            ];
            foreach ($items as [$name,$slug,$category,$categorySlug,$sku,$price,$age,$pieces,$cost]) {
                $categoryId = DB::table('categories')->insertGetId(['name'=>$category,'slug'=>$categorySlug]);
                $product = DB::table('products')->insertGetId(['name'=>$name,'slug'=>$slug,'category_id'=>$categoryId,'brand_id'=>$brand,'age'=>$age,
                    'pieces'=>$pieces,'description'=>'Bộ kit thực hành theo từng bước, dành cho hoạt động học tập tại nhà hoặc trong lớp.','origin'=>'Trung Quốc','material'=>'Nhựa ABS']);
                $variant = DB::table('product_variants')->insertGetId(['product_id'=>$product,'name'=>'Tiêu chuẩn','sku'=>$sku,'price'=>$price,'min_stock'=>3,'weight_grams'=>450]);
                DB::table('product_barcodes')->insert(['variant_id'=>$variant,'code'=>$sku,'format'=>'code128']);
                DB::table('product_supplier')->insert(['variant_id'=>$variant,'supplier_id'=>$supplier,'purchase_price'=>35]);
                $inventory->receive($inventory->lock($warehouse,$variant),12,12*$cost,12*$cost,$admin->id,'DEMO-HCM-'.$sku);
                $inventory->receive($inventory->lock($warehouse,$variant),8,8*($cost+15000),8*($cost+15000),$admin->id,'DEMO-BATCH-'.$sku);
                $inventory->receive($inventory->lock($secondary,$variant),5,5*$cost,5*$cost,$admin->id,'DEMO-HN-'.$sku);
                $first ??= $variant;
            }
            DB::table('coupons')->insert(['code'=>'HELLOKIT','amount'=>20000]);
            $order = $sales->checkout($customer->id,[$first=>2],['name'=>'Khách demo','phone'=>'0900000000','address'=>'Địa chỉ mẫu TP.HCM',
                'warehouse_id'=>$warehouse,'payment_method'=>'cod','coupon'=>'HELLOKIT'],'SO-DEMO-001');
            $sales->changeStatus($order,'confirmed',$admin->id);
            $sales->fulfill($order,DB::table('order_items')->where('order_id',$order)->value('id'),2,$admin->id,'FF-DEMO-001');
            $sales->changeStatus($order,'completed',$admin->id);
            $sales->cash($order,'payment',(int) DB::table('orders')->where('id',$order)->value('total'),'PAY-DEMO-001',$admin->id);
            DB::table('expense_categories')->insert(['name'=>'Marketing']);
            DB::table('blog_posts')->insert(['user_id'=>$admin->id,'title'=>'Bắt đầu một buổi STEM tại nhà','slug'=>'stem-tai-nha',
                'body'=>"Chọn một câu hỏi gần gũi: vì sao cây cầu đứng vững?\n\nThử lắp hai mô hình rồi đặt cùng một tải trọng lên từng cây cầu.",
                'status'=>'published','publish_at'=>now(),'seo_description'=>'Gợi ý hoạt động STEM đơn giản cho gia đình.']);
        },3);
    }
}
