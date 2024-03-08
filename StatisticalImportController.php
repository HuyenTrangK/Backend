<?php

namespace App\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\SystemController;
use App\Models\Tenant\Import;
use App\Models\Tenant\ImportDetail;
use App\Models\Tenant\Setting;
use App\Models\Tenant\WareHouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Yajra\DataTables\DataTables;

class StatisticalImportController extends Controller
{
    private SystemController $system;

    public function __construct(SystemController $system)
    {
        set_time_limit(0);

        $this->system = $system;
    }

    public function index(): \Illuminate\Contracts\View\View|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse
    {
        $this->system->connect_db();
        if (Auth::check()) {
            return view('tenant.admin.statistical.import.index')
                ->with('warehouse', WareHouse::query()->orderBy('name')->get())
                ->with('setting', Setting::query()->find(1));
        }

        return Redirect::to('/login');
    }

    public function fetchdata(int $warehouse_id, $time)
    {
        $this->system->connect_db();
        if (Auth::check()) {
            $query = Import::query()->selectRaw('SUM(subtotal) AS price,SUM(quantity) AS quantity,SUM(fee_ship) AS fee_ship,SUM(vat) AS vat,count(1) as count,fil_year,fil_precious,time')
                ->fromSub(function ($query) use ($warehouse_id) {
                    $query->selectRaw('YEAR(imports.created_at) As fil_year,QUARTER(imports.created_at) As fil_precious,DATE_FORMAT(imports.created_at,"%Y-%m") As time,
                        SUM(importdetails.import_price/100 * importdetails.vat * importdetails.quantity) as vat,SUM(importdetails.import_price * importdetails.quantity) as subtotal,SUM(importdetails.quantity) as quantity,imports.fee_ship as fee_ship')
                        ->from('imports')
                        ->when(Auth::user()->role <= 0 && $warehouse_id != 0, function ($query) use ($warehouse_id) {
                            return $query->where('imports.warehouse_id', '=', $warehouse_id)
                                ->whereNotNull('imports.supplier_id');
                        })
                        ->when(Auth::user()->role > 1, function ($query) {
                            return $query->where('imports.warehouse_id', '=', Auth::user()->warehouse_id)
                                ->whereNotNull('imports.supplier_id');
                        })
                        ->when(Auth::user()->role == 1, function ($query) {
                            return $query->where('imports.user_id', '=', Auth::id());
                        })
                        ->whereNotNull('imports.supplier_id')
                        ->leftJoin('importdetails', 'importdetails.import_id', '=', 'imports.id')
                        ->groupBy('imports.id');
                }, 'sub')
                ->when($time == 'fil_precious', function ($query) {
                    return $query->groupBy('fil_year', 'fil_precious')->orderBy('fil_year', 'DESC')->orderBy('fil_precious', 'DESC');
                }, function ($query) use ($time) {
                    return $query->groupBy($time)->orderBy($time, 'DESC');
                });
            try {
                return Datatables::of($query)->make(true);
            } catch (\Exception) {
            }
        }
    }

    public function filter(int $warehouse_id, $time): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        if (Auth::check()) {
            $query = Import::query()->selectRaw('SUM(subtotal) AS price,SUM(quantity) AS quantity,SUM(fee_ship) AS fee_ship,SUM(paid_amount) AS paid_amount,SUM(vat) AS vat,count(1) as count,fil_year,fil_precious,time')
                ->fromSub(function ($query) use ($warehouse_id) {
                    $query->selectRaw('YEAR(imports.created_at) As fil_year,QUARTER(imports.created_at) As fil_precious,DATE_FORMAT(imports.created_at,"%Y-%m") As time,
                        SUM(importdetails.import_price/100 * importdetails.vat * importdetails.quantity) as vat,SUM(importdetails.import_price * importdetails.quantity) as subtotal,SUM(importdetails.quantity) as quantity,imports.fee_ship as fee_ship,imports.paid_amount as paid_amount')
                        ->from('imports')
                        ->when(Auth::user()->role <= 0 && $warehouse_id != 0, function ($query) use ($warehouse_id) {
                            return $query->where('imports.warehouse_id', '=', $warehouse_id)
                                ->whereNotNull('imports.supplier_id');
                        })
                        ->when(Auth::user()->role > 1, function ($query) {
                            return $query->where('imports.warehouse_id', '=', Auth::user()->warehouse_id)
                                ->whereNotNull('imports.supplier_id');
                        })
                        ->when(Auth::user()->role == 1, function ($query) {
                            return $query->where('imports.user_id', '=', Auth::id());
                        })
                        ->whereNotNull('imports.supplier_id')
                        ->leftJoin('importdetails', 'importdetails.import_id', '=', 'imports.id')
                        ->groupBy('imports.id');
                }, 'sub')
                ->when($time == 'fil_precious', function ($query) {
                    return $query->groupBy('fil_year', 'fil_precious')->orderBy('fil_year', 'DESC')->orderBy('fil_precious', 'DESC');
                }, function ($query) use ($time) {
                    return $query->groupBy($time)->orderBy($time, 'DESC');
                })->get();
            $price = [];
            $debt = [];
            $quantity = [];
            if ($this->valid($query)) {
                $data = $query->toArray();
                $data = array_reverse($data);
                foreach ($data as $key => $item) {
                    $x = ($time == 'time') ? $item['time'] : (($time == 'fil_year') ? $item['fil_year'] : $item['fil_precious'].' '.$item['fil_year']);
                    $total = round($item['price'] + $item['fee_ship'] + $item['vat']);
                    $price[] = [
                        'x' => $x,
                        'y' => $total,
                    ];
                    $debt[] = [
                        'x' => $x,
                        'y' => $total - $item['paid_amount'],
                    ];
                    $quantity[] = [
                        'x' => $x,
                        'y' => $item['count'],
                    ];
                }
            }

            return response()->json([
                'price' => $price,
                'debt' => $debt,
                'quantity' => $quantity,
            ]);
        }

        return Redirect::to('/login');
    }

    public function detail(Request $request): string|\Illuminate\Http\RedirectResponse
    {
        if (Auth::check()) {
            $code = Setting::query()->find(1)->value('code_import');
            $warehouse_id = Auth::user()->role <= 0 ? $request->input('warehouse_id') : Auth::user()->warehouse_id;
            $type = $request->input('type');
            $time = Carbon::parse($request->input('time'));
            $import = Import::query()
                ->when((Auth::user()->role <= 0 && $warehouse_id != 0) || (Auth::user()->role > 1), function ($query) use ($warehouse_id) {
                    return $query->where('imports.warehouse_id', '=', $warehouse_id)
                        ->whereNotNull('imports.supplier_id');
                })
                ->when($type == 'time', function ($query) use ($time) {
                    return $query->where(DB::raw('(DATE_FORMAT(created_at,"%Y-%m"))'), '=', $time->format('Y-m'));
                })
                ->when($type == 'fil_precious', function ($query) use ($time) {
                    return $query->where(DB::raw('(QUARTER(created_at))'), '=', $time->quarter)
                        ->where(DB::raw('(YEAR(created_at))'), '=', $time->year);
                })
                ->when($type == 'fil_year', function ($query) use ($time) {
                    return $query->where(DB::raw('(YEAR(created_at))'), '=', $time->year);
                })
                ->when(Auth::user()->role == 1, function ($query) {
                    return $query->where('imports.user_id', '=', Auth::id());
                })
                ->get();
            $output = '
                <div class="card-body">
                <table class="table table-separate table-head-custom table-foot-custom table-checkable display nowrap" id="table_import_'.$request->input('time').'">
                    <thead>
                        <tr>
                            <th>'.__('product.stt').'</th>
                            <th>'.__('product.id').'</th>
                            <th>'.__('product.image').'</th>
                            <th>'.__('product.product_name').'</th>
                            <th>'.__('product.code').'</th>
                            <th>Serial</th>
                            <th>'.__('product.warranty_to').'</th>
                            <th>Link drive</th>
                            <th>'.__('product.cost').'</th>
                            <th>'.__('product.tax').'</th>
                            <th>'.__('product.quantity').'</th>
                            <th>'.__('product.total').'</th>
                        </tr>
                    </thead>
                    <tbody>
                ';
            $i = 0;
            $total_quantity = 0;
            $total = 0;
            foreach ($import as $key => $value) {
                $detail = ImportDetail::query()->select('products.name as product_name', 'importdetails.*')
                    ->join('products', 'products.id', '=', 'importdetails.product_id')
                    ->where('import_id', '=', $value->id)->get();
                if ($detail->count() > 0) {
                    foreach ($detail as $key => $item) {
                        $i++;
                        $subtotal = ($item->import_price + $item->import_price / 100 * $item->vat) * $item->quantity;
                        $total_quantity += $item->quantity;
                        $total += $subtotal;
                        $output .= '
                            <tr>
                                <td>'.$i.'</td>
                                <td>'.$code.$item->import_id.'</td>';
                        if ($item->image) {
                            $output .= '
                                    <td>
                                        <div class="product__shape">
                                            <img class="product__img cursor-pointer" src="'.$item->image.'">
                                        </div>
                                    </td>';
                        } else {
                            $output .= '
                                    <td>
                                        <div class="product__shape">
                                            <img class="product__img cursor-pointer" src="'.asset('media/users/noimage.png').'">
                                        </div>
                                    </td>';
                        }
                        $output .= '
                                <td>'.$item->product_name.'</td>
                                <td>'.$item->product_code.'</td>
                                <td>'.$item->product_serial.'</td>
                                <td>'.Carbon::parse($item->date_end)->format('d-m-Y').'</td>';
                        if ($item->drive) {
                            $output .= '<td><a href='.$item->drive.' target="_blank">'.$item->drive.'</a></td>';
                        } else {
                            $output .= '<td>'.__('product.nothing').'</td>';
                        }
                        $output .= '
                                <td>'.number_format($item->import_price).'</td>
                                <td>'.number_format($item->import_price / 100 * $item->vat).'</td>
                                <td>'.$item->quantity.' '.$item->unit_name.'</td>
                                <td>'.number_format($subtotal).'</td>';
                    }
                }
            }

            $output .= '
                    </tbody>
                    <tfoot>
                    <tr>
                        <th>'.__('product.sum').'</th>
                        <th colspan="9"></th>
                        <th>'.$total_quantity.' '.__('product.product22').'</th>
                        <th>'.number_format($total + floatval($request->fee_ship)).'</td>
                    </tr>
                    </tfoot>
                </table>
            </div>';

            return $output;
        }

        return Redirect::to('/login');
    }
}
