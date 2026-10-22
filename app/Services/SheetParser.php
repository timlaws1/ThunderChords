<?php

namespace App\Services;

/**
 * Port of old_site/importer.js parseSheet / normalize / liftLine.
 */
class SheetParser
{
    private const JUNK = '/^\s*(advertisement|advertising|sponsored|print|transpose|autoscroll|chords:|tabs?:)\s*$/i';

    private const TAB = '/^\s*[a-gA-G][#b]?\s*[|:]\s*[-\dhpbrx\/\\\\~^()|*.\s<>]{3,}$/';

    private const SECTION = '/^\s*\[[^\]]+\]\s*$/';

    private const CHORD_TOK = '/^([A-G][#b]?)((?:maj|min|m|M|dim|aug|sus|add|no|[0-9+°ø\-#b()])*)(?:\/([A-G][#b]?))?$/';

    private const INLINE = '/\[ch\]([^\[\]]+)\[\/ch\]|\[([^\]]+)\]/i';

    /**
     * @return array{body: string, key: string, capo: int}
     */
    public function parseSheet(string $text): array
    {
        $cleaned = str_replace(["\r\n", "\r"], "\n", $text);
        $cleaned = str_replace("\u{00a0}", ' ', $cleaned);
        $cleaned = preg_replace('/\[\/?tab\]/i', '', $cleaned) ?? $cleaned;
        $cleaned = str_replace(["\u{266f}", "\u{266d}"], ['#', 'b'], $cleaned);

        $lifted = [];
        foreach (explode("\n", $cleaned) as $line) {
            if (preg_match(self::JUNK, $line)) {
                continue;
            }
            foreach ($this->liftLine($line) as $liftedLine) {
                $lifted[] = $liftedLine;
            }
        }

        ['key' => $key, 'capo' => $capo, 'lines' => $lines] = $this->extractHeaders($lifted);
        $body = implode("\n", $lines);
        $body = preg_replace('/[ \t]+$/m', '', $body) ?? $body;
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;
        $body = preg_replace("/^\n+|\n+$/", '', $body) ?? $body;
        $body .= "\n";

        return ['body' => $body, 'key' => $key, 'capo' => $capo];
    }

    public function normalize(string $text): string
    {
        return $this->parseSheet($text)['body'];
    }

    public function decodeEntities(string $s): string
    {
        $s = preg_replace_callback('/&#x([0-9a-f]+);/i', fn ($m) => mb_chr((int) hexdec($m[1]), 'UTF-8'), $s) ?? $s;
        $s = preg_replace_callback('/&#(\d+);/', fn ($m) => mb_chr((int) $m[1], 'UTF-8'), $s) ?? $s;

        return str_replace(
            ['&quot;', '&apos;', '&lt;', '&gt;', '&nbsp;', '&amp;'],
            ['"', "'", '<', '>', ' ', '&'],
            $s
        );
    }

    /**
     * @return list<string>
     */
    private function liftLine(string $line): array
    {
        if (preg_match(self::TAB, $line)) {
            return [$line];
        }
        if (preg_match(self::SECTION, $line)) {
            $inner = trim(substr(trim($line), 1, -1));

            return $this->isChordTok($inner) ? [$inner] : [$line];
        }

        $matches = [];
        if (preg_match_all(self::INLINE, $line, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $tok = trim($m[1][0] !== '' ? $m[1][0] : ($m[2][0] ?? ''));
                if (! $this->isChordTok($tok)) {
                    continue;
                }
                $matches[] = [
                    'start' => $m[0][1],
                    'end' => $m[0][1] + strlen($m[0][0]),
                    'chord' => $tok,
                ];
            }
        }
        if ($matches === []) {
            return [$line];
        }

        $lyric = '';
        $last = 0;
        $places = [];
        foreach ($matches as $mk) {
            $lyric .= substr($line, $last, $mk['start'] - $last);
            $places[] = ['chord' => $mk['chord'], 'col' => mb_strlen($lyric)];
            $last = $mk['end'];
        }
        $lyric .= substr($line, $last);

        if (trim($lyric) === '') {
            $only = '';
            $at = 0;
            foreach ($matches as $mk) {
                $only .= substr($line, $at, $mk['start'] - $at).$mk['chord'];
                $at = $mk['end'];
            }

            return [rtrim($only.substr($line, $at), " \t")];
        }

        $chords = '';
        foreach ($places as $p) {
            $at = mb_strlen($chords) > $p['col'] ? mb_strlen($chords) + 1 : $p['col'];
            $chords .= str_repeat(' ', max(0, $at - mb_strlen($chords))).$p['chord'];
        }

        return [$chords, rtrim($lyric, " \t")];
    }

    /**
     * @param  list<string>  $lines
     * @return array{key: string, capo: int, lines: list<string>}
     */
    private function extractHeaders(array $lines): array
    {
        $key = '';
        $capo = 0;
        $i = 0;
        $n = count($lines);
        while ($i < $n) {
            $l = $lines[$i];
            if (trim($l) === '') {
                $i++;

                continue;
            }
            if (preg_match('/^\s*key\s*:\s*(.+?)\s*$/i', $l, $km)) {
                $key = trim($km[1]);
                $i++;

                continue;
            }
            if (preg_match('/^\s*capo\s*:?\s*(\d+)\s*$/i', $l, $cm)) {
                $capo = (int) $cm[1];
                $i++;

                continue;
            }
            break;
        }

        return ['key' => $key, 'capo' => $capo, 'lines' => array_slice($lines, $i)];
    }

    private function isChordTok(string $s): bool
    {
        return (bool) preg_match(self::CHORD_TOK, trim($s));
    }
}
