<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Books;
use App\Services\Inventory;
use App\Services\Purchasing;
use App\Services\Reports;
use App\Services\Sales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class KitlabTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed();
        return [
            User::where('email','admin@kitlab.test')->firstOrFail(),
            User::where('email','demo@kitlab.test')->firstOrFail(),
            DB::table('product_variants')->where('sku','KIT-STEM-001')->first(),
            DB::table('warehouses')->where('code','HCM')->value('id'),
        ];
    }

    public function test_admin_create_product_variant_and_barcode_lookup(): void
    {
        [$admin,$customer,$variant] = $this->fixture();
        $this->actingAs($customer)->get('/admin')->assertForbidden();
        $this->actingAs($admin)->get('/admin')->assertOk();
        $product = DB::table('products')->first();
        $this->post('/admin/data/products',['id'=>$product->id,'name'=>'Bộ cầu nâng mới','slug'=>$product->slug,
            'category_id'=>$product->category_id,'brand_id'=>$product->brand_id,'age'=>8,'pieces'=>125,'published'=>1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products',['id'=>$product->id,'name'=>'Bộ cầu nâng mới']);
        $this->post('/admin/data/product_variants',['product_id'=>$variant->product_id,'name'=>'Đỏ','price'=>200000,'min_stock'=>2,'weight_grams'=>100])->assertSessionHasNoErrors();
        $new = DB::table('product_variants')->where('name','Đỏ')->first();
        $this->assertStringStartsWith('KIT-',$new->sku);
        $this->post('/admin/data/product_variants',['product_id'=>$variant->product_id,'name'=>'Trùng','sku'=>$new->sku,'price'=>200000,'min_stock'=>2,'weight_grams'=>100])->assertSessionHasErrors('sku');
        $this->post('/admin/data/product_barcodes',['variant_id'=>$new->id,'code'=>'5901234123457','format'=>'ean13'])->assertSessionHasNoErrors();
        $id = DB::table('product_barcodes')->where('variant_id',$new->id)->value('id');
        $this->get('/admin/label/'.$id)->assertOk()->assertSee('5901234123457');
        $this->get('/admin/data/product_variants?q=5901234123457')->assertOk()->assertSee($new->sku);
        $this->post('/admin/data/product_barcodes',['variant_id'=>$variant->id,'code'=>'5901234123457','format'=>'ean13'])->assertSessionHasErrors('code');
        $this->assertDatabaseHas('audit_logs',['entity'=>'product_variants','entity_id'=>$new->id,'user_id'=>$admin->id]);
    }

    public function test_partial_purchase_preserves_historical_rate_and_allocates_landed_cost(): void
    {
        [$admin,,$variant,$warehouse] = $this->fixture();
        $supplier = DB::table('suppliers')->value('id');
        $this->actingAs($admin)->post('/admin/data/suppliers',['name'=>'Nhà cung cấp mới','code'=>'NEW-CN','country'=>'China','currency'=>'CNY','active'=>1])->assertSessionHasNoErrors();
        $this->post('/admin/purchasing',['action'=>'create','supplier_id'=>$supplier,'currency'=>'CNY','exchange_rate'=>'3500',
            'purchase_date'=>today()->toDateString(),'items'=>[['variant_id'=>$variant->id,'quantity'=>5,'unit_price'=>'25','discount'=>'1']]])->assertSessionHasNoErrors();
        $po = DB::table('purchase_orders')->latest('id')->value('id');
        foreach (['pending','approved','ordered'] as $status) $this->post('/admin/purchasing',['action'=>'state','id'=>$po,'status'=>$status])->assertSessionHasNoErrors();
        $this->post('/admin/purchasing',['action'=>'shipment','id'=>$po,'warehouse_id'=>$warehouse,'origin'=>'China','carrier'=>'Demo','method'=>'Road'])->assertSessionHasNoErrors();
        $shipment = DB::table('shipments')->where('purchase_order_id',$po)->value('id');
        foreach ([['freight',1000,0],['vat',999,1]] as [$category,$amount,$recoverable]) {
            $this->post('/admin/purchasing',['action'=>'expense','id'=>$po,'shipment_id'=>$shipment,'category'=>$category,'amount'=>$amount,
                'currency'=>'USD','exchange_rate'=>'2','recoverable'=>$recoverable,'allocation'=>'quantity'])->assertSessionHasNoErrors();
        }
        $item = DB::table('purchase_order_items')->where('purchase_order_id',$po)->value('id');
        $service = app(Purchasing::class);
        $service->receive($po,$warehouse,$shipment,[$item=>['accepted'=>2,'damaged'=>1]],$admin->id,'GRN-A');
        $this->assertDatabaseHas('purchase_orders',['id'=>$po,'status'=>'partial']);
        $this->assertDatabaseHas('goods_receipt_items',['purchase_order_item_id'=>$item,'accepted'=>2,'damaged'=>1,'purchase_vnd'=>168000,'allocated_vnd'=>2000]);
        DB::table('exchange_rates')->insert(['currency'=>'CNY','rate'=>3600,'rate_date'=>today()->toDateString()]);
        $service->receive($po,$warehouse,null,[$item=>['accepted'=>3]],$admin->id,'GRN-B');
        $this->assertDatabaseHas('goods_receipt_items',['purchase_order_item_id'=>$item,'accepted'=>3,'purchase_vnd'=>252000,'allocated_vnd'=>0]);
        $this->assertDatabaseHas('purchase_orders',['id'=>$po,'status'=>'received']);
        $this->assertSame(13,Books::vnd('1.25','10.00'));
    }

    public function test_reserve_fulfill_fifo_wac_cancel_return_refund_and_profit(): void
    {
        [$admin,$customer,$variant,$warehouse] = $this->fixture();
        $sales = app(Sales::class);
        $delivery = ['name'=>'Người nhận','phone'=>'0900000000','address'=>'HCM','warehouse_id'=>$warehouse,'payment_method'=>'cod'];
        $id = $sales->checkout($customer->id,[$variant->id=>3],$delivery,'TEST-ORDER');
        $this->assertDatabaseHas('inventory',['warehouse_id'=>$warehouse,'variant_id'=>$variant->id,'reserved'=>3]);
        $this->assertSame($id,$sales->checkout($customer->id,[$variant->id=>3],$delivery,'TEST-ORDER'));
        $item = DB::table('order_items')->where('order_id',$id)->value('id');
        $sales->changeStatus($id,'confirmed',$admin->id);
        $fulfillment = $sales->fulfill($id,$item,2,$admin->id,'TEST-FULFILL');
        $this->assertDatabaseHas('fulfillments',['id'=>$fulfillment,'net_sales'=>658000,'fifo_cogs'=>240000,'wac_cogs'=>252000]);
        $sales->cash($id,'payment',658000,'TEST-PAY',$admin->id);
        $sales->returnUnits($id,$fulfillment,1,true,$admin->id,'TEST-RETURN');
        $sales->cash($id,'refund',200000,'TEST-REFUND',$admin->id);
        $this->assertDatabaseHas('return_items',['fulfillment_id'=>$fulfillment,'net_sales'=>329000,'fifo_cogs'=>120000,'wac_cogs'=>126000]);
        $this->assertDatabaseHas('financial_transactions',['reference'=>'TEST-REFUND','amount'=>200000]);
        $cancel = $sales->checkout($customer->id,[$variant->id=>2],$delivery,'TEST-CANCEL');
        $sales->changeStatus($cancel,'cancelled',$admin->id);
        $this->assertDatabaseHas('inventory',['warehouse_id'=>$warehouse,'variant_id'=>$variant->id,'reserved'=>1]);
        $expense = DB::table('expense_categories')->value('id');
        $this->actingAs($admin)->post('/admin/expenses',['action'=>'create','expense_category_id'=>$expense,'description'=>'Test expense','amount'=>12000,
            'currency'=>'VND','exchange_rate'=>1,'transaction_date'=>today()->toDateString(),'payment_method'=>'cash'])->assertSessionHasNoErrors();
        $this->post('/admin/expenses',['action'=>'approve','id'=>DB::table('operating_expenses')->value('id')])->assertSessionHasNoErrors();
        $report = app(Reports::class)->sales(today()->toDateString(),today()->toDateString(),'fifo');
        $this->assertSame(967000,$report['revenue']);
        $this->assertSame(360000,$report['cogs']);
        $this->assertSame(607000,$report['gross']);
        $this->assertSame(595000,$report['net']);
        $this->get('/admin/report?json=1')->assertOk()->assertJsonPath('revenue',967000);
        $this->get('/admin/report')->assertOk();
    }

    public function test_failed_reservation_is_atomic_and_inventory_cannot_go_negative(): void
    {
        [$admin,$customer,$variant,$warehouse] = $this->fixture();
        try {
            app(Sales::class)->checkout($customer->id,[$variant->id=>99],
                ['name'=>'Test','phone'=>'123','address'=>'HCM','warehouse_id'=>$warehouse,'payment_method'=>'cod'],'FAILED-ORDER');
            $this->fail('Expected insufficient stock');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('orders',['number'=>'FAILED-ORDER']);
        }
        $inventory = app(Inventory::class);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => $inventory->issue($inventory->lock($warehouse,$variant->id),99,$admin->id,'BAD-ISSUE'));
    }

    public function test_blog_publishing_metadata_and_excel_endpoints(): void
    {
        [$admin] = $this->fixture();
        $this->actingAs($admin)->post('/admin/data/blog_posts',['title'=>'Lắp robot','slug'=>'lap-robot','body'=>'Nội dung',
            'status'=>'published','seo_title'=>'Học lắp robot'])->assertSessionHasNoErrors();
        $this->get('/blog/lap-robot')->assertOk()->assertSee('Học lắp robot');
        $this->get('/sitemap.xml')->assertOk()->assertSee('/blog/lap-robot');
        $this->get('/admin/excel/template')->assertOk()->assertDownload('kitlab-products-template.xlsx');
        $this->get('/admin/excel/products')->assertOk()->assertDownload('kitlab-products.xlsx');
        $this->post('/admin/excel/preview',['mode'=>'insert'])->assertSessionHasErrors('file');
    }
}
