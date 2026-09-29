<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nueva consulta en TRAMA</title>
</head>
<body style="margin:0;background:#f4f4f2;color:#191919;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:680px;margin:0 auto;padding:32px 20px;">
        <div style="background:#ffffff;border:1px solid #deded9;border-radius:10px;padding:28px;">
            <p style="margin:0 0 8px;color:#9a6c15;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">
                Contacto · TRAMA
            </p>

            <h1 style="margin:0 0 22px;font-family:Georgia,'Times New Roman',serif;font-size:28px;">
                Nueva consulta recibida
            </h1>

            <table role="presentation" style="width:100%;border-collapse:collapse;margin-bottom:22px;">
                <tr>
                    <td style="padding:7px 0;color:#666666;width:110px;vertical-align:top;">Motivo</td>
                    <td style="padding:7px 0;font-weight:700;">{{ $reasonLabel }}</td>
                </tr>
                <tr>
                    <td style="padding:7px 0;color:#666666;vertical-align:top;">Nombre</td>
                    <td style="padding:7px 0;">{{ $contactMessage->name }}</td>
                </tr>
                <tr>
                    <td style="padding:7px 0;color:#666666;vertical-align:top;">Email</td>
                    <td style="padding:7px 0;">{{ $contactMessage->email }}</td>
                </tr>
            </table>

            <p style="margin:0 0 8px;color:#666666;font-size:13px;font-weight:700;text-transform:uppercase;">
                Mensaje
            </p>

            <div style="white-space:pre-wrap;line-height:1.65;background:#f7f7f5;border:1px solid #e3e3df;border-radius:8px;padding:16px;">{{ $contactMessage->message }}</div>

            <p style="margin:22px 0 0;color:#777777;font-size:13px;line-height:1.55;">
                Podés responder directamente a este correo: el Reply-To apunta a {{ $contactMessage->email }}.
            </p>
        </div>
    </div>
</body>
</html>
