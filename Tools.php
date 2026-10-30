<?php

namespace Plugin\Mcp;

/**
 * The tools an assistant can call. All of them only read.
 */
class Tools
{
    const MAX_LIMIT = 200;

    public static function definitions()
    {
        $readOnly = array('readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false);

        return array(
            array(
                'name' => 'list_languages',
                'title' => 'List languages',
                'description' => 'Lists the site\'s languages with their codes and home URLs, and the menus in each language. Pages belong to a language and sit in a menu.',
                'inputSchema' => array('type' => 'object', 'properties' => new \stdClass()),
                'annotations' => $readOnly,
            ),
            array(
                'name' => 'list_menus',
                'title' => 'List menus',
                'description' => 'Lists the menus (top-level page trees such as the main menu or footer) with their alias and number of pages.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'languageCode' => array('type' => 'string', 'description' => 'Only menus of this language, e.g. "en".'),
                    ),
                ),
                'annotations' => $readOnly,
            ),
            array(
                'name' => 'list_pages',
                'title' => 'List pages',
                'description' => 'Lists pages in menu order (depth first) with id, title, URL and parent. Use get_page with an id for the full page.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'languageCode' => array('type' => 'string', 'description' => 'Only pages of this language.'),
                        'menu' => array('type' => 'string', 'description' => 'Only pages in the menu with this alias, e.g. "menu1".'),
                        'parentId' => array('type' => 'integer', 'description' => 'Only the pages below this page.'),
                        'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => 100),
                        'offset' => array('type' => 'integer', 'minimum' => 0, 'default' => 0),
                    ),
                ),
                'annotations' => $readOnly,
            ),
            array(
                'name' => 'get_page',
                'title' => 'Get page',
                'description' => 'Returns one page: title, URL, SEO fields (meta title, description, keywords) and the published text content, grouped by block and widget. Pass either id or url.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id' => array('type' => 'integer', 'description' => 'Page id from list_pages or search_pages.'),
                        'url' => array('type' => 'string', 'description' => 'Full page URL or its path, e.g. "about-us/".'),
                        'includeHtml' => array('type' => 'boolean', 'default' => false, 'description' => 'Also return the HTML of text widgets.'),
                    ),
                ),
                'annotations' => $readOnly,
            ),
            array(
                'name' => 'search_pages',
                'title' => 'Search pages',
                'description' => 'Finds pages whose title, SEO fields or published text contain the query (case-insensitive). Returns matching pages with a snippet.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'query' => array('type' => 'string', 'minLength' => 2, 'description' => 'Words to look for.'),
                        'languageCode' => array('type' => 'string', 'description' => 'Only pages of this language.'),
                        'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20),
                    ),
                    'required' => array('query'),
                ),
                'annotations' => $readOnly,
            ),
        );
    }

    public static function exists($name)
    {
        foreach (self::definitions() as $definition) {
            if ($definition['name'] === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $name
     * @param array $arguments
     * @param array $token The calling token's row, for permission checks
     * @return array Always a JSON object (associative array)
     * @throws ToolException
     */
    public static function call($name, $arguments, $token)
    {
        switch ($name) {
            case 'list_languages':
                return self::listLanguages();
            case 'list_menus':
                return self::listMenus($arguments);
            case 'list_pages':
                return self::listPages($arguments);
            case 'get_page':
                return self::getPage($arguments);
            case 'search_pages':
                return self::searchPages($arguments);
        }
        throw new ToolException('Unknown tool: ' . $name);
    }

    protected static function listLanguages()
    {
        $languages = array();
        foreach (Pages::languages() as $language) {
            $languages[] = array(
                'code' => $language->getCode(),
                'title' => $language->getTitle(),
                'abbreviation' => $language->getAbbreviation(),
                'url' => $language->getLink(),
                'visible' => (bool)$language->isVisible(),
                'textDirection' => $language->getTextDirection(),
                'menus' => array_map(function ($menu) {
                    return array('alias' => $menu['alias'], 'title' => $menu['title']);
                }, Pages::menus($language->getCode())),
            );
        }
        return array('languages' => $languages);
    }

    protected static function listMenus($arguments)
    {
        $languageCode = self::languageArgument($arguments);
        $menus = array();
        foreach (Pages::menus($languageCode) as $menu) {
            $menus[] = array(
                'alias' => $menu['alias'],
                'title' => $menu['title'],
                'languageCode' => $menu['languageCode'],
                'pageCount' => count(Pages::listPages($menu['languageCode'], $menu['alias'])),
            );
        }
        return array('menus' => $menus);
    }

    protected static function listPages($arguments)
    {
        $languageCode = self::languageArgument($arguments);
        $menu = isset($arguments['menu']) ? self::stringArgument($arguments, 'menu') : null;
        $parentId = isset($arguments['parentId']) ? self::intArgument($arguments, 'parentId', 1) : null;
        $limit = isset($arguments['limit']) ? self::intArgument($arguments, 'limit', 1, self::MAX_LIMIT) : 100;
        $offset = isset($arguments['offset']) ? self::intArgument($arguments, 'offset', 0) : 0;

        $pages = Pages::listPages($languageCode, $menu, $parentId);
        return array(
            'total' => count($pages),
            'offset' => $offset,
            'pages' => array_slice($pages, $offset, $limit),
        );
    }

    protected static function getPage($arguments)
    {
        if (isset($arguments['id'])) {
            $row = Pages::find(self::intArgument($arguments, 'id', 1));
        } elseif (isset($arguments['url'])) {
            $row = self::findByUrl(self::stringArgument($arguments, 'url'));
        } else {
            throw new ToolException('Pass either id or url.');
        }
        if (!$row) {
            throw new ToolException('Page not found. Use list_pages or search_pages to find page ids.');
        }
        return Pages::details($row, !empty($arguments['includeHtml']));
    }

    protected static function searchPages($arguments)
    {
        $query = trim(self::stringArgument($arguments, 'query'));
        if (mb_strlen($query) < 2) {
            throw new ToolException('query must be at least 2 characters.');
        }
        $limit = isset($arguments['limit']) ? self::intArgument($arguments, 'limit', 1, 50) : 20;
        $results = Pages::search($query, self::languageArgument($arguments), $limit);
        return array('query' => $query, 'count' => count($results), 'results' => $results);
    }

    /**
     * Matches a full URL or a path against the URLs the site generates.
     */
    protected static function findByUrl($url)
    {
        $base = ipConfig()->baseUrl();
        $path = $url;
        if (stripos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        } elseif (preg_match('#^https?://#i', $path)) {
            $path = ltrim((string)parse_url($path, PHP_URL_PATH), '/');
        }
        $path = trim(strtok($path, '?#'), '/');

        foreach (Pages::languages() as $language) {
            foreach (Pages::listPages($language->getCode()) as $page) {
                $pagePath = trim(substr((string)$page['url'], strlen($base)), '/');
                if ($pagePath === $path) {
                    return Pages::find($page['id']);
                }
            }
        }
        return null;
    }

    protected static function languageArgument($arguments)
    {
        if (!isset($arguments['languageCode'])) {
            return null;
        }
        $code = self::stringArgument($arguments, 'languageCode');
        if (!Pages::language($code)) {
            throw new ToolException('Unknown language code "' . $code . '". Use list_languages to see the codes.');
        }
        return $code;
    }

    protected static function stringArgument($arguments, $name)
    {
        if (!isset($arguments[$name]) || !is_string($arguments[$name])) {
            throw new ToolException($name . ' must be a string.');
        }
        return $arguments[$name];
    }

    protected static function intArgument($arguments, $name, $min = null, $max = null)
    {
        $value = $arguments[$name] ?? null;
        if (is_string($value) && ctype_digit($value)) {
            $value = (int)$value;
        }
        if (!is_int($value) || ($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            $range = $max !== null ? " between $min and $max" : ($min !== null ? " of at least $min" : '');
            throw new ToolException($name . ' must be a whole number' . $range . '.');
        }
        return $value;
    }
}
