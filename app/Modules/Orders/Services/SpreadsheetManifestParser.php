<?php

namespace App\Modules\Orders\Services;

use App\Support\Contracts\ManifestParser;
use App\Support\Enums;
use InvalidArgumentException;
use SimpleXMLElement;
use ZipArchive;

/** Reads the shared dispatch manifest without adding a spreadsheet package to the frozen dependency set. */
final class SpreadsheetManifestParser implements ManifestParser
{
    private const MAX_ROWS = 5000;

    /** @var array<string, string> */
    private const HEADERS = [
        'mark' => 'consignment_mark',
        'marks' => 'consignment_mark',
        '唛头' => 'consignment_mark',
        'chinesename' => 'description_cn',
        '中文品名' => 'description_cn',
        'englishname' => 'description_en',
        '英文品名' => 'description_en',
        'hscode' => 'hs_code',
        '海关编码' => 'hs_code',
        'material' => 'material',
        '材质' => 'material',
        'use' => 'usage',
        '用途' => 'usage',
        'brand' => 'brand',
        '品牌' => 'brand',
        'typeofpackaging' => 'package_type',
        '外包装种类' => 'package_type',
        '包装类型' => 'package_type',
        'no' => 'carton_qty',
        'cartonqty' => 'carton_qty',
        '箱数' => 'carton_qty',
        'productquantity' => 'unit_qty',
        '产品数量' => 'unit_qty',
        'unitpriceaud' => 'unit_price_cents',
        '单价澳元' => 'unit_price_cents',
        'totalpriceaud' => 'total_price_cents',
        '总价澳元' => 'total_price_cents',
        'weightkg' => 'actual_weight_kg',
        '实重kg' => 'actual_weight_kg',
        'lengthcm' => 'length_mm',
        'lengthmm' => 'length_mm',
        '长cm' => 'length_mm',
        '长mm' => 'length_mm',
        'widthcm' => 'width_mm',
        'widthmm' => 'width_mm',
        '宽cm' => 'width_mm',
        '宽mm' => 'width_mm',
        'heightcm' => 'height_mm',
        'heightmm' => 'height_mm',
        '高cm' => 'height_mm',
        '高mm' => 'height_mm',
        'totalm³' => 'cbm',
        'totalm3' => 'cbm',
        '总方数' => 'cbm',
        'consigneename' => 'deliver_to_name',
        '收件人公司名人名' => 'deliver_to_name',
        'address' => 'deliver_to_address',
        '收件人地址' => 'deliver_to_address',
        'contact' => 'deliver_to_phone',
        '联系方式' => 'deliver_to_phone',
        'state' => 'deliver_to_state',
        '州' => 'deliver_to_state',
        'postcode' => 'deliver_to_postcode',
        '邮编' => 'deliver_to_postcode',
        'fbareference' => 'fba_reference',
        'fbashipmentid' => 'fba_reference',
        'desc' => 'fba_reference',
        '备注' => 'fba_reference',
        'externalref' => 'external_ref',
        '客户参考号' => 'external_ref',
    ];

    public function parse(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Manifest file does not exist: {$path}");
        }

        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
        try {
            $matrix = match ($extension) {
                'csv' => $this->readCsv($path),
                'xlsx' => $this->readXlsx($path),
                default => null,
            };
        } catch (InvalidArgumentException) {
            return [
                'rows' => [],
                'errors' => [['row' => 0, 'column' => 'file', 'message' => __('orders.imports.errors.corrupted_file')]],
                'warnings' => [],
                'raw_rows' => [],
            ];
        }

        if ($matrix === null) {
            return [
                'rows' => [],
                'errors' => [['row' => 0, 'column' => 'file', 'message' => __('orders.imports.errors.unsupported_file')]],
                'warnings' => [],
                'raw_rows' => [],
            ];
        }

