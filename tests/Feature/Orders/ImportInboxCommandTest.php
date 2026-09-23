<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Mail\ImportResultMail;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #145 自动导入 — `imports:inbox` (cron, every five minutes): every enabled client's inbox folder
 * (storage/app/private/imports/inbox/<code>) is swept, each list read with the client's defaults as a from_stock list, auto-confirmed
 * only when clean, moved to processed / review / failed with a `.result.txt`, and the result mailed.
 */
class ImportInboxCommandTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const FIXTURE = 'tests/Fixtures/imports/consolidation_dsf.xlsx';

    public function test_the_sweep_imports_the_files_of_enabled_clients_moves_them_and_writes_the_result(): void
    {
        Storage::fake('local');
        Mail::fake();
        $enabled = $this->client(['code' => 'AAA', 'contact_email' => 'ops@example.test', 'import_defaults' => ['inbox_enabled' => true, 'auto_confirm' => true, 'group_by' => 'recipient', 'address_type_default' => 'residential', 'notify_email' => 'lists@example.test']]);
        $disabled = $this->client(['code' => 'BBB', 'import_defaults' => ['auto_confirm' => true]]);
        $disk = Storage::disk('local');
        $disk->put('imports/inbox/AAA/list.xlsx', file_get_contents(base_path(self::FIXTURE)));
        $disk->put('imports/inbox/AAA/notes.md', 'not a list');
        $disk->put('imports/inbox/BBB/list.xlsx', file_get_contents(base_path(self::FIXTURE)));

        $this->artisan('imports:inbox', ['--min-age' => 0])->expectsOutputToContain('AAA: list.xlsx → processed')->expectsOutputToContain('1 file(s) processed')->assertSuccessful();

        $import = OrderImport::query()->withoutGlobalScopes()->where('client_id', $enabled->id)->sole();
        $this->assertSame(['inbox', 'imported', 'from_stock', 'recipient', 'residential'], [$import->source, $import->status, $import->orderType(), $import->errors['context']['group_by'], $import->errors['context']['address_type_default']]);
        $this->assertTrue($import->errors['context']['automation']['auto_confirmed']);
        $orders = Order::query()->withoutGlobalScopes()->where('client_id', $enabled->id)->get();
        $this->assertCount(5, $orders);
        $this->assertSame(['excel'], $orders->pluck('source')->unique()->all(), 'an inbox file is a spreadsheet import (orders.source excel)');
        $disk->assertMissing('imports/inbox/AAA/list.xlsx');
        $disk->assertExists('imports/inbox/AAA/notes.md');
        $processed = $disk->files('imports/inbox/AAA/processed');
        $this->assertCount(2, $processed, 'the stamped file and its .result.txt');
        $result = collect($processed)->first(fn ($p) => str_ends_with($p, '.result.txt'));
        $this->assertNotNull($result);
        $text = $disk->get($result);
        $this->assertStringContainsString(__('orders.imports.auto.result_imported', ['count' => 5]), $text);
        $this->assertStringContainsString($orders->first()->order_no, $text);
        Mail::assertSent(ImportResultMail::class, fn (ImportResultMail $mail) => $mail->hasTo('lists@example.test') && $mail->text === $text);

        // The disabled client's file is left where it is; nothing of it read.
        $disk->assertExists('imports/inbox/BBB/list.xlsx');
        $this->assertSame(0, OrderImport::query()->withoutGlobalScopes()->where('client_id', $disabled->id)->count());
        $this->assertSame([], $disk->files('imports/inbox/BBB/processed'));
    }

    public function test_a_fresh_file_waits_a_problem_list_lands_in_review_with_the_portal_link_and_a_replayed_file_is_not_read_twice(): void
    {
        Storage::fake('local');
        Mail::fake();
        $client = $this->client(['code' => 'CCC', 'contact_email' => 'contact@example.test', 'import_defaults' => ['inbox_enabled' => true, 'auto_confirm' => true]]);
        $disk = Storage::disk('local');
        $csv = "ChannelWaybillNumber,Recipient,Recipient's Phone Number,Postal Code,State/Province,City,Detailed Address,Commodity,商品数量,Length(cm),Width(cm),Height(cm),Weight(kg)\n"
            ."CW2001-1,Test Recipient F,0412000006,2000,NSW,Sydney,10 Sample St,测试商品F,2,40,30,20,8\n"
            ."CW2002-1,Test Recipient G,0412000007,3000,VIC,Melbourne,11 Sample St,测试商品G,1,40,30,20,\n";
        $disk->put('imports/inbox/CCC/problem.csv', $csv);

        // Just written: the default 60-second age guard leaves it alone.
        $this->artisan('imports:inbox')->expectsOutputToContain('0 file(s) processed')->assertSuccessful();
        $disk->assertExists('imports/inbox/CCC/problem.csv');
        $this->assertSame(0, OrderImport::query()->count());

        $this->artisan('imports:inbox', ['--min-age' => 0, '--client' => 'CCC'])->expectsOutputToContain('CCC: problem.csv → review')->assertSuccessful();
        $import = OrderImport::query()->sole();
        $this->assertSame(['inbox', 'pending', 1], [$import->source, $import->status, $import->error_count]);
        $this->assertSame(0, Order::query()->count(), 'a refused row means no automatic confirmation');
        $review = $disk->files('imports/inbox/CCC/review');
        $this->assertCount(2, $review);
        $text = $disk->get(collect($review)->first(fn ($p) => str_ends_with($p, '.result.txt')));
        $this->assertStringContainsString(__('orders.imports.auto.result_pending', ['ready' => 1, 'blocked' => 0, 'errors' => 1]), $text);
        $this->assertStringContainsString(route('portal.asns.imports.show', $import), $text);
        $this->assertStringContainsString('必须是大于 0 的数字', $text);
        Mail::assertSent(ImportResultMail::class, fn (ImportResultMail $mail) => $mail->hasTo('contact@example.test'), 'no notify address → the contact email');
        $this->actingAs($this->clientUser($client))->get(route('portal.asns.imports.show', $import))->assertOk()->assertSee(__('portal.inbound.sources.inbox'));

        // The same file dropped again: recognised by its hash, not read twice, the result text says so.
        $disk->put('imports/inbox/CCC/problem-again.csv', $csv);
        $this->artisan('imports:inbox', ['--min-age' => 0])->assertSuccessful();
        $this->assertSame(1, OrderImport::query()->count());
        $again = collect($disk->files('imports/inbox/CCC/review'))->filter(fn ($p) => str_contains($p, 'problem-again.csv.result.txt'))->first();
        $this->assertNotNull($again);
        $this->assertStringContainsString(__('orders.imports.auto.result_replayed', ['id' => $import->id]), $disk->get($again));
    }
}
