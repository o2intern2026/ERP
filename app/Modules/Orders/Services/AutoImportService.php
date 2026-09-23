<?php

namespace App\Modules\Orders\Services;

use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Mail\ImportResultMail;
use App\Modules\Orders\Models\OrderImport;
use App\Support\Enums;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * CHANGE_REQUESTS #145 自动导入: the portal upload's pipeline (OrderImportService — parser, grouping, duplicate protection, audit,
 * confirm) driven without a person on the page — by the client's system through `POST /orders/api/imports` (source `api`) or by a
 * file dropped in the client's inbox folder that `imports:inbox` sweeps every five minutes (source `inbox`). The options a person
 * would tick come from the request, else from the client's import defaults (`clients.import_defaults`, set by admin on the client
 * form: group_by, address_type_default, auto_confirm, inbox_enabled, notify_email). AUTO-CONFIRM NEVER GUESSES: only a list with zero
 * refused rows and zero blocked / duplicate groups (and no collection request) is confirmed by itself; anything else stays `pending`
 * for the client to review in the portal (已提交清单), exactly as an upload would. A file the client already sent (same sha256, still
 * pending or already imported) is not read again: the earlier import is returned as a replay unless `force` is set. Both order
 * types of CR #144 are accepted through the API; an inbox file is always a from_stock list (there is nobody to type a pickup party).
 */
final class AutoImportService
{
    public const SOURCES = ['api', 'inbox'];

    /** Every source a client's own submission may carry — the portal pages list, show and confirm all of them. */
    public const CLIENT_SOURCES = ['portal', 'api', 'inbox'];

    public const INBOX_ROOT = 'imports/inbox';

    public const INBOX_EXTENSIONS = ['csv', 'xlsx', 'xls', 'txt'];

    /** Where a swept file ends up, by the import's status; anything else (an exception, a failed import) goes to `failed`. */
    public const INBOX_BUCKETS = ['imported' => 'processed', 'pending' => 'review'];

    public function __construct(private readonly OrderImportService $imports) {}

    /** The client's inbox folder on the `local` disk (storage/app/private): imports/inbox/<client code>. */
    public static function inboxFolder(Client $client): string
    {
        return self::INBOX_ROOT.'/'.$client->code;
    }

