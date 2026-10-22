<?php

namespace App\Support;

class Slug
{
    /**
     * Match old_site/lib/data.js slug helper.
     */
    public static function slug(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KD);
            if ($normalized === false) {
                $normalized = $value;
            }
        } else {
            $normalized = $value;
        }

        $ascii = preg_replace('/[\x{0300}-\x{036f}]/u', '', $normalized) ?? $normalized;
        $lower = mb_strtolower($ascii, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9]+/', '-', $lower) ?? $lower;
        $slug = trim($slug, '-');
        $slug = mb_substr($slug, 0, 80, 'UTF-8');

        return $slug !== '' ? $slug : 'item';
    }

    /**
     * @param  callable(string): bool  $existsCallback
     */
    public static function uniqueSlugForUser(int $userId, string $base, callable $existsCallback): string
    {
        unset($userId);

        $slug = self::slug($base);
        if (! $existsCallback($slug)) {
            return $slug;
        }

        $n = 2;
        while ($existsCallback("{$slug}-{$n}")) {
            $n++;
        }

        return "{$slug}-{$n}";
    }
}
