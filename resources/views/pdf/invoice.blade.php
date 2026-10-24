<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->reference }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #1e293b;
            margin: 0;
            padding: 30px;
            font-size: 14px;
        }
        .header-table {
            width: 100%;
            margin-bottom: 40px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 20px;
        }
        .logo-text {
            font-size: 24px;
            font-weight: bold;
            color: #0f172a;
        }
        .invoice-title {
            text-align: right;
            font-size: 28px;
            font-weight: bold;
            color: #2563eb;
            text-transform: uppercase;
        }
        .details-table {
            width: 100%;
            margin-bottom: 30px;
        }
        .details-col {
            vertical-align: top;
            width: 50%;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .items-table th {
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 10px;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            color: #475569;
        }
        .items-table td {
            border: 1px solid #cbd5e1;
            padding: 12px 10px;
        }
        .total-box {
            text-align: right;
            margin-top: 20px;
        }
        .total-amount {
            font-size: 20px;
            font-weight: bold;
            color: #0f172a;
        }
        .stamp {
            display: inline-block;
            padding: 8px 16px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .stamp-paid {
            color: #15803d;
            border: 2px solid #15803d;
            background-color: #dcfce7;
        }
        .stamp-unpaid {
            color: #b91c1c;
            border: 2px solid #b91c1c;
            background-color: #fee2e2;
        }
        .footer {
            margin-top: 60px;
            text-align: center;
            font-size: 12px;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
            padding-top: 15px;
        }
    </style>
</head>
<body>

    <table class="header-table">
        <tr>
            <td>
                <div class="logo-text">{{ $community->name ?? 'Community Hub' }}</div>
                <div style="color: #64748b; font-size: 12px; margin-top: 4px;">
                    Residential Homeowners Association<br>
                    {{ $community->name ?? 'Community' }} Estate Operations
                </div>
            </td>
            <td class="invoice-title">
                STATEMENT
                <div style="font-size: 13px; color: #64748b; font-weight: normal; margin-top: 4px;">
                    REF: {{ $invoice->reference }}
                </div>
            </td>
        </tr>
    </table>

    <table class="details-table">
        <tr>
            <td class="details-col">
                <strong>BILLED TO:</strong><br>
                {{ $invoice->user->name }}<br>
                {{ $invoice->user->lot ?? 'Lot 42' }}, {{ $invoice->user->street ?? 'Residential Drive' }}<br>
                {{ $invoice->user->email }}
            </td>
            <td class="details-col" style="text-align: right;">
                <strong>Issue Date:</strong> {{ $invoice->period_start ? $invoice->period_start->format('M d, Y') : date('M d, Y') }}<br>
                <strong>Due Date:</strong> {{ $invoice->due_on ? $invoice->due_on->format('M d, Y') : date('M d, Y') }}<br>
                <strong>Billing Period:</strong> {{ $invoice->period_start?->format('M Y') ?? 'Current Cycle' }}<br>
                <div style="margin-top: 10px;">
                    <span class="stamp {{ $invoice->status === 'Paid' ? 'stamp-paid' : 'stamp-unpaid' }}">
                        {{ $invoice->status }}
                    </span>
                </div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th>Description</th>
                <th>Billing Cycle</th>
                <th style="text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <strong>Regular Assessment — HOA Maintenance & Operations</strong><br>
                    <span style="font-size: 12px; color: #64748b;">Includes 24/7 gate security, landscaping, pool & clubhouse utilities</span>
                </td>
                <td>{{ $invoice->period_start?->format('M Y') ?? 'Monthly' }}</td>
                <td style="text-align: right;">${{ number_format($invoice->amount(), 2) }} {{ $invoice->currency }}</td>
            </tr>
        </tbody>
    </table>

    <div class="total-box">
        <div style="color: #64748b; font-size: 13px;">Total Outstanding Balance:</div>
        <div class="total-amount">${{ number_format($invoice->amount(), 2) }} {{ $invoice->currency }}</div>
        @if($invoice->paid_at)
            <div style="color: #15803d; font-size: 12px; margin-top: 4px;">
                Settled on {{ $invoice->paid_at->format('M d, Y \a\t g:i A') }}
            </div>
        @endif
    </div>

    <div class="footer">
        Questions regarding this statement? Contact administration at billing@communityhub.org.<br>
        Thank you for maintaining our shared community environment.
    </div>

</body>
</html>
