<?php

namespace App\Enums;

/**
 * Relationship of a family member to the cardholder.
 */
enum FamilyRelation: string
{
    case Father = 'father';
    case Mother = 'mother';
    case FatherInLaw = 'father_in_law';
    case MotherInLaw = 'mother_in_law';
    case Spouse = 'spouse';
    case Son = 'son';
    case Daughter = 'daughter';
    case Sibling = 'sibling';
    case Other = 'other';
}
