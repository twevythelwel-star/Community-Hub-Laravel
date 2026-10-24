<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Official Payment Receipt #{{ $transaction->receipt_number ?? $transaction->reference }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #0f172a;
            margin: 0;
            padding: 30px;
        }
        .header {
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }
        .org-name {
            font-size: 20px;
            font-weight: 800;
            color: #1e3a8a;
        }
        .receipt-badge {
            float: right;
            font-size: 14px;
            font-weight: bold;
            color: #059669;
            background: #ecfdf5;
            border: 1px solid #10b981;
            padding: 5px 12px;
            border-radius: 6px;
        }
        .meta-table {
            width: 100%;
            margin-bottom: 25px;
            border-collapse: collapse;
        }
        .meta-table td {
            padding: 8px 10px;
            font-size: 14px;
        }
        .label {
            font-weight: 600;
            color: #64748b;
            width: 35%;
        }
        .value {
            color: #0f172a;
            font-weight: 700;
        }
        .amount-box {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 25px 0;
        }
        .amount-title {
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
        }
        .amount-value {
            font-size: 32px;
            font-weight: 800;
            color: #0f172a;
        }
        .footer {
            border-top: 1px solid #e2e8f0;
            padding-top: 15px;
            margin-top: 40px;
            font-size: 11px;
            color: #94a3b8;
            text-align: center;
        }
        .clear {
            clear: both;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="receipt-badge">OFFICIAL PAYMENT RECEIPT</div>
        <div class="org-name">{{ $community->name ?? 'Community HOA' }}</div>
        <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Treasury & Revenue Orchestration Engine</div>
        <div class="clear"></div>
    </div>

    <table class="meta-table">
        <tr>
            <td class="label">Receipt Number</td>
            <td class="value">{{ $transaction->receipt_number ?? ('REC-'.str_pad($transaction->id, 6, '0', STR_PAD_LEFT)) }}</td>
        </tr>
        <tr>
            <td class="label">Transaction Reference</td>
            <td class="value" style="font-family: monospace;">{{ $transaction->reference }}</td>
        </tr>
        <tr>
            <td class="label">Payment Date / Time</td>
            <td class="value">{{ $transaction->created_at->format('F d, Y h:i:s A') }}</td>
        </tr>
        <tr>
            <td class="label">Payee / Account</td>
            <td class="value">{{ $transaction->user?->name ?? 'Community Resident' }} {{ $transaction->user?->lot ? '(Lot '.$transaction->user->lot.')' : '' }}</td>
        </tr>
        @if($transaction->invoice)
        <tr>
            <td class="label">Associated Statement</td>
            <td class="value">{{ $transaction->invoice->reference }} (Due {{ $transaction->invoice->due_on->format('M d, Y') }})</td>
        </tr>
        @endif
        <tr>
            <td class="label">Payment Channel</td>
            <td class="value" style="text-transform: capitalize;">{{ str_replace('_', ' ', $transaction->payment_channel) }}</td>
        </tr>
        <tr>
            <td class="label">Settlement Status</td>
            <td class="value" style="color: #059669; text-transform: uppercase;">{{ $transaction->status }}</td>
        </tr>
        @if($transaction->notes)
        <tr>
            <td class="label">Ledger Memo</td>
            <td class="value" style="font-weight: 500; color: #475569;">{{ $transaction->notes }}</td>
        </tr>
        @endif
    </table>

    <div class="amount-box">
        <div class="amount-title">Total Settled Amount</div>
        <div class="amount-value">{{ $transaction->currency }} {{ number_format($transaction->amount_minor / 100, 2) }}</div>
    </div>

    <div style="font-size: 12px; color: #64748b; line-height: 1.6; margin-top: 20px;">
        This document serves as formal confirmation of payment processed through the {{ $community->name ?? 'Community' }} Treasury. Payment is recorded in minor units to the Master Ledger and reconciled with financial settlement institutions.
    </div>

    <div class="footer">
        Generated electronically on {{ now()->format('Y-m-d H:i:s T') }} &bull; Transaction ID #{{ $transaction->id }} &bull; Non-Custodial Payment Engine
    </div>
</body>
</html>
