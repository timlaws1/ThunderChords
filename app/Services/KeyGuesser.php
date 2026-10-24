<?php

namespace App\Services;

/**
 * Port of guessKey / chordsIn essentials from old_site/public/music.js + chords.js.
 */
class KeyGuesser
{
    private const CHORD = '/^([A-G][#b]?)((?:maj|min|m|M|dim|aug|sus|add|no|[0-9+°ø\-#b()])*)(?:\/([A-G][#b]?))?$/';

    private const NEUTRAL = '/^(?:[|\/\-]+|\(?x\d+\)?|N\.?C\.?|%|[:.|]+)$/i';

    private const TAB = '/^\s*[a-gA-G][#b]?\s*[|:]\s*[-\dhpbrx\/\\\\~^()|*.\s<>]{3,}$/';

    private const SECTION = '/^\s*\[[^\]]+\]\s*$/';

    /** @var array<string, int> */
    private const IDX = [
        'C' => 0, 'C#' => 1, 'Db' => 1, 'D' => 2, 'D#' => 3, 'Eb' => 3, 'E' => 4, 'Fb' => 4,
        'E#' => 5, 'F' => 5, 'F#' => 6, 'Gb' => 6, 'G' => 7, 'G#' => 8, 'Ab' => 8, 'A' => 9,
        'A#' => 10, 'Bb' => 10, 'B' => 11, 'Cb' => 11, 'B#' => 0,
    ];

    /** @var list<string> */
    private const TUNINGS = [
        'EADGBE', 'DADGBE', 'DADGAD', 'DGDGBD', 'DADF#AD', 'EbAbDbGbBbEb', 'CGCFAD', 'DGCFAD',
    ];

    /**
     * @return array{key: string, guessed: bool}|null
     */
    public function guessKey(string $body, string $key): ?array
    {
        if ($this->keyInfo($key) !== null) {
            return ['key' => $key, 'guessed' => false];
        }
        $chords = $this->chordsIn($body);
        $first = $chords[0] ?? null;
        if ($first === null || ! preg_match(self::CHORD, $first, $m)) {
            return null;
        }
        $minor = (bool) preg_match('/^(m|min)(?!aj)/', $m[2]);

        return ['key' => $m[1].($minor ? 'm' : ''), 'guessed' => true];
    }

    /**
     * Apply guess to a parsed import when key is empty.
     *
     * @param  array{title?: string, artist?: string, key?: string, capo?: int, body: string}  $data
     * @return array{title?: string, artist?: string, key: string, capo: int, body: string, keyGuessed: bool}
     */
    public function withGuessedKey(array $data): array
    {
        $key = trim((string) ($data['key'] ?? ''));
        $guess = $this->guessKey($data['body'], $key);
        if ($guess === null) {
            return array_merge($data, ['key' => $key, 'keyGuessed' => false]);
        }

        return array_merge($data, [
            'key' => $guess['key'],
            'keyGuessed' => $guess['guessed'],
        ]);
    }

    /**
     * @return array{root: int, minor: bool}|null
     */
    public function keyInfo(string $key): ?array
    {
        if (! preg_match(self::CHORD, trim($key), $m)) {
            return null;
        }
        if (! isset(self::IDX[$m[1]])) {
            return null;
        }

        return [
            'root' => self::IDX[$m[1]],
            'minor' => (bool) preg_match('/^(m|min)(?!aj)/', $m[2]),
        ];
    }

    /**
     * Distance on the circle of fifths, from 0 to 6. Relative major and minor share a position.
     */
    public function fifthsDistance(string $from, string $to): ?int
    {
        $a = $this->fifthsPosition($from);
        $b = $this->fifthsPosition($to);
        if ($a === null || $b === null) {
            return null;
        }
        $delta = abs($a - $b);

        return min($delta, 12 - $delta);
    }

    private function fifthsPosition(string $key): ?int
    {
        $info = $this->keyInfo($key);
        if ($info === null) {
            return null;
        }
        $root = $info['minor'] ? ($info['root'] + 3) % 12 : $info['root'];

        return ($root * 7) % 12;
    }

    /**
     * @return list<string>
     */
    public function chordsIn(string $text): array
    {
        $lines = explode("\n", $text);
        $types = $this->classify($lines);
        $out = [];
        foreach ($lines as $i => $line) {
            if (($types[$i] ?? '') !== 'chord') {
                continue;
            }
            foreach (preg_split('/\s+/', trim($line)) ?: [] as $tok) {
                if ($tok !== '' && preg_match(self::CHORD, $tok)) {
                    $out[] = $tok;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function classify(array $lines): array
    {
        $types = [];
        foreach ($lines as $i => $l) {
            $t = trim($l);
            if ($t === '') {
                $type = 'blank';
            } elseif (preg_match('/^===.+===$/', $t)) {
                $type = 'divider';
            } elseif (preg_match(self::SECTION, $l)) {
                $type = 'section';
            } elseif (preg_match(self::TAB, $l)) {
                $type = 'tab';
            } elseif (str_starts_with($t, '\\')) {
                $type = 'lyric';
            } elseif ($this->isTuningLine($l)) {
                $type = 'tuning';
            } elseif ($this->isChordLine($l)) {
                $type = ($t === 'A' && ($types[$i - 1] ?? '') === 'chord') ? 'lyric' : 'chord';
            } else {
                $type = 'lyric';
            }
            $types[] = $type;
        }

        return $types;
    }

    private function isChordLine(string $line): bool
    {
        $toks = array_values(array_filter(preg_split('/\s+/', trim($line)) ?: []));
        $chords = 0;
        foreach ($toks as $t) {
            if (preg_match(self::CHORD, $t)) {
                $chords++;
            } elseif (! preg_match(self::NEUTRAL, $t)) {
                return false;
            }
        }

        return $chords > 0;
    }

    private function isTuningLine(string $line): bool
    {
        $t = preg_split('/\s+/', trim($line)) ?: [];

        return count($t) >= 5 && in_array(implode('', $t), self::TUNINGS, true);
    }
}
