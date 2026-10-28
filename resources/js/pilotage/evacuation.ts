import Dexie from 'dexie';
import { randomBytes, toB64 } from '../poste/local/crypto';
import { PosteDb, getMeta, setMeta } from '../poste/local/db';
import { PosteVault } from '../poste/local/vault';

export type EvacPerson = { id: string; full_name: string; organisation: string | null; group: string | null; zone: string; entered_at: string; category: { id: string; name: string; color: string; priority_circuit: boolean } };
export type Evacuation = { event: { id: string; name: string; ends_at: string }; total: number; zones: { name: string; count: number; people: EvacPerson[] }[]; as_of: string };

export const EVAC_DB = 'ikoma-pilotage';
export const WIPE_AFTER_EVENT_MS = 24 * 3_600_000;

/**
 * Dernière liste d'évacuation connue, conservée chiffrée sur l'appareil du superviseur (mêmes principes que le poste : PIN de 6 chiffres, clé non exportable,
 * 15 échecs = effacement). Effacée à la déconnexion et 24 h après la fin de l'événement. Rien n'est lisible tant que le coffre est verrouillé.
 */
export class LocalEvacuation {
  readonly db: PosteDb;
  readonly vault: PosteVault;
  constructor(options: { name?: string; iterations?: number; now?: () => number } = {}) {
    this.db = new PosteDb(options.name ?? EVAC_DB);
    this.vault = new PosteVault(this.db, { iterations: options.iterations, now: options.now });
  }

  state(): Promise<'vide' | 'verrouille' | 'deverrouille'> { return this.vault.state(); }

  async create(pin: string, agent: { id: string; name: string }, endsAt: string | null): Promise<void> {
    await this.vault.enrol({ token: 'pilotage', secret: toB64(randomBytes(32)), pin, agent, poste: { id: 'pilotage', name: 'Pilotage' }, eventEndsAt: endsAt, settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 } });
  }

  async unlock(pin: string): Promise<void> { await this.vault.unlock(pin); }
  lock(): void { this.vault.lock(); }
  wipe(): Promise<void> { return this.vault.wipe(); }

  async save(data: Evacuation): Promise<void> {
    await this.vault.putSecret('evacuation', data);
    await setMeta(this.db, 'lastSyncAt', data.as_of);
    await setMeta(this.db, 'eventEndsAt', data.event.ends_at);
  }

  async load(): Promise<{ data: Evacuation; knownAt: string } | null> {
    const data = await this.vault.getSecret<Evacuation>('evacuation');
    return data ? { data, knownAt: (await getMeta(this.db, 'lastSyncAt')) ?? data.as_of } : null;
  }

  /** Vrai quand l'événement est terminé depuis plus de 24 h : la copie doit être effacée. */
  async expired(now = Date.now()): Promise<boolean> {
    const ends = await getMeta(this.db, 'eventEndsAt');
    return !!ends && now > Date.parse(ends) + WIPE_AFTER_EVENT_MS;
  }
}

/** À appeler à la déconnexion explicite : efface la base locale du superviseur, sans attendre le coffre. */
export async function wipePilotageLocal(): Promise<void> {
  try { await Dexie.delete(EVAC_DB); } catch { /* rien à effacer ou base occupée : elle sera réessayée à la prochaine ouverture */ }
}
