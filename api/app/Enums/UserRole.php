<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Reviewer = 'reviewer';
    case Viewer = 'viewer';

    public function canReview(): bool
    {
        return $this !== self::Viewer;
    }
}
