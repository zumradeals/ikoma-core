import { fromB64, openBytes, openJson, sealBytes, sealJson, toB64 } from './crypto';
import { getMeta, setMeta } from './db';
import type { LocalOp } from './apply';
import type { Snapshot } from './list';
import type { PosteVault } from './vault';

const CHUNK = 500;

/** Magasin chiffré : tout contenu personnel (visiteurs, file d'opérations, photos) est scellé par la clé de données avant d'être écrit. */
export class PosteStore {
  constructor(private readonly vault: PosteVault) {}
  private get db() { return this.vault.db; }
  private get key(): CryptoKey { if (!this.vault.key) throw new Error('verrouille'); return this.vault.key; }

  async saveSnapshot(snapshot: Snapshot): Promise<void> {
    const { visitors, ...head } = snapshot;
    const rows = [{ id: 'list:head', data: await sealJson(this.key, head, 'list:head') }];
    for (let i = 0; i * CHUNK < visitors.length; i++) {
      const id = `list:v:${i}`;
      rows.push({ id, data: await sealJson(this.key, visitors.slice(i * CHUNK, (i + 1) * CHUNK), id) });
    }
    const stale = (await this.db.chunks.toCollection().primaryKeys()).filter(k => k.startsWith('list:'));
    await this.db.transaction('rw', [this.db.chunks, this.db.meta], () => {
      void this.db.chunks.bulkDelete(stale); void this.db.chunks.bulkPut(rows); void setMeta(this.db, 'listVersion', snapshot.version);
    });
  }

  async loadSnapshot(): Promise<Snapshot | null> {
    const head = await this.db.chunks.get('list:head');
    if (!head) return null;
    const base = await openJson<Omit<Snapshot, 'visitors'>>(this.key, head.data, 'list:head');
    const visitors: Snapshot['visitors'] = [];
    for (let i = 0; ; i++) {
      const row = await this.db.chunks.get(`list:v:${i}`);
      if (!row) break;
      visitors.push(...await openJson<Snapshot['visitors']>(this.key, row.data, `list:v:${i}`));
    }
    return { ...base, visitors };
  }

  async nextSeq(): Promise<number> {
    const seq = ((await getMeta(this.db, 'lastSeq')) ?? 0) + 1;
    await setMeta(this.db, 'lastSeq', seq);
    return seq;
  }

  async addOp(op: LocalOp, photo?: Blob, documentPhoto?: Blob): Promise<void> {
    // Le chiffrement (WebCrypto) se fait avant d'ouvrir la transaction : IndexedDB referme une transaction qui attend autre chose que ses propres requêtes.
    const sealedPhoto = photo ? await sealBytes(this.key, new Uint8Array(await photo.arrayBuffer()), `photo:${op.id}`) : null;
    const sealedDocument = documentPhoto ? await sealBytes(this.key, new Uint8Array(await documentPhoto.arrayBuffer()), `photo:doc:${op.id}`) : null;
    const data = await sealJson(this.key, op, `op:${op.id}`);
    await this.db.transaction('rw', [this.db.ops, this.db.photos], () => {
      if (sealedPhoto) void this.db.photos.put({ id: op.id, data: sealedPhoto });
      if (sealedDocument) void this.db.photos.put({ id: `doc:${op.id}`, data: sealedDocument });
      void this.db.ops.put({ id: op.id, seq: op.seq, type: op.type, status: 'pending', data });
    });
  }

  async getOp(id: string): Promise<{ op: LocalOp; status: 'pending' | 'done' | 'conflict' } | null> {
    const row = await this.db.ops.get(id);
    return row ? { op: await openJson<LocalOp>(this.key, row.data, `op:${id}`), status: row.status } : null;
  }

  async ops(status: 'pending' | 'done' | 'conflict'): Promise<LocalOp[]> {
    const rows = await this.db.ops.where('status').equals(status).sortBy('seq');
    return Promise.all(rows.map(r => openJson<LocalOp>(this.key, r.data, `op:${r.id}`)));
  }

  /**
   * Chiffres du poste. Le type et l'état d'une opération sont en clair dans IndexedDB : on compte sans rien déchiffrer,
   * et seules les dernières opérations affichées sont ouvertes.
   */
  async deskOps(recentTypes: string[], limit: number): Promise<{ counts: Record<string, number>; pending: Record<string, number>; recent: LocalOp[] }> {
    const rows = await this.db.ops.toArray();
    const counts: Record<string, number> = {}; const pending: Record<string, number> = {};
    for (const r of rows) {
      if (r.status !== 'conflict') counts[r.type] = (counts[r.type] ?? 0) + 1;
      if (r.status === 'pending') pending[r.type] = (pending[r.type] ?? 0) + 1;
    }
    const last = rows.filter(r => r.status !== 'conflict' && recentTypes.includes(r.type)).sort((a, b) => b.seq - a.seq).slice(0, limit);
    return { counts, pending, recent: await Promise.all(last.map(r => openJson<LocalOp>(this.key, r.data, `op:${r.id}`))) };
  }

  async count(status: 'pending' | 'done' | 'conflict'): Promise<number> { return this.db.ops.where('status').equals(status).count(); }

  /** `id` seul : photo du visiteur ; `doc:<id>` : photo de la pièce d'identité. */
  async photoBase64(id: string): Promise<string> {
    const row = await this.db.photos.get(id);
    return row ? toB64(await openBytes(this.key, row.data, `photo:${id}`)) : '';
  }

  async setStatus(op: LocalOp, status: 'done' | 'conflict'): Promise<void> {
    const data = await sealJson(this.key, op, `op:${op.id}`);
    await this.db.transaction('rw', [this.db.ops, this.db.photos], () => {
      void this.db.ops.put({ id: op.id, seq: op.seq, type: op.type, status, data });
      void this.db.photos.delete(`doc:${op.id}`);
      void this.db.photos.delete(op.id); // la photo est partie avec l'opération (ou mise en quarantaine côté serveur)
    });
  }

  /** Après une copie de liste à jour, les opérations déjà envoyées sont dans la liste : on ne garde que les conflits à montrer à l'agent. */
  async purgeDone(): Promise<void> { await this.db.ops.where('status').equals('done').delete(); }

  async dismissConflict(id: string): Promise<void> { await this.db.ops.delete(id); }

  static fromB64 = fromB64;
}
