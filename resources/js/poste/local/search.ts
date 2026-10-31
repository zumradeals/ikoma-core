/**
 * Recherche locale d'un visiteur : même règle que app/Services/VisitorSearch.php (accents ignorés, chaque mot saisi doit
 * correspondre à un mot du nom, tolérance aux fautes par trigrammes, classement par dixième de qualité puis VIP puis score).
 */
const FUZZY_THRESHOLD = 0.4;
const MAX_TOKENS = 6;
const EXTRA: Record<string, string> = { œ: 'oe', æ: 'ae', ß: 'ss', ø: 'o', đ: 'd', ł: 'l', ð: 'd', þ: 'th' };

export function normalize(value: string): string {
  return value.normalize('NFD').replace(/\p{M}+/gu, '').toLowerCase().replace(/[œæßøđłðþ]/g, c => EXTRA[c]);
}

/** Trigrammes à la manière de pg_trgm : chaque mot est entouré de deux espaces devant et d'un derrière. */
export function trigrams(value: string): string[] {
  const out: string[] = [];
  for (const word of value.match(/[\p{L}\p{N}]+/gu) ?? []) {
    const padded = `  ${word} `;
    for (let i = 0; i + 3 <= padded.length; i++) out.push(padded.slice(i, i + 3));
  }
  return out;
}

/** Équivalent de word_similarity(mot, nom) : meilleure similarité entre les trigrammes du mot et une suite continue de ceux du nom. */
export function wordSimilarity(tokenGrams: Set<string>, nameGrams: string[]): number {
  const total = tokenGrams.size;
  if (total === 0 || nameGrams.length === 0) return 0;
  let shared = 0;
  const present = new Set(nameGrams);
  for (const g of tokenGrams) if (present.has(g)) shared++;
  if (shared / total < FUZZY_THRESHOLD * 0.999) return shared / total; // borne haute : inutile de chercher la meilleure suite
  let best = 0;
  for (let i = 0; i < nameGrams.length; i++) {
    const seen = new Set<string>();
    let inter = 0;
    for (let j = i; j < nameGrams.length; j++) {
      const g = nameGrams[j];
      if (!seen.has(g)) { seen.add(g); if (tokenGrams.has(g)) inter++; }
      const sim = inter / (total + seen.size - inter);
      if (sim > best) best = sim;
    }
  }
  return best;
}

export type Indexed = { id: string; full_name: string; organisation: string | null; host: string | null; priority: boolean; norm: string; grams: string[]; homonyms: number };

export type Found = { id: string; full_name: string; organisation: string | null; host: string | null; homonyms: number; priority: boolean; index: number };

export function tokensOf(query: string): string[] {
  const q = query.trim();
  if (q.length < 2 || q.length > 160) return [];
  const tokens = Array.from(new Set(normalize(q).match(/[\p{L}\p{N}]+/gu) ?? [])).slice(0, MAX_TOKENS);
  return tokens.join('').length < 2 ? [] : tokens;
}

export function searchIndex(entries: Indexed[], query: string, limit = 10): Indexed[] {
  const tokens = tokensOf(query);
  if (tokens.length === 0) return [];
  const prepared = tokens.map(token => ({ token, grams: new Set(trigrams(token)), fuzzy: token.length >= 3 }));
  const scored: { entry: Indexed; sum: number; quality: number }[] = [];
  for (const entry of entries) {
    let sum = 0; let ok = true;
    for (const { token, grams, fuzzy } of prepared) {
      const n = entry.norm;
      const starts = n.startsWith(token); const word = n.includes(' ' + token); const inside = n.includes(token);
      if (fuzzy) {
        if (starts) sum += 1; else if (word) sum += 0.9; else if (inside) sum += 0.7;
        else { const sim = wordSimilarity(grams, entry.grams); if (sim >= FUZZY_THRESHOLD) sum += sim; else { ok = false; break; } }
      } else if (starts || word) sum += starts ? 1 : 0.9;
      else { ok = false; break; }
    }
    if (ok) scored.push({ entry, sum, quality: Math.round((sum / tokens.length) * 10) / 10 });
  }
  scored.sort((a, b) => b.quality - a.quality || Number(b.entry.priority) - Number(a.entry.priority) || b.sum - a.sum || compare(a.entry.full_name, b.entry.full_name) || compare(a.entry.id, b.entry.id));
  return scored.slice(0, limit).map(s => s.entry);
}

/** Même ordre de points de code Unicode que PostgreSQL UTF-8 COLLATE "C".
 * Comparer des unités UTF-16 (<, >) inverse l'ordre des caractères hors BMP face à U+E000-U+FFFF. */
function compare(a: string, b: string): number {
  const left = Array.from(a);
  const right = Array.from(b);
  for (let i = 0; i < Math.min(left.length, right.length); i++) {
    const x = left[i].codePointAt(0)!;
    const y = right[i].codePointAt(0)!;
    if (x !== y) return x < y ? -1 : 1;
  }
  return Math.sign(left.length - right.length);
}
