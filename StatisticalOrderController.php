<?php

namespace App\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\SystemController;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderDetail;
use App\Models\Tenant\Setting;
use App\Models\Tenant\WareHouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Yajra\DataTables\DataTables;

class StatisticalOrderController extends Controller
{
    private SystemController $system;

    public function __construct(SystemController $system)
    {
        set_time_limit(0);

        $this->system = $system;
        $this->code = Setting::query()->find(1)->value('code_order');
    }

    public function index(): \Illuminate\Contracts\View\View|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse
    {
        $this->system->connect_db();
        if (Auth::check()) {
            return view('tenant.admin.statistical.order.index')
                ->with('warehouse', WareHouse::query()->orderBy('name')->get())
                ->with('setting', Setting::query()->find(1));
        }

        return Redirect::to('/login');
    }

    public function fetchdata(int $warehouse_id, $time)
    {
        $this->system->connect_db();
        if (Auth::check()) {
            $warehouse_id = Auth::user()->role <= 0 ? $warehouse_id : Auth::user()->warehouse_id;
            $query = Order::query()->selectRaw('SUM(subtotal) AS price,SUM(quantity) AS quantity,SUM(fee_ship) AS fee_ship,SUM(discount) AS discount,SUM(vat) AS vat,count(1) as count,fil_year,fil_precious,time,fil_day')
                ->fromSub(function ($query) use ($warehouse_id) {
                    $query->selectRaw('YEAR(orders.created_at) As fil_year,QUARTER(orders.created_at) As fil_precious,DATE_FORMAT(orders.created_at,"%Y-%m") As time,DATE_FORMAT(orders.created_at,"%Y-%m-%d") As fil_day,
                        (CASE WHEN orders.print = 1 THEN SUM(orderdetails.price/100 * importdetails.vat * orderdetails.quantity) END) as vat,
                        SUM(orderdetails.price * orderdetails.quantity) as subtotal,SUM(orderdetails.quantity) as quantity,(CASE WHEN orders.km - orders.free > 0 THEN orders.fee_ship * (orders.km - orders.free) ELSE 0 END) as fee_ship,orders.discount as discount')
                        ->from('orders')
                        ->when($warehouse_id != 0, function ($query) use ($warehouse_id) {
                            return $query->where('orders.warehouse_id', '=', $warehouse_id);
                        })
                        ->when(Auth::user()->role == 1, function ($query) {
                            return $query->where('imports.user_id', '=', Auth::id());
                        })
                        ->leftJoin('orderdetails', 'orderdetails.order_id', '=', 'orders.id')
                        ->leftJoin('services', 'services.id', '=', 'orderdetails.service_id')
                        ->leftJoin('importdetails', 'importdetails.product_code', '=', 'orderdetails.product_code')
                        ->leftJoin('imports', 'imports.id', '=', 'importdetails.import_id')
                        ->groupBy('orders.id');
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

        return Redirect::to('/login');
    }

    public function filter(int $warehouse_id, $time): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $this->system->connect_db();
        if (Auth::check()) {
            $warehouse_id = Auth::user()->role <= 0 ? $warehouse_id : Auth::user()->warehouse_id;
            $query = Order::query()->selectRaw('SUM(subtotal) AS price,SUM(quantity) AS quantity,SUM(fee_ship) AS fee_ship,SUM(paid_amount) AS paid_amount,SUM(discount) AS discount,SUM(vat) AS vat,count(1) as count,fil_year,fil_precious,time,fil_day')
                ->fromSub(function ($query) use ($warehouse_id) {
                    $query->selectRaw('YEAR(orders.created_at) As fil_year,QUARTER(orders.created_at) As fil_precious,DATE_FORMAT(orders.created_at,"%Y-%m") As time,DATE_FORMAT(orders.created_at,"%Y-%m-%d") As fil_day,
                        (CASE WHEN orders.print = 1 THEN SUM(orderdetails.price/100 * importdetails.vat * orderdetails.quantity) END) as vat,
                        SUM(orderdetails.price * orderdetails.quantity) as subtotal,SUM(orderdetails.quantity) as quantity,(CASE WHEN orders.km - orders.free > 0 THEN orders.fee_ship * (orders.km - orders.free) ELSE 0 END) as fee_ship,orders.discount as discount,orders.paid_amount as paid_amount')
                        ->from('orders')
                        ->when($warehouse_id != 0, function ($query) use ($warehouse_id) {
                            return $query->where('orders.warehouse_id', '=', $warehouse_id);
                        })
                        ->when(Auth::user()->role == 1, function ($query) {
                            return $query->where('imports.user_id', '=', Auth::id());
                        })
                        ->leftJoin('orderdetails', 'orderdetails.order_id', '=', 'orders.id')
                        ->leftJoin('importdetails', 'importdetails.product_code', '=', 'orderdetails.product_code')
                        ->leftJoin('imports', 'imports.id', '=', 'importdetails.import_id')
                        ->groupBy('orders.id');
                }, 'sub')
                ->when($time == 'fil_precious', function ($query) {
                    return $query->groupBy('fil_year', 'fil_precious')->orderBy('fil_year', 'DESC')->orderBy('fil_precious', 'DESC');
                }, function ($query) use ($time) {
                    return $query->groupBy($time)->orderBy($time, 'DESC');
                })
                ->when($time != 'fil_year', function ($query) use ($time) {
                    $limit = $time == 'time' ? 12 : ($time == 'fil_day' ? 30 : 4);

                    return $query->limit($limit);
                })->get();
            $sales = [];
            $debt = [];
            $quantity = [];
            if ($this->valid($query)) {
                $data = $query->toArray();
                $data = array_reverse($data);
                foreach ($data as $key) {
                    $x = ($time == 'fil_day') ? $key['fil_day'] : ($time == 'time' ? $key['time'] : (($time == 'fil_year') ? $key['fil_year'] : $key['fil_precious'].' '.$key['fil_year']));
                    $total = round($key['price'] + $key['fee_ship'] - $key['discount']);
                    $sales[] = [
                        'x' => $x,
                        'y' => $total,
                    ];
                    $debt[] = [
                        'x' => $x,
                        'y' => $total - $key['paid_amount'],
                    ];
                    $quantity[] = [
                        'x' => $x,
                        'y' => $key['count'],
                    ];
                }
            }

            return response()->json([
                'sales' => $sales,
                'debt' => $debt,
                'quantity' => $quantity,
            ]);
        }

        return Redirect::to('/login');
    }

    public function detail(Request $request): string|\Illuminate\Http\RedirectResponse
    {
        $this->system->connect_db();
        if (Auth::check()) {
            $warehouse_id = Auth::user()->role <= 0 ? $request->input('warehouse_id') : Auth::user()->warehouse_id;
            $type = $request->input('type');
            $time = Carbon::parse($request->input('time'));
            $query = Order::query()
                ->when($warehouse_id != 0, function ($query) use ($warehouse_id) {
                    return $query->where('orders.warehouse_id', '=', $warehouse_id);
                })
                ->when($type == 'fil_day', function ($query) use ($time) {
                    return $query->where(DB::raw('(DATE_FORMAT(orders.created_at,"%Y-%m-%d"))'), '=', $time->format('Y-m-d'));
                })
                ->when($type == 'time', function ($query) use ($time) {
                    return $query->where(DB::raw('(DATE_FORMAT(orders.created_at,"%Y-%m"))'), '=', $time->format('Y-m'));
                })
                ->when($type == 'fil_precious', function ($query) use ($time) {
                    return $query->where(DB::raw('(QUARTER(orders.created_at))'), '=', $time->quarter)
                        ->where(DB::raw('(YEAR(created_at))'), '=', $time->year);
                })
                ->when($type == 'fil_year', function ($query) use ($time) {
                    return $query->where(DB::raw('(YEAR(orders.created_at))'), '=', $time->year);
                })
                ->get();
            $output = '
                <div class="card-body">
                <table class="table table-separate table-head-custom table-foot-custom table-checkable display nowrap" id="table_order_'.$request->input('time').'">
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
                            <th>'.__('product.price').'</th>
                            <th>'.__('product.tax').'</th>
                            <th>'.__('product.quantity').'</th>
                            <th>'.__('product.revenue').'</th>
                        </tr>
                    </thead>
                    <tbody>';
            $i = 0;
            $total_sprice = 0;
            $total_quantity = 0;
            $total = 0;
            foreach ($query as $key => $value) {
                $detail = OrderDetail::query()->select('units.name as unit_name', 'products.name as product_name', 'orderdetails.*', 'importdetails.import_price', 'importdetails.vat', 'importdetails.product_serial', 'importdetails.image', 'importdetails.drive', 'importdetails.date_end')
                    ->join('importdetails', 'importdetails.product_code', '=', 'orderdetails.product_code')
                    ->when(Auth::user()->role == 1, function ($query) {
                        return $query->join('imports', 'imports.id', '=', 'importdetails.import_id')
                            ->where('imports.user_id', '=', Auth::id());
                    })
                    ->join('products', 'products.id', '=', 'importdetails.product_id')
                    ->join('units', 'units.id', '=', 'products.unit_id')
                    ->where('order_id', '=', $value->id)->get();
                if ($this->valid($detail)) {
                    foreach ($detail as $key => $item) {
                        $i++;
                        $subtotal = $item->price * $item->quantity;
                        $total_sprice += $item->price;
                        $total_quantity += $item->quantity;
                        $total += $subtotal;
                        $output .= '
                            <tr>
                                <td>'.$i.'</td>
                                <td>'.$this->code.$value->id.'</td>';
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
                        if ($value->print == 1) {
                            $output .= '
                                <td>'.number_format($item->price / 1.1).'</td>
                                <td>'.number_format($item->price / 1.1 / 100 * $item->vat).'</td>';
                        } else {
                            $output .= '
                                <td>'.number_format($item->price).'</td>
                                <td>0</td>';
                        }
                        $output .= '
                                <td>'.$item->quantity.' '.$item->unit_name.'</td>
                                <td>'.number_format($subtotal).'</td>';
                    }
                }
                if ((Auth::user()->role != 1)) {
                    $detail = OrderDetail::query()->select('services.name as service_name', 'orderdetails.*')
                        ->join('services', 'services.id', '=', 'orderdetails.service_id')
                        ->where('order_id', '=', $value->id)->get();
                    if ($this->valid($detail)) {
                        foreach ($detail as $key => $item) {
                            $i++;
                            $subtotal = $item->price * $item->quantity;
                            $total_sprice += $item->price;
                            $total_quantity += $item->quantity;
                            $total += $subtotal;
                            $output .= '
                            <tr>
                                <td>'.$i.'</td>
                                <td>'.$this->code.$value->id.'</td>';
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
                                <td>'.$item->service_name.'</td>
                                <td></td>
                                <td></td>
                                <td>'.Carbon::parse($value->created_at)->addMonth()->format('d-m-Y').'</td>
                                <td>'.__('product.nothing').'</td>
                                <td>'.number_format($item->price).'</td>
                                <td>'.number_format(0).'</td>
                                <td>'.$item->quantity.' Sản phẩm</td>
                                <td>'.number_format($subtotal).'</td>';
                        }
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
                        <th>'.number_format($total + floatval($request->input('fee_ship')) - floatval($request->input('discount'))).'</td>
                    </tr>
                    </tfoot>
                </table>
            </div>';

            return $output;
        }

        return Redirect::to('/login');
    }
}
