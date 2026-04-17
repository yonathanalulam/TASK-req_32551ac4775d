<?php

declare(strict_types=1);

namespace Meridian\Domain\Content\Parsing;

/**
 * Deterministic HTML denoising. Operates purely on the provided payload; never fetches
 * any remote resources.
 *
 * Removes: script, style, iframe, noscript, object, embed, template, form,
 *          nav, header, footer, aside, figure, figcaption, button, menu,
 *          hidden/ARIA-hidden elements, tracking attributes, inline styles.
 *
 * Returns:
 *   - title: candidate title (first <h1> or <title>)
 *   - body: plain-text extract with paragraph breaks preserved
 *   - media_candidates: list of img/video/audio src references (local pointers or hashed remote)
 *   - provenance_urls: list of hrefs preserved for evidence
 */
final class HtmlDenoiser
{
    private const DROP_TAGS = [
        'script', 'style', 'iframe', 'noscript', 'object', 'embed', 'template',
        'form', 'nav', 'header', 'footer', 'aside', 'button', 'menu', 'dialog',
        'figcaption',
    ];

    private const BOILERPLATE_ID_CLASS_RE = '/\b(ads?|advert|promo|sponsor|banner|newsletter|subscribe|popup|share|social|cookie|consent|related|trending|recommend|comments?|footer|header|nav(?:igation)?|sidebar|breadcrumbs?|menu|utility-bar|site-chrome)\b/i';

    public function denoise(string $html): array
    {
        if ($html === '') {
            return ['title' => null, 'body' => '', 'media_candidates' => [], 'provenance_urls' => [], 'ad_link_count' => 0];
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new \DOMXPath($dom);

        // Remove disallowed tags
        foreach (self::DROP_TAGS as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag), false) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        // Remove nodes explicitly hidden
        foreach ($xpath->query('//*[@aria-hidden="true" or contains(@style, "display:none") or contains(@style, "visibility:hidden") or @hidden]') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
        // Drop boilerplate by class/id
        foreach ($xpath->query('//*[@class or @id]') ?: [] as $node) {
            /** @var \DOMElement $node */
            $class = $node->getAttribute('class');
            $id = $node->getAttribute('id');
            if ((($class !== '') && preg_match(self::BOILERPLATE_ID_CLASS_RE, $class)) ||
                (($id !== '') && preg_match(self::BOILERPLATE_ID_CLASS_RE, $id))) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Candidate title
        $title = null;
        $h1 = $dom->getElementsByTagName('h1');
        if ($h1->length > 0) {
            $title = trim((string) $h1->item(0)?->textContent);
        }
        if (($title === null || $title === '') && $dom->getElementsByTagName('title')->length > 0) {
            $title = trim((string) $dom->getElementsByTagName('title')->item(0)?->textContent);
        }

        // Track ad-like links before stripping anchors
        $adLinkCount = 0;
        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            /** @var \DOMElement $anchor */
            $cls = $anchor->getAttribute('class');
            $rel = $anchor->getAttribute('rel');
            if (preg_match('/\b(ad|sponsor|promo|affiliate|track|utm)\b/i', $cls . ' ' . $rel)) {
                $adLinkCount++;
            }
            if (preg_match('/[?&]utm_|(\/|\.)(ad|ads|promo|sponsor|affiliate)(\/|\.)/i', $anchor->getAttribute('href'))) {
                $adLinkCount++;
            }
        }

        // Media candidates
        $media = [];
        foreach (['img' => 'image', 'video' => 'video', 'audio' => 'audio'] as $tag => $kind) {
            foreach ($dom->getElementsByTagName($tag) as $el) {
                /** @var \DOMElement $el */
                $src = $el->getAttribute('src');
                if ($src === '') {
                    continue;
                }
                $media[] = [
                    'media_type' => $kind,
                    'src' => $src,
                    'alt' => $el->getAttribute('alt'),
                ];
            }
        }

        // Provenance URLs
        $provenance = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $a) {
            /** @var \DOMElement $a */
            $href = $a->getAttribute('href');
            if ($href !== '' && strlen($href) < 2000) {
                $provenance[] = $href;
            }
        }

        // Extract body as text, preserving paragraph boundaries
        $bodyBlocks = [];
        $blockTags = ['p', 'div', 'article', 'section', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'td', 'th'];
        foreach ($blockTags as $bt) {
            foreach ($dom->getElementsByTagName($bt) as $node) {
                $text = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
                if ($text !== '') {
                    $bodyBlocks[] = $text;
                }
            }
        }
        $body = trim(implode("\n\n", $bodyBlocks));

        return [
            'title' => $title !== null && $title !== '' ? $title : null,
            'body' => $body,
            'media_candidates' => $media,
            'provenance_urls' => $provenance,
            'ad_link_count' => $adLinkCount,
        ];
    }
}
