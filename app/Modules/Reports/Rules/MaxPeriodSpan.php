<?php

namespace App\Modules\Reports\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * 2026-09-10 audit: the `to` date may be at most ReportPeriod::MAX_DAYS after `from`. Before, ReportPeriod::fromInput()
 * silently shortened a longer range and the page / CSV covered a different period than the one asked for; now the
 * filter is rejected with a Chinese message that names the latest allowed `to`. Runs only when both dates parse — the
 * `date` rules report an unparsable value.
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
        $fromInput = $this->data[$this->fromField] ?? null;
        if (! filled($fromInput) || ! filled($value)) {
            return;
        }
        try {
            $from = CarbonImmutable::parse((string) $fromInput)->startOfDay();
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
}
