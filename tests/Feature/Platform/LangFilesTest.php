<?php

namespace Tests\Feature\Platform;

use Tests\TestCase;

/**
 * i18n/zh sweep review: `php -l` accepts a PHP array literal that repeats a key — the later value silently wins and every string under
 * the earlier one disappears (lang/zh/warehouse.php once carried two 'errors' arrays under 'outbound', so eight refusals rendered as
 * raw keys). Lint every lang file with the tokenizer, so a duplicate fails the suite instead of reaching the page.
 */
class LangFilesTest extends TestCase
{
    public function test_no_lang_file_repeats_a_key_inside_one_array_literal(): void
    {
        $files = glob(base_path('lang/zh/*.php')) ?: [];
        $this->assertNotEmpty($files);

        $problems = [];
        foreach ($files as $file) {
            foreach ($this->duplicateKeys($file) as $problem) {
                $problems[] = basename($file).': '.$problem;
            }
        }

        $this->assertSame([], $problems, "Duplicate keys in lang files:\n".implode("\n", $problems));
    }

    public function test_every_lang_file_returns_an_array(): void
    {
        foreach (glob(base_path('lang/zh/*.php')) ?: [] as $file) {
            $this->assertIsArray(require $file, basename($file));
        }
    }

    /**
     * Walks the token stream, keeping one set of seen string keys per open array literal ([...] or array(...)).
     *
     * @return list<string> "key (line a and line b)"
     */
    private function duplicateKeys(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $count = count($tokens);
        $arrays = [];   // stack of seen keys, one entry per open array literal
        $parens = [];   // stack of booleans: does this '(' open an array(...) literal?
        $problems = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '[') {
                $arrays[] = [];

                continue;
            }
            if ($token === ']') {
                array_pop($arrays);

                continue;
            }
            if ($token === '(') {
                $j = $i - 1;
                while ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j--;
                }
                $isArray = $j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_ARRAY;
                $parens[] = $isArray;
                if ($isArray) {
                    $arrays[] = [];
                }

                continue;
            }
            if ($token === ')') {
                if (array_pop($parens)) {
                    array_pop($arrays);
                }

                continue;
            }
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING || $arrays === []) {
                continue;
            }

            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $count || ! is_array($tokens[$j]) || $tokens[$j][0] !== T_DOUBLE_ARROW) {
                continue; // a value, not a key
            }

            $key = substr($token[1], 1, -1);
            $top = count($arrays) - 1;
            if (isset($arrays[$top][$key])) {
                $problems[] = sprintf("'%s' (line %d and line %d)", $key, $arrays[$top][$key], $token[2]);
            } else {
                $arrays[$top][$key] = $token[2];
            }
        }

        return $problems;
    }
}
