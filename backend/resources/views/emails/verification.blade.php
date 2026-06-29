<!DOCTYPE html>
<html>
<head>
    <title>Verify Your Email - Wiki Donate</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <style>
        /* Reset */
        body { margin: 0; padding: 0; background-color: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }
        table { border-collapse: collapse; }
        a { text-decoration: none; }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5;">
    <!-- Main container -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f5; padding: 40px 0;">
        <tr>
            <td align="center">
                <!-- Email card -->
                <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 500px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05); border: 1px solid #e5e7eb;">
                    <!-- Header with gradient -->
                    <tr>
                        <td style="padding: 32px 24px; text-align: center; background-color: #6366f1; background: linear-gradient(135deg, #4f46e5, #9333ea);">
                            <h1 style="margin: 0 0 8px 0; font-size: 24px; font-weight: 700; color: #ffffff;">Welcome to Wiki Donate, {{ $data->username }}!</h1>
                            <p style="margin: 0; font-size: 14px; color: rgba(255, 255, 255, 0.9);">Please verify your email address</p>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding: 32px 24px;">
                            <p style="margin: 0 0 16px 0; font-size: 16px; color: #374151; line-height: 1.5;">Thank you for registering. Click the button below to verify your email and activate your account:</p>
                            <!-- Button -->
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center" style="padding: 8px 0 24px 0;">
                                        <a href="{{ $verificationUrl }}" style="display: inline-block; background-color: #6366f1; background: linear-gradient(135deg, #4f46e5, #9333ea); color: #ffffff; font-weight: 600; font-size: 16px; padding: 14px 28px; border-radius: 8px; text-decoration: none; box-shadow: 0 2px 4px rgba(79, 70, 229, 0.3);">Verify Email</a>
                                    </td>
                                </tr>
                            </table>
                            <!-- Fallback link -->
                            <p style="margin: 0 0 8px 0; font-size: 14px; color: #6b7280;">If the button doesn't work, copy and paste this link into your browser:</p>
                            <p style="margin: 0 0 24px 0; font-size: 14px; color: #4f46e5; word-break: break-all;">{{ $verificationUrl }}</p>
                            <!-- Expiry notice -->
                            <p style="margin: 0 0 8px 0; font-size: 14px; color: #6b7280;">This link will expire in 1 hour.</p>
                            <p style="margin: 0; font-size: 14px; color: #6b7280;">If you did not create an account, you can safely ignore this email.</p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 0 24px 32px 24px;">
                            <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 0 0 16px 0;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af; text-align: center;">- The Wiki Donate Team</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
