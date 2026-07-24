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
                            <p style="margin:0 0 8px 0;font-size:18px;font-weight:600;color:#111827;">New sign-in to your account</p>
                            <p style="margin:0;font-size:14px;line-height:22px;color:#4b5563;">
                                Hi {{ $userName }}, your SkillLeo account was just signed in to from a device we
                                haven't seen before.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 0 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
                                <tr>
                                    <td style="padding:12px 16px;font-size:13px;color:#6b7280;">Device</td>
                                    <td style="padding:12px 16px;font-size:13px;color:#111827;text-align:right;font-weight:500;">{{ $device }}</td>
                                </tr>
                                @if ($location)
                                <tr>
                                    <td style="padding:0 16px 12px 16px;font-size:13px;color:#6b7280;">Location</td>
                                    <td style="padding:0 16px 12px 16px;font-size:13px;color:#111827;text-align:right;font-weight:500;">{{ $location }}</td>
                                </tr>
                                @endif
                                @if ($ip)
                                <tr>
                                    <td style="padding:0 16px 12px 16px;font-size:13px;color:#6b7280;">IP address</td>
                                    <td style="padding:0 16px 12px 16px;font-size:13px;color:#111827;text-align:right;font-weight:500;">{{ $ip }}</td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding:0 16px 12px 16px;font-size:13px;color:#6b7280;">Time</td>
                                    <td style="padding:0 16px 12px 16px;font-size:13px;color:#111827;text-align:right;font-weight:500;">{{ $signedInAt }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px 0 32px;">
                            <p style="margin:0;font-size:14px;line-height:22px;color:#4b5563;">
                                If this was you, nothing to do.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 0 32px;">
                            <a href="{{ $revokeUrl }}"
                               style="display:block;padding:12px 20px;background-color:#b91c1c;color:#ffffff;text-decoration:none;border-radius:8px;font-size:14px;font-weight:600;text-align:center;">
                                This wasn't me — sign out everywhere
                            </a>
                            <p style="margin:10px 0 0 0;font-size:12px;line-height:18px;color:#6b7280;text-align:center;">
                                This signs out every device, including this new one. Change your password afterwards.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px 32px 32px;">
                            <p style="margin:0;font-size:12px;line-height:18px;color:#9ca3af;border-top:1px solid #e5e7eb;padding-top:16px;">
                                The location is approximate and derived from the IP address, so it may show a nearby
                                city or your provider's rather than exactly where you are.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
