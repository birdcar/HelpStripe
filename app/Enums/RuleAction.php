<?php

namespace App\Enums;

/**
 * The effects a matched rule can apply.
 *
 * Every action is executed through a Phase 2 action class (CreateRequest is
 * the exception — mail rules feed the create payload rather than editing
 * post-hoc), never by writing the model directly. That keeps the activity log,
 * domain events, and first-response bookkeeping correct, and it's where the
 * trigger-loop guard lives (see App\Support\Automation\ActionApplier).
 *
 * Mail-layer actions accumulate into the request-create payload
 * (set_category/assign_to/set_urgent); the request-mutating actions
 * (change_status/add_private_note/notify_user) only make sense once a Request
 * exists, so they're for the trigger/scheduled layers. The builder UI doesn't
 * currently constrain actions per layer — a mail rule with `change_status` is
 * a no-op the applier skips, named as a small rough edge in the tour doc.
 */
enum RuleAction: string
{
    case SetCategory = 'set_category';
    case AssignTo = 'assign_to';
    case SetUrgent = 'set_urgent';
    case ChangeStatus = 'change_status';
    case AddPrivateNote = 'add_private_note';
    case NotifyUser = 'notify_user';

    /**
     * Get the human-readable label for this action.
     */
    public function label(): string
    {
        return match ($this) {
            self::SetCategory => 'Set category',
            self::AssignTo => 'Assign to',
            self::SetUrgent => 'Set urgent',
            self::ChangeStatus => 'Change status',
            self::AddPrivateNote => 'Add private note',
            self::NotifyUser => 'Notify user',
        };
    }
}
