<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
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
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace AnimeDb\Plugins\AnimedbShikimori\Tests\Widget;

use AnimeDb\Plugins\AnimedbShikimori\Http\GraphQlClient;
use AnimeDb\Plugins\AnimedbShikimori\Http\UnauthorizedHttpException;
use AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriOAuthClient;
use AnimeDb\Plugins\AnimedbShikimori\Tests\Widget\Fixture\StubTwigFactory;
use AnimeDb\Plugins\AnimedbShikimori\Widget\NewWidget;
use PHPUnit\Framework\TestCase;

final class NewWidgetTest extends TestCase
{
    public function testMetadataReturnsExpectedNameTitleKeyAndDescriptionKey(): void
    {
        $metadata = NewWidget::metadata();

        self::assertSame('new', $metadata->name);
        self::assertSame('widget.new.title', $metadata->titleKey);
        self::assertSame('widget.new.description', $metadata->descriptionKey);
    }

    public function testRenderSendsAnonymousQueryWithoutMylistWhenNoTokenIsStored(): void
    {
        $oauth = $this->createMock(ShikimoriOAuthClient::class);
        $oauth->method('accessToken')->willReturn(null);

        $client = $this->createMock(GraphQlClient::class);
        $client->expects(self::once())
            ->method('query')
            ->with(self::anything(), ['limit' => 20], null, null)
            ->willReturn(['animes' => [['id' => '1', 'name' => 'New Anime', 'airedOn' => ['date' => '2026-08-01']]]]);

        $widget = new NewWidget($client, $oauth, StubTwigFactory::create());

        $html = $widget->render();

        self::assertStringContainsString('New Anime', $html);
    }

    public function testRenderSendsBearerAndMylistFilterWhenTokenIsStored(): void
    {
        $oauth = $this->createMock(ShikimoriOAuthClient::class);
        $oauth->method('accessToken')->willReturn('the-token');

        $client = $this->createMock(GraphQlClient::class);
        $client->expects(self::once())
            ->method('query')
            ->with(self::anything(), ['limit' => 20, 'mylist' => '!planned,!watching,!rewatching,!completed,!on_hold,!dropped'], null, 'the-token')
            ->willReturn(['animes' => [['id' => '2', 'name' => 'Personalized Anime']]]);

        $widget = new NewWidget($client, $oauth, StubTwigFactory::create());

        $html = $widget->render();

        self::assertStringContainsString('Personalized Anime', $html);
    }

    public function testRenderFallsBackToAnonymousQueryWhenAuthedRequestIsUnauthorized(): void
    {
        $oauth = $this->createMock(ShikimoriOAuthClient::class);
        $oauth->method('accessToken')->willReturn('a-stale-token');

        $client = $this->createMock(GraphQlClient::class);
        $client->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $query, array $variables, ?callable $onHeartbeat, ?string $bearer): array {
                if ($bearer !== null) {
                    throw new UnauthorizedHttpException('401');
                }

                self::assertNull($bearer);

                return ['animes' => [['id' => '3', 'name' => 'Anonymous Fallback']]];
            });

        $widget = new NewWidget($client, $oauth, StubTwigFactory::create());

        $html = $widget->render();

        self::assertStringContainsString('Anonymous Fallback', $html);
    }
}
