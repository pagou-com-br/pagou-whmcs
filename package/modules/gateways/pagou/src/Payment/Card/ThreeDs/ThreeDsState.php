<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\ThreeDs;

enum ThreeDsState: string
{
    case NotRequired = 'not_required';
    case Required = 'required';
    case Challenge = 'challenge';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public static function fromProvider(?string $value): self
    {
        return match (strtolower((string) $value)) {
            'not_required', 'none' => self::NotRequired,
            'required', 'requires_action' => self::Required,
            'challenge', 'challenged' => self::Challenge,
            'succeeded', 'authenticated', 'success' => self::Succeeded,
            'failed', 'rejected' => self::Failed,
            default => self::Unknown,
        };
    }
}
