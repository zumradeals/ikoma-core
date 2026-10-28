import { ApiError } from '../types';
import type { SnapVisitor } from './list';

/** Audit global A3 : un invité qui n'est pas approuvé par l'officier de sécurité ne peut pas être accueilli ; refuser une personne reste toujours possible. */
export function assertApproved(v: Pick<SnapVisitor, 'approval'>): void {
  if ((v.approval ?? 'approuve') === 'approuve') return;
  throw new ApiError('non_approuve', v.approval === 'refuse' ? 'L’officier de sécurité n’a pas approuvé cet invité : appelez le superviseur.' : 'Cet invité attend l’approbation de l’officier de sécurité : l’accueil est impossible pour le moment.', null, 422);
}
