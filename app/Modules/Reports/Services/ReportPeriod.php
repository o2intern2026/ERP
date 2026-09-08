<?php

namespace App\Modules\Reports\Services;

use Carbon\CarbonImmutable;

/** An inclusive date range (Australia/Melbourne business days) for A21 / A22 reports; defaults to the current month. */
final class ReportPeriod
{
    public const MAX_DAYS = 366;

    public function __construct(public readonly CarbonImmutable $from, public readonly CarbonImmutable $to) {}

    /** Validation rules for the from / to query parameters (dates, to ≥ from; span checked in fromInput). */
    public static function rules(): array
    {
        return ['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']];
    }

    /** @param array{from?:?string, to?:?string} $input */
    public static function fromInput(array $input): self
    {
        $default = self::currentMonth();
        $from = filled($input['from'] ?? null) ? CarbonImmutable::parse($input['from'])->startOfDay() : $default->from;
        $to = filled($input['to'] ?? null) ? CarbonImmutable::parse($input['to'])->startOfDay() : ($from->greaterThan($default->to) ? $from : $default->to);
        if ($to->lessThan($from)) {
            $to = $from;
        }
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $to = $from->addDays(self::MAX_DAYS);
        }

        return new self($from, $to);
    }

    public static function currentMonth(): self
    {
        $today = CarbonImmutable::today();

        return new self($today->startOfMonth(), $today->endOfMonth()->startOfDay());
    }

    /** The ISO week (Monday – Sunday) before the given day — what the Monday 07:00 weekly mail covers. */
    public static function lastWeek(?CarbonImmutable $today = null): self
    {
        $today ??= CarbonImmutable::today();
        $monday = $today->startOfWeek(CarbonImmutable::MONDAY)->subWeek();

        return new self($monday, $monday->addDays(6));
    }

    public static function lastMonth(?CarbonImmutable $today = null): self
    {
        $today ??= CarbonImmutable::today();
        $first = $today->startOfMonth()->subMonth();

        return new self($first, $first->endOfMonth()->startOfDay());
    }

    /** Exclusive upper bound for `created_at < ?` comparisons. */
    public function toExclusive(): CarbonImmutable
    {
        return $this->to->addDay();
    }

    public function label(): string
    {
        return $this->from->toDateString().' ~ '.$this->to->toDateString();
    }

    /** Filename-safe stem. */
    public function slug(): string
    {
        return $this->from->format('Ymd').'-'.$this->to->format('Ymd');
    }
}
