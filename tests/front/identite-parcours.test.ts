import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/*
 * Parcours de création d'identité : « Personnel permanent » (Habilitations) et « Catégories institutionnelles » ne créent, n'importent ni ne
 * modifient aucune identité. Seul le registre « Personnes » crée une personne ; les autres écrans qui enregistrent une identité (registre des
 * titulaires dans « Badges et cartes », import de liste) sont recensés ici pour que tout ajout futur soit explicite (lot P2).
 */
const page = (name: string) => readFileSync(join(process.cwd(), 'resources/js/pages/Admin', `${name}.vue`), 'utf8');
const writes = (source: string) => [...source.matchAll(/(?:post|put|delete)\(\s*[`'"](\/administration\/[a-z0-9/_-]*)/g)].map(m => m[1]);
const IDENTITY = /\/administration\/(personnes|titulaires|imports|invites|atelier\/personnes)(\/|$)/;

describe('parcours de création d’identité', () => {
  it('« Personnel permanent » n’accorde que des habilitations : aucune création ni import d’identité', () => {
    const calls = writes(page('Habilitations'));
    expect(calls.length).toBeGreaterThan(0);
    expect(calls.filter(c => IDENTITY.test(c))).toEqual([]);
    expect(calls.every(c => c.startsWith('/administration/habilitations'))).toBe(true);
  });

  it('« Catégories institutionnelles » ne gère que des catégories', () => {
    const calls = writes(page('CategoriesInstitutionnelles'));
    expect(calls.filter(c => IDENTITY.test(c))).toEqual([]);
    expect(calls.every(c => c.startsWith('/administration/categories-institutionnelles'))).toBe(true);
  });

  it('le registre « Personnes » est le seul écran des deux entrées à créer une personne, avec confirmation d’homonyme', () => {
    expect(writes(page('Personnes'))).toEqual(['/administration/personnes']);
    expect(page('Personnes')).toContain('confirmer-homonyme');
  });

  it('recense les écrans hors des deux entrées qui enregistrent encore une identité (à traiter au lot P2)', () => {
    expect(writes(page('Titulaires')).filter(c => c === '/administration/titulaires' || c.startsWith('/administration/titulaires/import'))).not.toEqual([]);
    expect(writes(page('Imports')).some(c => c.startsWith('/administration/imports/confirmer'))).toBe(true);
  });
});
