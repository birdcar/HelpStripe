<?php

use App\Enums\ConditionField;
use App\Enums\ConditionOperator;
use App\Enums\RequestStatus;
use App\Models\Category;
use App\Models\Request;
use App\Models\User;
use App\Support\Automation\Condition;
use App\Support\Automation\ConditionEvaluator;
use App\Support\Resend\InboundEmail;
use Carbon\CarbonImmutable;

/*
 * The condition matcher — the engine's pure-logic core. These tests run the
 * full operator × field matrix against both subject types (InboundEmail and
 * Request) without touching the rest of the engine, so a matching bug surfaces
 * here, not three layers deep in the pipeline.
 */

/**
 * Build an InboundEmail from minimal parts via its real factory (the DTO has
 * no public constructor — fromResend is the single parse point).
 *
 * @param  list<string>  $to
 */
function inboundEmail(string $from = 'pat@example.com', string $subject = 'Hello', string $body = 'Body text', array $to = ['support@helpstripe.test']): InboundEmail
{
    return InboundEmail::fromResend(
        ['data' => ['email_id' => 'em_1', 'from' => $from, 'subject' => $subject, 'to' => $to]],
        ['text' => $body, 'headers' => []],
    );
}

function condition(ConditionField $field, ConditionOperator $operator, mixed $value = null): Condition
{
    return new Condition($field, $operator, $value);
}

/**
 * @param  list<Condition>  $conditions
 */
function evaluate(array $conditions, Request|InboundEmail $subject): bool
{
    return app(ConditionEvaluator::class)->matches($conditions, $subject);
}

test('empty conditions match every subject', function () {
    expect(evaluate([], inboundEmail()))->toBeTrue()
        ->and(evaluate([], Request::factory()->create()))->toBeTrue();
});

test('conditions are ANDed — all must hold', function () {
    $email = inboundEmail(subject: 'Invoice overdue', body: 'please pay');

    expect(evaluate([
        condition(ConditionField::Subject, ConditionOperator::Contains, 'invoice'),
        condition(ConditionField::Body, ConditionOperator::Contains, 'pay'),
    ], $email))->toBeTrue();

    expect(evaluate([
        condition(ConditionField::Subject, ConditionOperator::Contains, 'invoice'),
        condition(ConditionField::Body, ConditionOperator::Contains, 'refund'),
    ], $email))->toBeFalse();
});

test('contains is case-insensitive', function () {
    $email = inboundEmail(subject: 'INVOICE #42');

    expect(evaluate([condition(ConditionField::Subject, ConditionOperator::Contains, 'invoice')], $email))->toBeTrue();
});

test('email fields resolve from the inbound email', function () {
    $email = inboundEmail(from: 'biller@acme.test', to: ['billing@helpstripe.test']);

    expect(evaluate([condition(ConditionField::FromEmail, ConditionOperator::Equals, 'biller@acme.test')], $email))->toBeTrue()
        ->and(evaluate([condition(ConditionField::ToMailbox, ConditionOperator::Equals, 'billing@helpstripe.test')], $email))->toBeTrue()
        ->and(evaluate([condition(ConditionField::ToMailbox, ConditionOperator::Equals, 'support@helpstripe.test')], $email))->toBeFalse();
});

test('equals and not_equals are inverses', function () {
    $email = inboundEmail(subject: 'Exactly this');

    expect(evaluate([condition(ConditionField::Subject, ConditionOperator::Equals, 'Exactly this')], $email))->toBeTrue()
        ->and(evaluate([condition(ConditionField::Subject, ConditionOperator::NotEquals, 'Exactly this')], $email))->toBeFalse()
        ->and(evaluate([condition(ConditionField::Subject, ConditionOperator::NotEquals, 'Something else')], $email))->toBeTrue();
});

test('request category, status, assignee and urgency resolve from the model', function () {
    $category = Category::factory()->create();
    $staff = User::factory()->create();
    $request = Request::factory()->create([
        'team_id' => $category->team_id,
        'category_id' => $category->id,
        'status' => RequestStatus::Pending,
        'assigned_to' => $staff->id,
        'is_urgent' => true,
    ]);

    expect(evaluate([condition(ConditionField::Category, ConditionOperator::Equals, $category->id)], $request))->toBeTrue()
        ->and(evaluate([condition(ConditionField::Status, ConditionOperator::Equals, RequestStatus::Pending->value)], $request))->toBeTrue()
        ->and(evaluate([condition(ConditionField::Status, ConditionOperator::Equals, RequestStatus::Active->value)], $request))->toBeFalse()
        ->and(evaluate([condition(ConditionField::Assignee, ConditionOperator::Equals, $staff->id)], $request))->toBeTrue()
        ->and(evaluate([condition(ConditionField::IsUrgent, ConditionOperator::Equals, true)], $request))->toBeTrue();
});

test('is_null matches a missing field — e.g. an unassigned request', function () {
    $assigned = Request::factory()->create(['assigned_to' => User::factory()]);
    $unassigned = Request::factory()->create(['assigned_to' => null]);

    expect(evaluate([condition(ConditionField::Assignee, ConditionOperator::IsNull)], $unassigned))->toBeTrue()
        ->and(evaluate([condition(ConditionField::Assignee, ConditionOperator::IsNull)], $assigned))->toBeFalse();
});

test('gt and lt compare numeric age_hours against a frozen clock', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 12:00:00'));

    $old = Request::factory()->create([
        'created_at' => CarbonImmutable::now()->subHours(25),
        'updated_at' => CarbonImmutable::now()->subHours(25),
    ]);
    $fresh = Request::factory()->create([
        'created_at' => CarbonImmutable::now()->subHours(23),
        'updated_at' => CarbonImmutable::now()->subHours(23),
    ]);

    expect(evaluate([condition(ConditionField::AgeHours, ConditionOperator::GreaterThan, 24)], $old))->toBeTrue()
        ->and(evaluate([condition(ConditionField::AgeHours, ConditionOperator::GreaterThan, 24)], $fresh))->toBeFalse()
        ->and(evaluate([condition(ConditionField::AgeHours, ConditionOperator::LessThan, 24)], $fresh))->toBeTrue();

    CarbonImmutable::setTestNow();
});

test('a request-only field on an email subject never matches (except is_null)', function () {
    $email = inboundEmail();

    // age_hours has no meaning for an email → resolves null → no operator
    // matches except is_null.
    expect(evaluate([condition(ConditionField::AgeHours, ConditionOperator::GreaterThan, 1)], $email))->toBeFalse()
        ->and(evaluate([condition(ConditionField::AgeHours, ConditionOperator::IsNull)], $email))->toBeTrue();
});

test('an email-only field on a request subject never matches (except is_null)', function () {
    $request = Request::factory()->create();

    expect(evaluate([condition(ConditionField::FromEmail, ConditionOperator::Equals, 'x@y.test')], $request))->toBeFalse()
        ->and(evaluate([condition(ConditionField::FromEmail, ConditionOperator::IsNull)], $request))->toBeTrue();
});

test('request body resolves to the opening note', function () {
    $request = Request::factory()->create();
    $request->notes()->create([
        'customer_id' => $request->customer_id,
        'body' => 'My printer is on fire',
        'is_private' => false,
        'source' => $request->source,
    ]);

    expect(evaluate([condition(ConditionField::Body, ConditionOperator::Contains, 'printer')], $request))->toBeTrue();
});
