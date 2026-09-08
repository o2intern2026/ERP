<?php

namespace App\Modules\Orders\Services;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * A12: best-effort plain text out of an uploaded purchase order — PDF (pdftotext when on PATH, else a naive reader of the
 * PDF's text operators), .eml (decoded text part) or .txt. Accuracy is not acceptance; a person confirms the draft.
 */
final class DraftOrderTextExtractor
{
    public function extract(string $path, string $extension): string
    {
        $text = match (strtolower($extension)) {
            'pdf' => $this->fromPdf($path),
            'eml' => $this->fromEmail((string) file_get_contents($path)),
            default => (string) file_get_contents($path),
        };

        return trim(preg_replace("/[ \t]+/", ' ', str_replace(["\r\n", "\r"], "\n", $text)) ?? '');
    }

    private function fromPdf(string $path): string
    {
        $binary = (new ExecutableFinder)->find('pdftotext');
        if ($binary !== null) {
            try {
                $process = new Process([$binary, '-layout', '-enc', 'UTF-8', $path, '-']);
                $process->setTimeout(20)->run();
                if ($process->isSuccessful() && trim($process->getOutput()) !== '') {
                    return $process->getOutput();
                }
            } catch (Throwable) {
                // fall through to the naive reader
            }
        }

        return $this->naivePdfText((string) file_get_contents($path));
    }

    /** Reads the strings of Tj / TJ text operators in document order, inflating FlateDecode streams where possible. */
    private function naivePdfText(string $raw): string
    {
        $chunks = [];
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams)) {
            foreach ($streams[1] as $stream) {
                $inflated = @gzuncompress($stream);
                if ($inflated === false) {
                    $inflated = @gzinflate($stream);
                }
                $chunks[] = $inflated !== false ? $inflated : $stream;
            }
        } else {
            $chunks[] = $raw;
        }

        $lines = [];
        foreach ($chunks as $chunk) {
            if (! preg_match_all('/\[((?:[^\]\\\\]|\\\\.)*)\]\s*TJ|\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/s', $chunk, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                if (isset($match[2])) {
                    $lines[] = $this->unescape($match[2]);
                } else {
                    preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/s', $match[1], $parts);
                    $lines[] = implode('', array_map([$this, 'unescape'], $parts[1]));
                }
            }
        }

        return implode("\n", array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
    }

    private function unescape(string $pdfString): string
    {
        return stripcslashes($pdfString);
    }

    /** Headers are skipped; the first text/plain part is decoded (quoted-printable / base64); HTML is stripped as a last resort. */
    private function fromEmail(string $raw): string
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        [$headers, $body] = array_pad(explode("\n\n", $raw, 2), 2, '');

        if (preg_match('/boundary="?([^";\n]+)"?/i', $headers, $m)) {
            $parts = array_slice(explode('--'.trim($m[1]), $body), 1);
            $plain = null;
            $html = null;
            foreach ($parts as $part) {
                [$partHeaders, $partBody] = array_pad(explode("\n\n", ltrim($part, "\n"), 2), 2, '');
                $decoded = $this->decodeBody($partBody, $partHeaders);
                if (stripos($partHeaders, 'text/plain') !== false) {
                    $plain ??= $decoded;
                } elseif (stripos($partHeaders, 'text/html') !== false) {
                    $html ??= $decoded;
                }
            }
            $text = $plain ?? ($html !== null ? html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>|<\/div>|<\/tr>/i', "\n", $html) ?? $html)) : '');
        } else {
            $text = $this->decodeBody($body, $headers);
            if (stripos($headers, 'text/html') !== false) {
                $text = html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>|<\/div>|<\/tr>/i', "\n", $text) ?? $text));
            }
        }

        $subject = preg_match('/^Subject:\s*(.+)$/mi', $headers, $s) ? $this->decodeHeader(trim($s[1])) : '';

        return trim($subject."\n".$text);
    }

    private function decodeBody(string $body, string $headers): string
    {
        if (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $headers)) {
            return quoted_printable_decode($body);
        }
        if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $headers)) {
            return (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', true);
        }

        return $body;
    }

    private function decodeHeader(string $value): string
    {
        return function_exists('iconv_mime_decode') ? (iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $value) : $value;
    }
}
