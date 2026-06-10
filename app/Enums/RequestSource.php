<?php

namespace App\Enums;

/**
 * Where a request (or an individual note on its timeline) originated.
 *
 * HelpSpot tracks the intake channel for every interaction: an inbound
 * email, the self-service portal, the public API, or an agent working
 * inside the app. Phases 3 and 4 wire up the Email/Portal/Api channels;
 * Phase 1 seeds a realistic mix so later reporting has shape.
 */
enum RequestSource: string
{
    case Email = 'email';
    case Portal = 'portal';
    case Api = 'api';
    case Agent = 'agent';

    /**
     * Get the human-readable label for this source.
     */
    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Portal => 'Portal',
            self::Api => 'API',
            self::Agent => 'Agent',
        };
    }
}
