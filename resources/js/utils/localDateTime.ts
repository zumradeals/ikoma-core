/**
 * Convertit la valeur d'un champ « datetime-local » (heure locale du navigateur, sans fuseau) en date UTC ISO 8601.
 * Sans cette conversion, le serveur lirait l'heure saisie comme de l'UTC et décalerait une habilitation de plusieurs heures
 * pour tout utilisateur dont le fuseau n'est pas UTC. Valeur vide ou illisible : renvoyée telle quelle (le serveur la refuse).
 */
export function toUtcIso(local: string): string {
  if (!local) return local;
  const date = new Date(local);
  return Number.isNaN(date.getTime()) ? local : date.toISOString();
}
