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
        $this->assertSame(['key' => 'G', 'guessed' => false], $keys->guessKey("Am  F", 'G'));
    }
}
