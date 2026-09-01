<?php

namespace App\Services;

use App\Helpers\Brand;
use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class InvoicePdfRenderer
{
    /**
     * Render an Arabic A4 invoice once, then reuse the binary until any
     * invoice, payment, line item, branch, or receipt-setting input changes.
     */
    public function render(Invoice $invoice): string
    {
        $cacheKey = 'cashier:invoice-pdf:v4:'.$invoice->getKey().':'.$this->fingerprint($invoice);

        // The production cache table is UTF-8 text. Raw PDF bytes are not
        // valid UTF-8 and MySQL rejects them, so cache an ASCII-safe payload.
        $encoded = Cache::remember(
            $cacheKey,
            now()->addDay(),
            fn (): string => base64_encode($this->build($invoice)),
        );

        $binary = base64_decode($encoded, true);
        if ($binary === false) {
            Cache::forget($cacheKey);

            return $this->build($invoice);
        }

        return $binary;
    }

    private function build(Invoice $invoice): string
    {
        $tempDir = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($tempDir);

        $baseConfig = (new ConfigVariables)->getDefaults();
        $baseFonts = (new FontVariables)->getDefaults();

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'tempDir' => $tempDir,
            'fontDir' => array_merge($baseConfig['fontDir'], [
                public_path('assets/fonts/tajawal'),
            ]),
            'fontdata' => $baseFonts['fontdata'] + [
                'tajawal' => [
                    'R' => 'Tajawal-Regular.ttf',
                    'B' => 'Tajawal-Bold.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ],
            ],
            'default_font' => 'tajawal',
            'default_font_size' => 10,
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 15,
        ]);

        $pdf->SetDirectionality('rtl');
        $pdf->SetTitle('فاتورة '.$invoice->number);
        $pdf->SetAuthor(Brand::name());
        $pdf->SetCreator(Brand::name());
        $pdf->WriteHTML(view('admin.cashier.invoice-pdf', compact('invoice'))->render());

        return $pdf->Output('', Destination::STRING_RETURN);
    }

    private function fingerprint(Invoice $invoice): string
    {
        $orders = $invoice->tableSession
            ? $invoice->tableSession->orders
            : collect([$invoice->order])->filter();

        $items = $orders->flatMap->items;
        $settings = Setting::query()
            ->whereIn('key', [
                'site_name',
                'legal_name',
                'tax_number',
                'currency_symbol',
                'receipt_footer',
            ])
            ->get(['key', 'value', 'updated_at'])
            ->map(fn (Setting $setting): array => [
                $setting->key,
                $setting->value,
                $setting->updated_at?->getTimestamp(),
            ])
            ->all();

        return hash('sha256', serialize([
            'invoice' => [$invoice->updated_at?->getTimestamp(), $invoice->toArray()],
            'branch' => [
                $invoice->branch?->getKey(),
                $invoice->branch?->updated_at?->getTimestamp(),
                $invoice->branch?->legalProfile?->updated_at?->getTimestamp(),
            ],
            'issuer' => [$invoice->issuer?->getKey(), $invoice->issuer?->updated_at?->getTimestamp()],
            'orders' => $orders->map(fn ($order): array => [$order->getKey(), $order->updated_at?->getTimestamp()])->all(),
            'items' => $items->map(fn ($item): array => [
                $item->getKey(),
                $item->updated_at?->getTimestamp(),
                $item->modifiers->map(fn ($modifier): array => [$modifier->getKey(), $modifier->updated_at?->getTimestamp()])->all(),
            ])->all(),
            'payments' => $invoice->payments->map(fn ($payment): array => [
                $payment->getKey(),
                $payment->updated_at?->getTimestamp(),
                $payment->receiver?->updated_at?->getTimestamp(),
            ])->all(),
            'settings' => $settings,
            'template' => [
                filemtime(resource_path('views/admin/cashier/invoice-pdf.blade.php')),
                filemtime(resource_path('views/admin/cashier/_invoice-document.blade.php')),
            ],
        ]));
    }
}
