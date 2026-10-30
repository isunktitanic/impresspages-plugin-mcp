<?php

namespace Plugin\Mcp;

/**
 * Cleans HTML written by an assistant before it goes into a Text widget. An allow-list:
 * other tags are unwrapped (their text kept), dangerous ones dropped with their content,
 * and every attribute except a safe link href is removed.
 */
class Html
{
    const ALLOWED = array(
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'a',
        'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
    );

    const DROPPED = array('script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'template', 'noscript', 'link', 'meta', 'base', 'head', 'title');

    public static function sanitize($html)
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="mcpRoot">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('mcpRoot');
        if (!$root) {
            return '';
        }
        self::cleanChildren($root);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }
        return trim($result);
    }

    protected static function cleanChildren(\DOMNode $node)
    {
        // Copy the list first: it changes while nodes are removed or unwrapped.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                continue;
            }
            if (!$child instanceof \DOMElement) {
                $node->removeChild($child); // comments, processing instructions
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROPPED, true)) {
                $node->removeChild($child);
                continue;
            }

            self::cleanChildren($child);

            if (!in_array($tag, self::ALLOWED, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            $href = $tag === 'a' ? trim($child->getAttribute('href')) : null;
            foreach (iterator_to_array($child->attributes) as $attribute) {
                $child->removeAttribute($attribute->name);
            }
            if ($href !== null && self::isSafeUrl($href)) {
                $child->setAttribute('href', $href);
            }
        }
    }

    protected static function isSafeUrl($url)
    {
        // Control characters and spaces can hide a "javascript:" scheme from simple checks.
        if (preg_match('/[\x00-\x20]/', $url)) {
            return false;
        }
        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $url, $matches)) {
            return in_array(strtolower($matches[1]), array('http', 'https', 'mailto', 'tel'), true);
        }
        // Relative links and #anchors; not "//host", which would point off the site without saying so.
        return strpos($url, '//') !== 0;
    }
}
