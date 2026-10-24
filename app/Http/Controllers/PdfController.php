<?php

namespace App\Http\Controllers;

use App\Models\Community;
use App\Models\GatePass;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Visitor;
use App\Services\QrCodePng;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * PDF documents.
 *
 * Every method here loads a record by route-model binding, so each one needs
 * to answer "may this caller see this record?" independently — the route
 * group's `auth` middleware only establishes *who* is asking. None of them
 * did, which made each an enumerable read of another household's document.
 */
class PdfController extends Controller
{
    /**
     * Download official HOA statement / invoice PDF.
     *
     * Route sits inside `can:accessBilling`, which now admits every resident,
     * so the gate alone let any household download any other household's
     * statement by incrementing the id.
     */
    public function downloadInvoice(Request $request, Invoice $invoice): Response
    {
        $this->authorizeInvoice($request->user(), $invoice);

        $invoice->load('user');
        $community = Community::first();

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'community' => $community,
        ])->setPaper('a4', 'portrait');

        return $pdf->download("statement-{$invoice->reference}.pdf");
    }

    /**
     * Download visitor entry permit PDF.
     *
     * Keyed on the share token, not the visitor id.
     *
     * This route is public — it backs the "Download Printable PDF Pass" button
     * on the guest pass page, which a visitor reaches with a token they were
     * sent. Binding it to `{visitor}` meant the id was the credential, so
     * walking 1..n returned every guest's name, host lot and vehicle to an
     * unauthenticated caller. The token is the credential the guest actually
     * holds, and GuestPassController::show() already treats it that way.
     */
    public function downloadVisitorPass(string $token, QrCodePng $qr): Response
    {
        $visitor = Visitor::with('homeowner')->where('share_token', $token)->firstOrFail();
        $community = Community::first();

        $qrData = route('guest-pass.show', ['token' => $visitor->share_token]);

        $pdf = Pdf::loadView('pdf.gate-pass', [
            'name' => $visitor->name,
            'category' => $visitor->type ?? 'Visitor',
            'lot' => $visitor->homeowner?->lot ?? 'Residential Lot',
            'vehicle' => $visitor->vehicle,
            'qrImage' => $this->qrImage($qr, $qrData),
            'community' => $community,
        ])->setPaper('a5', 'landscape');

        return $pdf->download("visitor-permit-{$visitor->id}.pdf");
    }

    /**
     * Download resident gate pass permit PDF.
     *
     * A pass is a credential document naming its holder and their lot. Only
     * the holder and the security roles that police the gate may print one.
     */
    public function downloadGatePass(Request $request, GatePass $gatePass, QrCodePng $qr): Response
    {
        $user = $request->user();

        abort_unless(
            $gatePass->user_id === $user->id || $user->can('manageSecurity'),
            403,
            'You can only download your own gate pass.',
        );

        $gatePass->load('user');
        $community = Community::first();

        $pdf = Pdf::loadView('pdf.gate-pass', [
            'name' => $gatePass->user->name,
            'category' => $gatePass->category->value ?? 'Resident',
            'lot' => $gatePass->user->lot ?? 'Residential Zone',
            'vehicle' => 'REGISTERED RESIDENT',
            'qrImage' => $this->qrImage($qr, "GATE-PASS-{$gatePass->pass_id}"),
            'community' => $community,
        ])->setPaper('a5', 'landscape');

        return $pdf->download("resident-permit-{$gatePass->pass_id}.pdf");
    }

    /**
     * The QR as an inline PNG data: URI, rendered here rather than by a third
     * party; DomPDF draws data: URIs with remote fetching disabled. 180 px
     * matches the slot in pdf/gate-pass.blade.php; margin 4 is the standard
     * quiet zone, which printed codes need more than screens do.
     */
    private function qrImage(QrCodePng $qr, string $content): string
    {
        return 'data:image/png;base64,'.base64_encode($qr->render($content, 180, 4));
    }

    /**
     * A statement is readable by the household it bills and by the
     * administrators who issue it. Nobody else, including other residents.
     */
    private function authorizeInvoice(User $user, Invoice $invoice): void
    {
        abort_unless(
            $invoice->user_id === $user->id || $user->can('manageBilling'),
            403,
            'You can only view your own statements.',
        );
    }
}
