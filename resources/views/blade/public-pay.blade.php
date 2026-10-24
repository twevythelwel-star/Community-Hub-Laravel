@extends('layouts.public')

@section('title', $paymentLink->title . ' — Community Checkout')

@section('content')
<main id="main" class="min-h-[85vh] py-10 px-4 sm:px-6 lg:px-8 max-w-xl mx-auto">
    <div class="bg-card border border-border shadow-xl rounded-2xl overflow-hidden">
        <!-- Header Banner -->
        <div class="bg-gradient-to-r from-primary to-primary/80 p-6 text-primary-foreground text-center">
            <p class="text-xs uppercase tracking-widest font-semibold opacity-80">{{ $community->name ?? 'Cypress Bay Community' }}</p>
            <h1 class="text-2xl font-black mt-1">{{ $paymentLink->title }}</h1>
            @if($paymentLink->description)
                <p class="text-sm opacity-90 mt-2 max-w-md mx-auto">{{ $paymentLink->description }}</p>
            @endif
        </div>

        @if($paymentLink->invoice && $paymentLink->invoice->status === 'Paid')
            <div class="p-8 text-center space-y-4 bg-emerald-500/10 border border-emerald-500/30 rounded-2xl m-6">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-emerald-600 text-white font-black text-2xl shadow-lg">✓</div>
                <h2 class="text-2xl font-black text-emerald-900 dark:text-emerald-100">Invoice Settled</h2>
                <p class="text-sm text-emerald-700 dark:text-emerald-300 max-w-sm mx-auto">
                    This invoice (<span class="font-mono font-bold">{{ $paymentLink->invoice->reference }}</span>) has already been paid in full on {{ $paymentLink->invoice->paid_at?->format('M d, Y h:i A') ?? 'record' }}. No further payment is required.
                </p>
                <div class="pt-2">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-emerald-200 dark:bg-emerald-900 text-emerald-900 dark:text-emerald-200">
                        Zero Balance Due
                    </span>
                </div>
            </div>
        @else
        <div class="p-6 sm:p-8 space-y-6">
            <!-- Amount Display -->
            <div class="bg-muted/50 border border-border rounded-xl p-5 text-center">
                <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Amount Due</span>
                <div class="text-4xl font-extrabold text-foreground mt-1">
                    {{ $paymentLink->formattedAmount() }}
                </div>
                <div class="flex items-center justify-center gap-2 mt-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                        Verified Community Request
                    </span>
                    <span class="text-xs text-muted-foreground">• Ref: #{{ substr($paymentLink->token, 0, 8) }}</span>
                </div>
            </div>

            <!-- Fast 1-Tap Payment Grid -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground mb-3">
                    Fast 1-Tap Checkout
                </label>
                <div class="grid grid-cols-3 gap-3">
                    <button onclick="simulateFastPay('Apple Pay')" type="button" class="flex flex-col items-center justify-center p-3.5 rounded-xl border border-border bg-slate-900 text-white hover:bg-black transition-all shadow-sm group">
                        <span class="text-xs font-semibold"> Apple Pay</span>
                        <span class="text-[10px] text-slate-400 mt-0.5">1-Tap Touch ID</span>
                    </button>
                    <button onclick="simulateFastPay('Google Pay')" type="button" class="flex flex-col items-center justify-center p-3.5 rounded-xl border border-border bg-white text-slate-900 hover:bg-slate-50 transition-all shadow-sm">
                        <span class="text-xs font-bold text-blue-600">G Pay</span>
                        <span class="text-[10px] text-muted-foreground mt-0.5">Android / Web</span>
                    </button>
                    <button onclick="simulateFastPay('Samsung Wallet')" type="button" class="flex flex-col items-center justify-center p-3.5 rounded-xl border border-border bg-blue-900 text-white hover:bg-blue-950 transition-all shadow-sm">
                        <span class="text-xs font-bold">Samsung</span>
                        <span class="text-[10px] text-blue-200 mt-0.5">Wallet</span>
                    </button>
                </div>
            </div>

            <!-- Credit / Debit Card Direct Checkout Form -->
            <form action="{{ route('pay.process', ['token' => $paymentLink->token]) }}" method="POST" class="space-y-4 pt-2">
                @csrf
                <input type="hidden" name="channel" value="stripe_card">

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground mb-1.5">
                        Payer Name & Lot Number
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <input type="text" name="payer_name" required placeholder="Full Name" class="w-full px-3.5 py-2.5 rounded-lg border border-border bg-background text-sm focus:ring-2 focus:ring-primary focus:outline-none">
                        <input type="text" name="lot" required placeholder="Lot # (e.g. Lot 42)" class="w-full px-3.5 py-2.5 rounded-lg border border-border bg-background text-sm focus:ring-2 focus:ring-primary focus:outline-none">
                    </div>
                </div>

                @if(!$paymentLink->amount_minor)
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground mb-1.5">
                        Custom Contribution Amount ($ JMD)
                    </label>
                    <input type="number" step="1" min="100" name="custom_amount" required placeholder="e.g. 5000" class="w-full px-3.5 py-2.5 rounded-lg border border-border bg-background text-sm font-semibold focus:ring-2 focus:ring-primary focus:outline-none">
                </div>
                @endif

                {{--
                    This was a card number, expiry and CVC field labelled
                    "🔒 256-bit Encrypted", on a page anyone with the link can
                    reach without signing in. The inputs carried no `name`
                    attribute, so nothing was ever submitted, and the claim was
                    not backed by anything. A card field that goes nowhere is
                    worse than none: somebody types a real card number into it.

                    Card details belong on the payment provider's own page.
                --}}
                <div class="p-3.5 rounded-xl border border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/40 text-xs text-amber-900 dark:text-amber-200">
                    <p class="font-semibold mb-1">Card payment is not yet in service</p>
                    <p>Use one of the other methods above, or contact the community office to arrange payment. Your card details are never entered on this page.</p>
                </div>

                <button type="submit" class="w-full py-3.5 px-4 bg-primary text-primary-foreground font-bold rounded-xl shadow hover:bg-primary/90 transition-all flex items-center justify-center gap-2">
                    <span>Pay {{ $paymentLink->formattedAmount() }}</span>
                </button>
            </form>

            <!-- Other Options Accordion -->
            <div class="border-t border-border pt-5 space-y-3">
                <span class="block text-xs font-bold uppercase tracking-wider text-muted-foreground">
                    Direct Wire & Peer Rails
                </span>
                <div class="grid grid-cols-2 gap-2.5 text-xs">
                    <a href="https://cash.app/$CypressBayHOA" target="_blank" class="p-2.5 rounded-lg border border-border bg-card hover:bg-muted text-center font-semibold text-emerald-600 dark:text-emerald-400 block transition-colors">
                        Cash App ($CypressBayHOA)
                    </a>
                    <a href="mailto:payments@cypressbay.org?subject=Zelle%20Payment" class="p-2.5 rounded-lg border border-border bg-card hover:bg-muted text-center font-semibold text-purple-600 dark:text-purple-400 block transition-colors">
                        Zelle (payments@cypressbay.org)
                    </a>
                </div>

                <div class="p-3 bg-muted/40 rounded-lg text-xs text-muted-foreground space-y-1">
                    <span class="font-bold text-foreground">Bank Wire:</span> National Commercial Bank (NCB) • Account #102938475 • Branch 001
                </div>
            </div>

            <!-- PDF Poster Download Action -->
            <div class="border-t border-border pt-4 text-center">
                <a href="{{ route('pay.poster', ['token' => $paymentLink->token]) }}" target="_blank" class="inline-flex items-center gap-2 text-xs font-semibold text-primary hover:underline">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    <span>Download Printable QR Poster (PDF Flyer)</span>
                </a>
            </div>
        </div>
        @endif
    </div>
</main>

<script>
function simulateFastPay(method) {
    alert(method + ' requires an active Jamaican acquiring merchant gateway session. Your payment intent must be processed and verified server-side by the provider before the invoice can be settled.');
}
</script>
@endsection
