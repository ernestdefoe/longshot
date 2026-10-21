import app from 'flarum/forum/app';
import type { Wallet } from '../common/types';

const base = () => `${app.forum.attribute('apiUrl')}/longshot`;

/** The last wallet any response carried: the header chip reads it. */
export const wallet: { current: Wallet | null } = { current: null };

export function get<T = any>(path: string, params: Record<string, any> = {}): Promise<T> {
  return app.request<any>({ method: 'GET', url: base() + path, params }).then((r) => remember(r));
}

/**
 * A write. Refusals the member can act on (422, and 409 when prices moved)
 * come back to the caller instead of a generic alert, so the slip can say
 * what went wrong where the member is looking.
 */
export function send<T = any>(method: 'POST' | 'PATCH' | 'DELETE', path: string, body?: any): Promise<T> {
  return app
    .request<any>({
      method,
      url: base() + path,
      body,
      // Returning false hands every other error to Flarum's own alert.
      errorHandler: (error: any) => (error.status === 409 || error.status === 422 ? undefined : false),
    })
    .then((r) => remember(r));
}

function remember(response: any) {
  const w = response?.meta?.wallet;
  if (w !== undefined) wallet.current = w;
  if (response?.data?.balance !== undefined && response?.data?.canBet !== undefined) wallet.current = response.data;
  return response;
}

/** The message of a refused request, and any new prices it carried. */
export function refusal(error: any): { message: string; prices: Array<{ selection: number; odds: number | null; line: string | null }> } {
  const body = error?.response ?? {};
  const message = body?.errors?.[0]?.detail ?? app.translator.trans('core.lib.error.generic_message');

  return { message: String(message), prices: body?.meta?.prices ?? [] };
}
