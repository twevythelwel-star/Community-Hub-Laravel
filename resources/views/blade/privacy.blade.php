@extends('layouts.public')

@section('title', 'Privacy Policy — ' . ($branding->app_name ?? 'Community Hub'))
@section('description', 'How Community Hub collects, uses, shares and protects resident data.')

@section('content')
<article class="mx-auto max-w-3xl px-4 py-12">

    <header class="mb-10">
        <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">Privacy Policy</h1>
        <p class="mt-2 text-sm text-muted-foreground">Effective {{ \Illuminate\Support\Carbon::parse(config('legal.effective_from'))->toFormattedDateString() }}</p>
    </header>

    @include('blade.partials.legal-placeholder-notice')

    <div class="space-y-10">

        <section aria-labelledby="collect">
            <h2 id="collect" class="text-xl font-semibold tracking-tight">1. Information We Collect</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li><strong class="text-foreground">Account details:</strong> name, email address, phone number, and role within the community.</li>
                <li><strong class="text-foreground">Identity documents of visitors and household staff:</strong> the document type, its number, its expiry date and, where one is uploaded, a photograph of the document and of the person. These are supplied by the resident who registers them.</li>
                <li><strong class="text-foreground">Movement records:</strong> every entry and exit scanned at a gate — who, which gate, when, and whether entry was allowed or refused, with the reason for a refusal.</li>
                <li><strong class="text-foreground">Community activity:</strong> visitor registrations, gate pass usage, safety alerts and how you voted on them, feedback, and billing and donation records.</li>
                <li><strong class="text-foreground">Blocklist entries:</strong> where someone is refused entry to the estate, their name, the stated reason and any photograph supplied.</li>
                <li><strong class="text-foreground">Communications:</strong> feedback and messages submitted through the app.</li>
                <li><strong class="text-foreground">AI-generated content:</strong> documents you provide for AI-assisted notification generation.</li>
                <li><strong class="text-foreground">Technical data:</strong> browser type, device type, and session information.</li>
            </ul>
        </section>

        <section aria-labelledby="use">
            <h2 id="use" class="text-xl font-semibold tracking-tight">2. How We Use Your Information</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li>To provide and improve the Community Hub platform and its features.</li>
                <li>To send community-wide notifications and announcements you have authorised.</li>
                <li>To process billing and HOA dues management.</li>
                <li>To maintain security records, visitor logs, and access control.</li>
                <li>To power AI-assisted features (where you explicitly opt in).</li>
            </ul>
        </section>

        <section aria-labelledby="share">
            <h2 id="share" class="text-xl font-semibold tracking-tight">3. Information Sharing</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                We do not sell your personal information. We share it only with:
            </p>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-relaxed text-muted-foreground">
                @foreach(config('legal.processors') as $processor)
                    <li>
                        <strong class="text-foreground">{{ $processor['name'] }}</strong>
                        @unless($processor['active'])
                            <span class="text-xs">(not currently in use)</span>
                        @endunless
                        — {{ $processor['purpose'] }} {{ $processor['data'] }}
                    </li>
                @endforeach
                <li>Whoever hosts this application on the community's behalf.</li>
                <li>Law enforcement or regulators, where required by law.</li>
            </ul>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                This application contains no analytics, advertising or tracking software. Nothing
                about your use of it is sent to an advertising network or a measurement provider,
                because none is installed.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Within the community, your name, role and property are visible to administrators.
                Your email address and phone number are not shown to other residents.
            </p>
        </section>

        <section aria-labelledby="rights" id="your-rights">
            <h2 id="rights" class="text-xl font-semibold tracking-tight">4. Your Rights</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li><strong class="text-foreground">Access:</strong> You may request a copy of the data held about you.</li>
                <li><strong class="text-foreground">Correction:</strong> You may update your display name and phone number on the Profile page. Your legal name and sign-in address are changed by an administrator.</li>
                <li><strong class="text-foreground">Erasure:</strong> You may request erasure by writing to {{ config('legal.contact.privacy_email') }}. See below for what can and cannot be erased.</li>
                <li><strong class="text-foreground">Portability:</strong> Contact {{ config('legal.contact.privacy_email') }} to request an export.</li>
            </ul>

            {{--
                This list previously said: "Deletion: You may permanently delete
                your account via Settings > Deactivation."

                That was not true. Deactivation sets the account's status to
                Inactive, revokes the gate pass and ends the session. No record
                is erased — deliberately, because the access log is the estate's
                security history. Telling somebody their data was permanently
                deleted when it was retained is a misstatement about a data
                right, so the mechanism is described plainly instead.
            --}}
            <h3 class="mt-6 text-base font-semibold tracking-tight">Deactivation is not erasure</h3>
            <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                Closing your account from the Deactivation page marks it inactive, revokes your
                gate pass and signs you out. It does not delete your records. Community security
                history — gate entries and exits, visitors you registered, safety alerts you raised
                — is retained, because it is the estate's record of who came and went rather than
                only yours.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                To ask for erasure of data that is not subject to that retention, write to
                {{ config('legal.contact.privacy_email') }} or to
                {{ config('legal.contact.postal_address') }}. Say what you want erased. We will
                tell you what can be erased, what must be kept, and why.
            </p>
        </section>

        <section aria-labelledby="security">
            <h2 id="security" class="text-xl font-semibold tracking-tight">5. Security</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Credentials are stored using one-way hashing, sessions are protected against fixation
                and cross-site request forgery, and digital gate passes are signed server-side with a
                key that is never sent to your device. Access to security records is restricted by role.
            </p>
        </section>

        <section aria-labelledby="retention">
            <h2 id="retention" class="text-xl font-semibold tracking-tight">6. Data Retention</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Access and visitor records are retained for as long as the community requires them for
                security and audit purposes. Deactivating your account revokes your gate pass immediately;
                associated security records are retained where a legitimate community interest applies.
            </p>
        </section>

        <section aria-labelledby="children">
            <h2 id="children" class="text-xl font-semibold tracking-tight">7. Children</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Accounts are for adults. You must be at least {{ config('legal.minimum_age') }} to
                hold one, and accounts are created by community administrators rather than by
                self-registration.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                The application does not ask for anyone's date of birth and has no age
                verification, so it must not be offered to children while that remains the case. If
                you believe a child's personal data has reached us — for example in a visitor
                registration — write to {{ config('legal.contact.privacy_email') }} and it will be
                removed unless the estate is required to keep it.
            </p>
        </section>

        <section aria-labelledby="contact">
            <h2 id="contact" class="text-xl font-semibold tracking-tight">8. Contact</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Questions about this policy, or about the data held on you, should go to
                {{ config('legal.contact.privacy_email') }}, or in writing to
                {{ config('legal.contact.postal_address') }}. The data controller is
                {{ config('legal.entity.name') }}.
            </p>
        </section>
    </div>
</article>
@endsection
