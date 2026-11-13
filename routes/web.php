<?php
use App\Http\Controllers\{Admin, Dashboard, Operations, Shop, Spreadsheets};
use Illuminate\Support\Facades\Route;

Route::get('/',[Shop::class,'index'])->name('home');
Route::get('/san-pham/{slug}',[Shop::class,'product'])->name('product');
Route::get('/gio-hang',[Shop::class,'cart'])->name('cart');
Route::post('/gio-hang',[Shop::class,'changeCart'])->name('cart.change');
Route::get('/blog',[Shop::class,'blog'])->name('blog');
Route::get('/blog/{slug}',[Shop::class,'article'])->name('article');
Route::get('/sitemap.xml',[Shop::class,'sitemap']);
Route::get('/robots.txt',fn () => response("User-agent: *\nDisallow: /admin\nDisallow: /tai-khoan\nSitemap: ".url('/sitemap.xml')."\n",200,['Content-Type'=>'text/plain']));
Route::post('/ban-tin',[Shop::class,'newsletter'])->middleware('throttle:10,1')->name('newsletter');
Route::get('/thong-tin/{page}',fn (string $page) => view('shop.info',compact('page')))->whereIn('page',['gioi-thieu','lien-he','chinh-sach'])->name('info');
Route::middleware('guest')->group(function () {
    Route::view('/dang-nhap','shop.auth',['register'=>false])->name('login');
    Route::view('/dang-ky','shop.auth',['register'=>true])->name('register');
    Route::post('/dang-nhap',[Shop::class,'signIn'])->middleware('throttle:6,1');
    Route::post('/dang-ky',[Shop::class,'register'])->middleware('throttle:6,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/dang-xuat',[Shop::class,'logout'])->name('logout');
    Route::get('/tai-khoan',[Shop::class,'account'])->name('account');
    Route::post('/tai-khoan',[Shop::class,'profile'])->name('profile');
    Route::get('/tai-khoan/don/{id}',[Shop::class,'order'])->whereNumber('id')->name('order');
    Route::post('/thanh-toan',[Shop::class,'checkout'])->middleware('throttle:12,1')->name('checkout');
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/',[Admin::class,'home'])->name('home');
        Route::get('/data/{table}',[Admin::class,'catalog'])->name('catalog');
        Route::post('/data/{table}',[Admin::class,'save'])->name('save');
        Route::post('/image/{id}',[Admin::class,'image'])->whereNumber('id')->name('image');
        Route::get('/label/{id}',[Admin::class,'label'])->whereNumber('id')->name('label');
        Route::match(['get','post'],'/roles',[Admin::class,'role'])->name('roles');
        Route::get('/operations/{tab}',[Operations::class,'screen'])->name('operations');
        Route::post('/purchasing',[Operations::class,'purchasing'])->name('purchasing');
        Route::post('/inventory',[Operations::class,'inventory'])->name('inventory');
        Route::post('/orders',[Operations::class,'orders'])->name('orders');
        Route::post('/expenses',[Operations::class,'expenses'])->name('expenses');
        Route::get('/report',[Dashboard::class,'report'])->name('report');
        Route::get('/excel',[Spreadsheets::class,'screen'])->name('excel');
        Route::get('/excel/{kind}',[Spreadsheets::class,'download'])->name('download');
        Route::post('/excel/preview',[Spreadsheets::class,'preview'])->name('preview');
        Route::post('/excel/{id}/confirm',[Spreadsheets::class,'confirm'])->whereNumber('id')->name('confirm');
    });
});
