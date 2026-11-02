import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export type Institution = {
  custom: boolean; name: string; fullName: string; tagline: string; logoUrl: string | null;
  homeTitle: string; homeDescription: string; loginGreeting: string; footer: string;
  primary: string; secondary: string; accent: string; homeEnabled?: boolean;
};

/** Identité d'IKOMA ACCESS : valeur par défaut quand aucune identité institutionnelle n'est enregistrée. */
export const STANDARD_INSTITUTION: Institution = {
  custom: false, name: 'IKOMA ACCESS', fullName: 'IKOMA ACCESS', tagline: '', logoUrl: null, homeTitle: '', homeDescription: '',
  loginGreeting: 'Bienvenue sur IKOMA ACCESS', footer: '', primary: '#0B3C6B', secondary: '#0F5AA6', accent: '#F75C03', homeEnabled: true,
};

/** Identité affichée (partagée par le serveur) ; ne décide d'aucun droit ni d'aucun comportement métier. */
export function useInstitution() {
  const page = usePage<{ institution?: Institution }>();

  return computed<Institution>(() => page?.props?.institution ?? STANDARD_INSTITUTION);
}
