<?php
namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Books;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as Reader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as Writer;

final class Spreadsheets
{
    private const HEADERS = ['name','sku','barcode','category','brand','price','sale_price','min_stock','description','weight_grams','age','origin','seo_title','seo_description'];
    private const RULES = ['name'=>'required|string|max:191','sku'=>'required|alpha_dash:ascii|max:70','barcode'=>'nullable|regex:/^[A-Za-z0-9_.-]+$/|max:80','category'=>'required|string|max:191',
        'brand'=>'required|string|max:191','price'=>'required|integer|min:0|max:1000000000','sale_price'=>'nullable|integer|min:0|lte:price',
        'min_stock'=>'required|integer|min:0|max:1000000','description'=>'nullable|string|max:10000','weight_grams'=>'nullable|integer|min:0|max:1000000',
        'age'=>'nullable|integer|min:0|max:99','origin'=>'nullable|string|max:191','seo_title'=>'nullable|string|max:191','seo_description'=>'nullable|string|max:2000'];

    public function screen(Request $r)
    {
        $this->allow($r);
        $job = DB::table('import_jobs')->where('user_id',$r->user()->id)->find($r->integer('job'));
        return view('admin.excel',['job'=>$job,'jobs'=>DB::table('import_jobs')->where('user_id',$r->user()->id)->latest('id')->limit(25)->get(),
            'errorsList'=>$job ? DB::table('import_job_errors')->where('import_job_id',$job->id)->get() : collect()]);
    }

    public function download(Request $r, string $kind)
    {
        $this->allow($r);
        if ($kind === 'template') return self::xlsx('kitlab-products-template.xlsx', self::HEADERS, []);
        if ($kind === 'errors') {
            $job = DB::table('import_jobs')->where('user_id',$r->user()->id)->find($r->integer('job'));
            abort_unless($job,404);
            return self::xlsx('kitlab-import-errors.xlsx',['row','message'],DB::table('import_job_errors')->where('import_job_id',$job->id)->get(['row_number','message']));
        }
        abort_unless($kind === 'products',404);
        $rows = DB::table('product_variants as v')->join('products as p','p.id','=','v.product_id')->join('categories as c','c.id','=','p.category_id')
            ->join('brands as b','b.id','=','p.brand_id')->orderBy('v.id')
            ->selectRaw('p.name,v.sku,(SELECT MIN(code) FROM product_barcodes WHERE variant_id=v.id) as barcode,c.name as category,b.name as brand,v.price,v.sale_price,v.min_stock,p.description,v.weight_grams,p.age,p.origin,p.seo_title,p.seo_description');
        if ($r->integer('category')) $rows->where('c.id',$r->integer('category'));
        return self::xlsx('kitlab-products.xlsx',self::HEADERS,$rows->cursor());
    }

