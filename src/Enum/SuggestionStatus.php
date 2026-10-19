<?php

namespace App\Enum;

/** Lifecycle of a {@see \App\Entity\MetadataSuggestion}. */
enum SuggestionStatus: string
{
    /** Below the auto-apply threshold: waits for an admin decision. */
    case Pending = 'pending';
    /** Written to the file and the database. */
    case Applied = 'applied';
    case Rejected = 'rejected';
    /** Was applied, then rolled back to the previous values. */
    case Reverted = 'reverted';
}
