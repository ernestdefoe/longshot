<?php

namespace ErnestDefoe\Longshot\Feed;

/** A team as a provider reports it. */
final class Side
{
    /**
     * @param list<string> $aliases other names the same provider uses for it ("Texas Tech" for "Texas Tech Red Raiders")
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $abbreviation = null,
        public readonly ?string $color = null,
        public readonly ?string $altColor = null,
        public readonly ?string $logo = null,
        public readonly ?string $id = null,
        public readonly array $aliases = [],
    ) {
    }

    /** "Manchester United F.C." and "manchester united" are the same key. */
    public static function key(string $name): string
    {
        $name = str_replace(['.', "'", '&'], ['', '', ' and '], strtolower(\Illuminate\Support\Str::ascii($name)));
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? '';
        $name = preg_replace('/\b(fc|cf|afc|sc|ac|club|the)\b/', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    /** A colour as #rrggbb, or null for anything else. */
    public static function color(mixed $value): ?string
    {
        $value = ltrim(trim((string) $value), '#');

        return preg_match('/^[0-9a-f]{6}$/i', $value) ? '#'.strtolower($value) : null;
    }
}
