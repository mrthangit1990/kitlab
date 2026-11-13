<?php
namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Books;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Picqer\Barcode\BarcodeGeneratorSVG;

final class Admin
{
    public function home()
    {
        abort_unless(auth()->user()->canManage('admin'), 403);
        return view('admin.home');
    }

    public function catalog(Request $request, string $table)
    {
        [$title, $permission, $fields] = $this->module($request, $table);
        $edit = $request->integer('edit') ? DB::table($table)->find($request->integer('edit')) : null;
        $rows = DB::table($table);
        if ($table === 'product_variants' && $request->filled('q')) {
            $rows->where(fn ($q) => $q->where('sku', $request->string('q')->toString())
                ->orWhereIn('id', DB::table('product_barcodes')->where('code', $request->string('q')->toString())->select('variant_id')));
        }
        return view('admin.catalog', ['table' => $table, 'title' => $title, 'fields' => $fields, 'edit' => $edit, 'rows' => $rows->latest('id')->simplePaginate(30)->withQueryString()]);
    }

    public function save(Request $request, string $table)
    {
        [, , $fields] = $this->module($request, $table);
        $id = $request->integer('id') ?: null;
        $original = $id ? DB::table($table)->find($id) : null;
        abort_if($id && !$original, 404);
        $unique = [
            'categories' => ['name','slug'], 'brands'=>['name'], 'products'=>['slug'], 'product_variants'=>['sku'],
            'product_barcodes'=>['code'], 'suppliers'=>['code'], 'warehouses'=>['code'], 'expense_categories'=>['name'],
            'blog_categories'=>['name'], 'blog_posts'=>['slug'], 'coupons'=>['code'],
        ];
        foreach ($unique[$table] ?? [] as $field) {
            $fields[$field] = [...explode('|', $fields[$field]), Rule::unique($table, $field)->ignore($id)];
        }
        $data = $request->validate($fields);
        $data = array_filter($data, fn ($value) => $value !== null);
        if ($table === 'product_variants' && empty($data['sku'])) {
            $data['sku'] = $original?->sku ?? 'KIT-'.strtoupper((string) Str::ulid());
        }
        if ($table === 'product_variants' && $request->exists('sale_price')) {
            $data['sale_price'] = $request->input('sale_price');
        }
        if ($table === 'product_barcodes') {
            $data['code'] ??= $original?->code ?? 'KIT-'.strtoupper((string) Str::ulid());
            self::barcodeCheck($data['code'], $data['format']);
        }
        if ($table === 'blog_posts') {
            $data['user_id'] = $request->user()->id;
            $data['publish_at'] ??= $data['status'] === 'published' ? now() : null;
        }
        DB::transaction(function () use ($table, $id, $data, $request) {
            $model = match ($table) { 'products' => new Product, 'product_variants' => new ProductVariant, default => null };
            if ($model) {
                $row = $id ? $model->newQuery()->findOrFail($id) : $model;
                $row->fill($data)->save();
                $id = $row->id;
            } elseif ($id) {
                DB::table($table)->where('id', $id)->update($data);
            } else {
                $id = DB::table($table)->insertGetId($data);
            }
            Books::audit($request->user()->id, 'save', $table, $id, $data);
        });
        return redirect()->route('admin.catalog', $table)->with('notice', 'Đã lưu dữ liệu.');
    }

    public function image(Request $request, int $id)
    {
        abort_unless($request->user()->canManage('catalog'), 403);
        $product = Product::findOrFail($id);
        $data = $request->validate(['image'=>'required|image|mimes:jpeg,png,webp|max:4096','alt'=>'required|string|max:191']);
        $path = $request->file('image')->store('products', 'public');
        DB::table('product_images')->insert(['product_id'=>$product->id,'path'=>$path,'alt'=>$data['alt']]);
        return back()->with('notice','Đã thêm hình ảnh.');
    }

    public static function barcodeCheck(string $code, string $format): void
    {
        $length = match ($format) {'ean13'=>13,'upca'=>12,default=>0};
        if ($length) {
            Books::require(strlen($code) === $length && ctype_digit($code), 'Độ dài hoặc ký tự barcode không hợp lệ.');
            $digits = strrev($code);
            $sum = 0;
            for ($i = 1; $i < $length; $i++) {
                $sum += (int) $digits[$i] * ($i % 2 ? 3 : 1);
            }
            Books::require((10 - $sum % 10) % 10 === (int) $digits[0], 'Sai chữ số kiểm tra barcode.');
        }
    }

    public function label(Request $request, int $id)
    {
        abort_unless($request->user()->canManage('catalog'), 403);
        $row = DB::table('product_barcodes as b')->join('product_variants as v','v.id','=','b.variant_id')
            ->join('products as p','p.id','=','v.product_id')->where('b.id',$id)->first(['b.*','p.name','v.sku','v.price','v.sale_price']);
        abort_unless($row,404);
        $svg = $row->format === 'qr'
            ? (new Writer(new ImageRenderer(new RendererStyle(160), new SvgImageBackEnd)))->writeString($row->code)
            : (new BarcodeGeneratorSVG)->getBarcode($row->code, match ($row->format) {
                'ean13'=>BarcodeGeneratorSVG::TYPE_EAN_13, 'upca'=>BarcodeGeneratorSVG::TYPE_UPC_A, default=>BarcodeGeneratorSVG::TYPE_CODE_128,
            });
        return view('admin.label', compact('row','svg'));
    }

    public function role(Request $request)
    {
        abort_unless($request->user()->canManage('users'),403);
        if ($request->isMethod('post')) {
            $data = $request->validate(['user_id'=>'required|exists:users,id','role_id'=>'required|exists:roles,id','action'=>'required|in:add,remove']);
            $role = DB::table('roles')->find($data['role_id']);
            abort_if($role->name === 'Super Admin' && (!$request->user()->canManage('*') || $data['action'] === 'remove'),403);
            $key = collect($data)->only(['user_id','role_id'])->all();
            DB::transaction(function () use ($data,$key,$request) {
                if ($data['action'] === 'add') DB::table('role_user')->insertOrIgnore($key);
                else DB::table('role_user')->where($key)->delete();
                Books::audit($request->user()->id,$data['action'],'role_user',$data['user_id'],$key);
            });
            return back()->with('notice','Đã cập nhật phân quyền.');
        }
        return view('admin.roles',['roles'=>DB::table('roles')->get(),'users'=>DB::table('users')->select('id','name','email')->get(),
            'assignments'=>DB::table('role_user')->join('users','users.id','=','role_user.user_id')->join('roles','roles.id','=','role_user.role_id')->get(['users.email','roles.name'])]);
    }

    private function module(Request $request, string $table): array
    {
        $module = config('admin')[$table] ?? null;
        abort_unless($module,404);
        abort_unless($request->user()->canManage($module[1]),403);
        return $module;
    }
}
