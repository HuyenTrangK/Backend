<?php

namespace App\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\SystemController;
use App\Models\Tenant\Brand;
use App\Models\Tenant\Category;
use App\Models\Tenant\Product;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Unit;
use App\Models\Tenant\WareHouse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Yajra\DataTables\DataTables;

class StatisticalDepotController extends Controller
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
            return view('tenant.admin.statistical.depot.index')
                ->with('warehouse', WareHouse::query()->orderBy('name')->get())
                ->with('category', Category::query()->orderBy('name')->get())
                ->with('brand', Brand::query()->orderBy('name')->get())
                ->with('unit', Unit::query()->orderBy('name')->get())
                ->with('setting', Setting::query()->find(1));
        }

        return Redirect::to('/login');
    }

    public function fetchdata(int $warehouse_id, $time)
    {
        $this->system->connect_db();
        if (Auth::check()) {
            $warehouse_id = Auth::user()->role <= 0 ? $warehouse_id : Auth::user()->warehouse_id;
            if ($time == 'time') {
                $timeline = Carbon::now()->startOfMonth()->format('Y-m-d');
            } elseif ($time == 'fil_precious') {
                $timeline = Carbon::now()->startOfQuarter()->format('Y-m-d');
            } else {
                $timeline = Carbon::now()->startOfYear()->format('Y-m-d');
            }
            $query = Product::query()->selectRaw('units.name as unit_name,products.*,
                    (CASE WHEN DATE_FORMAT(imports.created_at,"%Y-%m-%d") < "'.$timeline.'" THEN SUM(importdetails.quantity - importdetails.transfer - importdetails.soldout) ELSE 0 END) as quantity_begin,
                    (CASE WHEN DATE_FORMAT(imports.created_at,"%Y-%m-%d") < "'.$timeline.'" THEN SUM( (importdetails.quantity - importdetails.transfer - importdetails.soldout) * (importdetails.import_price + importdetails.import_price/100 * importdetails.vat) ) ELSE 0 END) as price_begin,
                    SUM(importdetails.quantity - importdetails.transfer) As quantity_import,
                    SUM(importdetails.quantity * (importdetails.import_price + importdetails.import_price/100 * importdetails.vat)) As price_import,
                    SUM(importdetails.soldout) As quantity_export,
                    SUM(importdetails.soldout * importdetails.sell_price) As price_export,
                    SUM(importdetails.quantity - importdetails.transfer - importdetails.soldout) As quantity_end,
                    SUM((importdetails.quantity - importdetails.transfer - importdetails.soldout) * (importdetails.import_price + importdetails.import_price/100 * importdetails.vat)) As price_end')
                ->leftJoin('importdetails', 'importdetails.product_id', '=', 'products.id')
                ->leftJoin('imports', 'imports.id', '=', 'importdetails.import_id')
                ->leftJoin('units', 'units.id', '=', 'products.unit_id')
                ->when($warehouse_id != 0, function ($query) use ($warehouse_id) {
                    return $query->where('imports.warehouse_id', '=', $warehouse_id);
                })
                ->when(Auth::user()->role == 1, function ($query) {
                    return $query->where('imports.user_id', '=', Auth::id());
                })
                ->groupBy('products.id')
                ->orderBy('products.name')
                ->get();
            try {
                return Datatables::of($query)->make(true);
            } catch (\Exception) {
            }
        }

        return Redirect::to('/login');
    }
}
