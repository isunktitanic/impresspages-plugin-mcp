<?php

namespace Plugin\Mcp;

/**
 * Read access to languages, menus and pages, limited to what a visitor can see
 * unless the "Include hidden pages" option is on.
 */
class Pages
{
    protected static $rows = null;

    /**
     * Every page row that isn't deleted, keyed by id. Sites are small enough to load them all.
     */
    protected static function rows()
    {
        if (self::$rows === null) {
            self::$rows = array();
            foreach (ipDb()->selectAll('page', '*', array('isDeleted' => 0), 'ORDER BY `pageOrder`') as $row) {
                self::$rows[(int)$row['id']] = $row;
            }
        }
        return self::$rows;
    }

    public static function languages()
    {
        $result = array();
        foreach (ipContent()->getLanguages() as $language) {
            if (!$language->isVisible() && !Model::includeHidden()) {
                continue;
            }
            $result[] = $language;
        }
        return $result;
    }

    public static function language($code)
    {
        foreach (self::languages() as $language) {
            if ($language->getCode() === $code) {
                return $language;
            }
        }
        return null;
    }

    public static function menus($languageCode = null)
    {
        $result = array();
        foreach (self::rows() as $row) {
            if ((int)$row['parentId'] !== 0 || !self::isReadable($row)) {
                continue;
            }
            if ($languageCode !== null && $row['languageCode'] !== $languageCode) {
                continue;
            }
            $result[] = $row;
        }
        return $result;
    }

