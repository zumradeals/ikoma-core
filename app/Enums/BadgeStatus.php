<?php

namespace App\Enums;

enum BadgeStatus: string
{
    case Available = 'Disponible';
    case Assigned = 'Associé';
    case Returned = 'Restitué';
    case Cancelled = 'Annulé';
    case Closed = 'Clos';
}