    public function preview(Request $r)
    {
        $this->allow($r);
        $r->validate(['file'=>'required|file|mimes:xlsx|max:4096','mode'=>'required|in:insert,update']);
        $path = $r->file('file')->getRealPath();
        $zip = new \ZipArchive;
        Books::require($zip->open($path) === true,'Tệp XLSX không hợp lệ.');
        $expanded = 0;
        for ($i=0;$i<$zip->numFiles;$i++) $expanded += $zip->statIndex($i)['size'];
        $zip->close();
        Books::require($expanded <= 25000000,'Tệp Excel sau giải nén quá lớn.');
        try {
            $reader = new Reader;
            $reader->setReadDataOnly(true);
            $info = $reader->listWorksheetInfo($path);
            Books::require(count($info) === 1 && $info[0]['totalRows'] <= 2001 && $info[0]['totalColumns'] === count(self::HEADERS),'Dùng một sheet theo mẫu và tối đa 2.000 dòng.');
            $rows = $reader->load($path)->getActiveSheet()->toArray(null,false,false,false);
        } catch (\PhpOffice\PhpSpreadsheet\Exception $exception) {
            Books::require(false,'Không đọc được tệp XLSX.');
        }
        Books::require(array_shift($rows) === self::HEADERS,'Tên hoặc thứ tự cột sai mẫu.');
        $payload = $errors = $skus = $barcodes = [];
        foreach ($rows as $index => $cells) {
            if (count(array_filter($cells,fn ($x) => $x !== null && $x !== '')) === 0) continue;
            $line = $index + 2;
            $row = array_combine(self::HEADERS,array_map(fn ($x) => $x === null || trim((string) $x) === '' ? null : trim((string) $x),$cells));
            $validator = Validator::make($row,self::RULES);
            if ($validator->fails()) {
                $errors[$line] = implode(' ', $validator->errors()->all());
                continue;
            }
            $sku = strtoupper($row['sku']);
            $barcode = strtoupper($row['barcode'] ?? '');
            $category = DB::table('categories')->where('name',$row['category'])->value('id');
            $brand = DB::table('brands')->where('name',$row['brand'])->value('id');
            $current = ProductVariant::where('sku',$sku)->first();
            $barcodeOwner = $barcode ? DB::table('product_barcodes')->where('code',$barcode)->value('variant_id') : null;
            if (isset($skus[$sku]) || ($barcode && isset($barcodes[$barcode]))) $errors[$line] = 'SKU hoặc barcode trùng trong tệp.';
            elseif (!$category || !$brand) $errors[$line] = 'Danh mục hoặc thương hiệu chưa tồn tại.';
            elseif ($barcodeOwner && $barcodeOwner != $current?->id) $errors[$line] = 'Barcode đã thuộc SKU khác.';
            elseif ($r->mode === 'insert' && $current) $errors[$line] = 'SKU đã tồn tại. Chọn chế độ cập nhật.';
            elseif ($r->mode === 'update' && !$current) $errors[$line] = 'Không tìm thấy SKU để cập nhật.';
            else $payload[$line] = [...$row,'sku'=>$sku,'barcode'=>$barcode,'category_id'=>$category,'brand_id'=>$brand,'variant_id'=>$current?->id];
            $skus[$sku] = true;
            if ($barcode) $barcodes[$barcode] = true;
        }
        $id = DB::transaction(function () use ($r,$payload,$errors) {
            $id = DB::table('import_jobs')->insertGetId(['user_id'=>$r->user()->id,'filename'=>mb_substr(basename($r->file('file')->getClientOriginalName()),0,191),
                'status'=>'preview','total'=>count($payload)+count($errors),'failed'=>count($errors),'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]);
            foreach ($errors as $line=>$message) DB::table('import_job_errors')->insert(['import_job_id'=>$id,'row_number'=>$line,'message'=>$message]);
            return $id;
        });
        return redirect()->route('admin.excel',['job'=>$id]);
    }

    public function confirm(Request $r, int $id)
    {
        $this->allow($r);
        DB::transaction(function () use ($r,$id) {
            $job = DB::table('import_jobs')->where(['id'=>$id,'user_id'=>$r->user()->id])->lockForUpdate()->first();
            abort_unless($job,404);
            Books::require($job->status === 'preview' && $job->total > $job->failed,'Không có dữ liệu chờ nhập.');
            foreach (json_decode($job->payload,true,512,JSON_THROW_ON_ERROR) as $row) {
                $variant = ProductVariant::where('sku',$row['sku'])->lockForUpdate()->first();
                Books::require($variant?->id == $row['variant_id'],'SKU đã thay đổi, hãy tải lại tệp.');
                if (!$variant) {
                    $product = Product::create([
                        ...collect($row)->only(['name','category_id','brand_id','description','seo_description'])->all(),
                        'slug'=>(Str::slug($row['name']) ?: 'san-pham').'-'.strtolower((string) Str::ulid()),
                        'age'=>$row['age'] ?? 0,'origin'=>$row['origin'] ?? '','seo_title'=>$row['seo_title'] ?? '',
                    ]);
                    $variant = ProductVariant::create(['product_id'=>$product->id,'name'=>'Tiêu chuẩn','sku'=>$row['sku'],'price'=>$row['price']]);
                }
                $variant->update([...collect($row)->only(['price','sale_price','min_stock'])->all(),'weight_grams'=>$row['weight_grams'] ?? $variant->weight_grams ?? 0]);
                if ($row['barcode']) {
                    $owner = DB::table('product_barcodes')->where('code',$row['barcode'])->lockForUpdate()->first();
                    Books::require(!$owner || $owner->variant_id == $variant->id,'Barcode đã thay đổi, hãy tải lại tệp.');
                    if (!$owner) DB::table('product_barcodes')->insert(['code'=>$row['barcode'],'variant_id'=>$variant->id,'format'=>'code128']);
                }
            }
            DB::table('import_jobs')->where('id',$id)->update(['status'=>'completed','successful'=>$job->total-$job->failed,'payload'=>null]);
            Books::audit($r->user()->id,'confirm','import_jobs',$id,['successful'=>$job->total-$job->failed,'failed'=>$job->failed]);
        },3);
        return redirect()->route('admin.excel',['job'=>$id])->with('notice','Đã hoàn thành nhập Excel.');
    }

    public static function xlsx(string $name,array $headers,iterable $rows)
    {
        return response()->streamDownload(function () use ($headers,$rows) {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet();
            $sheet->fromArray($headers,null,'A1');
            $sheet->freezePane('A2');
            $i = 2;
            foreach ($rows as $row) {
                foreach (array_values((array) $row) as $j=>$value) {
                    if ($value !== null) $sheet->setCellValueExplicit([$j+1,$i],(string) $value,DataType::TYPE_STRING);
                }
                $i++;
            }
            (new Writer($book))->save('php://output');
        },$name,['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function allow(Request $r): void { abort_unless($r->user()->canManage('catalog'),403); }
}
