<?php
namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\User;
use App\Services\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

final class Shop
{
    public function index(Request $r)
    {
        $filter = $r->validate(['q'=>'nullable|string|max:100','category'=>'nullable|integer','brand'=>'nullable|integer','age'=>'nullable|integer|min:0','min'=>'nullable|integer|min:0','max'=>'nullable|integer|min:0','stock'=>'nullable|boolean','sort'=>'nullable|in:new,price,price_desc']);
        $query = Product::query()->where('published', true)->whereHas('variants')->with('variants')
            ->addSelect(['display_price'=>DB::table('product_variants')->selectRaw('MIN(COALESCE(sale_price,price))')->whereColumn('product_id','products.id')]);
        if (!empty($filter['q'])) {
            $term = '%'.$filter['q'].'%';
            $query->where(fn ($q) => $q->where('name','like',$term)->orWhereHas('variants',fn ($v) => $v->where('sku','like',$term)));
        }
        foreach (['category'=>'category_id','brand'=>'brand_id','age'=>'age'] as $field=>$col) {
            if (isset($filter[$field])) $query->where($col,$filter[$field]);
        }
        if (isset($filter['min']) || isset($filter['max']) || !empty($filter['stock'])) {
            $query->whereHas('variants', function ($v) use ($filter) {
                $v->whereRaw('COALESCE(sale_price,price) BETWEEN ? AND ?', [$filter['min'] ?? 0,$filter['max'] ?? PHP_INT_MAX]);
                if (!empty($filter['stock'])) {
                    $v->whereExists(fn ($q) => $q->selectRaw('1')->from('inventory as i')->join('warehouses as w','w.id','=','i.warehouse_id')
                        ->where('w.active',true)->whereColumn('i.variant_id','product_variants.id')->whereColumn('i.on_hand','>','i.reserved'));
                }
            });
        }
        match ($filter['sort'] ?? 'new') {'price'=>$query->orderBy('display_price'),'price_desc'=>$query->orderByDesc('display_price'),default=>$query->latest('id')};
        return view('shop.index', ['products'=>$query->simplePaginate(24)->withQueryString(),'categories'=>DB::table('categories')->orderBy('name')->get(),
            'brands'=>DB::table('brands')->orderBy('name')->get(),'posts'=>$this->published()->latest('publish_at')->limit(3)->get()]);
    }

    public function product(string $slug)
    {
        $product = Product::where(['slug'=>$slug,'published'=>true])->with('variants')->firstOrFail();
        $images = DB::table('product_images')->where('product_id',$product->id)->get();
        $stock = DB::table('inventory as i')->join('warehouses as w','w.id','=','i.warehouse_id')->where('w.active',true)
            ->whereIn('i.variant_id',$product->variants->pluck('id'))->groupBy('i.variant_id')->selectRaw('i.variant_id, SUM(i.on_hand-i.reserved) as qty')->pluck('qty','i.variant_id');
        $barcodes = DB::table('product_barcodes')->whereIn('variant_id',$product->variants->pluck('id'))->get()->groupBy('variant_id');
        $related = Product::where('published',true)->where('category_id',$product->category_id)->where('id','<>',$product->id)->limit(4)->get();
        return view('shop.product',compact('product','images','stock','related','barcodes'));
    }

    public function cart(Request $r)
    {
        $cart = $r->session()->get('cart',[]);
        $items = DB::table('product_variants as v')->join('products as p','p.id','=','v.product_id')
            ->where('p.published',true)->whereIn('v.id',array_keys($cart))->get(['v.*','p.name as product_name']);
        $warehouses = DB::table('warehouses')->where('active',true)->get();
        $reference = $r->session()->get('checkout_ref') ?? 'SO-'.Str::ulid();
        $r->session()->put('checkout_ref',$reference);
        return view('shop.cart',compact('cart','items','warehouses','reference'));
    }

    public function changeCart(Request $r)
    {
        $data = $r->validate(['variant_id'=>'required|exists:product_variants,id','quantity'=>'required|integer|min:0|max:99','mode'=>'nullable|in:add,set']);
        $cart = $r->session()->get('cart',[]);
        $id = $data['variant_id'];
        $qty = $data['quantity'] + (($data['mode'] ?? 'set') === 'add' ? ($cart[$id] ?? 0) : 0);
        if ($qty === 0) unset($cart[$id]);
        else $cart[$id] = min(99,$qty);
        $r->session()->put('cart',$cart);
        $r->session()->forget('checkout_ref');
        return redirect()->route('cart');
    }