    /**
     * Read one list for a client. `$options`: order_type, group_by, address_type_default, auto_confirm, force, the from_stock inbound
     * context (container_no, container_size, expected_date, reference, notes), requested_date, and for pickup_deliver the pickup party
     * (`pickup` = name, phone, address, suburb, state, postcode) — validated by the caller; anything unknown falls back to the client's
     * defaults, then to today's rules.
     *
     * @param  array<string, mixed>  $options
     * @return array{import: OrderImport, replayed: bool, auto_confirmed: bool}
     */
    public function import(Client $client, UploadedFile $file, array $options, string $source, ?int $actorId = null): array
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown automated import source {$source}.");
        }
        $defaults = $client->importDefaults();
        if (! filter_var($options['force'] ?? false, FILTER_VALIDATE_BOOL)) {
            $previous = $this->previous((int) $client->id, hash_file('sha256', $file->getRealPath()));
            if ($previous !== null) {
                return ['import' => $previous, 'replayed' => true, 'auto_confirmed' => (bool) data_get($previous->errors, 'context.automation.auto_confirmed', false)];
            }
        }
        $autoConfirm = isset($options['auto_confirm']) && $options['auto_confirm'] !== ''
            ? filter_var($options['auto_confirm'], FILTER_VALIDATE_BOOL)
            : $defaults['auto_confirm'];

        $import = $this->imports->preview($file, $this->context($client, $options, $defaults, $source, $autoConfirm), $actorId);
        $confirmed = false;
        if ($autoConfirm && $this->canAutoConfirm($import)) {
            $keys = collect($import->errors['groups'] ?? [])->where('status', 'ready')->pluck('key')->all();
            $import = $this->imports->confirm($import, $keys, [], $actorId);
            $confirmed = $import->status === 'imported';
            $audit = $import->errors ?? [];
            $audit['context']['automation']['auto_confirmed'] = $confirmed;
            $audit['context']['automation']['confirmed_at'] = now()->toDateTimeString();
            $import->update(['errors' => $audit]);
            $import = $import->fresh();
        }

        return ['import' => $import, 'replayed' => false, 'auto_confirmed' => $confirmed];
    }

    /**
     * The rule behind every automated confirmation: a pending list with NO refused row and NO blocked / duplicate / ASN-matched group
     * (error_count = 0, issues empty, every group ready), at least one group, and no 需要我们上门提货 request (a plan must be chosen by a
     * person). Everything else waits for the client in the portal.
     */
    public function canAutoConfirm(OrderImport $import): bool
    {
        if ($import->status !== 'pending' || (int) $import->error_count > 0 || ($import->errors['issues'] ?? []) !== []) {
            return false;
        }
        $groups = collect($import->errors['groups'] ?? []);

        return $groups->isNotEmpty()
            && $groups->every(fn (array $group) => ($group['status'] ?? null) === 'ready')
            && ! is_array($import->errors['context']['inbound']['collection'] ?? null);
    }

    /**
     * One file of the client's inbox folder: read it as a from_stock list with the client's defaults, move it to
     * processed / review / failed (stamped), leave `<file>.result.txt` beside it and mail the result when the client has an address.
     *
     * @return array{bucket:string, target:string, summary:?array<string, mixed>, error:?string}
     */
    public function importInboxFile(Client $client, string $path): array
    {
        $disk = Storage::disk('local');
        $name = basename($path);
        $summary = null;
        $error = null;
        try {
            $result = $this->import($client, new UploadedFile($disk->path($path), $name, null, null, true), ['order_type' => 'from_stock'], 'inbox');
            $summary = $this->summary($result['import'], $result['replayed'], $result['auto_confirmed']);
            $bucket = self::INBOX_BUCKETS[$summary['status']] ?? 'failed';
        } catch (Throwable $e) {
            report($e);
            $error = $e->getMessage();
            $bucket = 'failed';
        }
        $target = self::inboxFolder($client).'/'.$bucket.'/'.now()->format('Ymd-His').'-'.$name;
        $disk->move($path, $target);
        $text = $summary !== null ? $this->resultText($summary) : __('orders.imports.auto.result_error', ['file' => $name, 'error' => (string) $error])."\n";
        $disk->put($target.'.result.txt', $text);
        $this->notify($client, $summary, $text);

        return ['bucket' => $bucket, 'target' => $target, 'summary' => $summary, 'error' => $error];
    }

    /**
     * What the API answers and the inbox result file / mail is written from — counts, the orders created, every refused row and
     * blocked group with its message, and the portal page where the client reviews a pending list.
     *
     * @return array<string, mixed>
     */
    public function summary(OrderImport $import, bool $replayed = false, ?bool $autoConfirmed = null): array
    {
        $audit = $import->errors ?? [];
        $groups = collect($audit['groups'] ?? []);
        $blockedStatuses = ['blocked', 'duplicate', 'asn_match'];

        return [
            'import_id' => (int) $import->id,
            'status' => (string) $import->status,
            'source' => (string) $import->source,
            'order_type' => $import->orderType(),
            'file_name' => $audit['context']['original_name'] ?? null,
            'replayed' => $replayed,
            'auto_confirmed' => $autoConfirmed ?? (bool) data_get($audit, 'context.automation.auto_confirmed', false),
            'row_count' => (int) $import->row_count,
            'error_count' => (int) $import->error_count,
            'groups' => [
                'total' => $groups->count(),
                'ready' => $groups->where('status', 'ready')->count(),
                'blocked' => $groups->whereIn('status', $blockedStatuses)->count(),
                'imported' => $groups->where('status', 'imported')->count(),
            ],
            'orders' => array_values(array_map(fn (array $created) => [
                'order_id' => (int) $created['order_id'],
                'order_no' => (string) $created['order_no'],
                'rows' => array_values($created['row_numbers'] ?? []),
            ], $audit['result']['created'] ?? [])),
            'blocked' => $groups->whereIn('status', $blockedStatuses)->map(fn (array $group) => [
                'mark' => (string) $group['consignment_mark'],
                'status' => (string) $group['status'],
                'rows' => array_values($group['row_numbers'] ?? []),
                'message' => (string) $group['message'],
            ])->values()->all(),
            'issues' => array_values(array_map(fn (array $issue) => [
                'row' => (int) $issue['row'],
                'column' => (string) ($issue['label'] ?? $issue['column']),
                'message' => (string) $issue['message'],
            ], $audit['issues'] ?? [])),
            'warnings_count' => count($audit['warnings'] ?? []),
            'review_url' => route('portal.asns.imports.show', $import),
        ];
    }

    /**
     * The Chinese result text (inbox `.result.txt`, the result mail): what happened, the orders, every problem, and the portal page
     * when the list still needs a person.
     *
     * @param  array<string, mixed>  $summary
     */
    public function resultText(array $summary): string
    {
        $lines = [__('orders.imports.auto.result_title', ['file' => $summary['file_name'] ?? '—', 'id' => $summary['import_id']])];
        if ($summary['replayed']) {
            $lines[] = __('orders.imports.auto.result_replayed', ['id' => $summary['import_id']]);
        }
        $lines[] = match ($summary['status']) {
            'imported' => __('orders.imports.auto.result_imported', ['count' => count($summary['orders'])]),
            'pending' => __('orders.imports.auto.result_pending', ['ready' => $summary['groups']['ready'], 'blocked' => $summary['groups']['blocked'], 'errors' => count($summary['issues'])]),
            default => __('orders.imports.auto.result_failed'),
        };
        foreach ($summary['orders'] as $order) {
            $lines[] = __('orders.imports.auto.result_order', ['order_no' => $order['order_no'], 'rows' => implode(', ', $order['rows'])]);
        }
        foreach ([...$summary['blocked'], ...$summary['issues']] as $problem) {
            $lines[] = '· '.$problem['message'];
        }
        if ($summary['status'] !== 'imported') {
            $lines[] = __('orders.imports.auto.result_review', ['url' => $summary['review_url']]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The OrderImportService context of an automated list — the portal submission's shape (CR #123 / #144) with the request's options
     * over the client's defaults, plus `automation` (source, whether auto-confirm was asked, when the file arrived).
     *
     * @param  array<string, mixed>  $options
     * @param  array{group_by:string, address_type_default:string, auto_confirm:bool, inbox_enabled:bool, notify_email:?string}  $defaults
     * @return array<string, mixed>
     */
    private function context(Client $client, array $options, array $defaults, string $source, bool $autoConfirm): array
    {
        $orderType = ($options['order_type'] ?? null) === 'pickup_deliver' ? 'pickup_deliver' : 'from_stock';
        $text = fn (string $key, int $max): ?string => filled($options[$key] ?? null) ? mb_substr(trim((string) $options[$key]), 0, $max) : null;
        $date = fn (string $key): ?string => filled($options[$key] ?? null) ? Carbon::parse((string) $options[$key])->toDateString() : null;
        $context = [
            'client_id' => (int) $client->id, // the token / the folder names the client, never the request
            'job_id' => null,
            'order_type' => $orderType,
            'service_level' => 'standard',
            'source' => $source,
            'client_visible' => true,
            'group_by' => in_array($options['group_by'] ?? null, Enums::IMPORT_GROUP_BYS, true) ? $options['group_by'] : $defaults['group_by'],
            'address_type_default' => in_array($options['address_type_default'] ?? null, Enums::IMPORT_ADDRESS_TYPE_DEFAULTS, true) ? $options['address_type_default'] : $defaults['address_type_default'],
            'automation' => ['source' => $source, 'auto_confirm' => $autoConfirm, 'auto_confirmed' => false, 'received_at' => now()->toDateTimeString()],
        ];
        if ($orderType === 'pickup_deliver') {
            $pickup = is_array($options['pickup'] ?? null) ? $options['pickup'] : [];
            $field = fn (string $key): string => trim((string) ($pickup[$key] ?? ''));
            $context['requested_date'] = $date('requested_date') ?? today()->addDays(3)->toDateString();
            $context['inbound'] = null;
            $context['pickup'] = [
                'name' => $field('name'),
                'phone' => $field('phone'),
                'address' => $field('address'),
                'suburb' => $field('suburb'),
                'state' => mb_strtoupper($field('state')),
                'postcode' => $field('postcode'),
            ];

            return $context;
        }
        $expected = filled($options['expected_date'] ?? null) ? Carbon::parse((string) $options['expected_date']) : today();
        $context['requested_date'] = $date('requested_date') ?? $expected->copy()->addDays(7)->toDateString(); // the portal default: 预计到港日 + 7
        $context['inbound'] = [
            'container_no' => filled($options['container_no'] ?? null) ? mb_strtoupper(mb_substr(trim((string) $options['container_no']), 0, 20)) : null,
            'container_size' => in_array((string) ($options['container_size'] ?? ''), Enums::CONTAINER_SIZES, true) ? (string) $options['container_size'] : null,
            'expected_date' => $date('expected_date'),
            'reference' => $text('reference', 60),
            'notes' => $text('notes', 2000),
            'uploaded_at' => now()->toDateTimeString(),
        ];

        return $context;
    }

    /** The client's earlier import of the very same file (sha256), still pending or already imported — what a replay returns. */
    private function previous(int $clientId, string $sha256): ?OrderImport
    {
        return OrderImport::query()->withoutGlobalScopes()->where('client_id', $clientId)->whereIn('status', ['pending', 'imported'])
            ->latest('id')->limit(500)->get()
            ->first(fn (OrderImport $import) => data_get($import->errors, 'context.sha256') === $sha256);
    }

    /** The result mail — to the client's notify address, else its contact email; best effort, never fails the sweep. */
    private function notify(Client $client, ?array $summary, string $text): void
    {
        $email = $client->importDefaults()['notify_email'] ?? $client->contact_email;
        if (! filled($email)) {
            return;
        }
        try {
            Mail::to((string) $email)->send(new ImportResultMail($client, $summary, $text));
        } catch (Throwable $e) {
            Log::warning('imports:inbox — result mail failed', ['client' => $client->code, 'error' => $e->getMessage()]);
        }
    }
}