        return $this->normalise($matrix);
    }

    /** @return list<list<string|null>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException("Manifest file cannot be opened: {$path}");
        }

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_map(fn ($value) => $this->clean((string) $value), $row);
            if (count($rows) > self::MAX_ROWS + 10) {
                break;
            }
        }
        fclose($handle);

        if (isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $rows[0][0]);
        }

        return $rows;
    }

    /** @return list<list<string|null>> */
    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException("Manifest workbook cannot be opened: {$path}");
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new InvalidArgumentException('Manifest workbook has no first worksheet.');
        }

        $shared = $sharedXml === false ? [] : $this->sharedStrings($sharedXml);
        $sheet = new SimpleXMLElement($sheetXml);
        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rows = [];

        foreach ($sheet->children($namespace)->sheetData->row as $row) {
            $values = [];
            foreach ($row->children($namespace)->c as $cell) {
                $attributes = $cell->attributes();
                $reference = (string) $attributes->r;
                preg_match('/^[A-Z]+/', $reference, $match);
                $index = $this->columnIndex($match[0] ?? 'A');
                $type = (string) $attributes->t;

                if ($type === 'inlineStr') {
                    $parts = $cell->xpath('.//*[local-name()="t"]') ?: [];
                    $value = implode('', array_map(fn ($part) => (string) $part, $parts));
                } elseif ($type === 's') {
                    $value = $shared[(int) $cell->children($namespace)->v] ?? null;
                } else {
                    $value = isset($cell->children($namespace)->v) ? (string) $cell->children($namespace)->v : null;
                }

                $values[$index] = $this->clean($value);
            }

            if ($values !== []) {
                $last = max(array_keys($values));
                $rows[] = array_map(fn ($index) => $values[$index] ?? null, range(0, $last));
            }
            if (count($rows) > self::MAX_ROWS + 10) {
                break;
            }
        }

        return $rows;
    }

    /** @return list<string> */
    private function sharedStrings(string $xml): array
    {
        $root = new SimpleXMLElement($xml);
        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $values = [];
        foreach ($root->children($namespace)->si as $item) {
            $parts = $item->xpath('.//*[local-name()="t"]') ?: [];
            $values[] = implode('', array_map(fn ($part) => (string) $part, $parts));
        }

        return $values;
    }

    /**
     * @param  list<list<string|null>>  $matrix
     * @return array<string, mixed>
     */
    private function normalise(array $matrix): array
    {
        [$headerIndex, $columns] = $this->locateHeaders($matrix);
        if ($headerIndex === null || ! isset($columns['consignment_mark'], $columns['carton_qty'])) {
            return [
                'rows' => [],
                'errors' => [['row' => 0, 'column' => 'header', 'message' => __('orders.imports.errors.headers_missing')]],
                'warnings' => [],
                'raw_rows' => [],
            ];
        }

        $headers = $matrix[$headerIndex];
        $rows = [];
        $errors = [];
        $rawRows = [];
        $carry = [];

        foreach (array_slice($matrix, $headerIndex + 1, self::MAX_ROWS, true) as $matrixIndex => $cells) {
            if ($this->isEmpty($cells) || $this->looksLikeHeader($cells)) {
                continue;
            }

            $rowNumber = $matrixIndex + 1;
            $raw = [];
            foreach ($cells as $index => $value) {
                $name = trim((string) ($headers[$index] ?? '')) ?: $this->columnName($index);
                $raw[$name] = $value;
            }
            $rawRows[] = ['row' => $rowNumber, 'raw_json' => $raw];

            $mapped = [];
            foreach ($columns as $field => $index) {
                $mapped[$field] = $this->clean($cells[$index] ?? null);
            }

            if (filled($mapped['consignment_mark'] ?? null)
                && isset($carry['consignment_mark'])
                && mb_strtolower((string) $mapped['consignment_mark']) !== mb_strtolower((string) $carry['consignment_mark'])) {
                foreach (['deliver_to_name', 'deliver_to_phone', 'deliver_to_address', 'deliver_to_state', 'deliver_to_postcode', 'fba_reference'] as $field) {
                    unset($carry[$field]);
                }
            }

            foreach (['consignment_mark', 'deliver_to_name', 'deliver_to_phone', 'deliver_to_address', 'deliver_to_state', 'deliver_to_postcode', 'fba_reference'] as $field) {
                if (filled($mapped[$field] ?? null)) {
                    $carry[$field] = $mapped[$field];
                } elseif (array_key_exists($field, $carry)) {
                    $mapped[$field] = $carry[$field];
                }
            }

            [$state, $postcode, $suburb] = $this->addressParts(
                $mapped['deliver_to_address'] ?? null,
                $mapped['deliver_to_state'] ?? null,
                $mapped['deliver_to_postcode'] ?? null,
            );
            $mapped['deliver_to_state'] = $state;
            $mapped['deliver_to_postcode'] = $postcode;

            $rowErrors = $this->validateRow($rowNumber, $mapped, $raw);
            if ($rowErrors !== []) {
                array_push($errors, ...$rowErrors);

                continue;
            }

            $lengthMm = $this->dimension($mapped['length_mm'] ?? null, $headers[$columns['length_mm'] ?? -1] ?? '');
            $widthMm = $this->dimension($mapped['width_mm'] ?? null, $headers[$columns['width_mm'] ?? -1] ?? '');
            $heightMm = $this->dimension($mapped['height_mm'] ?? null, $headers[$columns['height_mm'] ?? -1] ?? '');
            $cartons = (int) $mapped['carton_qty'];
            $cbm = $this->decimal($mapped['cbm'] ?? null);
            if ($cbm === null && $lengthMm && $widthMm && $heightMm) {
                $cbm = round(($lengthMm * $widthMm * $heightMm * $cartons) / 1_000_000_000, 4);
            }

            $rows[] = [
                'row' => $rowNumber,
                'consignment_mark' => (string) $mapped['consignment_mark'],
                'description_cn' => $mapped['description_cn'] ?? null,
                'description_en' => $mapped['description_en'] ?? null,
                'hs_code' => $mapped['hs_code'] ?? null,
                'material' => $mapped['material'] ?? null,
                'usage' => $mapped['usage'] ?? null,
                'brand' => $mapped['brand'] ?? null,
                'package_type' => ($mapped['package_type'] ?? null) ?: 'carton',
                'carton_qty' => $cartons,
                'unit_qty' => $this->integer($mapped['unit_qty'] ?? null),
                'unit_price_cents' => $this->money($mapped['unit_price_cents'] ?? null),
                'total_price_cents' => $this->money($mapped['total_price_cents'] ?? null),
                'actual_weight_kg' => $this->decimal($mapped['actual_weight_kg'] ?? null),
                'length_mm' => $lengthMm,
                'width_mm' => $widthMm,
                'height_mm' => $heightMm,
                'cbm' => $cbm,
                'deliver_to_name' => $mapped['deliver_to_name'] ?? null,
                'deliver_to_phone' => $mapped['deliver_to_phone'] ?? null,
                'deliver_to_address' => $mapped['deliver_to_address'] ?? null,
                'deliver_to_suburb' => $suburb,
                'deliver_to_state' => $state,
                'deliver_to_postcode' => $postcode,
                'fba_reference' => $mapped['fba_reference'] ?? null,
                'external_ref' => $mapped['external_ref'] ?? null,
                'raw_json' => $raw,
            ];
        }

        return [
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => $this->consistencyWarnings($rows),
            'raw_rows' => $rawRows,
        ];
    }

    /** @param list<list<string|null>> $matrix */
    private function locateHeaders(array $matrix): array
    {
        $bestIndex = null;
        $best = [];
        foreach (array_slice($matrix, 0, 10, true) as $index => $row) {
            $mapped = [];
            foreach ($row as $column => $header) {
                $field = self::HEADERS[$this->normaliseHeader($header)] ?? null;
                if ($field !== null && ! isset($mapped[$field])) {
                    $mapped[$field] = $column;
                }
            }
            if (count($mapped) >= count($best)) {
                $bestIndex = $index;
                $best = $mapped;
            }
        }

        return [$bestIndex, $best];
    }

    /** @param list<string|null> $cells */
    private function looksLikeHeader(array $cells): bool
    {
        return collect($cells)->filter(fn ($value) => isset(self::HEADERS[$this->normaliseHeader($value)]))->count() >= 3;
    }

    /** @return list<array<string, mixed>> */
    private function validateRow(int $rowNumber, array $mapped, array $raw): array
    {
        $errors = [];
        foreach (['consignment_mark', 'deliver_to_name', 'deliver_to_address', 'deliver_to_state', 'deliver_to_postcode'] as $field) {
            if (! filled($mapped[$field] ?? null)) {
                $errors[] = ['row' => $rowNumber, 'column' => $field, 'message' => __('orders.imports.errors.required', ['field' => __('orders.fields.'.$field)]), 'raw_json' => $raw];
            }
        }

        if (! $this->positiveNumber($mapped['carton_qty'] ?? null)) {
            $errors[] = ['row' => $rowNumber, 'column' => 'carton_qty', 'message' => __('orders.imports.errors.positive_number', ['field' => __('orders.fields.carton_qty')]), 'raw_json' => $raw];
        }
        if (! $this->positiveNumber($mapped['actual_weight_kg'] ?? null)) {
            $errors[] = ['row' => $rowNumber, 'column' => 'actual_weight_kg', 'message' => __('orders.imports.errors.positive_number', ['field' => __('orders.fields.weight_kg')]), 'raw_json' => $raw];
        }
        if (filled($mapped['deliver_to_state'] ?? null) && ! in_array(mb_strtoupper((string) $mapped['deliver_to_state']), Enums::STATES, true)) {
            $errors[] = ['row' => $rowNumber, 'column' => 'deliver_to_state', 'message' => __('orders.imports.errors.invalid_state'), 'raw_json' => $raw];
        }

        return $errors;
    }

    /** @param list<array<string, mixed>> $rows */
    private function consistencyWarnings(array $rows): array
    {
        $warnings = [];
        foreach (collect($rows)->groupBy('consignment_mark') as $mark => $group) {
            $signatures = $group->map(fn ($row) => $this->deliverySignature($row))->unique();
            if ($signatures->count() > 1) {
                $warnings[] = [
                    'row' => (int) $group->first()['row'],
                    'column' => 'consignment_mark',
                    'message' => __('orders.imports.errors.inconsistent_group', ['mark' => $mark]),
                ];
            }
        }

        return $warnings;
    }

    /** @return array{?string, ?string, ?string} */
    private function addressParts(?string $address, ?string $state, ?string $postcode): array
    {
        $address = $this->clean($address);
        $state = $this->clean($state);
        $postcode = $this->clean($postcode);
        $prefix = $address;

        if ($address !== null && preg_match('/\b(NSW|VIC|QLD|SA|WA|TAS|NT|ACT)\s*,?\s*(\d{4})\b/i', $address, $match, PREG_OFFSET_CAPTURE)) {
            $state ??= mb_strtoupper($match[1][0]);
            $postcode ??= $match[2][0];
            $prefix = trim(substr($address, 0, $match[0][1]), " ,\t\n\r\0\x0B");
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', (string) $prefix))));
        $suburb = $parts === [] ? null : end($parts);

        return [$state === null ? null : mb_strtoupper($state), $postcode, $suburb ?: null];
    }

    private function deliverySignature(array $row): string
    {
        return mb_strtolower(implode('|', array_map(fn ($value) => trim((string) $value), [
            $row['deliver_to_name'] ?? null,
            $row['deliver_to_address'] ?? null,
            $row['deliver_to_state'] ?? null,
            $row['deliver_to_postcode'] ?? null,
            $row['fba_reference'] ?? null,
        ])));
    }

    private function normaliseHeader(?string $header): string
    {
        $header = mb_strtolower(trim((string) $header));

        return preg_replace('/[\s_\-\/（）()\[\]*.:]+/u', '', $header) ?? $header;
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(str_replace("\r\n", "\n", $value));

        return $value === '' ? null : $value;
    }

    private function positiveNumber(?string $value): bool
    {
        return $value !== null && is_numeric(str_replace(',', '', $value)) && (float) str_replace(',', '', $value) > 0;
    }

    private function integer(?string $value): ?int
    {
        return $value === null || ! is_numeric(str_replace(',', '', $value)) ? null : (int) round((float) str_replace(',', '', $value));
    }

    private function decimal(?string $value): ?float
    {
        return $value === null || ! is_numeric(str_replace(',', '', $value)) ? null : (float) str_replace(',', '', $value);
    }

    private function money(?string $value): ?int
    {
        $decimal = $this->decimal($value);

        return $decimal === null ? null : (int) round($decimal * 100);
    }

    private function dimension(?string $value, string $header): ?int
    {
        $decimal = $this->decimal($value);
        if ($decimal === null) {
            return null;
        }

        return (int) round($decimal * (str_contains($this->normaliseHeader($header), 'cm') ? 10 : 1));
    }

    /** @param list<string|null> $row */
    private function isEmpty(array $row): bool
    {
        return collect($row)->every(fn ($value) => ! filled($value));
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + ord($letter) - 64;
        }

        return $index - 1;
    }

    private function columnName(int $index): string
    {
        $name = '';
        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $name = chr((($number - 1) % 26) + 65).$name;
        }

        return $name;
    }
}
