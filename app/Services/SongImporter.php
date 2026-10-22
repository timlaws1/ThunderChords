<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Port of old_site/importer.js URL import with SSRF guards.
 */
class SongImporter
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    private const LOCAL_HOST = '/^(localhost|.*\.localhost|.*\.local|.*\.internal)$/i';

    public function __construct(
        private SheetParser $parser,
        private KeyGuesser $keys,
    ) {}

    /**
     * @return array{title: string, artist: string, key: string, capo: int, body: string, keyGuessed?: bool}
     */
    public function importFromUrl(string $url): array
    {
        $current = $this->assertSafeImportUrl($url);
        for ($hop = 0; $hop < 5; $hop++) {
            $response = Http::withHeaders([
                'User-Agent' => self::UA,
                'Accept' => 'text/html,*/*',
                'Accept-Language' => 'en-GB,en;q=0.9',
            ])
                ->withOptions(['allow_redirects' => false, 'timeout' => 20])
                ->get($current);

            $status = $response->status();
            if ($status >= 300 && $status < 400) {
                $loc = $response->header('Location');
                if (! $loc) {
                    throw new RuntimeException("The site answered {$status}. Use the paste option instead.");
                }
                $current = $this->assertSafeImportUrl((string) $this->resolveUrl($current, $loc));

                continue;
            }
            if (! $response->successful()) {
                throw new RuntimeException("The site answered {$status}. Use the paste option instead.");
            }
            $html = $response->body();
            $song = $this->ultimateGuitar($html) ?? $this->generic($html);
            if ($song === null) {
                throw new RuntimeException('Could not find a chord sheet on that page. Use the paste option instead.');
            }

            return $this->keys->withGuessedKey($song);
        }

        throw new RuntimeException('Too many redirects. Use the paste option instead.');
    }

    public function assertSafeImportUrl(string $urlString, ?bool $allowLocal = null): string
    {
        $allowLocal ??= (bool) config('chords.allow_local_import');

        $parts = parse_url($urlString);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('That is not a web address.');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new RuntimeException('Only http and https links can be imported.');
        }
        if (! empty($parts['user']) || ! empty($parts['pass'])) {
            throw new RuntimeException('That link is not allowed.');
        }

        $host = trim($parts['host'], '[]');
        if (! $allowLocal && (preg_match(self::LOCAL_HOST, $host) || $host === '0.0.0.0')) {
            throw new RuntimeException('That address cannot be imported. Use the paste option instead.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! $allowLocal && $this->isPrivateIp($host)) {
                throw new RuntimeException('That address cannot be imported. Use the paste option instead.');
            }

            return $this->buildHref($parts);
        }

        $addrs = @dns_get_record($host, DNS_A + DNS_AAAA);
        if ($addrs === false) {
            $addrs = [];
        }
        $ips = [];
        foreach ($addrs as $rec) {
            if (isset($rec['ip'])) {
                $ips[] = $rec['ip'];
            }
            if (isset($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }
        if ($ips === []) {
            $fallback = gethostbynamel($host) ?: [];
            $ips = $fallback;
        }
        if ($ips === []) {
            throw new RuntimeException('Could not look up that host. Use the paste option instead.');
        }
        if (! $allowLocal) {
            foreach ($ips as $ip) {
                if ($this->isPrivateIp($ip)) {
                    throw new RuntimeException('That address cannot be imported. Use the paste option instead.');
                }
            }
        }

        return $this->buildHref($parts);
    }

    /**
     * @return array{title: string, artist: string, key: string, capo: int, body: string}|null
     */
    public function ultimateGuitar(string $html): ?array
    {
        if (! preg_match('/<div[^>]*js-store[^>]*>/', $html, $tagMatch)) {
            return null;
        }
        if (! preg_match('/data-content="([^"]*)"/', $tagMatch[0], $rawMatch)) {
            return null;
        }
        $decoded = $this->parser->decodeEntities($rawMatch[1]);
        try {
            $json = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $data = $json['store']['page']['data'] ?? null;
        $content = $data['tab_view']['wiki_tab']['content'] ?? null;
        if (! is_string($content) || $content === '') {
            return null;
        }
        $t = $data['tab'] ?? [];
        $meta = $data['tab_view']['meta'] ?? [];
        $parsed = $this->parser->parseSheet($content);

        return [
            'title' => (string) ($t['song_name'] ?? ''),
            'artist' => (string) ($t['artist_name'] ?? ''),
            'key' => (string) ($meta['tonality'] ?? $t['tonality_name'] ?? ($parsed['key'] !== '' ? $parsed['key'] : '')),
            'capo' => (int) ($meta['capo'] ?? $t['capo'] ?? $parsed['capo'] ?? 0),
            'body' => $parsed['body'],
        ];
    }

    /**
     * @return array{title: string, artist: string, key: string, capo: int, body: string}|null
     */
    public function generic(string $html): ?array
    {
        if (! preg_match_all('/<pre[^>]*>([\s\S]*?)<\/pre>/i', $html, $matches)) {
            return null;
        }
        $blocks = $matches[1];
        usort($blocks, fn ($a, $b) => strlen($b) <=> strlen($a));
        $best = $blocks[0] ?? null;
        if (! is_string($best) || strlen($best) < 80) {
            return null;
        }
        $text = $this->parser->decodeEntities(
            preg_replace('/<[^>]+>/', '', preg_replace('/<br\s*\/?>/i', "\n", $best) ?? $best) ?? $best
        );
        $raw = '';
        if (preg_match('/<meta[^>]+property="og:title"[^>]+content="([^"]*)"/i', $html, $m)
            || preg_match('/<title>([^<]*)<\/title>/i', $html, $m)) {
            $raw = $m[1];
        }
        $parsed = $this->parser->parseSheet($text);
        $title = preg_replace('/\s*(chords|tab|lyrics)\b.*$/i', '', $this->parser->decodeEntities($raw)) ?? '';

        return [
            'title' => trim($title),
            'artist' => '',
            'key' => $parsed['key'] ?: '',
            'capo' => $parsed['capo'] ?: 0,
            'body' => $parsed['body'],
        ];
    }

    private function isPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = array_map('intval', explode('.', $ip));
            [$a, $b] = [$parts[0] ?? 0, $parts[1] ?? 0];
            if ($a === 0 || $a === 10 || $a === 127) {
                return true;
            }
            if ($a === 169 && $b === 254) {
                return true;
            }
            if ($a === 172 && $b >= 16 && $b <= 31) {
                return true;
            }
            if ($a === 192 && $b === 168) {
                return true;
            }
            if ($a === 100 && $b >= 64 && $b <= 127) {
                return true;
            }

            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $v = strtolower($ip);
            if ($v === '::1' || $v === '::') {
                return true;
            }
            if (str_starts_with($v, 'fc') || str_starts_with($v, 'fd')) {
                return true;
            }
            if (str_starts_with($v, 'fe80')) {
                return true;
            }
            if (str_starts_with($v, '::ffff:')) {
                return $this->isPrivateIp(substr($v, 7));
            }

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function buildHref(array $parts): string
    {
        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return "{$scheme}://{$host}{$port}{$path}{$query}{$fragment}";
    }

    private function resolveUrl(string $base, string $relative): string
    {
        if (parse_url($relative, PHP_URL_SCHEME)) {
            return $relative;
        }
        $baseParts = parse_url($base);
        if ($baseParts === false) {
            return $relative;
        }
        if (str_starts_with($relative, '//')) {
            return ($baseParts['scheme'] ?? 'https').':'.$relative;
        }
        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'] ?? '';
        $port = isset($baseParts['port']) ? ':'.$baseParts['port'] : '';
        if (str_starts_with($relative, '/')) {
            return "{$scheme}://{$host}{$port}{$relative}";
        }
        $dir = preg_replace('#/[^/]*$#', '/', $baseParts['path'] ?? '/');

        return "{$scheme}://{$host}{$port}{$dir}{$relative}";
    }
}
