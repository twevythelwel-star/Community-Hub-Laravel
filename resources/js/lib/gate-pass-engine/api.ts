import axios from 'axios';
import { describeHttpError } from '@/lib/http';
import type { ColorVariant } from './config';
import type { GatePassValidationReport, GateId } from './types';

/**
 * Server calls that replace the client-side crypto.
 *
 * generateDynamicGatePassToken() and validateGatePassToken() used to run in the
 * browser using a signing key that shipped in the bundle. Both are now server
 * endpoints: the browser asks for a token and asks whether a scanned token is
 * valid, but never holds the key and never decides the answer.
 *
 * Every call here is therefore asynchronous where the original was synchronous.
 */

export type IssuedToken = {
    token: string;
    validFrom: string;
    validUntil: string;
    secondsRemaining: number;
};

export type ScanResult = {
    report: GatePassValidationReport;
    accessLogId: number;
};

export type RotationResult = {
    rotationSeq: number;
    variant: ColorVariant;
    rotatedAt: string;
};

/**
 * Fetches the current rolling token for the signed-in user's pass.
 *
 * The token is valid for one window (default 30s) and the same window returns
 * the same token, so polling slightly faster than the window is safe and cheap.
 */
export async function fetchGatePassToken(signal?: AbortSignal): Promise<IssuedToken> {
    const { data } = await axios.get<IssuedToken>('/dashboard/gate-pass/token', { signal });

    return data;
}

/** Advances the pass to its next approved colour variant. */
export async function rotateGatePassVisual(): Promise<RotationResult> {
    const { data } = await axios.post<RotationResult>('/dashboard/gate-pass/rotate');

    return data;
}

/**
 * Validates a scanned token.
 *
 * The server runs the four-stage pipeline and writes the access-log entry in the
 * same transaction, so a scan cannot be recorded without the decision that
 * justified it — and a denial cannot be dropped by the client.
 *
 * Requires the `scanPasses` permission; a caller without it gets a 403.
 */
export async function scanGatePassToken(
    token: string,
    gate: GateId = 'GATE-01',
): Promise<ScanResult> {
    const { data } = await axios.post<ScanResult>('/dashboard/gate-pass/scan', { token, gate });

    return data;
}

export { isAbortError } from '@/lib/http';

/**
 * Best-effort message from a Laravel validation or error response.
 *
 * The generic implementation lives in lib/http.ts; only the 403 wording is
 * specific to this module, and it stays here so other callers do not inherit
 * a message about gate passes.
 */
export function describeRequestError(error: unknown): string {
    return describeHttpError(error, {
        403: 'Your role is not permitted to scan gate passes.',
    });
}
