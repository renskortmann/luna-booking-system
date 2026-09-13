<?php

declare(strict_types=1);

namespace Luna\Controller;

use Luna\Auth;
use Luna\Bookings;
use Luna\Clock;
use Luna\Csrf;
use Luna\Http\Request;
use Luna\Http\Response;
use Luna\Resources;
use Luna\RuleSet;
use Luna\Session;
use Luna\View;

/**
 * The calendar - the page the system exists for.
 */
final class CalendarController
{
    public function show(Request $request): Response
    {
        $actor = Auth::requireActor();
        $resource = Resources::resolve($request->query('machine'));
        $rules = RuleSet::fromSettings();

        return View::page('calendar', [
            'title'    => (string) $resource['name'],
            'resource' => $resource,
            'machines' => Resources::allActive(),
            'actor'    => $actor,
            'rules'    => $rules,
            'csrf'     => Csrf::token(),
            'mine'     => $actor->userId() === null ? [] : Bookings::forUser($actor->userId()),
            // Handed to the browser as data attributes; the client mirrors the
            // rules for a civilised UI, but the server is what enforces them.
            'clientRules' => [
                'resourceId'       => (int) $resource['id'],
                'slotMinutes'      => $rules->slotMinutes,
                'openTime'         => $rules->openTime,
                'closeTime'        => $rules->closeTime,
                'openDays'         => $rules->openDays,
                'minMinutes'       => $rules->minMinutes,
                'maxMinutes'       => $rules->maxMinutes,
                'maxAdvanceDays'   => $rules->maxAdvanceDays,
                'timezone'         => $rules->timezone,
                'isAdmin'          => $actor->isAdmin,
                'userId'           => $actor->userId(),
                'nowIso'           => Clock::now()->setTimezone($rules->zone())->format('c'),
            ],
        ]);
    }
}
