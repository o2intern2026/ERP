<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Services\SpreadsheetManifestParser;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #123 — "要保证能够准确的转换客户订单信息": one test per real-world spreadsheet failure the parser now handles.
 * Fixtures live in tests/Fixtures/imports/ (generated once, committed); every message is Chinese and names the row and the column.
 */
class ManifestParserAccuracyTest extends TestCase
{
    private function parse(string $fixture): array
    {
        return app(SpreadsheetManifestParser::class)->parse(base_path('tests/Fixtures/imports/'.$fixture));
    }

    /** @return list<string> */
    private function messages(array $entries): array
    {
        return array_column($entries, 'message');
    }

    public function test_gb18030_csv_is_decoded_without_mojibake(): void
    {
        $parsed = $this->parse('gbk.csv');

        $this->assertSame([], $parsed['errors']);
        $this->assertSame(['GBK-01', '蓝牙音箱', 'Amazon FBA BWU2', 'NSW', '2170'], [$parsed['rows'][0]['consignment_mark'], $parsed['rows'][0]['description_cn'], $parsed['rows'][0]['deliver_to_name'], $parsed['rows'][0]['deliver_to_state'], $parsed['rows'][0]['deliver_to_postcode']]);
        $this->assertStringContainsString('GB18030', implode(' ', $this->messages($parsed['warnings'])));
    }

    public function test_utf16_unicode_text_exports_are_decoded_and_the_tab_delimiter_is_sniffed(): void
    {
        $le = $this->parse('utf16le.csv');
        $this->assertSame([], $le['errors']);
        $this->assertSame(['U16-01', '蓝牙音箱', 10, 85.0], [$le['rows'][0]['consignment_mark'], $le['rows'][0]['description_cn'], $le['rows'][0]['carton_qty'], $le['rows'][0]['actual_weight_kg']]);
        $this->assertStringContainsString('UTF-16LE', implode(' ', $this->messages($le['warnings'])));
        $this->assertStringContainsString('Tab', implode(' ', $this->messages($le['warnings'])));

        $be = $this->parse('utf16be.csv');
        $this->assertSame([], $be['errors']);
        $this->assertSame('U16-02', $be['rows'][0]['consignment_mark']);
    }

    public function test_semicolon_delimited_csv_keeps_commas_inside_quoted_cells(): void
    {
        $parsed = $this->parse('semicolon.csv');

        $this->assertSame([], $parsed['errors']);
        $this->assertSame('SEMI-01', $parsed['rows'][0]['consignment_mark']);
        $this->assertSame('1 Warehouse Rd, Unit 2', $parsed['rows'][0]['deliver_to_address']);
        $this->assertSame('Moorebank', $parsed['rows'][0]['deliver_to_suburb']);
        $this->assertStringContainsString(';', implode(' ', $this->messages($parsed['warnings'])));
    }

    public function test_full_width_digits_letters_and_punctuation_are_folded_to_half_width(): void
    {
        $parsed = $this->parse('fullwidth.csv');

        $this->assertSame([], $parsed['errors']);
        $row = $parsed['rows'][0];
        $this->assertSame(['FW-01', 10, 200, 85.5, 600, '0400000001', 'NSW', '2170', 'FBA15ABC123'], [
            $row['consignment_mark'], $row['carton_qty'], $row['unit_qty'], $row['actual_weight_kg'], $row['length_mm'], $row['deliver_to_phone'], $row['deliver_to_state'], $row['deliver_to_postcode'], $row['fba_reference'],
        ]);
    }

    public function test_phones_stay_text_scientific_notation_is_refused_with_the_fix_and_leading_zero_is_restored(): void
    {
        $parsed = $this->parse('phone.csv');
        $errors = collect($parsed['errors']);

        $this->assertSame([2, 3], $errors->pluck('row')->unique()->values()->all());
        $this->assertSame('电话', $errors->firstWhere('row', 2)['label']);
        $this->assertStringContainsString('第 2 行「电话」', $errors->firstWhere('row', 2)['message']);
        $this->assertStringContainsString('请把电话列设为文本', $errors->firstWhere('row', 2)['message']);
        $this->assertStringContainsString('第 3 行「电话」', $errors->firstWhere('row', 3)['message']);

        $rows = collect($parsed['rows'])->keyBy('consignment_mark');
        $this->assertSame('0412345678', $rows['PH-03']['deliver_to_phone']);
        $this->assertSame('0412345678', $rows['PH-04']['deliver_to_phone']);
        $this->assertSame('0299999999', $rows['PH-05']['deliver_to_phone']);
        $this->assertStringContainsString('第 4 行「电话」补回了前导 0（412345678 → 0412345678）', implode(' ', $this->messages($parsed['warnings'])));
    }

