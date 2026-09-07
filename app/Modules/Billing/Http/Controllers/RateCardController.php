<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\Billing\Services\RateCardService;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Approval;
use App\Modules\Platform\Services\ApprovalService;
use App\Support\Enums;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** A5: rate cards (standard + per client), versions, draft editing, approval-gated activation. */
class RateCardController extends Controller
{
    public function index(): View
    {
        return view('billing::rate_cards.index', [
            'cards' => RateCard::query()->with('client')->withCount('items')->orderByDesc('is_standard')->orderBy('client_id')->orderByDesc('version')->get(),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, RateCardService $service): RedirectResponse
    {
        $data = $request->validate(['client_id' => ['required', 'integer', Rule::exists('clients', 'id')], 'name' => ['required', 'string', 'max:120'], 'effective_from' => ['required', 'date']]);
        $card = $service->createClientCard(Client::query()->findOrFail($data['client_id']), $request->user(), Carbon::parse($data['effective_from']), $data['name']);

        return redirect()->route('billing.rate_cards.show', $card)->with('status', __('billing.rate_cards.created'));
    }

    public function show(RateCard $card): View
    {
        return view('billing::rate_cards.show', [
            'card' => $card->load(['client', 'items.chargeCode']),
            'codes' => ChargeCode::query()->where('active', true)->orderBy('code')->get(),
            'palletClasses' => Enums::PALLET_CLASSES, 'pricingModes' => Enums::PRICING_MODES,
            'approved' => app(ApprovalService::class)->isApproved('rate_card_change', 'rate_card', $card->id),
            'pendingApproval' => Approval::query()->where('type', 'rate_card_change')->where('subject_type', 'rate_card')->where('subject_id', $card->id)->where('status', 'pending')->exists(),
        ]);
    }

    public function newVersion(Request $request, RateCard $card, RateCardService $service): RedirectResponse
    {
        $data = $request->validate(['effective_from' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:500']]);
        $new = $service->newVersion($card, $request->user(), Carbon::parse($data['effective_from']), $data['notes'] ?? null);

        return redirect()->route('billing.rate_cards.show', $new)->with('status', __('billing.rate_cards.new_version_created', ['version' => $new->version]));
    }

    public function addItem(Request $request, RateCard $card, RateCardService $service): RedirectResponse
    {
        $data = $this->itemData($request, true);
        try {
            $service->addItem($card, $data);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['item' => $e->getMessage()]);
        }

        return back()->with('status', __('billing.rate_cards.item_saved'));
    }

    public function updateItem(Request $request, RateItem $item, RateCardService $service): RedirectResponse
    {
        $data = $this->itemData($request, false);
        try {
            $service->updateItem($item, $data);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['item' => $e->getMessage()]);
        }

        return back()->with('status', __('billing.rate_cards.item_saved'));
    }

    public function requestActivation(Request $request, RateCard $card, RateCardService $service): RedirectResponse
    {
        try {
            $service->requestActivation($card, $request->user(), $request->input('note'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['card' => $e->getMessage()]);
        }

        return back()->with('status', __('billing.rate_cards.activation_requested'));
    }

    public function activate(Request $request, RateCard $card, RateCardService $service): RedirectResponse
    {
        try {
            $service->activate($card, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['card' => $e->getMessage()]);
        }

        return back()->with('status', __('billing.rate_cards.activated', ['version' => $card->version]));
    }

    /** @return array<string, mixed> */
    private function itemData(Request $request, bool $withCode): array
    {
        $data = $request->validate([
            'charge_code_id' => [$withCode ? 'required' : 'nullable', 'integer', Rule::exists('charge_codes', 'id')],
            'rate' => ['nullable', 'numeric', 'min:0'], 'min_charge' => ['nullable', 'numeric', 'min:0'], 'is_poa' => ['nullable', 'boolean'],
            'pricing_mode' => ['nullable', Rule::in(Enums::PRICING_MODES)], 'markup_percent' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'pallet_class' => ['nullable', Rule::in(Enums::PALLET_CLASSES)], 'weight_band_min' => ['nullable', 'numeric', 'min:0'], 'weight_band_max' => ['nullable', 'numeric', 'min:0'],
            'zone' => ['nullable', 'string', 'max:30'], 'service_level' => ['nullable', 'string', 'max:20'], 'threshold_json' => ['nullable', 'json'], 'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return array_filter([
            'charge_code_id' => $data['charge_code_id'] ?? null,
            'rate_cents' => isset($data['rate']) && $data['rate'] !== '' ? (int) round($data['rate'] * 100) : null,
            'min_charge_cents' => isset($data['min_charge']) && $data['min_charge'] !== '' ? (int) round($data['min_charge'] * 100) : null,
            'is_poa' => (bool) ($data['is_poa'] ?? false),
            'pricing_mode' => $data['pricing_mode'] ?? 'fixed',
            'markup_percent' => $data['markup_percent'] ?? null,
            'pallet_class' => $data['pallet_class'] ?? null,
            'weight_band_min' => $data['weight_band_min'] ?? null,
            'weight_band_max' => $data['weight_band_max'] ?? null,
            'zone' => $data['zone'] ?? null, 'service_level' => $data['service_level'] ?? null,
            'threshold_json' => isset($data['threshold_json']) && $data['threshold_json'] !== '' ? json_decode($data['threshold_json'], true) : null,
            'notes' => $data['notes'] ?? null,
        ], fn ($v, $k) => $v !== null || in_array($k, ['rate_cents', 'min_charge_cents', 'threshold_json', 'weight_band_max'], true), ARRAY_FILTER_USE_BOTH);
    }
}
