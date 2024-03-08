<?php

namespace App\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\SystemController;
use App\Models\Tenant\Expense;
use App\Models\Tenant\ExpenseCategory;
use App\Models\Tenant\Import;
use App\Models\Tenant\ImportDetail;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderDetail;
use App\Models\Tenant\ReturnDetail;
use App\Models\Tenant\Returns;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Models\Tenant\WareHouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;

class StatisticalProfitController extends Controller
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
            return view('tenant.admin.statistical.profit.index')
                ->with('warehouse', WareHouse::query()->orderBy('name')->get())
                ->with('setting', Setting::query()->find(1));
        }

        return Redirect::to('/login');
    }

    public function calc($past, $present): string
    {
        if ($present <= 0 && $past <= 0) {
            return '0%';
        }
        if ($present == 0) {
            return '<span style="color: red">- 100 %</span>';
        }
        if ($past == 0) {
            return '<span style="color: #00b300">+ 100 %</span>';
        }
        if ($past < $present) {
            return '<span style="color: #00b300">+ '.abs(round($present / $past * 100, 2)).' %</span>';
        } elseif ($past > $present) {
            $percent = abs(round(100 - $present / $past * 100, 2));
            if ($percent > 100) {
                $percent = 100;
            }

            return '<span style="color: red">- '.$percent.' %</span>';
        } else {
            return '0%';
        }
    }

    public function get_profit_user($timeline1, $timeline2, $user_id): float|int
    {
        $profit_investor = 0;
        $query = Order::query()->whereBetween(DB::raw('DATE_FORMAT(orders.created_at,"%Y-%m-%d")'), [$timeline1, $timeline2])->get();
        if ($this->valid($query)) {
            $data = $query->toArray();
            foreach ($data as $key) {
                $query_detail = OrderDetail::query()->where('order_id', '=', $key['id'])
                    ->whereNotNull('orderdetails.product_code')
                    ->join('importdetails', 'importdetails.product_code', '=', 'orderdetails.product_code')
                    ->join('imports', 'imports.id', '=', 'importdetails.import_id')
                    ->where('imports.user_id', '=', $user_id)->get();
                if ($this->valid($query_detail)) {
                    $discount = 0;
                    $order_detail = $query_detail->toArray();
                    if ($key['discount'] > 0) {
                        $count = $query_detail->sum('quantity');
                        if ($count > 0) {
                            $discount = round($key['discount'] / $count);
                        }
                    }
                    foreach ($order_detail as $value) {
                        $query = ImportDetail::query()->where('product_code', '=', $value['product_code'])->first();
                        if ($this->valid($query)) {
                            $data_import_detail = $query->toArray();
                            $price = round($data_import_detail['import_price'] + ($data_import_detail['import_price'] / 100 * $data_import_detail['vat']));
                            $quantity = ImportDetail::query()->where('import_id', '=', $data_import_detail['import_id'])->sum('quantity');
                            $fee_ship = Import::query()->where('id', '=', $data_import_detail['import_id'])->first()->toArray()['fee_ship'];
                            $fee = ceil($fee_ship / $quantity / 1000) * 1000;
                            $price = $price + $fee;
                            $profit = round(($value['price'] - $price - $discount) * $value['quantity']);
                            if ($profit < 0) {
                                $profit = 0;
                            }
                            $profit_investor += ($profit * $this->system->read($user_id, 'percent') / 100);
                        }
                    }
                }
            }
        }
        $query = Returns::query()->whereBetween(DB::raw('DATE_FORMAT(returns.created_at,"%Y-%m-%d")'), [$timeline1, $timeline2])->get();
        if ($this->valid($query)) {
            $data = $query->toArray();
            foreach ($data as $key) {
                $return_detail = ReturnDetail::query()->select('returndetails.*')
                    ->where('return_id', '=', $key['id'])
                    ->join('importdetails', 'importdetails.product_code', '=', 'returndetails.product_code')
                    ->join('imports', 'imports.id', '=', 'importdetails.import_id')
                    ->where('imports.user_id', '=', $user_id)->get();
                if ($this->valid($return_detail)) {
                    $data = $return_detail->toArray();
                    foreach ($data as $value) {
                        $query = ImportDetail::query()->where('product_code', '=', $value['product_code'])->first();
                        if ($this->valid($query)) {
                            $data_import_detail = $query->toArray();
                            $price = round($data_import_detail['import_price'] + ($data_import_detail['import_price'] / 100 * $data_import_detail['vat']));
                            $profit = ($value['price'] + ($value['price'] / 100 * $key['fee']) - $price) * $value['quantity'];
                            //Investor
                            if ($this->system->read($user_id, 'role') == 1) {
                                $profit_investor -= ($profit * $this->system->read($user_id, 'percent') / 100);
                            }
                        }
                    }
                }
            }
        }

        return $profit_investor;
    }

    public function get_expense_category($timeline1, $timeline2, $category_id, $warehouse_id)
    {
        $query = Expense::query()->selectRaw('SUM(amount) as amount')
            ->whereBetween(DB::raw('DATE_FORMAT(expenses.created_at,"%Y-%m-%d")'), [$timeline1, $timeline2])
            ->when($warehouse_id != 0, function ($query) use ($warehouse_id) {
                return $query->where('orders.warehouse_id', '=', $warehouse_id);
            })
            ->where('expenses.category_id', '=', $category_id)
            ->first();

        return $query->amount;
    }

    public function get_data($timeline1, $timeline2, $warehouse_id): array
    {
        $order = Order::query()->selectRaw('SUM(orderdetails.price * orderdetails.quantity) as price')
            ->whereBetween(DB::raw('DATE_FORMAT(orders.created_at,"%Y-%m-%d")'), [$timeline1, $timeline2])
            ->leftJoin('orderdetails', 'orderdetails.order_id', '=', 'orders.id')
            ->when(Auth::user()->role <= 0 && $warehouse_id != 0, function ($query) use ($warehouse_id) {
                return $query->where('orders.warehouse_id', '=', $warehouse_id);
            })
            ->when(Auth::user()->role > 1, function ($query) {
                return $query->where('orders.warehouse_id', '=', Auth::user()->warehouse_id);
            })
            ->when(Auth::user()->role == 1, function ($query) {
                return $query
                    ->leftJoin('importdetails', 'importdetails.product_code', '=', 'orderdetails.product_code')
                    ->leftJoin('imports', 'imports.id', '=', 'importdetails.import_id')
                    ->where('imports.user_id', '=', Auth::id());
            })
            ->first();
        $order2 = Order::query()->select('orders.*')
            ->whereBetween(DB::raw('DATE_FORMAT(orders.created_at,"%Y-%m-%d")'), [$timeline1, $timeline2])
            ->when(Auth::user()->role <= 0 && $warehouse_id != 0, function ($query) use ($warehouse_id) {
                return $query->where('orders.warehouse_id', '=', $warehouse_id);
            })
            ->when(Auth::user()->role > 1, function ($query) {
                return $query->where('orders.warehouse_id', '=', Auth::user()->warehouse_id);
            })->get();
        $order_fee_ship = 0;
        $order_discount = 0;
        $fee_ship_user = 0;
        if ($this->valid($order2)) {
            foreach ($order2->toArray() as $key) {
                if (Auth::user()->role == 1) {
                    $detail = OrderDetail::query()->select('orderdetails.*')
                        ->where('order_id', '=', $key['id'])
                        ->join('importdetails', 'importdetails.product_code', '=', 'orderdetails.product_code')
                        ->join('imports', 'imports.id', '=', 'importdetails.import_id')
                        ->where('imports.user_id', '=', Auth::id())->get()->toArray();
                    $discount = 0;
                    if ($key['discount'] > 0) {
                        $count = 0;
                        foreach ($detail as $value) {
                            $count += $value['quantity'];
                        }
                        if ($count > 0) {
                            $discount = round($key['discount'] / $count);
                        }
                    }
                    if ($this->valid($detail)) {
                        foreach ($detail as $v) {
                            $order_discount += $discount * $v['quantity'];
                        }
                        if (($key['km'] - $key['free']) > 0) {
                            $order_fee_ship += $key['fee_ship'] * ($key['km'] - $key['free']);
                        }
                        $fee_ship_user += $key['fee_ship'] * $key['km'];
                    }
                } else {
                    $order_discount += $key['discount'];
                    if (($key['km'] - $key['free']) > 0) {
                        $order_fee_ship += $key['fee_ship'] * ($key['km'] - $key['free']);
                    }
                    $fee_ship_user += $key['fee_ship'] * $key['km'];
                }
            }
        }
        $return = Returns::query()->selectRaw('SUM((returndetails.price - returndetails.price/100 * returns.fee) * returndetails.quantity) as price')
            ->whereBetween(DB::raw('DATE_FORMAT(returns.created_at,"%Y-%m-%d")'), [$timeline1, $timeline2])
            ->leftJoin('returndetails', 'returndetails.return_id', '=', 'returns.id')
            ->when(Auth::user()->role <= 0 && $warehouse_id != 0, function ($query) use ($warehouse_id) {
                return $query->where('returns.warehouse_id', '=', $warehouse_id);
            })
            ->when(Auth::user()->role > 1, function ($query) {
                return $query->where('returns.warehouse_id', '=', Auth::user()->warehouse_id);
            })
            ->when(Auth::user()->role == 1, function ($query) {
                return $query
                    ->leftJoin('importdetails', 'importdetails.product_code', '=', 'returndetails.product_code')
                    ->leftJoin('imports', 'imports.id', '=', 'importdetails.import_id')
                    ->where('imports.user_id', '=', Auth::id());
            })
            ->first();
        $profit_seller = 0;
        $import_fee_ship = 0;
        $import_price = 0;
        $import_vat = 0;
        $order_vat = 0;
        $query = Order::query()->whereBetween(DB::raw('DATE_FORMAT(created_at,"%Y-%m-%d")'), [$timeline1, $timeline2])
            ->when(Auth::user()->role <= 0 && $warehouse_id != 0, function ($query) use ($warehouse_id) {
                return $query->where('warehouse_id', '=', $warehouse_id);
            })
            ->when(Auth::user()->role > 1, function ($query) {
                return $query->where('warehouse_id', '=', Auth::user()->warehouse_id);
            })->get();
        if ($this->valid($query)) {
            $data = $query->toArray();
            foreach ($data as $key) {
                $order_detail = OrderDetail::query()->where('order_id', '=', $key['id'])
                    ->when(Auth::user()->role == 1, function ($query) {
                        return $query
                            ->join('importdetails', 'importdetails.product_code', '=', 'orderdetails.product_code')
                            ->join('imports', 'imports.id', '=', 'importdetails.import_id')
                            ->where('imports.user_id', '=', Auth::id());
                    })->get();
                $data_detail = $order_detail->toArray();
                $discount = 0;
                if ($key['discount'] > 0) {
                    $count = 0;
                    foreach ($order_detail as $value) {
                        $count += $value['quantity'];
                    }
                    if ($count > 0) {
                        $discount = round($key['discount'] / $count);
                    }
                }
                if ($this->valid($data_detail)) {
                    $total = 0;
                    $i_price = 0;
                    foreach ($data_detail as $value) {
                        if ($this->valid($value['product_code'])) {
                            $query = ImportDetail::query()->where('product_code', '=', $value['product_code'])->first();
                            if ($this->valid($query)) {
                                $data_import_detail = $query->toArray();
                                $price = round($data_import_detail['import_price'] + ($data_import_detail['import_price'] / 100 * $data_import_detail['vat']));
                                if ($key['print'] == 1) {
                                    $order_vat += $value['price'] / 100 * $data_import_detail['vat'] * $value['quantity'];
                                }
                                $quantity = ImportDetail::query()->where('import_id', '=', $data_import_detail['import_id'])->sum('quantity');
                                $fee_ship = Import::query()->where('id', '=', $data_import_detail['import_id'])->first()->toArray()['fee_ship'];
                                $fee = ceil($fee_ship / $quantity / 1000) * 1000;
                                $import_fee_ship += $fee;
                                $import_price += $price * $value['quantity'];
                                if ($data_import_detail['vat'] == 10) {
                                    $import_vat += $data_import_detail['import_price'] / 100 * $data_import_detail['vat'] * $value['quantity'];
                                }
                                $i_price += ($price + $fee) * $value['quantity'];
                            }
                        }
                        $total += $value['price'] * $value['quantity'];
                    }
                    if ($total - $i_price > round($key['discount'])) {
                        $max = $total - $i_price - round($key['discount']);
                        foreach ($data_detail as $value) {
                            if ($this->valid($value['product_code'])) {
                                $p = round(($value['price'] - $discount) * $value['quantity']);
                                if ($p < 0) {
                                    $p = 0;
                                }
                                $seller_id = $key['user_id'];
                                $check = $p * $this->system->read($seller_id, 'percent') / 100;
                                if ($check > $max) {
                                    $check = $max;
                                }
                                $profit_seller += $check;
                            } else {
                                $profit = ($value['price'] - $discount) * $value['quantity'];
                                if ($profit < 0) {
                                    $profit = 0;
                                }
                                $seller_id = $key['user_id'];
                                $profit_seller += $profit * $this->system->read($seller_id, 'percent') / 100;
                            }
                        }
                    }
                }
            }
        }

        return [
            'import_price' => $import_price,
            'import_vat' => $import_vat,
            'import_fee_ship' => $import_fee_ship,
            'order_price' => $order->toArray()['price'],
            'order_vat' => $order_vat,
            'order_fee_ship' => $order_fee_ship,
            'fee_ship_user' => $fee_ship_user,
            'order_discount' => $order_discount,
            'return_price' => $return->toArray()['price'],
            'profit_seller' => $profit_seller,
        ];
    }

    public function get_expense($timeline1, $timeline2, $timeline3, $timeline4, $warehouse_id): array
    {
        $total_past = 0;
        $total_present = 0;
        $category_data = [];
        $query = ExpenseCategory::query()->get();
        if ($this->valid($query)) {
            $data = $query->toArray();
            foreach ($data as $key) {
                $profit_past = $this->get_expense_category($timeline1, $timeline2, $key['id'], $warehouse_id);
                $profit_present = $this->get_expense_category($timeline3, $timeline4, $key['id'], $warehouse_id);
                $category_data[] = [
                    'name' => $key['name'],
                    'past' => $profit_past,
                    'present' => $profit_present,
                    'percent' => $this->calc($profit_past, $profit_present),
                ];
                $total_past += $profit_past;
                $total_present += $profit_present;
            }
        }

        return [
            'category_data' => $category_data,
            'total_past' => $total_past,
            'total_present' => $total_present,
        ];
    }

    public function get_profit($timeline1, $timeline2, $timeline3, $timeline4, $warehouse_id): array
    {
        if (Auth::user()->role == 1) {
            $query = User::query()->where('id', '=', Auth::id())->get();
        } else {
            $warehouse_id = Auth::user()->role <= 0 ? $warehouse_id : Auth::user()->warehouse_id;
            $query = User::query()->where('users.role', '=', '1')
                ->when($warehouse_id != 0, function ($query) use ($warehouse_id) {
                    return $query->where('users.warehouse_id', '=', $warehouse_id);
                })
                ->get();
        }
        $total_past = 0;
        $total_present = 0;
        $user_data = [];
        if ($this->valid($query)) {
            $data = $query->toArray();
            foreach ($data as $key) {
                $profit_past = $this->get_profit_user($timeline1, $timeline2, $key['id']);
                $profit_present = $this->get_profit_user($timeline3, $timeline4, $key['id']);
                $user_data[] = [
                    'name' => $key['name'],
                    'past' => $profit_past,
                    'present' => $profit_present,
                    'percent' => $this->calc($profit_past, $profit_present),
                ];
                $total_past += $profit_past;
                $total_present += $profit_present;
            }
        }

        return [
            'user_data' => $user_data,
            'total_past' => $total_past,
            'total_present' => $total_present,
        ];
    }

    public function fetchdata(Request $request)
    {
        $this->system->connect_db();
        if (Auth::check()) {
            $diff = Carbon::parse($request->input('from'))->diffInDays($request->input('to'));
            $timeline1 = Carbon::parse($request->input('from'))->subDays($diff)->format('Y-m-d');
            $timeline2 = Carbon::parse($request->input('from'))->subDay()->format('Y-m-d');
            $timeline3 = Carbon::parse($request->input('from'))->format('Y-m-d');
            $timeline4 = Carbon::parse($request->input('to'))->format('Y-m-d');
            $warehouse_id = $request->input('warehouse_id');
            //Get expense
            $expense = $this->get_expense($timeline1, $timeline2, $timeline3, $timeline4, $warehouse_id);
            //Get past
            $past = $this->get_data($timeline1, $timeline2, $warehouse_id);
            $past_fee_ship = $past['fee_ship_user'] - $past['order_fee_ship'];
            $past_total = $past['order_price'] + $past['order_fee_ship'];
            $past_cost_total = $past['order_discount'] + $past['return_price'] + $past['profit_seller'] + $past['fee_ship_user'];
            $past_import_total = $past['import_price'] + $past['import_fee_ship'];
            $past_profit = $past_total - $past_import_total - $past_cost_total - $expense['total_past'];
            //Get present
            $present = $this->get_data($timeline3, $timeline4, $warehouse_id);
            $present_fee_ship = $present['fee_ship_user'] - $present['order_fee_ship'];
            $present_total = $present['order_price'] + $present['order_fee_ship'];
            $present_cost_total = $present['order_discount'] + $present['return_price'] + $present['profit_seller'] + $present['fee_ship_user'];
            $present_import_total = $present['import_price'] + $present['import_fee_ship'];
            $present_profit = $present_total - $present_import_total - $present_cost_total - $expense['total_present'];
            //Get profit user
            $profit = $this->get_profit($timeline1, $timeline2, $timeline3, $timeline4, $warehouse_id);
            //Output
            $output = '<table class="table table-separate table-head-custom table-foot-custom table-checkable display nowrap" id="table_import">
            <thead>
                <tr>
                    <th>'.__('product.text38').' </th>
                    <th>'.__('product.text39').' <br> ('.Carbon::parse($timeline1)->format('d/m/Y').' - '.Carbon::parse($timeline2)->format('d/m/Y').') </th>
                    <th>'.__('product.text40').' <br> ('.Carbon::parse($timeline3)->format('d/m/Y').' - '.Carbon::parse($timeline4)->format('d/m/Y').') </th>
                    <th>'.__('product.text41').'</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>'.__('product.text65').'</strong></td>
                    <td><strong>'.number_format($past_total).'</strong></td>
                    <td><strong>'.number_format($present_total).'</strong></td>
                    <td><strong>'.$this->calc($past_total, $present_total).'</strong></td>
                </tr>
                <tr>
                    <td>'.__('product.text66').'</td>
                    <td>'.number_format($past['order_price']).'</td>
                    <td>'.number_format($present['order_price']).'</td>
                    <td>'.$this->calc($past['order_price'], $present['order_price']).'</td>
                </tr>
                <tr>
                    <td>'.__('product.text67').'</td>
                    <td>'.number_format($past['order_vat']).'</td>
                    <td>'.number_format($present['order_vat']).'</td>
                    <td>'.$this->calc($past['order_vat'], $present['order_vat']).'</td>
                </tr>
                <tr>
                    <td>'.__('product.text68').'</td>
                    <td>'.number_format($past['order_fee_ship']).'</td>
                    <td>'.number_format($present['order_fee_ship']).'</td>
                    <td>'.$this->calc($past['order_fee_ship'], $present['order_fee_ship']).'</td>
                </tr>
                <tr>
                    <td><strong>'.__('product.text61').'</strong></td>
                    <td><strong>'.number_format($past_import_total).'</strong></td>
                    <td><strong>'.number_format($present_import_total).'</strong></td>
                    <td><strong>'.$this->calc($past_import_total, $present_import_total).'</strong></td>
                </tr>
                <tr>
                    <td>'.__('product.text62').'</td>
                    <td>'.number_format($past['import_price']).'</td>
                    <td>'.number_format($present['import_price']).'</td>
                    <td>'.$this->calc($past['import_price'], $present['import_price']).'</td>
                </tr>
                <tr>
                    <td>'.__('product.text63').'</td>
                    <td>'.number_format($past['import_vat']).'</td>
                    <td>'.number_format($present['import_vat']).'</td>
                    <td>'.$this->calc($past['import_vat'], $present['import_vat']).'</td>
                </tr>
                <tr>
                    <td>'.__('product.text64').'</td>
                    <td>'.number_format($past['import_fee_ship']).'</td>
                    <td>'.number_format($present['import_fee_ship']).'</td>
                    <td>'.$this->calc($past['import_fee_ship'], $present['import_fee_ship']).'</td>
                </tr>
                <tr>
                    <td><strong>'.__('product.text69').'</strong></td>
                    <td><strong>'.number_format($past_cost_total).'</strong></td>
                    <td><strong>'.number_format($present_cost_total).'</strong></td>
                    <td><strong>'.$this->calc($past_cost_total, $present_cost_total).'</strong></td>
                </tr>
                <tr>
                    <td>'.__('product.text70').'</td>
                    <td>'.number_format($past['order_discount']).'</td>
                    <td>'.number_format($present['order_discount']).'</td>
                    <td>'.$this->calc($past['order_discount'], $present['order_discount']).'</td>
                </tr>
                <tr>
                    <td>'.__('product.text71').'</td>
                    <td>'.number_format($past['return_price']).'</td>
                    <td>'.number_format($present['return_price']).'</td>
                    <td>'.$this->calc($past['return_price'], $present['return_price']).'</td>
                </tr>
                <tr>
                    <td>'.__('product.text68').'</td>
                    <td>'.number_format($past_fee_ship).'</td>
                    <td>'.number_format($present_fee_ship).'</td>
                    <td>'.$this->calc($past_fee_ship, $present_fee_ship).'</td>
                </tr>
                <tr>
                    <td>'.__('product.text72').'</td>
                    <td>'.number_format($past['profit_seller']).'</td>
                    <td>'.number_format($present['profit_seller']).'</td>
                    <td>'.$this->calc($past['profit_seller'], $present['profit_seller']).'</td>
                </tr>';
            if (Auth::user()->role !== 1) {
                $output .= '
                <tr>
                    <td><strong>'.__('product.text73').'</strong></td>
                    <td><strong>'.number_format($expense['total_past']).'</strong></td>
                    <td><strong>'.number_format($expense['total_present']).'</strong></td>
                    <td><strong>'.$this->calc($expense['total_past'], $expense['total_present']).'</strong></td>
                </tr>';
                $i = 1;
                foreach ($expense['category_data'] as $key) {
                    $output .= '<tr>
                        <td>'.$i++.'. '.$key['name'].'</td>
                        <td>'.number_format($key['past']).'</td>
                        <td>'.number_format($key['present']).'</td>
                        <td>'.$key['percent'].'</td>
                    </tr>';
                }
                $output .= '
                <tr>
                    <td><strong>'.__('product.text74').'</strong></td>
                    <td><strong>'.number_format($past_profit).'</strong></td>
                    <td><strong>'.number_format($present_profit).'</strong></td>
                    <td><strong>'.$this->calc($past_profit, $present_profit).'</strong></td>
                </tr>';
                if (Auth::user()->role <= 0) {
                    $output .= ' <td>1. '.User::query()->where('role', '=', '-1')->first()->toArray()['name'].'</td>';
                    if ($past_profit - $profit['total_past'] > 0) {
                        $output .= '<td>'.number_format($past_profit - $profit['total_past']).'</td>';
                    } else {
                        $output .= '<td>0</td>';
                    }
                    if ($present_profit - $profit['total_present'] > 0) {
                        $output .= '<td>'.number_format($present_profit - $profit['total_present']).'</td>';
                    } else {
                        $output .= '<td>0</td>';
                    }
                    $i = 2;
                    $output .= '<td>'.$this->calc($past_profit - $profit['total_past'], $present_profit - $profit['total_present']).'</td>';
                    foreach ($profit['user_data'] as $key) {
                        $output .= '<tr>
                            <td>'.$i++.'. '.$key['name'].'</td>
                            <td>'.number_format($key['past']).'</td>
                            <td>'.number_format($key['present']).'</td>
                            <td>'.$key['percent'].'</td>
                        </tr>';
                    }
                }
            } else {
                $output .= '
                    <tr>
                        <td><strong>'.__('product.text73').'</strong></td>
                        <td><strong>0</strong></td>
                        <td><strong>0</strong></td>
                        <td><strong>0%</strong></td>
                    </tr>
                    <tr>
                    <td><strong>'.__('product.text74').'</strong></td>
                    <td><strong>'.number_format($profit['user_data'][0]['past']).'</strong></td>
                    <td><strong>'.number_format($profit['user_data'][0]['present']).'</strong></td>
                    <td><strong>'.$profit['user_data'][0]['percent'].'</strong></td>
                </tr>';
            }
            $output .= '</tbody></table>';

            return $output;
        }
    }
}
