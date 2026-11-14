import { randomBytes, toB64 } from '../../../resources/js/poste/local/crypto';
import type { Lease } from '../../../resources/js/poste/local/vault';

/** Bail de poste tel que le serveur le remet (secret en base 64 + échéance). */
export const leaseOf = (secret: Uint8Array | string, hoursFromNow = 12): Lease => ({ secret: typeof secret === 'string' ? secret : toB64(secret), expires_at: new Date(Date.now() + hoursFromNow * 3_600_000).toISOString() });
export const newSecret = (): Uint8Array => randomBytes(32);

/** Serveur simulé du bail : répond avec ce secret tant que `state.online` ; sinon le réseau est coupé. */
export function leaseServer(secret: Uint8Array | string, state: { online: boolean; calls: number; status?: number } = { online: true, calls: 0 }): { fetcher: typeof fetch; state: typeof state } {
  const fetcher = (async (url: string | URL | Request) => {
    // Seul le bail est servi : les autres appels (synchronisation) échouent comme sans serveur, pour que ces tests ne portent que sur le coffre.
    if (!String(url).includes('/coffre/bail')) throw new TypeError('hors réseau');
    state.calls++;
    if (!state.online) throw new TypeError('hors réseau');
    if (state.status && state.status !== 200) return new Response(JSON.stringify({ code: 'appareil_inconnu', message: 'Refusé.' }), { status: state.status });
    return new Response(JSON.stringify(leaseOf(secret)), { status: 200, headers: { 'Content-Type': 'application/json' } });
  }) as unknown as typeof fetch;
  return { fetcher, state };
}

/** Ajoute de VRAIES opérations en attente (chiffrées par le coffre ouvert), comme celles d'un agent qui a travaillé hors ligne. */
export async function addPending(store: { addOp: (op: never) => Promise<void> }, count: number, agentId = 'u1'): Promise<void> {
  for (let i = 1; i <= count; i++) {
    await store.addOp({ id: 'op-' + i, seq: i, type: 'refuser', agent_id: agentId, client_time: new Date().toISOString(), visitor_rev: null, payload: { visitor_id: 'v' + i, motif: 'essai' } } as never);
  }
}
