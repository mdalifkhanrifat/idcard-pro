{{-- A notification e-mail (NotificationMail): the organization's words, text only (escaped), with its name and colour. --}}
<!doctype html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $notification->subject }}</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f3f4f6;font-family:'Noto Sans Bengali','Segoe UI',Roboto,Arial,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;">
        <tr><td style="height:4px;background:{{ $color }};"></td></tr>
        <tr>
            <td style="padding:20px 24px 4px;font-size:16px;font-weight:700;color:#111827;">{{ $organization }}</td>
        </tr>
        <tr>
            <td style="padding:8px 24px 24px;font-size:15px;line-height:1.6;">
                @foreach ($paragraphs as $paragraph)
                    <p style="margin:0 0 14px;">{{ $paragraph }}</p>
                @endforeach
            </td>
        </tr>
        @if ($qr)
            {{-- What the event adds (5.7b): e.g. an appointment's number, read at the desk --}}
            <tr>
                <td style="padding:0 24px 24px;text-align:center;">
                    <img src="{{ $message->embedData($qr, 'qr.png', 'image/png') }}" width="180" height="180" alt="{{ $qrCaption }}" style="display:block;margin:0 auto 8px;">
                    <p style="margin:0;font-size:13px;color:#4b5563;">{{ $qrCaption }}</p>
                </td>
            </tr>
        @endif
    </table>
</body>
</html>
