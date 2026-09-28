<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #164: industry location code Zone-Aisle-Bay-Level-Position. `bin` was the bay all along and is renamed; `position`
 * (1 = left, 2 = right slot of a bay) is new; the level stays in `rack_level`. Rack locations (storage / pickface with a level) get the
 * six-part code WH-ZONE-AISLE-BAY-LEVEL-POSITION, floor areas keep WH-ZONE-AISLE-BAY. The seeded warehouses take the city + number codes
 * the lead asked for (MEL → MEL1, SYD → SYD1); every location code is rebuilt with the new prefix. Ids never change, so scan labels
 * (L<id>) keep working; the printed text is stale until the labels are reprinted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->renameColumn('bin', 'bay');
        });
        Schema::table('locations', function (Blueprint $table) {
            $table->unsignedTinyInteger('position')->nullable()->after('rack_level');
        });

        DB::table('warehouses')->whereIn('code', ['MEL', 'SYD'])->update(['code' => DB::raw("CONCAT(code, '1')")]);

        $warehouses = DB::table('warehouses')->pluck('code', 'id');
        foreach (DB::table('locations')->orderBy('id')->get() as $row) {
            $rack = in_array($row->type, ['storage', 'pickface'], true) && $row->rack_level !== null;
            $parts = [(string) ($warehouses[$row->warehouse_id] ?? ''), $row->zone, $row->aisle, $row->bay];
            if ($rack) {
                $parts[] = (int) $row->rack_level;
                $parts[] = 1;
            }
            DB::table('locations')->where('id', $row->id)->update([
                'position' => $rack ? 1 : null,
                'full_code' => strtoupper(implode('-', $parts)),
            ]);
        }
    }

    public function down(): void
    {
        $warehouses = DB::table('warehouses')->pluck('code', 'id');
        foreach (DB::table('locations')->orderBy('id')->get() as $row) {
            DB::table('locations')->where('id', $row->id)->update([
                'full_code' => strtoupper(implode('-', [(string) ($warehouses[$row->warehouse_id] ?? ''), $row->zone, $row->aisle, $row->bay])),
            ]);
        }
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('position');
        });
        Schema::table('locations', function (Blueprint $table) {
            $table->renameColumn('bay', 'bin');
        });
    }
};