    /**
     * A page can be read when its language is readable and it isn't hidden by itself or a parent.
     * Password-protected pages count as hidden.
     */
    public static function isReadable($row)
    {
        if (!self::language($row['languageCode'])) {
            return false;
        }
        if (Model::includeHidden()) {
            return true;
        }
        $rows = self::rows();
        for ($current = $row; $current; $current = $rows[(int)$current['parentId']] ?? null) {
            if (!$current['isVisible'] || $current['isSecured']) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array|null The page row if it exists and can be read. Menus aren't pages.
     */
    public static function find($id)
    {
        $row = self::rows()[(int)$id] ?? null;
        if (!$row || (int)$row['parentId'] === 0 || !self::isReadable($row)) {
            return null;
        }
        return $row;
    }

    /**
     * Pages in menu order, depth first.
     *
     * @param string|null $languageCode
     * @param string|null $menu Menu alias, e.g. "menu1"
     * @param int|null $parentId Only this page's descendants
     * @return array
     */
    public static function listPages($languageCode = null, $menu = null, $parentId = null)
    {
        $result = array();
        if ($parentId) {
            $parent = self::rows()[(int)$parentId] ?? null;
            if ($parent && self::isReadable($parent)) {
                self::collect((int)$parent['id'], 0, $result);
            }
            return $result;
        }
        foreach (self::menus($languageCode) as $menuRow) {
            if ($menu !== null && $menuRow['alias'] !== $menu) {
                continue;
            }
            self::collect((int)$menuRow['id'], 0, $result);
        }
        return $result;
    }

    protected static function collect($parentId, $depth, &$result)
    {
        foreach (self::rows() as $row) {
            if ((int)$row['parentId'] !== $parentId || !self::isReadable($row)) {
                continue;
            }
            $item = self::summary($row);
            $item['depth'] = $depth;
            $result[] = $item;
            self::collect((int)$row['id'], $depth + 1, $result);
        }
    }

    public static function summary($row)
    {
        return array(
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'url' => self::url($row),
            'languageCode' => $row['languageCode'],
            'menu' => self::menuAlias($row),
            'parentId' => (int)$row['parentId'],
            'visible' => (bool)$row['isVisible'],
            'updatedAt' => self::date($row['updatedAt']),
        );
    }

    /**
     * @param bool $draft Read the latest revision (the draft, if there is one) instead of the published one
     */
    public static function details($row, $includeHtml = false, $draft = false)
    {
        $page = self::summary($row);
        $page += array(
            'isHome' => self::isHome($row),
            'urlPath' => $row['urlPath'],
            'seo' => array(
                'metaTitle' => (string)$row['metaTitle'],
                'description' => (string)$row['description'],
                'keywords' => (string)$row['keywords'],
            ),
            'layout' => $row['layout'],
            'redirectUrl' => $row['redirectUrl'] ?: null,
            'passwordProtected' => (bool)$row['isSecured'],
            'createdAt' => self::date($row['createdAt']),
        );

        $publishedRevisionId = self::publishedRevisionId($row['id']);
        $latestRevisionId = ipDb()->selectValue('revision', 'revisionId', array('pageId' => $row['id']), 'ORDER BY `createdAt` DESC, `revisionId` DESC');
        $page['hasUnpublishedChanges'] = $latestRevisionId && $latestRevisionId != $publishedRevisionId;
        $revisionId = $draft && $latestRevisionId ? $latestRevisionId : $publishedRevisionId;
        $page['version'] = $revisionId == $publishedRevisionId ? 'published' : 'draft';

        $blocks = array();
        $text = array();
        foreach (self::widgets($revisionId) as $widget) {
            $data = json_decode($widget['data'], true);
            $item = array(
                'widgetId' => (int)$widget['id'],
                'type' => $widget['name'],
                'text' => self::widgetText($widget['name'], is_array($data) ? $data : array()),
            );
            if ($includeHtml && is_array($data) && isset($data['text']) && is_string($data['text'])) {
                $item['html'] = $data['text'];
            }
            $blocks[$widget['blockName']][] = $item;
            if ($item['text'] !== '') {
                $text[] = $item['text'];
            }
        }
        $page['content'] = implode("\n\n", $text);
        $page['blocks'] = $blocks;

        return $page;
    }

    /**
     * Case-insensitive search in titles, SEO fields and published text.
     */
    public static function search($query, $languageCode = null, $limit = 20)
    {
        $results = array();
        foreach (self::rows() as $row) {
            if ((int)$row['parentId'] === 0 || !self::isReadable($row)) {
                continue;
            }
            if ($languageCode !== null && $row['languageCode'] !== $languageCode) {
                continue;
            }

            $fields = array(
                'title' => $row['title'],
                'metaTitle' => (string)$row['metaTitle'],
                'description' => (string)$row['description'],
                'keywords' => (string)$row['keywords'],
                'content' => self::plainContent($row['id']),
            );
            $matched = array();
            $snippet = null;
            foreach ($fields as $name => $value) {
                $position = mb_stripos($value, $query);
                if ($position === false) {
                    continue;
                }
                $matched[] = $name;
                if ($snippet === null) {
                    $snippet = self::snippet($value, $position, mb_strlen($query));
                }
            }
            if ($matched) {
                $results[] = self::summary($row) + array('matchedIn' => $matched, 'snippet' => $snippet);
                if (count($results) >= $limit) {
                    break;
                }
            }
        }
        return $results;
    }

    protected static function plainContent($pageId)
    {
        $text = array();
        foreach (self::widgets(self::publishedRevisionId($pageId)) as $widget) {
            $data = json_decode($widget['data'], true);
            $text[] = self::widgetText($widget['name'], is_array($data) ? $data : array());
        }
        return implode("\n", array_filter($text, 'strlen'));
    }

    protected static function snippet($value, $position, $length)
    {
        $start = max(0, $position - 80);
        $snippet = mb_substr($value, $start, $length + 160);
        return ($start > 0 ? '…' : '') . preg_replace('/\s+/u', ' ', $snippet) . ($start + $length + 160 < mb_strlen($value) ? '…' : '');
    }

    protected static function publishedRevisionId($pageId)
    {
        return ipDb()->selectValue('revision', 'revisionId', array('pageId' => $pageId, 'isPublished' => 1), 'ORDER BY `revisionId` DESC');
    }

    protected static function widgets($revisionId)
    {
        if (!$revisionId) {
            return array();
        }
        return ipDb()->selectAll(
            'widget',
            '*',
            array('revisionId' => $revisionId, 'isDeleted' => 0, 'isVisible' => 1),
            "ORDER BY `blockName` <> 'main', `blockName`, `position`"
        );
    }

    /**
     * Readable text of a widget. HTML widgets hold code (scripts, embeds), so they're left out.
     */
    public static function widgetText($name, $data)
    {
        if ($name === 'Html') {
            return '';
        }
        if ($name === 'Heading') {
            return self::htmlToText((string)($data['title'] ?? ''));
        }
        if ($name === 'Text') {
            return self::htmlToText((string)($data['text'] ?? ''));
        }

        // Other widgets (images, galleries, files, ...): collect their captions and titles.
        $parts = array();
        array_walk_recursive($data, function ($value, $key) use (&$parts) {
            if (is_string($value) && in_array($key, array('title', 'text', 'caption', 'description', 'label'), true)) {
                $parts[] = self::htmlToText($value);
            }
        });
        return implode("\n", array_filter($parts, 'strlen'));
    }

    public static function htmlToText($html)
    {
        // Line breaks in HTML source mean nothing; only tags start new lines.
        $html = preg_replace('/\s+/u', ' ', $html);
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<li\b[^>]*>#i', "\n- ", $html);
        $html = preg_replace('#<br\s*/?>|</tr>#i', "\n", $html);
        $html = preg_replace('#</(p|div|h[1-6]|blockquote|ul|ol)>#i', "\n\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/ *\n */u', "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = preg_replace("/\n\n(?=- )/", "\n", $text);
        return trim($text);
    }

    protected static function isHome($row)
    {
        return (int)$row['id'] === (int)ipContent()->getDefaultPageId($row['languageCode']);
    }

    protected static function url($row)
    {
        if ((int)$row['parentId'] === 0) {
            return null;
        }
        if (self::isHome($row)) {
            $language = self::language($row['languageCode']);
            return $language ? $language->getLink() : null;
        }
        $page = new \Ip\Page($row);
        return $page->getLink();
    }

    protected static function menuAlias($row)
    {
        $rows = self::rows();
        for ($current = $row; $current; $current = $rows[(int)$current['parentId']] ?? null) {
            if ((int)$current['parentId'] === 0) {
                return $current['alias'];
            }
        }
        return null;
    }

    protected static function date($timestamp)
    {
        $time = $timestamp ? strtotime($timestamp) : false;
        return $time && $time > 0 ? date('c', $time) : null;
    }
}
