<?php

declare(strict_types=1);

namespace Macrolab\Controller;

use DateTimeImmutable;
use Macrolab\Auth;
use Macrolab\Csrf;
use Macrolab\Http\HttpException;
use Macrolab\Http\Request;
use Macrolab\Http\Response;
use Macrolab\Projects;
use Macrolab\Session;
use Macrolab\TimeEntries;
use Macrolab\TimeEntry;
use Macrolab\TimeEntryException;
use Macrolab\TimeEntryPolicy;
use Macrolab\TimeEntryService;
use Macrolab\TimeRuleSet;
use Macrolab\TimeRules;
use Macrolab\View;

/**
 * An employee's own timesheet: the hours they worked, one month at a time.
 *
 * Plain forms, no JSON API. Nothing here needs to be live, so nothing here
 * needs JavaScript beyond the confirm-before-submit the whole site already has.
 */
final class TimeController
{
    public function show(Request $request): Response
    {
        $actor = Auth::requireActor();

        /*
         * The administrator has no timesheet, so send them to the overview
         * instead. Without this, requireUser() below would throw 401 and the
         * error handler would bounce a signed-in administrator to the sign-in
         * page, which is a genuinely baffling thing to have happen.
         */
        if ($actor->isAdmin) {
            return Response::redirect('/admin/time');
        }

        $user = Auth::requireUser();
        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            try {
                $this->add($request);

                return Response::redirect('/time?month=' . $this->monthOf($request));
            } catch (TimeEntryException $e) {
                $error = implode(' ', $e->errors);
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        $month = $this->month($request);
        $from = $month;
        $to = $month->modify('last day of this month');

        $entries = TimeEntries::forUser($user->id, $from, $to);

        return View::page('time/index', [
            'title'      => 'Time registration',
            'user'       => $user,
            'projects'   => Projects::allActive(),
            'entries'    => $entries,
            'month'      => $month,
            'prevMonth'  => $month->modify('-1 month')->format('Y-m'),
            'nextMonth'  => $month->modify('+1 month')->format('Y-m'),
            'totalMinutes' => array_sum(array_map(static fn (TimeEntry $e): int => $e->minutes, $entries)),
            'byProject'  => $this->byProject($entries),
            'rules'      => TimeRuleSet::fromSettings(),
            'today'      => TimeRules::today(),
            'error'      => $error,
        ], $error !== null ? 400 : 200);
    }

    public function edit(Request $request, string $id): Response
    {
        $actor = Auth::requireActor();
        $entry = $this->entryOr404($id);

        // The 403 for somebody else's entry, decided on the loaded row.
        TimeEntryPolicy::assertCanModify($entry, $actor);

        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            try {
                TimeEntryService::update(
                    actor: $actor,
                    entry: $entry,
                    projectId: (int) ($request->post('project_id', '0') ?? '0'),
                    workedOn: $this->workedOn($request),
                    minutes: $this->minutes($request),
                    note: $request->post('note'),
                );

                Session::flash('success', 'Your time entry has been changed.');

                return Response::redirect('/time?month=' . $entry->workedOn->format('Y-m'));
            } catch (TimeEntryException $e) {
                $error = implode(' ', $e->errors);
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return View::page('time/edit', [
            'title'    => 'Change a time entry',
            'entry'    => $entry,
            'projects' => Projects::allActive(),
            'rules'    => TimeRuleSet::fromSettings(),
            'error'    => $error,
        ], $error !== null ? 400 : 200);
    }

    public function delete(Request $request, string $id): Response
    {
        Csrf::verify($request);

        $actor = Auth::requireActor();
        $entry = $this->entryOr404($id);

        TimeEntryService::delete($actor, $entry);
        Session::flash('success', 'That time entry has been removed.');

        return Response::redirect('/time?month=' . $entry->workedOn->format('Y-m'));
    }

    private function add(Request $request): void
    {
        $actor = Auth::requireActor();

        TimeEntryService::create(
            actor: $actor,
            projectId: (int) ($request->post('project_id', '0') ?? '0'),
            workedOn: $this->workedOn($request),
            minutes: $this->minutes($request),
            note: $request->post('note'),
        );

        Session::flash('success', 'Your time has been logged.');
    }

    /** @throws TimeEntryException when the field is missing or malformed */
    private function workedOn(Request $request): DateTimeImmutable
    {
        $date = TimeRules::parseDate($request->post('worked_on', '') ?? '');

        if ($date === null) {
            throw TimeEntryException::invalid(['Choose the day you worked.']);
        }

        return $date;
    }

    /** @throws TimeEntryException when the field is missing or malformed */
    private function minutes(Request $request): int
    {
        $minutes = TimeRules::parseHours($request->post('hours', '') ?? '');

        if ($minutes === null) {
            throw TimeEntryException::invalid([
                'Enter the hours as a number like 3.5, or as 3:30.',
            ]);
        }

        return $minutes;
    }

    private function entryOr404(string $id): TimeEntry
    {
        if (!ctype_digit($id)) {
            throw HttpException::notFound();
        }

        $entry = TimeEntries::find((int) $id);

        if ($entry === null) {
            throw HttpException::notFound('That time entry no longer exists.');
        }

        return $entry;
    }

    /** The month being viewed, as its first day at UTC midnight. */
    private function month(Request $request): DateTimeImmutable
    {
        $month = TimeRules::parseDate($this->monthOf($request) . '-01');

        return $month ?? TimeRules::today()->modify('first day of this month');
    }

    private function monthOf(Request $request): string
    {
        $month = (string) ($request->query('month', '') ?? '');

        if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return $month;
        }

        return TimeRules::today()->format('Y-m');
    }

    /**
     * @param list<TimeEntry> $entries
     * @return list<array{project: string, minutes: int}>
     */
    private function byProject(array $entries): array
    {
        $totals = [];

        foreach ($entries as $entry) {
            $name = $entry->projectLabel();
            $totals[$name] = ($totals[$name] ?? 0) + $entry->minutes;
        }

        arsort($totals);

        $out = [];
        foreach ($totals as $project => $minutes) {
            $out[] = ['project' => (string) $project, 'minutes' => $minutes];
        }

        return $out;
    }
}
