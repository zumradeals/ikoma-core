import { describe, expect, it } from 'vitest';
import { LocalList, type Snapshot } from '../../resources/js/poste/local/list';
import { PERMANENT_PREFIX, decidePermanentOffline, trustRemainingMinutes, type PermanentSnapshot } from '../../resources/js/poste/local/permanent';
import { controlLocal, exitLocal, resolveBadge } from '../../resources/js/poste/local/site';
import { sha256Hex } from '../../resources/js/poste/local/crypto';

const T0 = Date.parse('2027-01-10T10:00:00Z');
const iso = (ms: number) => new Date(T0 + ms).toISOString();
const MIN = 60_000;
const TOKEN = 'cd'.repeat(32);
const ZONES = [{ id: 'z1', name: 'Zone P', est_accueil: false }, { id: 'z2', name: 'Zone fermée', est_accueil: false }];

async function permanent(over: Partial<PermanentSnapshot> = {}): Promise<PermanentSnapshot> {
  const hash = await sha256Hex(TOKEN);
  return {
    trust_minutes: 30, cards: [{ hash, status: 'active', holder_id: 'h1', identifier: 'ID-000001-8' }],
    policies: [{ zone_id: 'z1', permanent_allowed: true, offline_entry_allowed: true }, { zone_id: 'z2', permanent_allowed: true, offline_entry_allowed: false }],
    holders: [{ id: 'h1', identifier: 'ID-000001-8', name: 'Personne Fictive', organisation: null, status: 'actif', starts_on: null, ends_on: null, entitlements: [{ zone_id: 'z1', valid_from: iso(-60 * MIN), valid_until: iso(240 * MIN) }, { zone_id: 'z2', valid_from: iso(-60 * MIN), valid_until: iso(240 * MIN) }] }],
    ...over,
  };
}
async function list(snap: PermanentSnapshot): Promise<LocalList> {
  const s: Snapshot = {
    version: 'v', server_time: iso(0), poste: { id: 'p1', name: 'Tablette', station: 'Entrée', event_id: 'e1' }, event: { id: 'e1', name: 'Événement fictif', starts_at: iso(-86_400_000), ends_at: iso(86_400_000) },
    settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 }, categories: [], zones: ZONES, category_zone: [], hosts: [], postes: [{ id: 'p1', name: 'Entrée' }], visitors: [], badges: [], permanent: snap,
  };
  return new LocalList(s);
}
const gate = null;
const zone = (id: string) => ({ type: 'zone' as const, id });

