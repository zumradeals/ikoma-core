import { describe, expect, it } from 'vitest';
import { normalize, searchIndex, trigrams, type Indexed } from '../../resources/js/poste/local/search';

function visitor(id: string, full_name: string, priority = false): Indexed {
  const norm = normalize(full_name);
  return { id, full_name, organisation: null, host: null, priority, norm, grams: trigrams(norm), homonyms: 1 };
}

describe('ordre portable des noms — PostgreSQL COLLATE C', () => {
  it('départage en ordre UTF-8 binaire les majuscules et accents à score égal', () => {
    const entries = ['É alpha', 'À alpha', 'a alpha', 'A alpha'].map((name, i) => visitor(String(i), name));
    expect(searchIndex(entries, 'alpha').map(v => v.full_name)).toEqual(['A alpha', 'a alpha', 'À alpha', 'É alpha']);
  });

  it('compare les points de code Unicode et non les paires UTF-16', () => {
    const entries = [visitor('1', '\u{10000} alpha'), visitor('2', '\uE000 alpha')];
    expect(searchIndex(entries, 'alpha').map(v => v.full_name)).toEqual(['\uE000 alpha', '\u{10000} alpha']);
  });

  it('maintient la priorité VIP avant le départage lexical et les UUID à noms égaux', () => {
    const entries = [visitor('b', 'A alpha'), visitor('c', 'Z alpha', true), visitor('a', 'A alpha')];
    expect(searchIndex(entries, 'alpha').map(v => v.id)).toEqual(['c', 'a', 'b']);
  });
});
