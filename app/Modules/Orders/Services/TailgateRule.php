<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Support\Contracts\RateService;

/**
 * A16 / OMS-13: tailgate is needed for a heavy piece or a residential address. The weight threshold comes from the
 * client's rate card (`TR-TAILGATE` → threshold_json.tailgate_weight_kg, contracts/charge-codes.md); the fee itself is
 * raised once at shipment.quote_confirmed, never here. A person may override the rule with a reason (tailgate_reason =
 * manual) and the automatic rule then leaves the order alone.
 *
 * Per-piece weight = line weight / cartons (a line's actual_weight_kg is the line total); declared packages count as pieces.
 */
final class TailgateRule
{
    public const DEFAULT_WEIGHT_KG = 25.0;

    public function __construct(private readonly RateService $rates) {}

    /** @return array{required: bool, reason: ?string, threshold_kg: float, heaviest_piece_kg: float} */
    public function evaluate(Order $order): array
    {
        $threshold = (float) ($this->rates->thresholds($order->client_id, 'TR-TAILGATE')['tailgate_weight_kg'] ?? self::DEFAULT_WEIGHT_KG);
        $order->loadMissing('lines', 'declaredPackages');

        // Plain collections: an empty Eloquent collection would try to merge floats as models.
        $pieces = $order->lines->toBase()
            ->map(fn ($line) => $line->actual_weight_kg === null ? 0.0 : (float) $line->actual_weight_kg / max(1, (int) $line->carton_qty))
            ->merge($order->declaredPackages->toBase()->map(fn ($package) => (float) ($package->weight_kg ?? 0)));
        $heaviest = (float) ($pieces->max() ?? 0.0);

        if ($heaviest > 0 && $heaviest >= $threshold) {
            return ['required' => true, 'reason' => 'heavy_item', 'threshold_kg' => $threshold, 'heaviest_piece_kg' => $heaviest];
        }
        if ($order->deliver_to_address_type === 'residential') {
            return ['required' => true, 'reason' => 'residential_address', 'threshold_kg' => $threshold, 'heaviest_piece_kg' => $heaviest];
        }

        return ['required' => false, 'reason' => null, 'threshold_kg' => $threshold, 'heaviest_piece_kg' => $heaviest];
    }

    /** Apply the automatic rule unless a person has overridden it. */
    public function apply(Order $order): Order
    {
        if ($order->tailgate_reason === 'manual') {
            return $order;
        }
        $result = $this->evaluate($order);
        $order->forceFill(['tailgate_required' => $result['required'], 'tailgate_reason' => $result['reason']])->save();

        return $order;
    }
}
