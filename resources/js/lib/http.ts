import axios from 'axios';

/**
 * Turns an axios rejection into something worth showing a person.
 *
 * This was originally written inside lib/gate-pass-engine/api.ts, where its
 * 403 branch said "Your role is not permitted to scan gate passes." — correct
 * there and wrong everywhere else. The message is a per-call-site concern, so
 * callers pass the ones they want and everything else falls through to what
 * the server said.
 */
export function describeHttpError(
  error: unknown,
  overrides: Partial<Record<number, string>> = {},
): string {
  if (axios.isAxiosError(error)) {
    const status = error.response?.status;
    const data = error.response?.data as { message?: string } | undefined;

    if (status !== undefined && overrides[status] !== undefined) {
      return overrides[status] as string;
    }

    // An expired CSRF token reads as a server error otherwise.
    if (status === 419) {
      return 'Your session expired. Reload the page and try again.';
    }

    return data?.message ?? error.message;
  }

  return error instanceof Error ? error.message : 'Unexpected error.';
}

/** True when an axios rejection was caused by an aborted request, not a failure. */
export function isAbortError(error: unknown): boolean {
  return axios.isCancel(error) || (error as { name?: string })?.name === 'CanceledError';
}
