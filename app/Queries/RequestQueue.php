<?php

namespace App\Queries;

use App\Enums\RequestStatus;
use App\Models\Request;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the request queue's WHERE clauses from a criteria array.
 *
 * A dedicated query object instead of model scopes because three things
 * speak this vocabulary: the queue page (URL-bound filters), saved
 * Filters (criteria JSON), and later Phase 6's automation conditions and
 * Phase 8's reports. One class, one vocabulary.
 *
 * Criteria keys:
 *  - status:      RequestStatus value ('active', 'pending', …)
 *  - category_id: category id
 *  - assignee:    'me' | 'unassigned' | a user id
 *  - urgent:      truthy → urgent requests only
 *  - search:      substring-matched against subject and customer email
 *
 * Unknown keys are ignored on purpose — saved Filter JSON written by a
 * future phase (or an older one) must degrade gracefully, never break.
 */
class RequestQueue
{
    /**
     * Apply the criteria to a request query.
     *
     * @param  Builder<Request>  $builder
     * @param  array<string, mixed>  $criteria
     * @param  User|null  $user  resolves the relative 'me' assignee; criteria
     *                           store 'me' (not an id) so a shared "My Open"
     *                           filter means *the viewer's* open requests
     * @return Builder<Request>
     */
    public function apply(Builder $builder, array $criteria, ?User $user = null): Builder
    {
        $status = RequestStatus::tryFrom((string) ($criteria['status'] ?? ''));

        if ($status !== null) {
            $builder->where('status', $status);
        }

        if (! empty($criteria['category_id'])) {
            $builder->where('category_id', (int) $criteria['category_id']);
        }

        $this->applyAssignee($builder, $criteria['assignee'] ?? null, $user);

        if (! empty($criteria['urgent'])) {
            $builder->where('is_urgent', true);
        }

        $search = trim((string) ($criteria['search'] ?? ''));

        if ($search !== '') {
            // One grouped closure so the OR stays inside its own
            // parentheses — without it, `orWhereHas` would escape the
            // surrounding team/status constraints entirely.
            $builder->where(function (Builder $query) use ($search) {
                $query->where('subject', 'like', "%{$search}%")
                    ->orWhereHas('customer', function (Builder $customer) use ($search) {
                        $customer->where('email', 'like', "%{$search}%");
                    });
            });
        }

        return $builder;
    }

    /**
     * Apply the assignee criterion, which has two symbolic values on top
     * of plain user ids.
     *
     * @param  Builder<Request>  $builder
     */
    private function applyAssignee(Builder $builder, mixed $assignee, ?User $user): void
    {
        if ($assignee === null || $assignee === '') {
            return;
        }

        if ($assignee === 'unassigned') {
            $builder->whereNull('assigned_to');

            return;
        }

        if ($assignee === 'me') {
            if ($user !== null) {
                $builder->where('assigned_to', $user->id);
            }

            return;
        }

        if (is_numeric($assignee)) {
            $builder->where('assigned_to', (int) $assignee);
        }
    }
}
