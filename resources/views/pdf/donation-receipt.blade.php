<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Donation Record #{{ $donation->receipt_number }}</title>
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
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
        }
        .amount-val {
            font-size: 32px;
            font-weight: 900;
            color: #1e3a8a;
            margin-top: 5px;
        }
        .tax-note {
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            padding: 12px 15px;
            font-size: 13px;
            color: #1e40af;
            line-height: 1.5;
            margin-top: 25px;
        }
        .footer {
            margin-top: 40px;
            border-top: 1px solid #e2e8f0;
            padding-top: 15px;
            font-size: 12px;
            color: #94a3b8;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <span class="receipt-badge">DONATION RECORD</span>
        <div class="org-name">{{ $community->name ?? 'Community' }}</div>
        <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Community Fund Contribution Record</div>
    </div>

    <table class="meta-table">
        <tr>
            <td class="label">Receipt Number:</td>
            <td class="value">#{{ $donation->receipt_number ?? ('DON-' . $donation->id) }}</td>
        </tr>
        <tr>
            <td class="label">Date Issued:</td>
            <td class="value">{{ $donation->donated_at->format('F d, Y - h:i A') }}</td>
        </tr>
        <tr>
            <td class="label">Donor Name:</td>
            <td class="value">{{ $donation->donor_name ?? 'Anonymous Neighbor' }}</td>
        </tr>
        <tr>
            <td class="label">Campaign / Project:</td>
            <td class="value">{{ $donation->fundraiser->title }}</td>
        </tr>
        <tr>
            <td class="label">Beneficiary:</td>
            <td class="value">{{ $donation->fundraiser->beneficiary ?? 'Community Improvement Fund' }}</td>
        </tr>
        <tr>
            <td class="label">Pledge Type:</td>
            <td class="value">{{ $donation->is_recurring ? 'Monthly Recurring Pledge' : 'One-Time Contribution' }}</td>
        </tr>
    </table>

    <div class="amount-box">
        <div class="amount-title">Total Contribution Received</div>
        <div class="amount-val">${{ number_format($donation->amount_minor / 100, 2) }} {{ $donation->currency }}</div>
    </div>

    {{--
        This block previously read "Tax & Remittance Certification" and stated
        that the contribution "was received in full with zero goods or services
        exchanged", and that the HOA "holds registered non-profit / HOA status
        under Jamaica Statutory Provisions".

        None of that is established anywhere in this application. Donations are
        recorded by the donor themselves and no payment is taken, so the system
        cannot certify receipt; and no charitable registration is held, checked
        or stored. A donor could have presented this to a tax authority.

        If the community is in fact registered, put the registration number and
        the issuing body in configuration and state them here as facts, with
        wording a local accountant has approved.
    --}}
    <div class="tax-note">
        <strong>What this document is:</strong><br>
        A record of a contribution entered against this fundraiser. It is not a tax receipt and
        makes no statement about the deductibility of this contribution or about the charitable
        status of the community. Contact the community office if you need a formal receipt.
    </div>

    <div class="footer">
        Generated by Community Hub • Reference: {{ $donation->receipt_number ?? ('DON-' . $donation->id) }}
    </div>
</body>
</html>
