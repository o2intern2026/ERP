<?php

namespace App\Modules\Orders\Console;

use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Services\AutoImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * CHANGE_REQUESTS #145 自动导入 — cron (every five minutes in routes/console.php): sweep every active client's inbox folder
 * (storage/app/private/imports/inbox/<code>, clients with `inbox_enabled`) and hand each list to AutoImportService, which reads it
 * with the client's defaults, auto-confirms only a clean list, moves the file to processed / review / failed and leaves a
 * `.result.txt`. A file is left alone until it has been untouched for `--min-age` seconds (still being written).
 */
final class ImportInboxCommand extends Command
{
    protected $signature = 'imports:inbox {--client= : Only this client code} {--min-age=60 : Seconds a file must be untouched before it is read}';

    protected $description = 'Sweep the per-client inbox folders (storage/app/private/imports/inbox/<code>) and import every list found';

    public function handle(AutoImportService $auto): int
    {
        $disk = Storage::disk('local');
        $minAge = max(0, (int) $this->option('min-age'));
        $clients = Client::query()->withoutGlobalScopes()->where('status', 'active')
            ->when($this->option('client'), fn ($query, $code) => $query->where('code', $code))
            ->orderBy('code')->get();
        $count = 0;

        foreach ($clients as $client) {
            if (! $client->importDefaults()['inbox_enabled']) {
                continue;
            }
            $folder = AutoImportService::inboxFolder($client);
            $disk->makeDirectory($folder); // so ops can see where to drop files as soon as the client is enabled
            foreach ($disk->files($folder) as $path) {
                if (! in_array(mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)), AutoImportService::INBOX_EXTENSIONS, true)) {
                    continue;
                }
                if (now()->timestamp - $disk->lastModified($path) < $minAge) {
                    continue;
                }
                $result = $auto->importInboxFile($client, $path);
                $count++;
                $summary = $result['summary'];
                $this->line(sprintf('%s: %s → %s (%s)', $client->code, basename($path), $result['bucket'],
                    $summary === null ? 'error: '.$result['error'] : "import #{$summary['import_id']} {$summary['status']}, orders ".count($summary['orders']).", errors {$summary['error_count']}"));
            }
        }
        $this->info("imports:inbox — {$count} file(s) processed");

        return self::SUCCESS;
    }
}
