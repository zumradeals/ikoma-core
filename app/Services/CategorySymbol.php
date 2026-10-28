<?php

namespace App\Services;

/** Symbole imprimé avec la catégorie : la couleur n’est jamais le seul repère (plan, principe 3). */
final class CategorySymbol
{
    private const KNOWN = ['vip' => '★', 'visiteur' => '●', 'prestataire' => '▲', 'stagiaire' => '■'];

    private const OTHERS = ['◆', '✚', '▼', '◖', '✱', '♦'];

    /** @param  list<string>  $eventCategoryIds identifiants triés des catégories de l’événement */
    public static function for(string $name, string $id, array $eventCategoryIds): string
    {
        $key = mb_strtolower(trim($name));
        if (isset(self::KNOWN[$key])) {
            return self::KNOWN[$key];
        }
        $index = array_search($id, $eventCategoryIds, true);

        return self::OTHERS[(is_int($index) ? $index : 0) % count(self::OTHERS)];
    }
}
