import { describe, expect, it } from 'vitest';
import { LocalList, type Snapshot, type SnapVisitor } from '../../resources/js/poste/local/list';
import { applyOp, type LocalOp } from '../../resources/js/poste/local/apply';

const now = Date.now();
const iso = (o: number) => new Date(now + o).toISOString();
const v = { id: 'v1', full_name: 'Invité fictif', organisation: null, host_id: null, category_id: 'c1', valid_from: iso(-3_600_000), valid_until: iso(3_600_000), source: 'listé', status: 'attendu', host_validation: null, rev: iso(0), identity_done: false, photo_done: false, validated: false, badge_code: null } as SnapVisitor;
const list = () => new LocalList({ version: 'v', server_time: iso(0), poste: { id: 'p', name: 'P', station: 'S', event_id: 'e' }, event: { id: 'e', name: 'E', starts_at: iso(-1), ends_at: iso(1) }, settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 }, categories: [{ id: 'c1', name: 'Visiteur', color: '#000', priority_circuit: false }], zones: [], category_zone: [], hosts: [], postes: [], visitors: [{ ...v }], badges: [] } as unknown as Snapshot);
const op = (payload: Record<string, unknown>): LocalOp => ({ id: 'o1', seq: 1, type: 'identite', agent_id: 'a', client_time: iso(0), visitor_rev: null, payload: { visitor_id: 'v1', identity_checked: true, ...payload } });

describe('pièce d’identité (A1), hors ligne', () => {
  it('refuse l’identité sans type ou sans numéro valable', () => {
    expect(() => applyOp(list(), op({}), now)).toThrowError(/type et le numéro/);
    expect(() => applyOp(list(), op({ document_type: 'cni', document_number: 'AB' }), now)).toThrowError(/type et le numéro/);
    expect(() => applyOp(list(), op({ document_type: 'inconnu', document_number: 'CI0123456' }), now)).toThrowError(/type et le numéro/);
  });
  it('accepte un type connu et un numéro avec espaces et points', () => {
    const l = list();
    applyOp(l, op({ document_type: 'passeport', document_number: ' ci 01.23456 ' }), now);
    expect(l.visitors.get('v1')?.identity_done).toBe(true);
  });
});
