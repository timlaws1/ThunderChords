<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PageController extends Controller
{
    public function library(): View
    {
        return view('chords.library');
    }

    public function song(): View
    {
        return view('chords.song');
    }

    public function setlists(): View
    {
        return view('chords.setlists');
    }

    public function setlist(): View
    {
        return view('chords.setlist');
    }

    public function medley(): View
    {
        return view('chords.medley');
    }
}
