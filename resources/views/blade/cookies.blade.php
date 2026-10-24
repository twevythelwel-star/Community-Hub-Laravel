@extends('layouts.public')

@section('title', 'Cookie Policy — ' . ($branding->app_name ?? 'Community Hub'))
@section('description', 'Which cookies Community Hub sets, what each one does, and why no tracking consent is requested.')

@section('content')
<article class="mx-auto max-w-3xl px-4 py-12">

    <header class="mb-10">
        <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">Cookie Policy</h1>
        <p class="mt-2 text-sm text-muted-foreground">
            Effective {{ \Illuminate\Support\Carbon::parse(config('legal.effective_from'))->toFormattedDateString() }}
        </p>
    </header>

    @include('blade.partials.legal-placeholder-notice')

    <div class="space-y-10">

        <section aria-labelledby="summary">
            <h2 id="summary" class="text-xl font-semibold tracking-tight">In short</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                This application sets no advertising cookies, no analytics cookies and no
                third-party tracking cookies. It contains no analytics or advertising software of
                any kind. The only cookies it sets are the ones required to keep you signed in and
                to protect forms against cross-site request forgery.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                That is why you are not asked to accept cookies. Consent is required for cookies
                that are not strictly necessary, and there are none here. A banner asking you to
                agree to cookies that cannot be switched off would be theatre, not consent.
            </p>
        </section>

        <section aria-labelledby="what">
            <h2 id="what" class="text-xl font-semibold tracking-tight">Cookies we set</h2>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Cookies set by this application, their purpose and lifetime</caption>
                    <thead class="border-b border-border">
                        <tr class="text-xs uppercase tracking-wide text-muted-foreground">
                            <th scope="col" class="py-2 pr-4 font-semibold">Name</th>
                            <th scope="col" class="py-2 pr-4 font-semibold">Purpose</th>
                            <th scope="col" class="py-2 font-semibold">Lifetime</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60 text-muted-foreground">
                        <tr>
                            <th scope="row" class="py-3 pr-4 font-mono text-xs font-normal text-foreground">
                                {{ config('session.cookie') }}
                            </th>
                            <td class="py-3 pr-4">
                                Keeps you signed in between page loads. Without it you would have to
                                sign in on every request.
                            </td>
                            <td class="py-3">{{ config('session.lifetime') }} minutes</td>
                        </tr>
                        <tr>
                            <th scope="row" class="py-3 pr-4 font-mono text-xs font-normal text-foreground">
                                XSRF-TOKEN
                            </th>
                            <td class="py-3 pr-4">
                                Proves a form submission came from a page we served, so another site
                                cannot act on your behalf while you are signed in.
                            </td>
                            <td class="py-3">{{ config('session.lifetime') }} minutes</td>
                        </tr>
                        <tr>
                            <th scope="row" class="py-3 pr-4 font-mono text-xs font-normal text-foreground">
                                remember_web_&hellip;
                            </th>
                            <td class="py-3 pr-4">
                                Set only if you tick &ldquo;Remember me&rdquo; when signing in. Keeps
                                you signed in after you close the browser.
                            </td>
                            <td class="py-3">Until you sign out</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-4 text-sm leading-relaxed text-muted-foreground">
                All three are strictly necessary. Blocking them stops you signing in.
            </p>
        </section>

        <section aria-labelledby="storage">
            <h2 id="storage" class="text-xl font-semibold tracking-tight">Browser storage that is not a cookie</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                One preference is kept in your browser&rsquo;s local storage rather than a cookie:
                <code class="rounded bg-muted px-1 text-xs">theme</code>, which remembers whether
                you chose light or dark appearance. It never leaves your device and is not sent to
                the server. Clearing your site data resets it.
            </p>
        </section>

        <section aria-labelledby="third-party">
            <h2 id="third-party" class="text-xl font-semibold tracking-tight">Third-party cookies</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                None are set by this application. If card payment is switched on, the payment
                provider&rsquo;s own page will set its own cookies while you are on it, and its
                cookie policy applies there. Nothing is embedded in these pages that would let it
                set a cookie here.
            </p>
        </section>

        <section aria-labelledby="control">
            <h2 id="control" class="text-xl font-semibold tracking-tight">Controlling cookies</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Every browser lets you view, block and delete cookies in its settings. Because the
                cookies here are the ones that keep you signed in, blocking them will prevent you
                from using the application. There is nothing else to opt out of.
            </p>
        </section>

        <section aria-labelledby="contact">
            <h2 id="contact" class="text-xl font-semibold tracking-tight">Questions</h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Write to {{ config('legal.contact.privacy_email') }}.
            </p>
        </section>

        <nav aria-label="Related policies" class="border-t border-border/60 pt-6 text-sm">
            <p class="text-muted-foreground">
                See also the
                <a href="{{ route('privacy') }}" class="underline hover:text-foreground">Privacy Policy</a>,
                <a href="{{ route('terms') }}" class="underline hover:text-foreground">Terms of Service</a>
                and <a href="{{ route('refunds') }}" class="underline hover:text-foreground">Refund Policy</a>.
            </p>
        </nav>

    </div>
</article>
@endsection