    public function checkout(Request $r, Sales $sales)
    {
        $data = $r->validate(['name'=>'required|string|max:191','phone'=>'required|string|max:30','address'=>'required|string|max:500',
            'warehouse_id'=>'required|exists:warehouses,id','coupon'=>'nullable|string|max:50','payment_method'=>'required|in:cod,bank','reference'=>'required|regex:/^SO-[0-9A-Z]{26}$/']);
        if ($data['payment_method'] === 'bank') abort_unless(config('kitlab.bank'),422);
        $prior = DB::table('orders')->where(['user_id'=>$r->user()->id,'number'=>$data['reference']])->value('id');
        if ($prior) return redirect()->route('order',$prior);
        abort_unless($r->session()->get('checkout_ref') === $data['reference'],419);
        $id = $sales->checkout($r->user()->id,$r->session()->get('cart',[]),$data,$data['reference']);
        $r->session()->forget(['cart','checkout_ref']);
        return redirect()->route('order',$id);
    }

    public function account(Request $r)
    {
        return view('shop.account',['orders'=>DB::table('orders')->where('user_id',$r->user()->id)->latest('id')->simplePaginate(25)]);
    }

    public function profile(Request $r)
    {
        $data = $r->validate(['name'=>'required|string|max:191']);
        $r->user()->update($data);
        return back()->with('notice','Đã lưu hồ sơ.');
    }

    public function order(Request $r,int $id)
    {
        $order = DB::table('orders')->where(['id'=>$id,'user_id'=>$r->user()->id])->first();
        abort_unless($order,404);
        $items = DB::table('order_items')->where('order_id',$id)->get();
        $history = DB::table('order_status_histories')->where('order_id',$id)->orderBy('id')->get();
        return view('shop.order',compact('order','items','history'));
    }

    public function signIn(Request $r)
    {
        $credentials = $r->validate(['email'=>'required|email','password'=>'required|string|max:72']);
        if (!Auth::attempt($credentials)) return back()->withErrors(['email'=>'Thông tin đăng nhập chưa đúng.'])->onlyInput('email');
        $r->session()->regenerate();
        return redirect()->intended(route('account'));
    }

    public function register(Request $r)
    {
        $data = $r->validate(['name'=>'required|string|max:191','email'=>'required|email|max:191|unique:users','password'=>['required','string','max:72','confirmed',Password::min(10)]]);
        $user = DB::transaction(function () use ($data) {
            $user = User::create([...$data,'password'=>Hash::make($data['password'])]);
            DB::table('customers')->insert(['user_id'=>$user->id]);
            return $user;
        });
        Auth::login($user);
        $r->session()->regenerate();
        return redirect()->route('account');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        return redirect()->route('home');
    }

    public function blog() { return view('shop.blog',['posts'=>$this->published()->latest('publish_at')->simplePaginate(15)]); }

    public function article(string $slug)
    {
        $post = $this->published()->where('slug',$slug)->first();
        abort_unless($post,404);
        DB::table('blog_posts')->where('id',$post->id)->increment('views');
        return view('shop.article',compact('post'));
    }

    public function newsletter(Request $r)
    {
        $data = $r->validate(['email'=>'required|email|max:191']);
        DB::table('newsletter_subscribers')->insertOrIgnore($data);
        return back()->with('notice','Đã đăng ký nhận bản tin.');
    }

    public function sitemap()
    {
        $urls = [route('home'),route('blog')];
        foreach (Product::where('published',true)->pluck('slug') as $slug) $urls[] = route('product',$slug);
        foreach ($this->published()->pluck('slug') as $slug) $urls[] = route('article',$slug);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) $xml .= '<url><loc>'.htmlspecialchars($url,ENT_XML1,'UTF-8').'</loc></url>';
        return response($xml.'</urlset>',200,['Content-Type'=>'application/xml; charset=UTF-8']);
    }

    private function published()
    {
        return DB::table('blog_posts')->where('status','published')->where('publish_at','<=',now());
    }
}
