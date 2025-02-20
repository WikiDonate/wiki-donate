<!DOCTYPE html>
<html>
<head>
    <title>Thank You for Your Donation</title>
</head>
<body>
    <h1>Thank You, {{ $data['name'] }}!</h1>
    <p>
        We have received your generous donation of <strong>${{ $data['amount'] }}</strong>.
    </p>
    <p>Date of Donation: <strong>{{ $data['date'] }}</strong></p>
    <p>
        <strong>Please note: We will process the donation amount immediately and let you know</strong>
    </p>
    <p>Your support means the world to us. Thank you for making a difference!</p>
    <p>Best regards,</p>
    <p>-The Wiki Donate Team</p>
</body>

</html>
