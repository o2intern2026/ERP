<?php

namespace App\Modules\Reports\Rules;

use App\Modules\Reports\Services\ReportPeriod;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * 2026-09-10 audit: the `to` date may be at most ReportPeriod::MAX_DAYS after `from`. Before, ReportPeriod::fromInput()
 * silently shortened a longer range and the page / CSV covered a different period than the one asked for; now the
 * filter is rejected with a Chinese message that names the latest allowed `to`. An absent `from` is taken as the same
 * default fromInput() uses (the first day of the current month), so a hand-edited `?to=…` URL is checked too instead of
 * being clamped silently. Skips only when a supplied date does not parse — the `date` rules report that.
 */
final class MaxPeriodSpan implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly int $maxDays, private readonly string $fromField = 'from') {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! filled($value)) {
            return;
        }
        $fromInput = $this->data[$this->fromField] ?? null;
        try {
            $from = filled($fromInput) ? CarbonImmutable::parse((string) $fromInput)->startOfDay() : self::defaultFrom();
            $to = CarbonImmutable::parse((string) $value)->startOfDay();
        } catch (Throwable) {
            return;
        }
        if ($to->lessThan($from) || $from->diffInDays($to) <= $this->maxDays) {
            return;
        }

        $fail(__('reports.validation.messages.max_days', [
            'days' => $this->maxDays,
            'from' => $from->toDateString(),
            'latest' => $from->addDays($this->maxDays)->toDateString(),
        ]));
    }

    /** The `from` ReportPeriod::fromInput() falls back to — so the rule checks the span the page would actually use. */
    public static function defaultFrom(): CarbonImmutable
    {
        return ReportPeriod::currentMonth()->from;
    }
}
