<?php

declare(strict_types=1);

namespace Macrolab\Controller;

use Macrolab\Auth;
use Macrolab\Http\Request;
use Macrolab\Http\Response;
use Macrolab\Navigation;
use Macrolab\View;

/**
 * The front door. Signing in lands here, and from here a member chooses which
 * of the site's systems they came for.
 *
 * Deliberately open to the administrator as well as to members: the hub is
 * reachable from the brand link on every page, so it must render for whoever
 * is signed in rather than bouncing one of them somewhere else.
 */
final class HubController
{
    public function show(Request $request): Response
    {
        $actor = Auth::requireActor();

        return View::page('hub', [
            'title'        => 'Macrolab',
            'actor'        => $actor,
            'destinations' => Navigation::destinations($actor),
        ]);
    }
}
