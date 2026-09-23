<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Folds project names for alias matching (GigaPlan ≈ Гигаплан).
 * Cyrillic → Latin transliteration + strip punctuation/spaces.
 */
final class ProjectNameNormalizer
{
    /**
     * @var array<string, string>
     */
    private const CYR_TO_LAT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    public static function fold(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $out = '';
        $len = mb_strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($value, $i, 1);
            if (isset(self::CYR_TO_LAT[$ch])) {
                $out .= self::CYR_TO_LAT[$ch];
                continue;
            }
            if (preg_match('/[a-z0-9]/u', $ch) === 1) {
                $out .= $ch;
            }
        }

        return $out;
    }

    public static function equals(string $a, string $b): bool
    {
        $fa = self::fold($a);
        $fb = self::fold($b);

        return $fa !== '' && $fa === $fb;
    }

    /**
     * Whether folded names match exactly or one is a meaningful prefix of the other.
     */
    public static function matches(string $a, string $b, int $minPrefixLength = 4): bool
    {
        $fa = self::fold($a);
        $fb = self::fold($b);
        if ($fa === '' || $fb === '') {
            return false;
        }
        if ($fa === $fb) {
            return true;
        }
        if (mb_strlen($fa) < $minPrefixLength || mb_strlen($fb) < $minPrefixLength) {
            return false;
        }

        return str_starts_with($fa, $fb) || str_starts_with($fb, $fa);
    }
}
