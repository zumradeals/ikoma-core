export type CardModel = {
  category_code: string; label: string; color: string; mention: string; show_validity: boolean; qr_side: 'recto' | 'verso';
  version: number; status: string; validated: boolean; validated_at: string | null; validated_by: string | null; saved: boolean;
};

/** Données d'une section « modèle de carte » : celles que sert la page historique et la page Modèles. */
export type CardModelProps = {
  categories: { code: string; label: string }[]; selected: string | null; model: CardModel | null; preview: { recto: string; verso: string } | null;
  settings: { ligne1: string; ligne2: string; contact: string; avis: string }; limits: Record<string, number>; initialized: boolean; canConfigure: boolean;
};
