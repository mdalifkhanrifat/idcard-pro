<?php

namespace App\Enums;

/**
 * Formal education (legacy education_infos) or a training course (legacy other_courses).
 */
enum QualificationKind: string
{
    case Education = 'education';
    case Course = 'course';
}
