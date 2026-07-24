<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:32px 16px;background-color:#f4f4f2;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e5e0;">
                    <tr>
                        <td style="padding:32px 32px 0 32px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="width:32px;height:32px;background-color:#1d4ed8;border-radius:8px;text-align:center;vertical-align:middle;color:#ffffff;font-weight:700;font-size:13px;">SL</td>
                                    <td style="padding-left:10px;font-weight:700;font-size:18px;color:#1d4ed8;">SkillLeo</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px 8px 32px;">
                            <h1 style="margin:0;font-size:18px;color:#18181b;">{{ $title }}</h1>
                            @if($body)
                                <p style="margin:8px 0 0 0;font-size:14px;color:#52525b;line-height:1.5;">{{ $body }}</p>
                            @endif
                        </td>
                    </tr>
                    @if($link)
                    <tr>
                        <td style="padding:16px 32px;">
                            <a href="{{ $link }}" style="display:inline-block;background-color:#1d4ed8;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:10px 18px;border-radius:8px;">Open in SkillLeo</a>
                        </td>
                    </tr>
                    @endif
                    <tr>
                        <td style="padding:8px 32px 32px 32px;">
                            <p style="margin:0;font-size:12px;color:#a1a1aa;line-height:1.5;">
                                You're getting this because of your notification preferences in Profile &rsaquo; Notification preferences.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
