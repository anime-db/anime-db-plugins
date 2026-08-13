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

namespace AnimeDb\Plugins\Tools;

/**
 * Formats a single plugin's release notes body from a commit range, GitHub-"Generate release
 * notes"-style but scoped to `plugins/<id>/` (see issue #28): the built-in `gh --generate-notes`
 * cannot filter a monorepo release by path, so this walks the caller-supplied, already
 * path-filtered commit list itself.
 *
 * A commit carrying an associated pull request (the first entry of its `pullRequests`, resolved
 * by the caller via `gh api .../commits/<sha>/pulls` — the authoritative commit→PR mapping, not
 * a "#NN" regex over the subject, which cannot tell an issue reference apart from a PR number)
 * contributes one line built from the PR's title and author, not the commit subject: several
 * commits belonging to the same PR (a merge commit plus its squashed commits, or several commits
 * landed via merge rather than squash) collapse into that PR's single line. A commit with no
 * associated PR contributes its subject verbatim.
 *
 * Commit subjects and PR titles are public-repository, possibly-forked-PR content: they are
 * treated strictly as data and copied into the output as-is. The release body they end up in is
 * never fed back into anything executable or into the signed registry, so no markdown escaping
 * is performed (same trust boundary as GitHub's own "Generate release notes").
 */
final class ReleaseNotesFormatter
{
    /**
     * @param list<ReleaseNotesCommit> $commits in the same order as `git log` (newest first)
     */
    public function format(string $pluginId, string $version, string $repo, ?string $previousTag, array $commits): string
    {
        $lines = [];
        $seenPullRequestNumbers = [];

        foreach ($commits as $commit) {
            $pullRequest = $commit->pullRequests[0] ?? null;

            if ($pullRequest === null) {
                $lines[] = \sprintf('- %s', $commit->subject);
                continue;
            }

            if (isset($seenPullRequestNumbers[$pullRequest->number])) {
                continue;
            }
            $seenPullRequestNumbers[$pullRequest->number] = true;

            $lines[] = \sprintf(
                '- %s by @%s in [#%d](%s)',
                $pullRequest->title,
                $pullRequest->author,
                $pullRequest->number,
                $pullRequest->url,
            );
        }

        if ($previousTag === null || $previousTag === '') {
            return implode("\n", ['Первый релиз.', '', ...$lines]);
        }

        $compareUrl = \sprintf(
            'https://github.com/%s/compare/%s...%s/%s',
            $repo,
            $previousTag,
            $pluginId,
            $version,
        );

        return implode("\n", [...$lines, '', \sprintf('**Полный список изменений:** %s', $compareUrl)]);
    }
}
