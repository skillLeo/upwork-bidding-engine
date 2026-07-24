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
                            <p style="margin:0 0 8px 0;font-size:18px;font-weight:600;color:#111827;">You've been invited</p>
                            <p style="margin:0;font-size:14px;line-height:22px;color:#4b5563;">
                                {{ $invitedByName }} invited you to join <strong>{{ $workspaceName }}</strong> on
                                SkillLeo as a <strong>{{ $roleLabel }}</strong>.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px 0 32px;">
                            <a href="{{ $acceptUrl }}"
                               style="display:block;padding:12px 20px;background-color:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:8px;font-size:14px;font-weight:600;text-align:center;">
                                Accept invitation
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 32px 32px;">
                            <p style="margin:0;font-size:12px;line-height:18px;color:#9ca3af;border-top:1px solid #e5e7eb;padding-top:16px;">
                                This invitation expires on {{ $expiresAt }}. If you weren't expecting it, you can
                                ignore this email — nothing happens until you accept.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
