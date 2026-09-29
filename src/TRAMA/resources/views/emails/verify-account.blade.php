<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Verificación de cuenta | TRAMA</title>
</head>
<body style="background:#080a0d;color:#f5efe3;font-family:Arial,sans-serif;margin:0;padding:32px;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;margin:0 auto;background:#111820;border:1px solid #26313d;border-radius:8px;">
        <tr>
            <td style="padding:28px;">
                <h1 style="font-family:Georgia,serif;font-size:34px;letter-spacing:0;margin:0 0 12px;">TRAMA</h1>
                <p style="color:#d6a23a;font-size:12px;font-weight:700;letter-spacing:0;margin:0 0 22px;text-transform:uppercase;">Verificación de cuenta</p>

                <p style="font-size:18px;line-height:1.6;margin:0 0 16px;">
                    Gracias por crear tu cuenta.
                </p>

                <p style="font-size:15px;line-height:1.7;margin:0 0 22px;color:#aeb7c2;">
                    Para activar tu acceso, confirmá que este correo te pertenece. Después de verificar la cuenta vas a poder iniciar sesión y comentar en el portal.
                </p>

                <p style="margin:0 0 24px;">
                    <a href="{{ $verificationUrl }}" style="display:inline-block;background:#d6a23a;color:#080a0d;font-size:14px;font-weight:700;line-height:1;text-decoration:none;border-radius:6px;padding:14px 20px;">
                        Verificar cuenta
                    </a>
                </p>

                <p style="font-size:13px;line-height:1.7;margin:0 0 10px;color:#7f8a96;">
                    Si el botón no abre correctamente, copiá y pegá este enlace en tu navegador:
                </p>

                <p style="font-size:13px;line-height:1.7;margin:0 0 22px;word-break:break-all;">
                    <a href="{{ $verificationUrl }}" style="color:#d6a23a;text-decoration:none;">
                        {{ $verificationUrl }}
                    </a>
                </p>

                <p style="font-size:13px;line-height:1.7;margin:0;color:#7f8a96;">
                    Si no creaste esta cuenta, podés ignorar este correo.
                </p>

                <p style="border-top:1px solid #26313d;color:#aeb7c2;font-size:13px;line-height:1.7;margin:24px 0 0;padding-top:18px;">
                    Equipo de TRAMA
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
