@extends('layouts.public')

@section('title', 'Terms of Service — ' . ($branding->app_name ?? 'Community Hub'))
@section('description', 'The terms on which residents and staff may use the Community Hub platform.')

@section('content')
<article class="mx-auto max-w-3xl px-4 py-12">

    <header class="mb-10">
        <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">Terms of Service</h1>
        <p class="mt-2 text-sm text-muted-foreground">
            Effective {{ \Illuminate\Support\Carbon::parse(config('legal.effective_from'))->toFormattedDateString() }}
        </p>
    </header>

    @include('blade.partials.legal-placeholder-notice')

    <div class="space-y-10">

        <section aria-labelledby="who">
            <h2 id="who" class="text-xl font-semibold tracking-tight">1. Who you are contracting with</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                This service is operated by {{ config('legal.entity.name') }}
                ({{ config('legal.entity.type') }}), registration number
                {{ config('legal.entity.registration_number') }}, of
                {{ config('legal.entity.registered_address') }}
                (&ldquo;we&rdquo;, &ldquo;us&rdquo;). Contact:
                {{ config('legal.contact.general_email') }},
                {{ config('legal.contact.phone') }}.
            </p>
        </section>

        <section aria-labelledby="eligibility">
            <h2 id="eligibility" class="text-xl font-semibold tracking-tight">2. Who may use it</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Accounts are created by community administrators for residents, household staff and
                security personnel. You may not create your own account, and you must be at least
                {{ config('legal.minimum_age') }} years old to hold one. The service is not
                designed for or directed at children.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                You are responsible for what happens under your account. Tell an administrator
                promptly if you believe someone else has access to it.
            </p>
        </section>

        <section aria-labelledby="acceptable">
            <h2 id="acceptable" class="text-xl font-semibold tracking-tight">3. Acceptable use</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li>Do not raise safety alerts you know to be false. Alerts reach the whole community.</li>
                <li>Do not register a visitor you do not expect, or share a guest pass with someone it was not issued for.</li>
                <li>Do not use another resident&rsquo;s details, or information from the community directory, for anything other than community business.</li>
                <li>Do not attempt to access records belonging to other households.</li>
            </ul>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Gate passes, visitor records and access logs exist for the safety of everyone on
                the estate. Misusing them may result in your account being deactivated.
            </p>
        </section>

        <section aria-labelledby="content">
            <h2 id="content" class="text-xl font-semibold tracking-tight">4. What you post</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                You keep ownership of what you submit — safety alerts, feedback, visitor details
                and similar. You grant us permission to store and display it within the community
                for the purpose it was submitted for. You confirm you have the right to submit it,
                including any photograph of another person.
            </p>
        </section>

        <section aria-labelledby="availability">
            <h2 id="availability" class="text-xl font-semibold tracking-tight">5. Availability, and what this service is not</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                We aim to keep the service available but do not guarantee uninterrupted access.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                <strong class="text-foreground">This is not an emergency service.</strong> A safety
                alert raised here notifies residents and community staff. It does not contact the
                police, fire or ambulance services. In an emergency, call the emergency services
                first.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                <strong class="text-foreground">Gate passes do not by themselves grant entry.</strong>
                Access is decided by security personnel at the gate.
            </p>
        </section>

        <section aria-labelledby="fees">
            <h2 id="fees" class="text-xl font-semibold tracking-tight">6. Dues and payments</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Community dues are set by the community, not by this application, which records
                them. The amount, currency and due date shown on the Billing page are whatever
                administrators have configured. Any fee that applies to a particular payment method
                will be shown before you confirm that payment; if no fee is shown, none is charged
                by us.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                See the <a href="{{ route('refunds') }}" class="underline hover:text-foreground">Refund Policy</a>
                for how overpayments and disputes are handled.
            </p>
        </section>

        <section aria-labelledby="termination">
            <h2 id="termination" class="text-xl font-semibold tracking-tight">7. Suspension and closure</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                An administrator may deactivate an account that breaches these terms or that
                belongs to someone who no longer lives on or works at the estate. You may close
                your own account from the Deactivation page. Deactivation ends your access and
                invalidates your gate pass; it does not erase community records such as access
                logs, which are kept as described in the
                <a href="{{ route('privacy') }}" class="underline hover:text-foreground">Privacy Policy</a>.
                To ask for erasure, see
                <a href="{{ route('privacy') }}#your-rights" class="underline hover:text-foreground">Your rights</a>.
            </p>
        </section>

        <section aria-labelledby="liability">
            <h2 id="liability" class="text-xl font-semibold tracking-tight">8. Liability</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Nothing in these terms limits liability for death or personal injury caused by
                negligence, for fraud, or for anything else that cannot lawfully be limited.
                Subject to that, we are not liable for indirect or consequential loss arising from
                use of the service.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                <em>This clause in particular must be reviewed by a qualified adviser in
                {{ config('legal.entity.jurisdiction') }} before this policy is published.</em>
            </p>
        </section>

        <section aria-labelledby="changes">
            <h2 id="changes" class="text-xl font-semibold tracking-tight">9. Changes</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                We may update these terms. Material changes will be announced through the community
                notice board in the application before they take effect.
            </p>
        </section>

        <section aria-labelledby="law">
            <h2 id="law" class="text-xl font-semibold tracking-tight">10. Governing law</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                These terms are governed by the laws of {{ config('legal.entity.jurisdiction') }}.
            </p>
        </section>

        <nav aria-label="Related policies" class="border-t border-border/60 pt-6 text-sm">
            <p class="text-muted-foreground">
                See also the
                <a href="{{ route('privacy') }}" class="underline hover:text-foreground">Privacy Policy</a>,
                <a href="{{ route('cookies') }}" class="underline hover:text-foreground">Cookie Policy</a>
                and <a href="{{ route('refunds') }}" class="underline hover:text-foreground">Refund Policy</a>.
            </p>
        </nav>

    </div>
</article>
@endsection
