<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="icon" type="image/webp" href="/images/brand/logo.webp">
        <link rel="apple-touch-icon" href="/images/brand/logo.webp">

        <style>
            html,
            body {
                background: #080a0d;
                color: #f5f1e8;
                margin: 0;
                min-height: 100%;
            }

            /*
             * CSS crítico del primer pantallazo.
             *
             * SSR entrega la portada antes de que Vue termine de hidratarla. Si el
             * CSS completo tarda una fracción de segundo en llegar, estas reglas
             * mantienen la cabecera, la navegación, la cinta y la noticia principal
             * con tamaños correctos para evitar que se vean enlaces azules, logo
             * gigante o imágenes sin contenedor durante una carga con caché limpia.
             */
            .site-shell {
                min-height: 100vh;
                background: #080a0d;
                color: #f5f1e8;
                font-family: Inter, ui-sans-serif, system-ui, sans-serif;
            }

            .public-header,
            .breaking-ticker,
            .hero-grid,
            .content-band,
            .split-band,
            .category-showcase,            
            .public-footer {
                width: min(1480px, calc(100% - 48px));
                margin-left: auto;
                margin-right: auto;
            }

            .brand-row {
                align-items: center;
                border-bottom: 1px solid rgba(174, 183, 194, 0.18);
                display: flex;
                gap: 16px;
                min-height: 130px;
                padding: 26px 0;
            }

            .brand-mark {
                align-items: center;
                color: #f5f1e8;
                display: inline-flex;
                flex: 0 0 auto;
                gap: 14px;
                font-family: Georgia, 'Times New Roman', serif;
                font-size: clamp(46px, 5vw, 74px);
                font-weight: 900;
                line-height: 1;
                text-decoration: none;
            }

            .brand-icon {
                aspect-ratio: 1;
                display: block;
                height: clamp(42px, 5vw, 72px);
                object-fit: cover;
                width: auto;
            }

            .header-ad {
                align-self: center;
                display: block;
                flex: 1 1 520px;
                margin-inline: clamp(12px, 3vw, 48px);
                max-width: 728px;
                overflow: hidden;
            }

            .header-ad img {
                aspect-ratio: 728 / 90;
                display: block;
                height: auto;
                object-fit: cover;
                width: 100%;
            }

            .header-actions {
                align-items: center;
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                margin-left: auto;
            }

            .icon-action {
                align-items: center;
                background: #111820;
                border: 1px solid rgba(174, 183, 194, 0.18);
                border-radius: 8px;
                color: #f5f1e8;
                display: inline-flex;
                font-weight: 900;
                gap: 8px;
                justify-content: center;
                min-height: 42px;
                padding: 0 14px;
                text-decoration: none;
                transition:
                    background-color 0.18s ease,
                    border-color 0.18s ease,
                    color 0.18s ease;
            }

            .user-menu summary {
                max-width: min(240px, 34vw);
                min-width: 0;
            }

            .user-menu summary svg {
                flex: 0 0 auto;
            }

            .user-menu-name {
                display: block;
                min-width: 0;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .icon-action:hover,
            .icon-action:focus-visible {
                background: #17212c;
                border-color: rgba(74, 141, 255, 0.42);
                color: #f5f1e8;
            }

            .icon-action.accent {
                background: #dcae3c;
                border-color: #dcae3c;
                color: #080a0d;
            }

            .icon-action.accent:hover,
            .icon-action.accent:focus-visible {
                background: #f0c354;
                border-color: #f0c354;
                color: #050607;
            }

            .category-nav {
                border-bottom: 1px solid rgba(174, 183, 194, 0.18);
                display: flex;
                gap: 6px;
                overflow-x: auto;
                padding: 10px 0;
            }

            .category-nav a,
            .category-nav .nav-current {
                border: 1px solid transparent;
                border-radius: 999px;
                color: #aeb7c2;
                display: inline-flex;
                font-size: 13px;
                font-weight: 900;
                padding: 9px 13px;
                text-decoration: none;
                white-space: nowrap;
            }

            .category-nav .nav-current {
                border-color: #dcae3c;
                color: #f5f1e8;
            }

            .breaking-ticker {
                align-items: center;
                background: linear-gradient(90deg, rgba(216, 50, 60, 0.22), rgba(214, 162, 58, 0.08));
                border: 1px solid rgba(216, 50, 60, 0.45);
                border-radius: 8px;
                display: grid;
                gap: 12px;
                grid-template-columns: max-content minmax(0, 1fr);
                margin-top: 18px;
                min-height: 54px;
                overflow: hidden;
                padding: 8px 14px;
            }

            .ticker-track {
                display: flex;
                gap: 0;
                overflow: hidden;
                white-space: nowrap;
                width: max-content;
            }

            .ticker-item {
                align-items: center;
                color: #f5f1e8;
                display: inline-flex;
                flex-shrink: 0;
                font-weight: 800;
                gap: 7px;
                text-decoration: none;
            }

            .ticker-item span {
                color: #dcae3c;
                font-size: 11px;
                font-weight: 950;
                text-transform: uppercase;
            }

            .ticker-item strong {
                font: inherit;
            }

            .ticker-item::after {
                color: rgba(245, 241, 232, 0.55);
                content: "·";
                flex-shrink: 0;
                font-size: 16px;
                font-weight: 800;
                line-height: 1;
                margin: 0 18px;
            }

            .hero-grid {
                display: grid;
                gap: 16px;
                grid-template-columns: minmax(0, 1.85fr) minmax(320px, 0.75fr);
                margin-top: 16px;
            }

            .hero-story,
            .live-brief {
                background: #111820;
                border: 1px solid rgba(174, 183, 194, 0.18);
                border-radius: 8px;
                overflow: hidden;
            }

            .hero-story {
                min-height: 560px;
                position: relative;
            }

            .hero-story img {
                display: block;
                height: 100%;
                inset: 0;
                object-fit: cover;
                position: absolute;
                width: 100%;
            }

            .hero-overlay {
                background: linear-gradient(90deg, rgba(8, 10, 13, 0.86), rgba(8, 10, 13, 0.16));
                display: flex;
                flex-direction: column;
                inset: 0;
                justify-content: flex-end;
                padding: clamp(28px, 5vw, 64px);
                position: absolute;
                z-index: 1;
            }

            .hero-overlay h1 {
                color: #f5f1e8;
                font-family: Georgia, 'Times New Roman', serif;
                font-size: clamp(48px, 6vw, 92px);
                line-height: 0.92;
                margin: 12px 0;
                max-width: 760px;
            }

            .hero-overlay a {
                color: inherit;
                text-decoration: none;
            }

            @media (max-width: 1180px) {
                .header-ad {
                    display: none;
                }

                .hero-grid {
                    grid-template-columns: 1fr;
                }
            }

            @media (max-width: 760px) {
                .public-header,
                .breaking-ticker,
                .hero-grid,
                .content-band,
                .split-band,
                .category-showcase,                
                .public-footer {
                    width: calc(100% - 24px);
                }

                .brand-row {
                    align-items: flex-start;
                    flex-direction: column;
                    min-height: auto;
                }
            }
        </style>

        {{-- Tipografía editorial principal. --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet" />

        {{-- Carga el CSS como link directo antes de Ziggy y del JavaScript.
             Esto evita que un navegador con cache limpia pinte por un instante
             el HTML SSR sin estilos mientras espera el arranque de Vite/Vue. --}}
        <link rel="stylesheet" href="{{ Vite::asset('resources/css/app.css') }}">

        @php
            $screen = data_get($page, 'props.screen');
            $screenProps = data_get($page, 'props.screenProps', []);
            $homeHeroImage = $screen === 'Public/Home' ? data_get($screenProps, 'hero.cover_image') : null;
            $articleHeroImage = $screen === 'Public/ArticleShow' ? data_get($screenProps, 'article.cover_image') : null;
            $primaryImage = $homeHeroImage ?: $articleHeroImage;
            $headerBannerImage = data_get($page, 'props.advertisements.header.0.image_url');
        @endphp

        {{-- Precarga únicamente las imágenes que aparecen en el primer pantallazo.
             En portada y nota directa, la foto principal no espera al JavaScript;
             el primer banner del header también se pide temprano. --}}
        @if (is_string($primaryImage) && $primaryImage !== '')
            <link rel="preload" as="image" href="{{ $primaryImage }}" fetchpriority="high">
        @endif
        @if (is_string($headerBannerImage) && $headerBannerImage !== '')
            <link rel="preload" as="image" href="{{ $headerBannerImage }}" fetchpriority="high">
        @endif

        {{-- Rutas Ziggy, JavaScript y cabeceras administradas por Inertia. --}}
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
        <title inertia>{{ config('app.name', 'TRAMA') }}</title>
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
