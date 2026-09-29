<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Página temporalmente no disponible | TRAMA</title>
    <link rel="icon" type="image/webp" href="/images/brand/logo.webp">
    <link rel="apple-touch-icon" href="/images/brand/logo.webp">
    <style>
        :root {
            --bg: #07090d;
            --surface: #111820;
            --surface-soft: #151d27;
            --line: rgba(214, 162, 58, 0.28);
            --line-soft: rgba(255, 255, 255, 0.08);
            --text: #f7f1e6;
            --muted: #aab4c5;
            --gold: #d6a23a;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background:
                radial-gradient(circle at 22% 26%, rgba(214, 162, 58, 0.12), transparent 30%),
                radial-gradient(circle at 78% 12%, rgba(98, 182, 255, 0.10), transparent 28%),
                linear-gradient(180deg, #0b0f15 0%, var(--bg) 54%, #050608 100%);
            color: var(--text);
            font-family: Inter, Arial, sans-serif;
            margin: 0;
            min-height: 100vh;
        }

        .error-page {
            display: grid;
            grid-template-rows: auto 1fr;
            min-height: 100vh;
            padding: 34px clamp(20px, 6vw, 72px);
        }

        .error-header {
            align-items: center;
            display: flex;
            justify-content: space-between;
            margin: 0 auto;
            max-width: 1180px;
            width: 100%;
        }

        .brand {
            align-items: center;
            display: flex;
            gap: 14px;
            text-decoration: none;
        }

        .brand img {
            height: 58px;
            object-fit: contain;
            width: 58px;
        }

        .brand strong {
            color: var(--text);
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(42px, 6vw, 68px);
            letter-spacing: 0;
            line-height: 0.9;
        }

        .error-main {
            align-items: center;
            display: grid;
            margin: 0 auto;
            max-width: 1180px;
            padding: 72px 0;
            width: 100%;
        }

        .error-card {
            background: linear-gradient(145deg, rgba(17, 24, 32, 0.96), rgba(12, 17, 24, 0.96));
            border: 1px solid var(--line);
            border-radius: 10px;
            box-shadow: 0 28px 90px rgba(0, 0, 0, 0.32);
            max-width: 760px;
            padding: clamp(28px, 5vw, 54px);
        }

        .eyebrow {
            color: var(--gold);
            font-size: 12px;
            font-weight: 900;
            letter-spacing: 0.08em;
            margin: 0 0 18px;
            text-transform: uppercase;
        }

        h1 {
            color: var(--text);
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(42px, 8vw, 86px);
            line-height: 0.98;
            margin: 0;
        }

        .message {
            color: var(--muted);
            font-size: clamp(17px, 2vw, 21px);
            line-height: 1.62;
            margin: 24px 0 0;
            max-width: 620px;
        }

        .status-line {
            border-top: 1px solid var(--line-soft);
            color: var(--muted);
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 30px;
            padding-top: 18px;
        }

        .status-line strong {
            color: var(--gold);
        }

        .reload-link {
            background: var(--gold);
            border: 1px solid var(--gold);
            border-radius: 8px;
            color: #101010;
            display: inline-flex;
            font-size: 14px;
            font-weight: 900;
            margin-top: 30px;
            padding: 13px 18px;
            text-decoration: none;
            transition:
                background-color 0.18s ease,
                border-color 0.18s ease,
                color 0.18s ease;
        }

        .reload-link:hover,
        .reload-link:focus-visible {
            background: #f0c354;
            border-color: #f0c354;
            color: #050607;
        }

        @media (max-width: 720px) {
            .error-page {
                padding: 24px 18px;
            }

            .error-header {
                align-items: flex-start;
                flex-direction: column;
                gap: 16px;
            }

            .brand img {
                height: 46px;
                width: 46px;
            }

            .error-main {
                padding: 48px 0;
            }
        }
    </style>
</head>
<body>
    <div class="error-page">
        <header class="error-header">
            <a class="brand" href="/">
                <img src="/images/brand/logo.webp" alt="TRAMA">
                <strong>TRAMA</strong>
            </a>            
        </header>

        <main class="error-main">
            <section class="error-card" aria-labelledby="error-title">
                <p class="eyebrow">Servicio interrumpido</p>
                <h1 id="error-title">Página temporalmente no disponible.</h1>
                <p class="message">
                    Estamos trabajando para restablecer el acceso. Volvé a intentar en unos minutos.
                </p>
                <div class="status-line">
                    <strong>Estado 503</strong>
                    <span>El portal no puede cargar contenido en este momento.</span>
                </div>
                <a class="reload-link" href="/">Reintentar</a>
            </section>
        </main>
    </div>
</body>
</html>
