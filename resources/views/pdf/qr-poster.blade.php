<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Notice — {{ $paymentLink->title }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #0f172a;
            margin: 0;
            padding: 30px;
            text-align: center;
        }
        .poster {
            border: 4px solid #1e3a8a;
            border-radius: 16px;
            padding: 40px 30px;
            background: #ffffff;
        }
        .header {
            border-bottom: 3px solid #e2e8f0;
            padding-bottom: 25px;
            margin-bottom: 30px;
        }
        .estate-name {
            font-size: 18px;
            text-transform: uppercase;
            letter-spacing: 3px;
            color: #64748b;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .campaign-title {
            font-size: 32px;
            font-weight: 900;
            color: #1e3a8a;
            margin: 0 0 10px 0;
            line-height: 1.2;
        }
        .campaign-desc {
            font-size: 16px;
            color: #475569;
            max-width: 500px;
            margin: 0 auto;
        }
        .amount-badge {
            display: inline-block;
            background: #1e3a8a;
            color: #ffffff;
            font-size: 28px;
            font-weight: 800;
            padding: 10px 25px;
            border-radius: 50px;
            margin: 25px 0;
        }
        .qr-wrapper {
            margin: 20px auto;
            padding: 15px;
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            display: inline-block;
        }
        .qr-img {
            width: 220px;
            height: 220px;
            display: block;
        }
        .scan-prompt {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin-top: 15px;
        }
        .scan-sub {
            font-size: 14px;
            color: #64748b;
            margin-top: 5px;
        }
        .methods-footer {
            margin-top: 35px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
            font-size: 13px;
            color: #475569;
        }
        .methods-list {
            font-weight: 600;
            color: #0f172a;
            margin-top: 5px;
            font-size: 14px;
        }
        .url-text {
            font-family: monospace;
            font-size: 12px;
            color: #64748b;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="poster">
        <div class="header">
            <div class="estate-name">{{ $community->name ?? 'Community' }}</div>
            <h1 class="campaign-title">{{ $paymentLink->title }}</h1>
            @if($paymentLink->description)
                <p class="campaign-desc">{{ $paymentLink->description }}</p>
            @endif
        </div>

        @if($paymentLink->amount_minor)
            <div>
                <span class="amount-badge">{{ $paymentLink->formattedAmount() }}</span>
            </div>
        @endif

        <div class="qr-wrapper">
            {{-- Rendered on the server (App\Services\QrCodePng) and inlined as a
                 data: URI. It was fetched from api.qrserver.com, which handed a
                 third party every payment link, and cannot load at all with
                 remote fetching off. --}}
            <img class="qr-img" src="{{ $qrImage }}" alt="Scan to Pay">
        </div>

        <div class="scan-prompt">Point Your Phone Camera to Pay</div>
        <div class="scan-sub">Supports instant 1-tap checkout on any smartphone</div>

        <div class="methods-footer">
            <div>ACCEPTED PAYMENT CHANNELS:</div>
            <div class="methods-list">
                Apple Pay • Google Pay • Samsung Wallet • Credit/Debit Cards • Bank Wire • Cash App • Zelle
            </div>
            <div class="url-text">Direct Web Link: {{ $paymentLink->publicUrl() }}</div>
        </div>
    </div>
</body>
</html>
