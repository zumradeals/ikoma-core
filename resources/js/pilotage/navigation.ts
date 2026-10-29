/** Conserve l’événement lors des déplacements sans changer les autres filtres du lien. */
export function pilotageHref(path: string, eventId?: string): string {
  if (!eventId) return path;
  const [pathname, query] = path.split('?');
  const params = new URLSearchParams(query);
  params.set('event', eventId);
  return `${pathname}?${params.toString()}`;
}