    public function test_postcodes_are_four_digits_a_lost_leading_zero_is_padded_with_a_warning_and_the_rest_are_refused(): void
    {
        $parsed = $this->parse('postcode.csv');
        $rows = collect($parsed['rows'])->keyBy('consignment_mark');

        $this->assertSame('0800', $rows['PC-01']['deliver_to_postcode']);
        $this->assertSame('NT', $rows['PC-01']['deliver_to_state']);
        $this->assertSame('2170', $rows['PC-02']['deliver_to_postcode']);
        $this->assertStringContainsString('第 2 行「邮编」补回了前导 0（800 → 0800）', implode(' ', $this->messages($parsed['warnings'])));

        $errors = collect($parsed['errors'])->keyBy('row');
        $this->assertSame([4, 5, 6], $errors->keys()->all());
        foreach ([4 => 'ABCD', 5 => '12345', 6 => '100'] as $row => $value) {
            $this->assertSame('deliver_to_postcode', $errors[$row]['column']);
            $this->assertStringContainsString("第 {$row} 行「邮编」必须是 4 位澳大利亚邮编（现为“{$value}”）", $errors[$row]['message']);
        }
    }

    public function test_state_spellings_codes_full_names_lower_case_dotted_and_chinese_resolve_and_unknown_is_refused(): void
    {
        $parsed = $this->parse('state.csv');
        $states = collect($parsed['rows'])->pluck('deliver_to_state', 'consignment_mark')->all();

        $this->assertSame([
            'ST-01' => 'VIC', 'ST-02' => 'NSW', 'ST-03' => 'QLD', 'ST-04' => 'SA', 'ST-05' => 'NSW', 'ST-06' => 'WA',
            'ST-07' => 'TAS', 'ST-08' => 'NT', 'ST-09' => 'ACT', 'ST-10' => 'VIC',
        ], $states);
        $this->assertCount(1, $parsed['errors']);
        $this->assertSame([12, 'deliver_to_state', '州'], [$parsed['errors'][0]['row'], $parsed['errors'][0]['column'], $parsed['errors'][0]['label']]);
        $this->assertStringContainsString('第 12 行「州」无法识别为澳大利亚的州（现为“Mars”）', $parsed['errors'][0]['message']);
        $this->assertSame([], $parsed['warnings'], 'a state that matches its postcode range raises nothing');
    }

    public function test_a_missing_state_is_derived_from_a_valid_postcode_with_a_warning_and_a_mismatch_only_warns(): void
    {
        $parsed = $this->parse('state_from_postcode.csv');
        $rows = collect($parsed['rows'])->keyBy('consignment_mark');
        $warnings = implode(' ', $this->messages($parsed['warnings']));

        $this->assertSame('VIC', $rows['SP-01']['deliver_to_state']);
        $this->assertStringContainsString('第 2 行未填州，已按邮编 3121 判定为 VIC', $warnings);
        $this->assertSame('VIC', $rows['SP-02']['deliver_to_state'], 'the typed state is kept');
        $this->assertStringContainsString('第 3 行的州 VIC 与邮编 2000 所属的 NSW 不一致', $warnings);
        $errors = collect($parsed['errors'])->where('row', 4)->pluck('column')->all();
        $this->assertSame(['deliver_to_postcode', 'deliver_to_state'], $errors, 'no postcode to derive from → both are missing');
    }

    public function test_header_variants_map_to_the_right_fields_with_the_right_units(): void
    {
        $en = $this->parse('headers_en.csv');
        $this->assertSame([], $en['errors']);
        $row = $en['rows'][0];
        $this->assertSame(['EN-01', 'Shop B', '0399990000', '12 High St', 'Richmond', 'VIC', '3121', 'FBA-EN', 5, 32.5, 450, 350, 300, 'Kettle'], [
            $row['consignment_mark'], $row['deliver_to_name'], $row['deliver_to_phone'], $row['deliver_to_address'], $row['deliver_to_suburb'], $row['deliver_to_state'],
            $row['deliver_to_postcode'], $row['fba_reference'], $row['carton_qty'], $row['actual_weight_kg'], $row['length_mm'], $row['width_mm'], $row['height_mm'], $row['description_en'],
        ]);
        $this->assertSame(30, $row['unit_qty'], '"Qty" next to "Cartons" is the unit count, never the carton count');

        $cn = $this->parse('headers_cn.csv');
        $this->assertSame([], $cn['errors']);
        $row = $cn['rows'][0];
        $this->assertSame(['CN-01', 'Shop B', '12 High St', 'Richmond', 'VIC', '3121', 'FBA-CN', 5, 32.5, 450, 350, 300, '电热水壶'], [
            $row['consignment_mark'], $row['deliver_to_name'], $row['deliver_to_address'], $row['deliver_to_suburb'], $row['deliver_to_state'],
            $row['deliver_to_postcode'], $row['fba_reference'], $row['carton_qty'], $row['actual_weight_kg'], $row['length_mm'], $row['width_mm'], $row['height_mm'], $row['description_cn'],
        ]);
        $this->assertNull($row['unit_qty'], '件数 counted the cartons because there was no 箱数 column');

        $serial = $this->parse('headers_serial_no.csv');
        $this->assertSame([], $serial['errors']);
        $this->assertSame([10, 3], array_column($serial['rows'], 'carton_qty'), '"No." is the serial number here, 箱数 is the carton count');
        $this->assertSame([200, 150], array_column($serial['rows'], 'unit_qty'), '数量 stays a unit count when 箱数 exists');
    }

