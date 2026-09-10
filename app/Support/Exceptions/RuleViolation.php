<?php

namespace App\Support\Exceptions;

use InvalidArgumentException;
use Throwable;

/**
 * A business-rule refusal a person will read on a page. The English message stays the developer-facing text (logs and
 * service tests match on it); `userMessage()` renders the same refusal from lang/zh so controllers never echo raw English
 * into the Chinese UI (audit 2026-09-10). Services keep throwing InvalidArgumentException-compatible exceptions.
 */
class RuleViolation extends InvalidArgumentException
{
    /** @param array<string, string|int|float> $replace */
    public function __construct(string $message, private readonly string $langKey, private readonly array $replace = [])
    {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return __($this->langKey, $this->replace);
    }

    /** What to show a person for a service refusal: the translated text when there is one, the raw message otherwise. */
    public static function display(Throwable $e): string
    {
        return $e instanceof self ? $e->userMessage() : $e->getMessage();
    }
}
