<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent\Http;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Packstub\Agents\Mcp\AgentTool;
use Quansitech\Cmf\Agent\AgentPlugin;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeMcpAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = Filament::auth()->user();
        $panel = Filament::getCurrentPanel();
        $token = AgentTool::accessToken();
        $abilities = $token === null ? [] : (array) ($token->abilities ?? []);

        if ($request->bearerToken() === null || ! $actor instanceof Authenticatable || $token === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($panel === null || ! AgentPlugin::supportsPanel($panel) || ! $panel->hasPlugin(AgentPlugin::ID)
            || ! Gate::forUser($actor)->allows(AgentPlugin::MANAGE_TOKENS)
            || in_array('*', $abilities, true) || array_intersect(['read', 'write'], $abilities) === []) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
