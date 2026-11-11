<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\SalesReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SalesController extends Controller
{
    private function report(Request $request): SalesReport
    {
        $p = array_key_exists((string) $request->query('p'), SalesReport::PERIODS) ? (string) $request->query('p') : 'month';

        return new SalesReport($p, $request->query('from'), $request->query('to'));
    }

    private static function method(Request $request): ?string
    {
        return in_array($request->query('method'), ['cod', 'online'], true) ? $request->query('method') : null;
    }

    public function index(Request $request)
    {
        $r = $this->report($request);
        $rows = $r->rows(self::method($request));

        return view('admin.sales', [
            'r' => $r, 's' => $r->summary(), 'rows' => $rows, 'method' => self::method($request),
            'gst' => ['taxable' => $rows->sum('taxable'), 'gst' => $rows->sum('gst'), 'total' => $rows->sum('total')],
            'due' => SalesReport::codDue()->get(),
            'panel' => [
                'configured' => \App\Services\SalePanel::configured(), 'enabled' => \App\Services\SalePanel::enabled(),
                'sent' => Order::where('status', 'delivered')->whereNotNull('sale_synced_at')->where(fn ($q) => $q->whereNull('sale_order_no')->orWhere('sale_order_no', '!=', \App\Services\SalePanel::MANUAL))->count(),
                'manual' => Order::where('status', 'delivered')->where('sale_order_no', \App\Services\SalePanel::MANUAL)->count(),
                'unsent' => Order::where('status', 'delivered')->whereNull('sale_synced_at')->count(),
                'unsentOrders' => Order::where('status', 'delivered')->whereNull('sale_synced_at')->orderByRaw(SalesReport::SALE_DATE.' desc')->limit(300)->get(),
                'error' => Order::where('status', 'delivered')->whereNull('sale_synced_at')->whereNotNull('sale_sync_error')->latest('updated_at')->value('sale_sync_error'),
            ],
        ]);
    }

    /** For the accountant: every delivered order in the period, with GST. */
    public function export(Request $request)
    {
        $r = $this->report($request);
        $rows = $r->rows(self::method($request));
        $name = 'sales-'.$r->from->format('Y-m-d').'-to-'.$r->to->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Delivered on', 'Invoice', 'Order', 'Customer', 'State', 'Payment', 'Taxable value', 'GST', 'Total', 'COD received from courier', 'Remittance ref']);
            foreach ($rows as $row) {
                $o = $row['order'];
                fputcsv($out, [$row['date']->format('Y-m-d'), $o->invoice_number, $o->number, $o->name, $o->state, $row['method'],
                    number_format($row['taxable'], 2, '.', ''), number_format($row['gst'], 2, '.', ''), number_format($row['total'], 2, '.', ''),
                    $o->payment_method === 'cod' ? ($o->cod_remitted_at?->format('Y-m-d') ?? 'Not yet') : '', $o->cod_remittance_ref]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    public function panelTest()
    {
        if (! \App\Services\SalePanel::configured()) {
            return back()->with('error', 'Add the sale panel address and key in Settings → Sale panel sync first.');
        }
        $r = \App\Services\SalePanel::ping();

        return back()->with($r['ok'] ? 'status' : 'error', $r['ok'] ? '✓ '.$r['message'].(setting('sale.enabled') ? '' : ' Now tick “Send delivered orders to the sale panel” in Settings.') : $r['message']);
    }

    /**
     * Previous delivered orders not in the sale panel yet. Some were typed into the panel by hand, so
     * nothing goes in bulk: the ticked ones are sent, or marked "already in the panel" and never sent.
     */
    public function panelSend(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array|max:300', 'ids.*' => 'integer', 'action' => 'required|in:send,skip']);
        $orders = Order::whereIn('id', $data['ids'])->where('status', 'delivered')->whereNull('sale_synced_at')->get();
        if ($data['action'] === 'skip') {
            foreach ($orders as $o) {
                $o->forceFill(['sale_order_no' => \App\Services\SalePanel::MANUAL, 'sale_synced_at' => now(), 'sale_sync_error' => null])->saveQuietly();
            }

            return back()->with('status', $orders->count().' '.($orders->count() === 1 ? 'order' : 'orders').' marked as already in the sale panel — they won’t be sent.');
        }
        if (! \App\Services\SalePanel::configured()) {
            return back()->with('error', 'Add the sale panel address and key in Settings → Sale panel sync first.');
        }
        @set_time_limit(300);
        $ok = $fail = 0;
        $error = null;
        foreach ($orders as $o) {
            if (\App\Services\SalePanel::push($o)) {
                $ok++;
            } else {
                $fail++;
                $error ??= $o->fresh()->sale_sync_error;
                if ($fail >= 3 && $ok === 0) {
                    break;   // the same problem every time — stop and say so
                }
            }
        }

        return back()->with($fail && ! $ok ? 'error' : 'status', $ok.' '.($ok === 1 ? 'order' : 'orders').' sent to the sale panel.'.($fail ? ' '.$fail.' failed: '.$error : ''));
    }

    /** Tick the COD orders Delhivery paid for in one remittance. */
    public function codReceived(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|max:500', 'ids.*' => 'integer',
            'received_on' => 'required|date|before_or_equal:today', 'ref' => 'nullable|string|max:100',
            'undo' => 'nullable|boolean',
        ]);
        $q = Order::whereIn('id', $data['ids'])->where('payment_method', 'cod')->where('status', 'delivered');
        if ($request->boolean('undo')) {
            $n = $q->update(['cod_remitted_at' => null, 'cod_remittance_ref' => null]);

            return back()->with('status', $n.' marked as not received yet.');
        }
        $value = (float) (clone $q)->sum('total');
        $n = $q->update(['cod_remitted_at' => Carbon::parse($data['received_on'])->startOfDay(), 'cod_remittance_ref' => $data['ref'] ?: null]);

        return back()->with('status', $n.' COD '.($n === 1 ? 'order' : 'orders').' ('.money($value).') marked as received on '.Carbon::parse($data['received_on'])->format('j M Y').'.');
    }
}
