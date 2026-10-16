<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>WikiDonate Organization Verification</title>
</head>
<body style="font-family: system-ui, -apple-system, sans-serif; color: #1f2937; max-width: 600px; margin: 0 auto; padding: 24px;">
    <h2 style="color: #4f46e5;">WikiDonate</h2>
    <p>Hello,</p>
    <p>
        <strong>{{ $organizationName }}</strong> has been verified for payouts on WikiDonate.
    </p>
    <p>
        PayPal receiving email on file:<br>
        <code style="background: #f3f4f6; padding: 4px 8px; border-radius: 4px;">{{ $paypalEmail }}</code>
    </p>
    <p>
        Verified at: {{ $verifiedAt }}
    </p>
    <p style="color: #6b7280; font-size: 12px; margin-top: 24px;">
        This is an automated message from WikiDonate. Please contact support if you did not expect this verification.
    </p>
</body>
</html>
