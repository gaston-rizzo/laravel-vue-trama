<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>{{ $advertiser['name'] }}</title>

    <style>
        /* ============================================================================
         * PÁGINA DE DEMOSTRACIÓN DEL ANUNCIANTE
         * ============================================================================
         *
         * Esta página funciona de forma independiente del diseño público de TRAMA.
         * Sus estilos se mantienen dentro de este Blade para no incorporar reglas
         * específicas de los anunciantes ficticios al app.css general del portal.
         * ============================================================================
         */

        * {
            box-sizing: border-box;
        }

        html {
            margin: 0;
            padding: 0;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: #0b0f14;
            color: #f3f1eb;
            font-family:
                Inter,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }

        .advertiser-demo-page {
            width: 100%;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 24px;
        }

        .advertiser-demo-card {
            width: min(100%, 920px);
            padding: 48px;
            border: 1px solid #27313c;
            border-radius: 14px;
            background: #10151b;
        }

        .advertiser-demo-eyebrow {
            margin: 0 0 12px;
            color: #d9a640;
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .advertiser-demo-card h1 {
            margin: 0 0 10px;
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(2.5rem, 6vw, 4.8rem);
            font-weight: 700;
            line-height: 1;
        }

        .advertiser-demo-card h2 {
            margin: 0 0 18px;
            font-size: clamp(1.35rem, 3vw, 2rem);
            font-weight: 650;
            line-height: 1.25;
        }

        .advertiser-demo-copy {
            max-width: 720px;
            margin: 0;
            color: #aeb7c0;
            font-size: 1rem;
            line-height: 1.7;
        }

        .advertiser-demo-image {
            display: block;
            width: 100%;
            max-height: 300px;
            margin: 30px 0;
            border: 1px solid #27313c;
            background: #080b0e;
            object-fit: contain;
        }

        .advertiser-demo-benefits {
            display: grid;
            gap: 10px;
            margin: 0 0 28px;
            padding-left: 22px;
            color: #d8dde3;
            line-height: 1.5;
        }

        .advertiser-demo-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 10px 16px;
            border-radius: 7px;
            background: #d9a640;
            color: #0a0d11;
            font-size: 0.9rem;
            font-weight: 800;
        }

        .advertiser-demo-disclaimer {
            margin: 30px 0 0;
            padding-top: 20px;
            border-top: 1px solid #27313c;
            color: #7f8a95;
            font-size: 0.8rem;
            line-height: 1.5;
        }

        @media (max-width: 700px) {
            .advertiser-demo-page {
                align-items: flex-start;
                padding: 24px 16px;
            }

            .advertiser-demo-card {
                padding: 28px 22px;
            }

            .advertiser-demo-image {
                margin: 24px 0;
            }
        }
    </style>
</head>

<body>
    <main class="advertiser-demo-page">
        <section class="advertiser-demo-card">

            <p class="advertiser-demo-eyebrow">
                {{ $advertiser['eyebrow'] }}
            </p>

            <h1>
                {{ $advertiser['name'] }}
            </h1>

            <h2>
                {{ $advertiser['headline'] }}
            </h2>

            <p class="advertiser-demo-copy">
                {{ $advertiser['description'] }}
            </p>

            <img
                class="advertiser-demo-image"
                src="{{ $advertiser['image'] }}"
                alt="Publicidad de {{ $advertiser['name'] }}"
            >

            <ul class="advertiser-demo-benefits">
                @foreach ($advertiser['benefits'] as $benefit)
                    <li>{{ $benefit }}</li>
                @endforeach
            </ul>

            <span class="advertiser-demo-cta">
                {{ $advertiser['cta'] }}
            </span>


        </section>
    </main>
</body>
</html>