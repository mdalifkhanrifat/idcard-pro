<?php

namespace App\Enums;

/**
 * Who a card template is printed for (legacy "Family Card" templates were for family members).
 */
enum TemplateAudience: string
{
    case Cardholder = 'cardholder';
    case FamilyMember = 'family_member';
}
