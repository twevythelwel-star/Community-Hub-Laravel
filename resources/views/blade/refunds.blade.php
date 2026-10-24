@extends('layouts.public')

@section('title', 'Refund Policy — ' . ($branding->app_name ?? 'Community Hub'))
@section('description', 'How overpaid community dues, duplicate payments and donations are handled.')

@section('content')
<article class="mx-auto max-w-3xl px-4 py-12">

    <header class="mb-10">
        <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">Refund Policy</h1>
        <p class="mt-2 text-sm text-muted-foreground">
            Effective {{ \Illuminate\Support\Carbon::parse(config('legal.effective_from'))->toFormattedDateString() }}
        </p>
    </header>

    @include('blade.partials.legal-placeholder-notice')

    <div class="space-y-10">

        <section aria-labelledby="scope">
            <h2 id="scope" class="text-xl font-semibold tracking-tight">1. What this covers</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Community dues and assessments recorded against your household, and donations to
                community fundraisers. It does not cover anything you pay directly to a third
                party, such as a contractor engaged through a community listing.
            </p>
        </section>

        <section aria-labelledby="dues">
            <h2 id="dues" class="text-xl font-semibold tracking-tight">2. Community dues</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Dues fund the running of the estate and are generally <strong class="text-foreground">not refundable</strong>
                once the period they relate to has begun. You may ask for a refund where:
            </p>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li>you were charged twice for the same period;</li>
                <li>you paid more than the invoice amount;</li>
                <li>an invoice was raised against the wrong household; or</li>
                <li>a payment was taken after you ceased to be a resident.</li>
            </ul>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Where a refund is agreed, the community may instead apply the amount as a credit
                against your next invoice, with your agreement.
            </p>
        </section>

        <section aria-labelledby="donations">
            <h2 id="donations" class="text-xl font-semibold tracking-tight">3. Fundraiser donations</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Donations are voluntary and are treated as final once the funds have been applied
                to the project they were given for. If you gave the wrong amount, or gave to the
                wrong fundraiser, contact the community office as soon as you notice and it will be
                corrected where the funds have not yet been committed.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                A record of a donation in the application is a record of what was pledged. It is
                not a tax receipt and makes no statement about deductibility.
            </p>
        </section>

        <section aria-labelledby="how">
            <h2 id="how" class="text-xl font-semibold tracking-tight">4. How to request a refund</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Write to {{ config('legal.contact.general_email') }}, or to
                {{ config('legal.contact.postal_address') }}, with:
            </p>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li>your name and lot or unit number;</li>
                <li>the invoice reference or donation reference; and</li>
                <li>what you believe went wrong.</li>
            </ul>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Your invoice references are listed on the Billing page when you are signed in.
            </p>
        </section>

        <section aria-labelledby="timing">
            <h2 id="timing" class="text-xl font-semibold tracking-tight">5. Timing and method</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                We aim to acknowledge a request within
                <strong class="text-foreground">[NUMBER]</strong> working days and to resolve it
                within <strong class="text-foreground">[NUMBER]</strong> working days of
                acknowledgement. Refunds are returned by the method the payment was made in
                wherever possible.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                <em>These periods are placeholders. Set them to what the community can actually
                meet — a target that is missed routinely is worse than a longer one that is
                kept.</em>
            </p>
        </section>

        <section aria-labelledby="disputes">
            <h2 id="disputes" class="text-xl font-semibold tracking-tight">6. If you disagree with the outcome</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                You may raise the matter with the community committee through the Feedback page, or
                in writing to {{ config('legal.contact.postal_address') }}. Nothing here affects
                any statutory right you have in {{ config('legal.entity.jurisdiction') }}, or your
                right to dispute a card payment with your bank.
            </p>
        </section>

        <nav aria-label="Related policies" class="border-t border-border/60 pt-6 text-sm">
            <p class="text-muted-foreground">
                See also the
                <a href="{{ route('terms') }}" class="underline hover:text-foreground">Terms of Service</a>,
                <a href="{{ route('privacy') }}" class="underline hover:text-foreground">Privacy Policy</a>
                and <a href="{{ route('cookies') }}" class="underline hover:text-foreground">Cookie Policy</a>.
            </p>
        </nav>

    </div>
</article>
@endsection
