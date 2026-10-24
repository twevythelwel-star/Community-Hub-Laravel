import { router } from '@inertiajs/react';

type Method = 'post' | 'put' | 'patch' | 'delete';

// @inertiajs/core is only a transitive dependency, so take its type from router.
type RequestPayload = NonNullable<Parameters<typeof router.post>[1]>;

/**
 * An Inertia visit as a promise, for dialogs that must not claim success
 * before the server has accepted the change.
 *
 * Resolves on success. Rejects with the first validation message on a 422,
 * or with `undefined` when the visit finished any other way — a 403 or 500
 * fires neither onSuccess nor onError, only onFinish, and without that branch
 * the caller's "Saving…" state would never clear.
 */
export function submit(method: Method, url: string, data: RequestPayload = {}): Promise<void> {
  return new Promise((resolve, reject) => {
    let settled = false;

    const options = {
      preserveScroll: true,
      onSuccess: () => {
        settled = true;
        resolve();
      },
      onError: (errors: Record<string, string>) => {
        settled = true;
        reject(Object.values(errors)[0]);
      },
      onFinish: () => {
        if (!settled) reject(undefined);
      },
    };

    if (method === 'delete') {
      router.delete(url, { ...options, data });
    } else {
      router[method](url, data, options);
    }
  });
}