describe('titulaires permanents hors ligne', () => {
  it('reconnaît la carte par son empreinte, sans jamais conserver le jeton', async () => {
    const l = await list(await permanent());
    expect(await resolveBadge(l, 'IKOMA1:' + TOKEN)).toBe(PERMANENT_PREFIX + (await sha256Hex(TOKEN)));
    expect(await resolveBadge(l, 'ef'.repeat(32))).toBeNull();
    expect(JSON.stringify(l.snapshot.permanent)).not.toContain(TOKEN);
  });

  it('autorise une entrée habilitée tant que la confiance est valide', async () => {
    const snap = await permanent();
    const d = decidePermanentOffline(snap, await sha256Hex(TOKEN), gate, ZONES, T0, iso(-10 * MIN));
    expect(d.allowed).toBe(true);
    expect(d.zones).toEqual(['Zone fermée', 'Zone P']);
  });

  it('juge l’ancienneté de la confiance à CHAQUE décision : la même session bascule en refus quand la confiance expire', async () => {
    const snap = await permanent();
    const hash = await sha256Hex(TOKEN);
    const known = iso(-5 * MIN);
    expect(decidePermanentOffline(snap, hash, gate, ZONES, T0, known).allowed).toBe(true); // 5 min
    expect(decidePermanentOffline(snap, hash, gate, ZONES, T0 + 24 * MIN, known).allowed).toBe(true); // 29 min
    const late = decidePermanentOffline(snap, hash, gate, ZONES, T0 + 26 * MIN, known); // 31 min
    expect(late).toMatchObject({ allowed: false, reason: 'confiance_expiree' });
    expect(trustRemainingMinutes(snap, T0 + 26 * MIN, known)).toBeLessThanOrEqual(0);
    expect(trustRemainingMinutes(snap, T0, known)).toBe(25);
  });

  it('refuse sans politique approuvée, sans date de synchronisation connue ou avec une horloge à l’envers', async () => {
    const hash = await sha256Hex(TOKEN);
    expect(decidePermanentOffline(await permanent({ trust_minutes: 0 }), hash, gate, ZONES, T0, iso(-1 * MIN))).toMatchObject({ allowed: false, reason: 'hors_ligne_non_autorise' });
    const snap = await permanent();
    expect(decidePermanentOffline(snap, hash, gate, ZONES, T0, null)).toMatchObject({ reason: 'confiance_expiree' });
    expect(decidePermanentOffline(snap, hash, gate, ZONES, T0, iso(+5 * MIN))).toMatchObject({ reason: 'confiance_expiree' });
    expect(trustRemainingMinutes(await permanent({ trust_minutes: 0 }), T0, iso(-1 * MIN))).toBeNull();
  });

  it('refuse immédiatement une carte perdue, volée ou révoquée connue localement', async () => {
    const hash = await sha256Hex(TOKEN);
    for (const [status, reason] of [['perdue', 'carte_perdue'], ['volee', 'carte_volee'], ['revoquee', 'carte_revoquee']] as const) {
      const snap = await permanent();
      snap.cards[0].status = status;
      expect(decidePermanentOffline(snap, hash, gate, ZONES, T0, iso(-1 * MIN))).toMatchObject({ allowed: false, reason });
    }
  });

  it('refuse sans habilitation, hors zone habilitée, habilitation expirée ou à venir, et zone non ouverte hors ligne', async () => {
    const hash = await sha256Hex(TOKEN);
    const known = iso(-1 * MIN);
    expect(decidePermanentOffline(await permanent({ holders: [] }), hash, gate, ZONES, T0, known)).toMatchObject({ reason: 'habilitation_absente' });
    const expired = await permanent();
    expired.holders[0].entitlements = [{ zone_id: 'z1', valid_from: iso(-300 * MIN), valid_until: iso(-1 * MIN) }];
    expect(decidePermanentOffline(expired, hash, gate, ZONES, T0, known)).toMatchObject({ reason: 'habilitation_expiree' });
    const future = await permanent();
    future.holders[0].entitlements = [{ zone_id: 'z1', valid_from: iso(+10 * MIN), valid_until: iso(+300 * MIN) }];
    expect(decidePermanentOffline(future, hash, gate, ZONES, T0, known)).toMatchObject({ reason: 'habilitation_non_commencee' });
    const only1 = await permanent();
    only1.holders[0].entitlements = [{ zone_id: 'z1', valid_from: iso(-60 * MIN), valid_until: iso(60 * MIN) }];
    expect(decidePermanentOffline(only1, hash, zone('z2'), ZONES, T0, known)).toMatchObject({ reason: 'habilitation_absente' });
    expect(decidePermanentOffline(await permanent(), hash, zone('z2'), ZONES, T0, known)).toMatchObject({ allowed: false, reason: 'hors_ligne_non_autorise' });
    const noPolicy = await permanent({ policies: [] });
    expect(decidePermanentOffline(noPolicy, hash, gate, ZONES, T0, known)).toMatchObject({ reason: 'zone_sans_politique' });
  });

  it('refuse un titulaire clos ; les dates d’engagement n’ouvrent ni ne ferment rien', async () => {
    const hash = await sha256Hex(TOKEN);
    const closed = await permanent(); closed.holders[0].status = 'clos';
    expect(decidePermanentOffline(closed, hash, gate, ZONES, T0, iso(-1 * MIN)).reason).toBe('titulaire_clos');
    const ended = await permanent(); ended.holders[0].ends_on = '2000-01-09'; ended.holders[0].starts_on = '2099-01-01';
    expect(decidePermanentOffline(ended, hash, gate, ZONES, T0, iso(-1 * MIN)).allowed).toBe(true);
  });

  it('dit quelle habilitation manque et à quelle date', async () => {
    const hash = await sha256Hex(TOKEN);
    const future = await permanent(); future.holders[0].entitlements.forEach(e => { e.valid_from = '2099-01-01T00:00:00Z'; e.valid_until = '2099-12-31T00:00:00Z'; });
    expect(decidePermanentOffline(future, hash, gate, ZONES, T0, iso(-1 * MIN))).toMatchObject({ allowed: false, reason: 'habilitation_non_commencee', message: 'Habilitation pas encore valable : à partir du 01/01/2099.' });
    const past = await permanent(); past.holders[0].entitlements.forEach(e => { e.valid_from = '2000-01-01T00:00:00Z'; e.valid_until = '2000-02-03T00:00:00Z'; });
    expect(decidePermanentOffline(past, hash, gate, ZONES, T0, iso(-1 * MIN))).toMatchObject({ allowed: false, reason: 'habilitation_expiree', message: 'Habilitation expirée le 03/02/2000 : accès refusé.' });
  });

  it('ne se prononce jamais à partir de la catégorie : elle est seulement transmise pour l’affichage', async () => {
    const source = decidePermanentOffline.toString();
    expect(source.replace(/category: holder\.category \?\? null/, '')).not.toMatch(/categor/i);
  });

  it('refuse une carte préparée mais pas encore remise (non activée)', async () => {
    const snap = await permanent();
    snap.cards[0].status = 'non_activee';
    const d = decidePermanentOffline(snap, snap.cards[0].hash, null, [], Date.now(), new Date().toISOString());
    expect(d.allowed).toBe(false);
    expect(d.reason).toBe('carte_non_activee');
  });

  it('branche la décision dans le contrôle local et laisse toujours sortir, même carte révoquée ou confiance expirée', async () => {
    const snap = await permanent();
    snap.cards[0].status = 'perdue';
    const l = await list(snap);
    const printed = await resolveBadge(l, TOKEN);
    expect(printed).not.toBeNull();
    const control = controlLocal(l, printed, gate, T0, iso(-600 * MIN));
    expect(control).toMatchObject({ valid: false, reason: 'carte_perdue', source: 'local' });
    const exit = exitLocal(l, printed, gate, iso(-600 * MIN));
    expect(exit).toMatchObject({ ok: true, permanent: { card_kept: true } });
  });
});
