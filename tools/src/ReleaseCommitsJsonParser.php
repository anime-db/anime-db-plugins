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

namespace AnimeDb\Plugins\Tools;

/**
 * Decodes the commit-range JSON {@see ReleaseNotesFormatter} consumes on stdin (the shape
 * produced by pairing `git log -- plugins/<id>/` with `gh api .../commits/<sha>/pulls` for
 * each commit — assembled by the caller, not this tool, see issue #28) into
 * {@see ReleaseNotesCommit} value objects.
 *
 * Every string field is treated as untrusted data (commit subjects and PR titles come from a
 * public repository and may contain forked-PR content): this parser only checks shape, never
 * interprets the content.
 */
final class ReleaseCommitsJsonParser
{
    /**
     * @return list<ReleaseNotesCommit>
     */
    public function parse(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(\sprintf('Commits JSON is not valid JSON: %s', $exception->getMessage()));
        }

        if (!\is_array($decoded) || !array_is_list($decoded)) {
            throw new \RuntimeException('Commits JSON must be a JSON array.');
        }

        $commits = [];
        foreach ($decoded as $index => $entry) {
            if (!\is_array($entry)) {
                throw new \RuntimeException(\sprintf('Commit #%d must be a JSON object.', $index));
            }

            foreach (['sha', 'subject', 'author'] as $field) {
                if (!\is_string($entry[$field] ?? null) || $entry[$field] === '') {
                    throw new \RuntimeException(\sprintf('Commit #%d is missing a non-empty "%s" string field.', $index, $field));
                }
            }

            $rawPullRequests = $entry['prs'] ?? [];
            if (!\is_array($rawPullRequests) || !array_is_list($rawPullRequests)) {
                throw new \RuntimeException(\sprintf('Commit #%d has a "prs" field that must be a JSON array.', $index));
            }

            $pullRequests = [];
            foreach ($rawPullRequests as $prIndex => $pr) {
                if (!\is_array($pr)) {
                    throw new \RuntimeException(\sprintf('Commit #%d, PR #%d must be a JSON object.', $index, $prIndex));
                }

                if (!\is_int($pr['number'] ?? null)) {
                    throw new \RuntimeException(\sprintf('Commit #%d, PR #%d is missing an integer "number" field.', $index, $prIndex));
                }

                foreach (['title', 'url', 'author'] as $field) {
                    if (!\is_string($pr[$field] ?? null) || $pr[$field] === '') {
                        throw new \RuntimeException(\sprintf('Commit #%d, PR #%d is missing a non-empty "%s" string field.', $index, $prIndex, $field));
                    }
                }

                $pullRequests[] = new ReleaseNotesPullRequest(
                    number: $pr['number'],
                    title: $pr['title'],
                    url: $pr['url'],
                    author: $pr['author'],
                );
            }

            $commits[] = new ReleaseNotesCommit(
                sha: $entry['sha'],
                subject: $entry['subject'],
                author: $entry['author'],
                pullRequests: $pullRequests,
            );
        }

        return $commits;
    }
}
