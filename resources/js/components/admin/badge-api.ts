/**
 * Appels de l'espace de travail « Badges et cartes » : aucun contrat n'est recréé ici, ce sont les routes serveur des contrats d'émission (BadgeIssuance) et d'impression (SupportPrinting).
 */
export type Model = { code: string; label: string; color: string; mention: string; version: number; validated: boolean };
export type Person = { id: string; name: string; organisation: string | null; category: string | null; state: 'a_generer' | 'existant' | 'non_eligible'; code: string | null; reason: string | null; model: Model | null };
export type Plan = { a_generer: string[]; existants: { id: string; code: string }[]; non_eligibles: { id: string; code: string; message: string }[]; doublons_ignores: number };
export type Result = { complete: boolean; created: { badge_id: string; code: string; visitor_id: string | null; holder_id: string | null }[]; existing: { id?: string; badge_id?: string; code?: string; message?: string }[]; failed: { id: string; code: string; message: string }[]; printable: string[] };
export type SupportRow = { id: string; code: string; type: 'carte_permanente' | 'badge_temporaire'; state: string; state_label: string; holder_name: string | null; category: string | null; event_name: string; printed: boolean };
export type Registre = { data: SupportRow[]; meta: { current_page: number; last_page: number; total: number; per_page: number } };
/** Support réellement émis, composé par le serveur (même composeur que l'impression). */
export type SupportView = {
  name: string; role: string; identifier: string; label: string | null; badge_code: string; qr: string; portrait: string | null; mention: string; validity: string | null; provisional: boolean;
  institution: { name: string; fullName: string; logo: string | null }; tone: { color: string; ink: string };
};

export function xsrf(): string {
  return decodeURIComponent(document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '');
}

export function newOperation(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : '00000000-0000-4000-8000-' + String(Date.now()).padStart(12, '0');
}

export async function request<T>(path: string, body?: unknown): Promise<{ data: T | null; errors: string[] }> {
  try {
    const response = await fetch(path, body === undefined ? { credentials: 'same-origin', headers: { Accept: 'application/json' } }
      : { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify(body) });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const messages = Object.values((data.errors ?? {}) as Record<string, string[]>).flat();
      return { data: null, errors: messages.length ? messages : [data.message ?? (response.status === 403 ? 'Action non autorisée pour ce compte.' : 'Le service a refusé la demande.')] };
    }
    return { data: data as T, errors: [] };
  } catch {
    return { data: null, errors: ['Le service est indisponible. Réessayez.'] };
  }
}

/** Ouvre le document d'impression (tirage tracé côté serveur) dans un nouvel onglet. Produire le document n'est ni la confirmation de l'impression physique ni la remise. */
export function submitPrint(ids: string[], reason: string | null): void {
  const form = document.createElement('form');
  form.method = 'POST'; form.action = '/administration/badges/impression'; form.target = '_blank';
  const add = (name: string, value: string) => { const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.appendChild(input); };
  add('_token', xsrf());
  ids.forEach(id => add('badges[]', id));
  if (reason) add('reason', reason);
  document.body.appendChild(form); form.submit(); form.remove();
}

/** Le QR est un SVG composé par le serveur : affiché comme image (jamais injecté dans la page). */
export function svgSource(svg: string): string {
  const withNs = svg.includes('xmlns=') ? svg : svg.replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ');
  return 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(withNs)));
}
