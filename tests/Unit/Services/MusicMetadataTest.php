<?php

namespace Tests\Unit\Services;

use App\Services\MusicMetadata;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MusicMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lastfm.key' => null, 'services.musicbrainz.user_agent' => 'ThunderChords/test (https://example.com)']);
        Cache::flush();
    }

    public function test_tags_combine_itunes_genre_and_musicbrainz_genres(): void
    {
        $this->fakeCatalogue();

        $tags = (new MusicMetadata)->tagsFor('Coldplay', 'Yellow');

        $this->assertSame(['alternative', 'alternative rock', 'pop rock', 'britpop'], $tags);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'musicbrainz.org')
            && $request->hasHeader('User-Agent', 'ThunderChords/test (https://example.com)'));
    }

    public function test_tags_skip_a_different_recording_and_junk_tags(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'itunes.apple.com')) {
                return Http::response([
                    'results' => [[
                        'trackName' => 'Yellow Flicker Beat',
                        'artistName' => 'Lorde',
                        'primaryGenreName' => 'Pop',
                    ]],
                ]);
            }
            if (str_contains($request->url(), '/ws/2/artist/')) {
                return Http::response([
                    'genres' => [['name' => 'alternative rock', 'count' => 4]],
                    'tags' => [
                        ['name' => 'seen live', 'count' => 50],
                        ['name' => 'favorites', 'count' => 10],
                        ['name' => 'coldplay', 'count' => 8],
                    ],
                ]);
            }

            return Http::response([
                'artists' => [
                    ['id' => '11111111-1111-1111-1111-111111111111', 'name' => 'Coldplay Experience', 'score' => 100],
                    ['id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'name' => 'Coldplay', 'score' => 40],
                    ['id' => '22222222-2222-2222-2222-222222222222', 'name' => 'Coldplay', 'score' => 98],
                ],
            ]);
        });

        $this->assertSame(['alternative rock'], (new MusicMetadata)->tagsFor('Coldplay', 'Yellow'));
    }

    public function test_tags_keep_the_itunes_genre_when_musicbrainz_is_down(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'itunes.apple.com')) {
                return Http::response([
                    'results' => [[
                        'trackName' => 'Yellow (2011 Remaster)',
                        'artistName' => 'The Coldplay',
                        'primaryGenreName' => 'Alternative',
                    ]],
                ]);
            }

            return Http::response(['error' => 'unavailable'], 503);
        });

        $this->assertSame(['alternative'], (new MusicMetadata)->tagsFor('Coldplay', 'Yellow'));
    }

    public function test_tags_are_empty_when_the_lookup_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::failedConnection(),
        ]);

        $this->assertSame([], (new MusicMetadata)->tagsFor('Coldplay', 'Yellow'));
    }

    public function test_tags_are_empty_without_an_artist_and_no_request_is_sent(): void
    {
        Http::preventStrayRequests();

        $this->assertSame([], (new MusicMetadata)->tagsFor('', 'Yellow'));
        Http::assertNothingSent();
    }

    public function test_tags_include_lastfm_when_a_key_is_configured(): void
    {
        config(['services.lastfm.key' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'audioscrobbler.com')) {
                $this->assertStringContainsString('api_key=test-key', $request->url());
                $this->assertStringContainsString('method=track.getTopTags', $request->url());

                return Http::response([
                    'toptags' => [
                        'tag' => [
                            ['name' => 'seen live', 'count' => 90],
                            ['name' => 'britpop', 'count' => 80],
                            ['name' => 'piano', 'count' => 10],
                        ],
                    ],
                ]);
            }
            if (str_contains($request->url(), 'itunes.apple.com')) {
                return Http::response(['results' => []]);
            }

            return Http::response(['artists' => []]);
        });

        $this->assertSame(['britpop'], (new MusicMetadata)->tagsFor('Coldplay', 'Yellow'));
    }

    public function test_a_single_lastfm_tag_object_is_kept(): void
    {
        config(['services.lastfm.key' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'audioscrobbler.com')) {
                return Http::response([
                    'toptags' => ['tag' => ['name' => 'indie', 'count' => 40]],
                ]);
            }
            if (str_contains($request->url(), 'itunes.apple.com')) {
                return Http::response(['results' => []]);
            }

            return Http::response(['artists' => []]);
        });

        $this->assertSame(['indie'], (new MusicMetadata)->tagsFor('Band', 'Song'));
    }

    public function test_similar_tracks_come_from_lastfm(): void
    {
        config(['services.lastfm.key' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake([
            'ws.audioscrobbler.com/*' => Http::response([
                'similartracks' => [
                    'track' => [
                        ['name' => 'Clocks', 'artist' => ['name' => 'Coldplay']],
                        ['name' => 'Wonderwall', 'artist' => ['name' => 'Oasis']],
                    ],
                ],
            ]),
        ]);

        $tracks = (new MusicMetadata)->similarTracks('Coldplay', 'Yellow');

        $this->assertSame([
            ['title' => 'Clocks', 'artist' => 'Coldplay'],
            ['title' => 'Wonderwall', 'artist' => 'Oasis'],
        ], $tracks);
    }

    public function test_similar_tracks_are_empty_without_a_lastfm_key(): void
    {
        Http::preventStrayRequests();

        $this->assertSame([], (new MusicMetadata)->similarTracks('Coldplay', 'Yellow'));
        Http::assertNothingSent();
    }

    public function test_a_partial_lookup_is_cached_only_briefly(): void
    {
        $down = true;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$down) {
            if (str_contains($request->url(), 'itunes.apple.com')) {
                return Http::response([
                    'results' => [[
                        'trackName' => 'Brief',
                        'artistName' => 'Coldplay',
                        'primaryGenreName' => 'Alternative',
                    ]],
                ]);
            }
            if ($down) {
                return Http::response(['error' => 'unavailable'], 503);
            }
            if (str_contains($request->url(), '/ws/2/artist/')) {
                return Http::response([
                    'genres' => [['name' => 'rock', 'count' => 3]],
                    'tags' => [],
                ]);
            }

            return Http::response([
                'artists' => [
                    ['id' => '22222222-2222-2222-2222-222222222222', 'name' => 'Coldplay', 'score' => 100],
                ],
            ]);
        });
        $metadata = new MusicMetadata;

        $this->assertSame(['alternative'], $metadata->tagsFor('Coldplay', 'Brief'));
        Http::assertSentCount(2);

        $down = false;
        $this->assertSame(['alternative'], $metadata->tagsFor('Coldplay', 'Brief'));
        Http::assertSentCount(2);

        $this->travel(11)->minutes();

        $this->assertSame(['alternative', 'rock'], $metadata->tagsFor('Coldplay', 'Brief'));
    }

    public function test_a_successful_lookup_is_cached(): void
    {
        $this->fakeCatalogue();
        $metadata = new MusicMetadata;

        $metadata->tagsFor('Coldplay', 'Yellow');
        $metadata->tagsFor('Coldplay', 'Yellow');

        Http::assertSentCount(3);
    }

    private function fakeCatalogue(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'itunes.apple.com')) {
                return Http::response([
                    'results' => [
                        [
                            'trackName' => 'Yellow Flicker Beat',
                            'artistName' => 'Lorde',
                            'primaryGenreName' => 'Pop',
                        ],
                        [
                            'trackName' => 'Yellow (2011 Remaster)',
                            'artistName' => 'The Coldplay',
                            'primaryGenreName' => 'Alternative',
                        ],
                    ],
                ]);
            }
            if (str_contains($request->url(), '/ws/2/artist/')) {
                return Http::response([
                    'genres' => [
                        ['name' => 'pop rock', 'count' => 3],
                        ['name' => 'alternative rock', 'count' => 10],
                    ],
                    'tags' => [
                        ['name' => 'seen live', 'count' => 20],
                        ['name' => 'britpop', 'count' => 4],
                    ],
                ]);
            }

            return Http::response([
                'artists' => [
                    ['id' => '11111111-1111-1111-1111-111111111111', 'name' => 'Coldplay Experience', 'score' => 100],
                    ['id' => '22222222-2222-2222-2222-222222222222', 'name' => 'Coldplay', 'score' => 100],
                ],
            ]);
        });
    }
}
