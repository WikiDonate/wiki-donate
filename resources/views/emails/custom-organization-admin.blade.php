<!DOCTYPE html>
<html>
<head>
    <title>Custom organization needs review - Wiki Donate</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <style>
        body { margin: 0; padding: 0; background-color: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }
        table { border-collapse: collapse; }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f5; padding: 40px 0;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 560px; background-color: #ffffff; border-radius: 12px; border: 1px solid #e5e7eb;">
                    <tr>
                        <td style="padding: 24px;">
                            <h2 style="margin: 0 0 12px 0; font-size: 18px; color: #111827;">New custom organization: {{ $organization->name }}</h2>
                            <p style="margin: 0 0 8px 0; font-size: 14px; color: #374151;">Creator: <strong>{{ $creator->username }}</strong> ({{ $creator->email }})</p>
                            <p style="margin: 0 0 8px 0; font-size: 14px; color: #374151;">Formula: <strong>{{ $formula->name }}</strong> (ID {{ $formula->id }})</p>
                            <p style="margin: 0 0 8px 0; font-size: 14px; color: #374151;">Organization ID: {{ $organization->id }}@if($organization->ein) · EIN {{ $organization->ein }}@endif</p>
                            <p style="margin: 16px 0 0 0; font-size: 14px; color: #374151;">Review in Admin → Organizations, set a PayPal receiving email, then Verify.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
