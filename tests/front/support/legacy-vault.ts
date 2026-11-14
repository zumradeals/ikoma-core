import { PosteVault } from '../../../resources/js/poste/local/vault';
import { PosteDb, setMeta } from '../../../resources/js/poste/local/db';
import { createDeviceKey, createVault, fromB64, sealBytes } from '../../../resources/js/poste/local/crypto';

/** Crée un ANCIEN coffre par PIN (version 1), tel que les tablettes d'avant la suppression du PIN, pour tester la migration. Jamais utilisé en production. */
export class LegacyVault extends PosteVault {
  async enrolLegacy(input: { token: string; secret: string; pin: string; agent: { id: string; name: string }; poste: { id: string; name: string }; eventEndsAt: string | null; settings: { inactivity_minutes: number; clock_skew_seconds: number; pin_max_failures: number } }): Promise<void> {
    await this.wipe();
    const deviceKey = await createDeviceKey();
    const secret = fromB64(input.secret);
    const { record, dek } = await createVault(input.pin, secret, deviceKey, 1000);
    const db: PosteDb = this.db;
    await setMeta(db, 'deviceKey', deviceKey);
    await setMeta(db, 'serverSecret', await sealBytes(deviceKey, secret, 'serverSecret'));
    await setMeta(db, 'vault', record);
    await setMeta(db, 'posteId', input.poste.id); await setMeta(db, 'posteName', input.poste.name); await setMeta(db, 'eventEndsAt', input.eventEndsAt);
    await setMeta(db, 'settings', input.settings); await setMeta(db, 'listVersion', ''); await setMeta(db, 'lastSyncAt', null); await setMeta(db, 'clockOffsetMs', 0); await setMeta(db, 'lastSeq', 0);
    await this.writeFailures(deviceKey, { count: 0, lockedUntil: 0 });
    this.dek = dek;
    await this.putSecret('deviceToken', input.token);
    await this.putSecret('profile', input.agent);
  }
}
