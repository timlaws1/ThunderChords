<?php

namespace Tests\Unit;

use App\Services\KeyGuesser;
use App\Services\SheetParser;
use PHPUnit\Framework\TestCase;

class SheetParserTest extends TestCase
{
    public function test_normalize_junk_and_accidentals(): void
    {
        $parser = new SheetParser;
        $body = $parser->normalize("A♯m  B♭\nAdvertisement\nTranspose\nSome words\n");
        $this->assertSame("A#m  Bb\nSome words\n", $body);
    }

    public function test_guess_key_from_first_chord(): void
    {
        $keys = new KeyGuesser;
        $this->assertSame(['key' => 'Am', 'guessed' => true], $keys->guessKey("Am  F\nlyric", ''));
        $this->assertSame(['key' => 'G', 'guessed' => false], $keys->guessKey('Am  F', 'G'));
    }

    public function test_fifths_distance_uses_the_circle_of_fifths(): void
    {
        $keys = new KeyGuesser;

        $this->assertSame(0, $keys->fifthsDistance('G', 'G'));
        $this->assertSame(1, $keys->fifthsDistance('C', 'G'));
        $this->assertSame(0, $keys->fifthsDistance('Am', 'C'));
        $this->assertSame(6, $keys->fifthsDistance('C', 'F#'));
        $this->assertNull($keys->fifthsDistance('', 'G'));
        $this->assertNull($keys->fifthsDistance('not a key', 'G'));
    }
}
