<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Notification</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: #1a365d;
            color: white;
            padding: 20px;
            text-align: center;
        }
        .content {
            padding: 20px;
            background: #f9f9f9;
        }
        .footer {
            text-align: center;
            padding: 20px;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Express Autocare</h1>
    </div>

    <div class="content">
        <p>Dear {{ $user->name ?? 'Customer' }},</p>

        <p>{!! nl2br(e($message)) !!}</p>

        <p>Thank you for choosing Express Autocare!</p>
    </div>

    <div class="footer">
        <p>This is an automated message from Express Autocare.</p>
        <p>Please do not reply to this email.</p>
    </div>
</body>
</html>
