<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <title>Organization Verified</title>
    </head>
    <body style="margin: 0; padding: 24px; background-color: #f9fafb; font-family: Arial, Helvetica, sans-serif; color: #1f2937;">
        <div style="max-width: 560px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; padding: 32px; border: 1px solid #e5e7eb;">
            <h1 style="margin: 0 0 16px; font-size: 22px; color: #4338ca;">
                {{ $organization->name }} — verified for payouts
            </h1>
            <p style="font-size: 15px; line-height: 1.6; margin: 0 0 12px;">
                Wiki Donate has verified this email address as the PayPal payout destination for
                <strong>{{ $organization->name }}</strong>
                @if ($organization->ein)
                    (EIN {{ $organization->ein }})
                @endif
                .
            </p>
            <p style="font-size: 15px; line-height: 1.6; margin: 0 0 16px;">
                Donations allocated to this organization will be paid out to:
                <strong>{{ $organization->paypal_email }}</strong>
            </p>
            <p style="font-size: 13px; line-height: 1.6; margin: 0; color: #6b7280;">
                If you did not expect this message, please reply to this email so we can investigate.
            </p>
        </div>
    </body>
</html>