<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <title>Confirm your PayPal email</title>
    </head>
    <body style="margin: 0; padding: 24px; background-color: #f9fafb; font-family: Arial, Helvetica, sans-serif; color: #1f2937;">
        <div style="max-width: 560px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; padding: 32px; border: 1px solid #e5e7eb;">
            <h1 style="margin: 0 0 16px; font-size: 22px; color: #4338ca;">
                Confirm payouts for {{ $organization->name }}
            </h1>
            <p style="font-size: 15px; line-height: 1.6; margin: 0 0 12px;">
                Wiki Donate wants to send donation payouts for
                <strong>{{ $organization->name }}</strong>
                @if ($organization->ein)
                    (EIN {{ $organization->ein }})
                @endif
                to this PayPal address:
                <strong>{{ $organization->paypal_email }}</strong>
            </p>
            <p style="font-size: 15px; line-height: 1.6; margin: 0 0 20px;">
                Please confirm that this address belongs to the organization and
                can receive payouts:
            </p>
            <p style="margin: 0 0 20px;">
                <a href="{{ $confirmUrl }}" style="display: inline-block; background-color: #4f46e5; color: #ffffff; text-decoration: none; font-size: 15px; font-weight: bold; padding: 12px 28px; border-radius: 8px;">
                    Confirm PayPal email
                </a>
            </p>
            <p style="font-size: 13px; line-height: 1.6; margin: 0; color: #6b7280;">
                This link expires in 7 days. If you did not expect this message, please ignore it — no payouts will be sent.
            </p>
        </div>
    </body>
</html>
