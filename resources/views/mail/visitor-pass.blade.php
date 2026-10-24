<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Your Visitor Pass</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f4f7; color: #51545e; margin: 0; padding: 24px; }
        .card { background-color: #ffffff; max-width: 560px; margin: 0 auto; border-radius: 8px; padding: 32px; border: 1px solid #eaeaec; }
        h1 { color: #333333; font-size: 20px; font-weight: bold; margin-top: 0; }
        p { font-size: 15px; line-height: 1.6; color: #51545e; }
        .btn { display: inline-block; background-color: #0f172a; color: #ffffff !important; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: 600; margin: 18px 0; }
        .details { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px; margin: 20px 0; }
        .details-item { margin: 6px 0; font-size: 14px; }
        .footer { font-size: 12px; color: #94a3b8; text-align: center; margin-top: 24px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Hello {{ $visitor->name }},</h1>
        <p>You have been registered for access to the community.</p>
        
        <div class="details">
            <div class="details-item"><strong>Host:</strong> {{ $visitor->homeowner_name }}</div>
            <div class="details-item"><strong>Expected Arrival:</strong> {{ $visitor->expected_at?->format('l, F j, Y \a\t g:i A') }}</div>
            <div class="details-item"><strong>Pass Type:</strong> {{ $visitor->type }}</div>
        </div>

        <p>Please present the attached QR code or access your digital pass directly:</p>

        <a href="{{ $guestPassUrl }}" class="btn" target="_blank">View Visitor Pass</a>

        <p>Thank you for your cooperation.<br><strong>Community Management</strong></p>
    </div>
    <div class="footer">
        &copy; {{ date('Y') }} Community Hub. All rights reserved.
    </div>
</body>
</html>
