<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Vehicle Gate Pass Permit</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #0f172a;
            margin: 0;
            padding: 20px;
        }
        .permit-card {
            border: 4px solid #0f172a;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
        }
        .permit-header {
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .title {
            font-size: 26px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin: 0;
            color: #1e3a8a;
        }
        .subtitle {
            font-size: 13px;
            color: #64748b;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .qr-section {
            margin: 20px 0;
        }
        .qr-box {
            display: inline-block;
            padding: 10px;
            background: #ffffff;
            border: 2px solid #cbd5e1;
            border-radius: 8px;
        }
        .details-grid {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            text-align: left;
        }
        .details-grid td {
            padding: 8px 12px;
            font-size: 14px;
            border-bottom: 1px solid #f1f5f9;
        }
        .label {
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
            font-weight: bold;
        }
        .val {
            font-size: 16px;
            font-weight: bold;
            color: #0f172a;
        }
        .notice {
            background-color: #fef08a;
            border: 1px solid #eab308;
            padding: 10px;
            font-size: 12px;
            font-weight: bold;
            color: #713f12;
            border-radius: 6px;
            margin-top: 15px;
            text-transform: uppercase;
        }
    </style>
</head>
<body>

    <div class="permit-card">
        <div class="permit-header">
            <h1 class="title">{{ strtoupper($community->name ?? 'Community Access') }}</h1>
            <div class="subtitle">OFFICIAL VEHICLE DASHBOARD ENTRY PERMIT</div>
        </div>

        <div class="qr-section">
            <div class="qr-box">
                {{-- Rendered on the server (App\Services\QrCodePng) and inlined as a
                     data: URI. It was fetched from api.qrserver.com, which handed a
                     third party each pass's QR content — for a visitor, the bearer
                     guest-pass link — and cannot load at all with remote fetching off. --}}
                <img
                    src="{{ $qrImage }}"
                    alt="Access QR Code"
                    width="180" 
                    height="180"
                />
            </div>
            <div style="font-size: 11px; color: #64748b; margin-top: 6px;">SCAN AT SECURITY ACCESS BOOTH</div>
        </div>

        <table class="details-grid">
            <tr>
                <td style="width: 50%;">
                    <div class="label">Authorized Holder / Visitor</div>
                    <div class="val">{{ $name }}</div>
                </td>
                <td style="width: 50%;">
                    <div class="label">Pass Category</div>
                    <div class="val">{{ strtoupper($category) }}</div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="label">Destination Property</div>
                    <div class="val">{{ $lot ?: 'Authorized Zone' }}</div>
                </td>
                <td>
                    <div class="label">Vehicle Plate</div>
                    <div class="val">{{ $vehicle ?: 'REGISTERED' }}</div>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <div class="label">Permitted Entry Gate(s)</div>
                    <div class="val" style="color: #2563eb;">MAIN GATE 1, NORTH VISITOR ACCESS</div>
                </td>
            </tr>
        </table>

        <div class="notice">
            NOTICE: MUST BE DISPLAYED FACE UP ON FRONT VEHICLE DASHBOARD AT ALL TIMES WHILE ON PROPERTY
        </div>
    </div>

</body>
</html>