    public function test_a_unit_weight_column_is_multiplied_by_cartons_into_the_line_total_and_says_so(): void
    {
        $parsed = $this->parse('unit_weight.csv');

        $this->assertSame([], $parsed['errors']);
        $this->assertSame(85.0, $parsed['rows'][0]['actual_weight_kg']);
        $this->assertSame('actual_weight_kg', $parsed['warnings'][0]['column']);
        $this->assertStringContainsString('「单件重量(KG)」是单件重量，已按 单件重量 × 箱数 换算', $parsed['warnings'][0]['message']);
    }

    public function test_carton_quantities_accept_unit_words_and_refuse_decimals_or_letters(): void
    {
        $parsed = $this->parse('qty.csv');
        $rows = collect($parsed['rows'])->keyBy('consignment_mark');

        $this->assertSame(10, $rows['QTY-01']['carton_qty']);
        $this->assertSame(3, $rows['QTY-04']['carton_qty']);
        $errors = collect($parsed['errors'])->keyBy('row');
        $this->assertSame([3, 4], $errors->keys()->all());
        $this->assertStringContainsString('第 3 行「箱数」必须是整数（现为“10.5”）', $errors[3]['message']);
        $this->assertStringContainsString('第 4 行「箱数」必须是大于 0 的数字（现为“abc”）', $errors[4]['message']);
    }

    public function test_title_rows_above_the_header_are_skipped_and_row_numbers_stay_the_sheets_own(): void
    {
        $parsed = $this->parse('title_rows.csv');

        $this->assertSame([], $parsed['errors']);
        $this->assertSame([4, 5], array_column($parsed['rows'], 'row'));
        $this->assertSame(['TR-01', 'TR-02'], array_column($parsed['rows'], 'consignment_mark'));
    }

    public function test_identical_rows_are_flagged_as_duplicates_but_still_read(): void
    {
        $parsed = $this->parse('duplicate_rows.csv');

        $this->assertCount(2, $parsed['rows']);
        $this->assertStringContainsString('第 3 行与第 2 行内容完全相同', implode(' ', $this->messages($parsed['warnings'])));
    }

    public function test_a_legacy_xls_gets_the_save_as_message_while_a_renamed_csv_still_parses(): void
    {
        $legacy = $this->parse('legacy.xls');
        $this->assertSame([], $legacy['rows']);
        $this->assertStringContainsString('旧版 XLS 文件无法读取', $legacy['errors'][0]['message']);
        $this->assertStringContainsString('另存为', $legacy['errors'][0]['message']);

        $renamed = $this->parse('renamed.xls');
        $this->assertSame([], $renamed['errors']);
        $this->assertSame('XLS-01', $renamed['rows'][0]['consignment_mark']);
    }

    public function test_requested_date_and_service_level_columns_are_read_in_every_common_spelling(): void
    {
        $parsed = $this->parse('dates.csv');
        $rows = collect($parsed['rows'])->keyBy('consignment_mark');

        foreach (['DT-01', 'DT-02', 'DT-03', 'DT-04', 'DT-05'] as $mark) {
            $this->assertSame('2026-12-01', $rows[$mark]['requested_date'], $mark);
        }
        $this->assertSame('express', $rows['DT-01']['service_level']);
        $this->assertNull($rows['DT-02']['service_level']);
        $errors = collect($parsed['errors'])->keyBy('row');
        $this->assertSame([7, 8], $errors->keys()->all());
        $this->assertStringContainsString('第 7 行「要求送达日」不是有效日期（现为“soon”）', $errors[7]['message']);
        $this->assertStringContainsString('第 8 行「服务等级」无法识别（现为“overnight”）', $errors[8]['message']);
    }

    public function test_messages_never_leak_a_lang_key(): void
    {
        foreach (['phone.csv', 'postcode.csv', 'state.csv', 'qty.csv', 'dates.csv', 'legacy.xls'] as $fixture) {
            $parsed = $this->parse($fixture);
            foreach (array_merge($parsed['errors'], $parsed['warnings']) as $entry) {
                $this->assertDoesNotMatchRegularExpression('/orders\.[a-z_.]+/', $entry['message'], "{$fixture}: {$entry['message']}");
                $this->assertNotSame('', $entry['label']);
            }
        }
    }
}
