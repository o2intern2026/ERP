<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Services\DemoStory;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * `php artisan demo:run` — lead request 2026-09-11 (CHANGE_REQUESTS #113): build a compact, realistic end-to-end story on
 * the CURRENT database, additive and repeatable, print a Chinese step log with record numbers and clickable links, and
 * optionally stop at a chosen stage so testers finish the rest by hand. All the work is in DemoStory; this class only
 * validates options and renders. docs/demo-guide.zh.md §3.1 documents it.
 */
final class DemoRunCommand extends Command
{
    public function __construct()
    {
        $this->signature = 'demo:run'
            .' {--client=EDWARD : '.__('demo.options.client').'}'
            .' {--warehouse=MEL : '.__('demo.options.warehouse').'}'
            .' {--lines=6 : '.__('demo.options.lines').'}'
            .' {--orders=4 : '.__('demo.options.orders').'}'
            .' {--tag= : '.__('demo.options.tag').'}'
            .' {--until=delivered : '.__('demo.options.until').'}'
            .' {--json : '.__('demo.options.json').'}';
        $this->description = (string) __('demo.description');

        parent::__construct();
    }

    public function handle(DemoStory $story): int
    {
        $json = (bool) $this->option('json');

        try {
            $options = DemoStory::normalise([
                'client' => $this->option('client'), 'warehouse' => $this->option('warehouse'), 'lines' => $this->option('lines'),
                'orders' => $this->option('orders'), 'tag' => $this->option('tag'), 'until' => $this->option('until'),
            ]);
            if (! $json) {
                $this->line('<comment>'.$this->escape(__('demo.console.start', ['tag' => $options['tag'], 'client' => $options['client'], 'warehouse' => $options['warehouse'], 'until' => __('demo.stages.'.$options['until'])])).'</comment>');
            }
            $result = $story->run($options, $json ? null : fn (array $step) => $this->printStep($step));
        } catch (InvalidArgumentException $e) {
            if ($json) {
                $this->line((string) json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error(__('demo.console.refused', ['reason' => $e->getMessage()]));
            }

            return self::FAILURE;
        }

        if ($json) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->printSummary($result);
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /** @param array{ok: bool, stage: string, title: string, detail: string, url: ?string} $step */
    private function printStep(array $step): void
    {
        if ($step['ok']) {
            $this->line(sprintf('<info>✓ %s</info> — %s', $this->escape($step['title']), $this->escape($step['detail'])));
            if ($step['url'] !== null) {
                $this->line('    '.$this->escape($step['url']));
            }

            return;
        }

        $this->line(sprintf('<fg=red>✗ %s:%s</>', $this->escape($step['title']), $this->escape($step['detail'])));
    }

    /** @param array{ok: bool, stopped_at: ?string, summary: array<string, mixed>} $result */
    private function printSummary(array $result): void
    {
        $s = $result['summary'];
        $items = (array) __('demo.summary.items');
        $this->newLine();
        $title = $result['ok']
            ? __('demo.summary.title', ['tag' => $s['tag']])
            : __('demo.summary.title_partial', ['tag' => $s['tag'], 'stage' => __('demo.stages.'.$result['stopped_at'])]);
        $this->line('<comment>'.$this->escape($title).'</comment>');

        $rows = [];
        if ($s['job']) {
            $rows[] = [$items['job'], $s['job']['job_no'], '', $s['job']['url']];
        }
        if ($s['asn']) {
            $rows[] = [$items['asn'], $s['asn']['asn_no'], $s['asn']['status_label'], $s['asn']['url']];
        }
        if ($s['receipt']) {
            $rows[] = [$items['receipt'], $s['receipt']['receipt_no'], $s['receipt']['status_label'], $s['receipt']['url']];
        }
        foreach ($s['orders'] as $o) {
            $rows[] = [$items['order'].' '.$o['mark'], $o['order_no'], $o['status_label'], $o['url']];
        }
        if ($s['wave']) {
            $rows[] = [$items['wave'], $s['wave']['wave_no'], $s['wave']['status_label'], $s['wave']['url']];
        }
        if ($s['run']) {
            $rows[] = [$items['run'], $s['run']['run_no'], '', $s['run']['url']];
        }
        foreach ($s['shipments'] as $sh) {
            $rows[] = [$items['shipment'].' '.$sh['order_no'], $sh['shipment_no'], $sh['status_label'].' · '.$sh['source_label'], $sh['url']];
        }
        if ($s['invoice']) {
            $rows[] = [$items['invoice'], $s['invoice']['invoice_no'], $s['invoice']['status_label'].' · '.$s['invoice']['total'], $s['invoice']['url']];
        }
        $this->table(array_values((array) __('demo.summary.columns')), array_map(fn (array $row) => array_map(fn ($cell) => $this->escape((string) $cell), $row), $rows));

        if ($s['notes'] !== []) {
            $this->line(__('demo.summary.notes'));
            foreach ($s['notes'] as $note) {
                $this->line('  · '.$this->escape($note));
            }
        }
        if ($s['next'] !== []) {
            $this->line(__('demo.summary.next'));
            foreach ($s['next'] as $page) {
                $this->line('  · '.$this->escape($page['label']).'  '.$this->escape($page['url']));
            }
        }
    }

    private function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}
