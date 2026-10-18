<?php

declare(strict_types=1);

namespace Crab\Clockwerk;

/**
 * Keeps what looks like a secret off the dashboard: the values of fields
 * named like one, wherever they turn up again. A job pushed with a
 * reset token may fail with an error that quotes the address it called,
 * token and all, and hiding the field alone would hide nothing.
 */
final readonly class Secrets
{
    /**
     * @var list<string> Lower-case words; a field whose name contains one is a secret.
     */
    private array $words;

    /**
     * @param array<mixed> $words
     */
    public function __construct(array $words)
    {
        $this->words = array_values(array_filter(
            array_map(static fn(mixed $word): string => strtolower((string) $word), $words),
            static fn(string $word): bool => $word !== '',
        ));
    }

    /**
     * Data with the value of every secret field replaced, however deep.
     *
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    public function hidden(array $data): array
    {
        foreach ($data as $name => $value) {
            if ($this->names((string) $name)) {
                $data[$name] = '(hidden)';
            } elseif (is_array($value)) {
                $data[$name] = $this->hidden($value);
            }
        }
        return $data;
    }

    /**
     * Text with every secret taken out of it: the values the data's
     * secret fields hold, and whatever follows a secret's name as
     * "token=...", in an address or a header.
     *
     * @param array<int|string, mixed> $data The data whose secrets the text may quote.
     */
    public function scrubbed(string $text, array $data = []): string
    {
        foreach ($this->values($data) as $value) {
            $text = str_replace([$value, rawurlencode($value)], '(hidden)', $text);
        }
        if ($this->words !== []) {
            $names = implode('|', array_map(static fn(string $word): string => preg_quote($word, '~'), $this->words));
            $text = (string) preg_replace(
                '~([\w-]*(?:' . $names . ')[\w-]*)(=|:\s*|%3D)(?:Bearer\s+)?[^&\s"\'<>,;)]+~i',
                '$1$2(hidden)',
                $text,
            );
        }
        return $text;
    }

    /**
     * @param array<int|string, mixed> $data
     * @return list<string> The values of the secret fields, the ones long enough to be told from ordinary text.
     */
    private function values(array $data, bool $inside = false): array
    {
        $values = [];
        foreach ($data as $name => $value) {
            $secret = $inside || $this->names((string) $name);
            if (is_array($value)) {
                array_push($values, ...$this->values($value, $secret));
            } elseif ($secret && is_scalar($value) && strlen((string) $value) >= 4) {
                $values[] = (string) $value;
            }
        }
        return $values;
    }

    private function names(string $name): bool
    {
        $name = strtolower($name);
        foreach ($this->words as $word) {
            if (str_contains($name, $word)) {
                return true;
            }
        }
        return false;
    }
}
