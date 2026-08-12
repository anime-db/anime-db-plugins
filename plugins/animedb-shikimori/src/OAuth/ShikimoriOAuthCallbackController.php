<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
 */

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace AnimeDb\Plugins\AnimedbShikimori\OAuth;

use AnimeDb\PluginContracts\OAuth\OAuthStateMismatchException;
use AnimeDb\PluginContracts\OAuth\OAuthTokenExchangeException;
use Psr\Http\Client\ClientExceptionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Twig\Environment;

/**
 * `GET /oauth/shikimori` — the exact path Shikimori app #2212 has registered as its
 * `redirect_uri`. Opened by the system browser after {@see ShikimoriOAuthStartController}
 * redirected it to Shikimori, not by the desktop app itself.
 *
 * Query is checked *before* {@see ShikimoriOAuthClient::handleCallback()} is ever called:
 * a user who hits "Deny" comes back as `?error=access_denied&state=...` with no `code`, and
 * calling `handleCallback()` on that would burn the pending single-use `state` on a request
 * that was never going to succeed, leaving a genuine retry from the same session unable to
 * complete.
 *
 * `OAuthStateMismatchException`/`OAuthTokenExchangeException` (thrown by the contract) and
 * `ClientExceptionInterface` (thrown by the PSR-18 transport underneath it) are the only
 * failure modes {@see ShikimoriOAuthClient::handleCallback()} can produce — all three are
 * turned into the same friendly failure page, never a raw 500.
 *
 * The token-liveness probe ({@see ShikimoriTokenProbe}) only runs after a successful
 * exchange and is not allowed to turn success into failure — its result only toggles a
 * warning on the "done" page.
 */
#[AsController]
final class ShikimoriOAuthCallbackController
{
    private const CANCELLED_MESSAGE = 'oauth_result.message.cancelled';
    private const FAILED_MESSAGE = 'oauth_result.message.failed';
    private const SUCCESS_MESSAGE = 'oauth_result.message.success';
    private const PROBE_WARNING_MESSAGE = 'oauth_result.warning.probe_failed';

    public function __construct(
        private readonly ShikimoriOAuthClient $oauth,
        private readonly ShikimoriTokenProbe $probe,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $error = $request->query->getString('error');
            $code = $request->query->getString('code');
            $state = $request->query->getString('state');
        } catch (\Throwable) {
            return $this->renderResult(success: false, message: self::CANCELLED_MESSAGE);
        }

        if ($error !== '' || $code === '') {
            return $this->renderResult(success: false, message: self::CANCELLED_MESSAGE);
        }

        try {
            $this->oauth->handleCallback($state, $code);
        } catch (OAuthStateMismatchException|OAuthTokenExchangeException|ClientExceptionInterface) {
            return $this->renderResult(success: false, message: self::FAILED_MESSAGE);
        }

        $probeOk = $this->probe->check($this->oauth->accessToken() ?? '');

        return $this->renderResult(
            success: true,
            message: self::SUCCESS_MESSAGE,
            warning: $probeOk ? null : self::PROBE_WARNING_MESSAGE,
        );
    }

    /**
     * @param string  $message a `oauth_result.message.*` translation key, resolved by
     *                         the `trans` filter in `oauth_result.html.twig`, not display text
     * @param ?string $warning a `oauth_result.warning.*` translation key, same as $message
     */
    private function renderResult(bool $success, string $message, ?string $warning = null): Response
    {
        try {
            $html = $this->twig->render('@AnimedbShikimori/oauth_result.html.twig', [
                'success' => $success,
                'message' => $message,
                'warning' => $warning,
            ]);
        } catch (\Throwable) {
            return new Response('Could not display the authorization result.');
        }

        return new Response($html);
    }
}
