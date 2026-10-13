@props(['name' => 'arrow'])
@php
    $paths = [
        'menu' => 'M4 6h16M4 12h16M4 18h16',
        'bell' => 'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4',
        'chevron' => 'm6 9 6 6 6-6',
        'grid' => 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
        'user' => 'M20 21v-2a7 7 0 0 0-14 0v2M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
        'file' => 'M14 2H5v20h14V7l-5-5ZM14 2v6h5M8 12h8M8 16h6',
        'briefcase' => 'M3 7h18v14H3zM8 7V3h8v4M3 12a24 24 0 0 0 18 0M12 11v4',
        'building' => 'M4 22V3h12v19M16 10h4v12M8 7h4M8 11h4M8 15h4M9 22v-3h2v3',
        'check' => 'm5 12 4 4L19 6',
        'clock' => 'M12 8v5l3 2M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z',
        'flag' => 'M5 22V3m0 1c4-4 10 4 14 0v10c-4 4-10-4-14 0',
        'chart' => 'M4 3v18h17M8 16v-4M13 16V7M18 16v-7',
        'plus' => 'M12 5v14M5 12h14',
        'search' => 'm21 21-5-5M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z',
        'message' => 'M21 11a8 8 0 0 1-8 8H7l-4 3V11a9 9 0 0 1 18 0ZM8 10h8M8 14h5',
        'pin' => 'M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0ZM15 10a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        'arrow' => 'M5 12h14m-6-6 6 6-6 6',
        'shield' => 'm12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6l9-4Zm-4 10 3 3 5-6',
    ];
@endphp
<svg {{ $attributes->class(['icon']) }} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['arrow'] }}"/></svg>
