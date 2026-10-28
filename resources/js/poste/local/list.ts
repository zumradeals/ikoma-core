import type { Category } from '../types';
import { normalize, trigrams, type Indexed } from './search';

export type SnapVisitor = {
  id: string; full_name: string; organisation: string | null; host_id: string | null; category_id: string; valid_from: string; valid_until: string; source: string; status: string;
  host_validation: 'en_attente' | 'accepte' | 'refuse' | null; access_note?: string | null; group_id?: string | null; a_rebadger?: boolean; rev: string | null; identity_done: boolean; photo_done: boolean; validated: boolean; badge_code: string | null;
};
export type SnapBadge = { code: string; token_hash: string; category_id: string | null; status: string; mine: boolean; group_id?: string | null };
export type SnapGroup = { id: string; name: string; bus_number: string | null; opened: boolean; departed: boolean; arrival_closed: boolean };
export type SnapMember = { group_id: string; visitor_id: string; checked: boolean; confirmed: boolean; absent: boolean; boarded: boolean; arrived: boolean };
export type Snapshot = {
  version: string; server_time: string; poste: { id: string; name: string; station: string | null; event_id: string }; event: { id: string; name: string; starts_at: string; ends_at: string };
  settings: { inactivity_minutes: number; clock_skew_seconds: number; pin_max_failures: number; controle_stagiaires?: 'identite' | 'encadreur' };
  categories: Category[]; zones: { id: string; name: string; est_accueil: boolean }[]; category_zone: { category_id: string; zone_id: string }[];
  hosts: { id: string; name: string; service: string | null }[]; postes: { id: string; name: string }[]; visitors: SnapVisitor[]; badges: SnapBadge[]; groups?: SnapGroup[]; members?: SnapMember[];
};

/** Copie de travail en mémoire (déchiffrée seulement tant que la tablette est déverrouillée). */
export class LocalList {
  readonly visitors = new Map<string, SnapVisitor>();
  readonly categories = new Map<string, Category>();
  readonly badges = new Map<string, SnapBadge>();
  readonly hostNames = new Map<string, string>();
  readonly groups = new Map<string, SnapGroup>();
  readonly members = new Map<string, SnapMember>(); // par identifiant de stagiaire : un stagiaire n'est que dans un groupe
  private index: Indexed[] | null = null;

  constructor(readonly snapshot: Snapshot) {
    for (const v of snapshot.visitors) this.visitors.set(v.id, { ...v });
    for (const c of snapshot.categories) this.categories.set(c.id, c);
    for (const b of snapshot.badges) this.badges.set(b.code.toUpperCase(), { ...b });
    for (const h of snapshot.hosts) this.hostNames.set(h.id, h.name);
    for (const g of snapshot.groups ?? []) this.groups.set(g.id, { ...g });
    for (const m of snapshot.members ?? []) this.members.set(m.visitor_id, { ...m });
  }

  touch(): void { this.index = null; }

  indexed(): Indexed[] {
    if (this.index) return this.index;
    const counts = new Map<string, number>();
    const rows = Array.from(this.visitors.values()).map(v => {
      const norm = normalize(v.full_name);
      counts.set(norm, (counts.get(norm) ?? 0) + 1);
      return { v, norm };
    });
    this.index = rows.map(({ v, norm }) => ({ id: v.id, full_name: v.full_name, organisation: v.organisation, host: v.host_id ? this.hostNames.get(v.host_id) ?? null : null, priority: this.categories.get(v.category_id)?.priority_circuit ?? false, norm, grams: trigrams(norm), homonyms: counts.get(norm) ?? 1 }));
    return this.index;
  }

  zonesFor(visitor: SnapVisitor): string[] {
    const names = new Map(this.snapshot.zones.map(z => [z.id, z]));
    const zones = visitor.source === 'non listé'
      ? this.snapshot.zones.filter(z => z.est_accueil)
      : this.snapshot.category_zone.filter(cz => cz.category_id === visitor.category_id).map(cz => names.get(cz.zone_id)).filter((z): z is NonNullable<typeof z> => !!z);
    return zones.map(z => z.name).sort((a, b) => a.localeCompare(b, 'fr'));
  }
}
