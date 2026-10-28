import Dexie, { type Table } from 'dexie';
import type { VaultRecord } from './crypto';

/** Tout ce qui est personnel est chiffré avant d'arriver ici ; les champs en clair sont des identifiants, des numéros et des états. */
export type MetaRow = { key: string; value: unknown };
export type ChunkRow = { id: string; data: Uint8Array };
export type OpRow = { id: string; seq: number; type: string; status: 'pending' | 'done' | 'conflict'; data: Uint8Array };
export type PhotoRow = { id: string; data: Uint8Array };

export class PosteDb extends Dexie {
  meta!: Table<MetaRow, string>;
  chunks!: Table<ChunkRow, string>;
  ops!: Table<OpRow, string>;
  photos!: Table<PhotoRow, string>;
  constructor(name = 'ikoma-poste') {
    super(name);
    this.version(1).stores({ meta: 'key', chunks: 'id', ops: 'id, seq, status', photos: 'id' });
  }
}

export type PlainMeta = {
  vault: VaultRecord; deviceKey: CryptoKey; serverSecret: Uint8Array; posteId: string; posteName: string; eventEndsAt: string | null;
  settings: { inactivity_minutes: number; clock_skew_seconds: number; pin_max_failures: number };
  listVersion: string; lastSyncAt: string | null; clockOffsetMs: number; lastSeq: number;
};

export async function getMeta<K extends keyof PlainMeta>(db: PosteDb, key: K): Promise<PlainMeta[K] | undefined> {
  return (await db.meta.get(key))?.value as PlainMeta[K] | undefined;
}
export function setMeta<K extends keyof PlainMeta>(db: PosteDb, key: K, value: PlainMeta[K]): Promise<unknown> {
  return db.meta.put({ key, value });
}
