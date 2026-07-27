<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Controllers;

use App\Modules\Web\Application\PanelBootstrap;
use App\Modules\Web\Application\PanelSessionService;
use App\Modules\Web\Domain\PanelScreen;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Serves the panel shell — docs/08-frontend-web/01-web-panels.md §1.2.
 *
 * Every screen is the same document. The server's only jobs are to prove the
 * viewer is signed in, to state which screen the URL asked for so the client
 * router does not flash the default one first, and to embed the bootstrap blob.
 *
 * No business data is rendered server-side. The panel reads everything from
 * `/api/v1`, which keeps the Web module free of any dependency on Trading,
 * Ledger, Settlement or Custody: those modules are reached over HTTP from the
 * browser, where the API's own authorisation and tenancy checks apply to every
 * request rather than being re-implemented here.
 */
final class PanelController extends Controller
{
    public function __construct(
        private readonly PanelBootstrap $bootstrap,
        private readonly PanelSessionService $sessions,
    ) {}

    public function show(Request $request, ?string $screen = null): View
    {
        $panelScreen = PanelScreen::tryFrom((string) $screen) ?? PanelScreen::default();

        $user = $request->user();

        return view('web.panel', [
            'screen' => $panelScreen,
            'title' => $panelScreen->title(),
            'bootstrap' => $user === null
                ? []
                : $this->bootstrap->forUser($user, $this->sessions->token($request->session())),
        ]);
    }

    /** The sign-in screen. Carries no member data at all. */
    public function login(): View
    {
        return view('web.login');
    }
}
