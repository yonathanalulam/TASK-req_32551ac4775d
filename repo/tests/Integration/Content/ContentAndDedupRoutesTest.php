<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Content;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Tests\Integration\IntegrationTestCase;

/**
 * Covers the three content/dedup routes previously lacking direct HTTP tests:
 *   - PATCH /api/v1/content/{id}
 *   - GET /api/v1/dedup/candidates
 *   - POST /api/v1/dedup/recompute
 */
final class ContentAndDedupRoutesTest extends IntegrationTestCase
{
    private function ingestContent(string $username): string
    {
        $token = $this->login($username);
        $resp = $this->request('POST', '/api/v1/content/parse', [
            'kind' => 'plain_text',
            'title' => 'Editable',
            'payload' => $this->englishBody(),
            'media_source' => 'article',
        ], $this->bearer($token));
        self::assertSame(201, $resp->getStatusCode(), (string) $resp->getBody());
        return (string) $this->decode($resp)['data']['content']['content_id'];
    }

    public function testContentMetadataPatchUpdatesTitleAndTags(): void
    {
        $this->createUser('author', 'instructor');
        $contentId = $this->ingestContent('author');
        $token = $this->login('author');

        $patch = $this->request('PATCH', '/api/v1/content/' . $contentId, [
            'title' => 'Refined Title',
            'section_tags' => ['news', 'science'],
        ], $this->bearer($token));
        self::assertSame(200, $patch->getStatusCode());
        $data = $this->decode($patch)['data'];
        self::assertSame('Refined Title', $data['title']);
        self::assertSame(2, (int) $data['version']);

        // Persistence check.
        $row = DB::table('contents')->where('content_id', $contentId)->first();
        self::assertSame('Refined Title', $row->title);
        self::assertSame(2, DB::table('content_sections')->where('content_id', $contentId)->count());
    }

    public function testContentMetadataPatchDeniedWithoutPermission(): void
    {
        $this->createUser('author', 'instructor');
        $contentId = $this->ingestContent('author');

        $this->createUser('reader', 'learner');
        $token = $this->login('reader');
        $resp = $this->request('PATCH', '/api/v1/content/' . $contentId, [
            'title' => 'hijacked',
        ], $this->bearer($token));
        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('NOT_AUTHORIZED', $this->decode($resp)['error']['code']);
    }

    public function testDedupCandidatesListAndRecomputeFlow(): void
    {
        // Ingest two very similar articles so a candidate pair is produced on recompute.
        $this->createUser('author', 'instructor');
        $authorToken = $this->login('author');
        $mkBody = fn(int $i) => 'The quick brown fox jumps over the lazy dog and watches '
            . 'the bright sun rise above the river. This is a simple article #' . $i . ' about the '
            . 'fox, the dog, and all of the animals in the forest. '
            . str_repeat('Some narrative filler to pass the 200-char floor. ', 5);
        foreach (['A', 'A '] as $idx => $titleSuffix) {
            $resp = $this->request('POST', '/api/v1/content/parse', [
                'kind' => 'plain_text',
                'title' => 'Morning Report' . $titleSuffix, // nearly identical titles -> candidate pair
                'payload' => $mkBody($idx),
                'media_source' => 'article',
                'author' => 'Jane',
            ], $this->bearer($authorToken));
            self::assertSame(201, $resp->getStatusCode());
        }

        // POST /api/v1/dedup/recompute — admin-only
        $this->createUser('admin', 'administrator');
        $adminToken = $this->login('admin');
        $recompute = $this->request('POST', '/api/v1/dedup/recompute', null, $this->bearer($adminToken));
        self::assertSame(200, $recompute->getStatusCode());
        $candidatesCount = (int) $this->decode($recompute)['data']['candidates'];
        self::assertGreaterThanOrEqual(1, $candidatesCount);

        // GET /api/v1/dedup/candidates — admin can see the pair.
        $list = $this->request('GET', '/api/v1/dedup/candidates', null, $this->bearer($adminToken));
        self::assertSame(200, $list->getStatusCode());
        $items = $this->decode($list)['data'];
        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertArrayHasKey('left_content_id', $item);
            self::assertArrayHasKey('right_content_id', $item);
            self::assertArrayHasKey('title_similarity', $item);
            self::assertArrayHasKey('status', $item);
        }
    }

    public function testDedupRecomputeDeniedForNonAdmin(): void
    {
        $this->createUser('reviewer', 'reviewer');
        $token = $this->login('reviewer');
        $resp = $this->request('POST', '/api/v1/dedup/recompute', null, $this->bearer($token));
        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('NOT_AUTHORIZED', $this->decode($resp)['error']['code']);
    }
}
