@php($market = \App\Support\MarketProfile::class)
:root {
    --market-font-family: {!! $market::fontFamily() !!};
}

body {
    font-family: var(--market-font-family);
}
