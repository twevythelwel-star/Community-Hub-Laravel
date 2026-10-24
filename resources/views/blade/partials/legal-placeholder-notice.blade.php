{{--
    Shown on every policy page while config('legal.complete') is false.

    The point is that an unfinished policy should look unfinished. A template
    that reads like a published policy but names "[REGISTERED LEGAL NAME]" as
    the controller of someone's data is worse than an obviously blank one —
    a reader skims the headings and assumes it was reviewed.

    Set LEGAL_DETAILS_COMPLETE=true in .env once config/legal.php is filled in
    and the wording has been checked by someone qualified to check it.
--}}
@unless(config('legal.complete'))
    <div role="alert" class="mb-8 rounded-lg border border-amber-400 bg-amber-50 p-4 text-sm dark:border-amber-700 dark:bg-amber-950/50">
        <p class="font-semibold text-amber-900 dark:text-amber-200">
            This policy is a draft and is not in force.
        </p>
        <p class="mt-1 leading-relaxed text-amber-800 dark:text-amber-300">
            The business and contact details it refers to have not been filled in, and the wording
            has not been reviewed by a qualified adviser. Placeholders appear in square brackets.
            Do not rely on this document, and do not publish it, until
            <code class="rounded bg-amber-100 px-1 dark:bg-amber-900/60">config/legal.php</code>
            is complete.
        </p>
    </div>
@endunless
