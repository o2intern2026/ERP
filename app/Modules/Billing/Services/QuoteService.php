<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\CustomerQuote;
use App\Modules\Billing\Models\CustomerQuoteLine;
use App\Support\Contracts\RateService;
use App\Support\Numbers;
use Illuminate\Support\Facades\DB;

/** A18 (FIN-6) shared with OMS A7b: price a list of charge codes for a client; POA lines are flagged, never guessed. */
final class QuoteService
{
    public function __construct(private readonly RateService $rates) {}

    /**
     * @param  list<array{charge_code:string, qty:float, context?:array<string, mixed>, description?:string, transport_quote_id?:int}>  $lines
     * @param  array{job_id?:?int, order_id?:?int, stage?:string, valid_until?:?string, notes?:?string}  $attributes
     */
    public function create(int $clientId, array $lines, array $attributes = []): CustomerQuote
    {
        return DB::transaction(function () use ($clientId, $lines, $attributes): CustomerQuote {
            $quote = CustomerQuote::query()->create([
                'quote_no' => Numbers::next(CustomerQuote::query()->withoutGlobalScopes(), 'quote_no', 'QT'),
                'client_id' => $clientId, 'job_id' => $attributes['job_id'] ?? null, 'order_id' => $attributes['order_id'] ?? null,
                'stage' => $attributes['stage'] ?? 'preliminary', 'valid_until' => $attributes['valid_until'] ?? now()->addDays(14)->toDateString(),
                'status' => 'draft', 'notes' => $attributes['notes'] ?? null, 'created_by' => auth()->id(),
            ]);

            $subtotal = 0;
            $gst = 0;
            foreach ($lines as $line) {
                $code = ChargeCode::query()->where('code', $line['charge_code'])->firstOrFail();
                $priced = $this->rates->price($clientId, $code->code, (float) $line['qty'], $line['context'] ?? []);
                $amount = $priced['amount_cents'];
                CustomerQuoteLine::query()->create([
                    'customer_quote_id' => $quote->id, 'charge_code' => $code->code, 'description' => $line['description'] ?? $code->customer_description,
                    'qty' => $priced['qty'], 'uom' => $priced['uom'] ?? $code->default_uom, 'amount_cents' => $amount, 'transport_quote_id' => $line['transport_quote_id'] ?? null,
                    'assumptions' => ['is_poa' => $priced['is_poa'], 'missing_rate' => $priced['missing_rate'], 'rate_item_id' => $priced['rate_item_id'], 'calculation' => $priced['calculation_snapshot']] + ($line['context'] ?? []),
                ]);
                $subtotal += $amount;
                $gst += $code->gstRate() > 0 ? (int) round($amount * $code->gstRate()) : 0;
            }
            $quote->update(['subtotal_cents' => $subtotal, 'gst_cents' => $gst, 'total_cents' => $subtotal + $gst]);

            return $quote->fresh();
        });
    }

    public function setStatus(CustomerQuote $quote, string $status): CustomerQuote
    {
        $quote->update(['status' => $status]);

        return $quote->fresh();
    }
}
