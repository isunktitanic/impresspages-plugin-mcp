<?php

namespace Plugin\Mcp;

/**
 * The tools an assistant can call. The editing tools are only offered to tokens with "allow editing" on.
 */
class Tools
{
    const MAX_LIMIT = 200;

    /**
     * @param array|null $token Without a token: every tool, including the editing ones
     */
    public static function definitions($token = null)
    {
        $tools = self::readTools();
        if ($token === null || Model::canEdit($token)) {
            foreach ($tools as &$tool) {
                if ($tool['name'] === 'get_page') {
                    $tool['inputSchema']['properties']['version'] = array(
                        'type' => 'string',
                        'enum' => array('published', 'draft'),
                        'default' => 'published',
                        'description' => '"draft" returns the latest unpublished version, with the widget ids to edit.',
                    );
                }
            }
            unset($tool);
            $tools = array_merge($tools, self::editTools());
        }
        return $tools;
    }

    protected static function editTools()
    {
        $edit = array('readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false);

        return array(
            array(
                'name' => 'propose_page_fields',
                'title' => 'Propose page title and SEO fields',
                'description' => 'Proposes a new title, meta title, meta description and/or keywords for a page. Nothing changes on the site: '
                    . 'the proposal waits for an administrator to apply or reject it. A new proposal from you for the same page replaces your earlier one.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id' => array('type' => 'integer', 'description' => 'Page id.'),
                        'title' => array('type' => 'string', 'maxLength' => 255, 'description' => 'Page title, as shown in menus and headings.'),
                        'metaTitle' => array('type' => 'string', 'description' => 'Title for search engines and browser tabs.'),
                        'description' => array('type' => 'string', 'description' => 'Meta description for search results.'),
                        'keywords' => array('type' => 'string'),
                    ),
                    'required' => array('id'),
                ),
                'annotations' => $edit,
            ),
            array(
                'name' => 'update_text_widget',
                'title' => 'Edit a text block (draft)',
                'description' => 'Replaces the HTML of a Text widget in the page\'s unpublished draft. Visitors keep seeing the published page until an administrator publishes it. '
                    . 'Allowed HTML: p, br, strong, em, u, s, sub, sup, a href, ul, ol, li, h2-h6, blockquote, tables. Anything else is removed.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'pageId' => array('type' => 'integer'),
                        'widgetId' => array('type' => 'integer', 'description' => 'Id of a Text widget from get_page.'),
                        'html' => array('type' => 'string', 'description' => 'The complete new content of the text block.'),
                    ),
                    'required' => array('pageId', 'widgetId', 'html'),
                ),
                'annotations' => $edit,
            ),
            array(
                'name' => 'update_heading_widget',
                'title' => 'Edit a heading (draft)',
                'description' => 'Changes the text (and optionally the level) of a Heading widget in the page\'s unpublished draft. Nothing is published.',
                'inputSchema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'pageId' => array('type' => 'integer'),
                        'widgetId' => array('type' => 'integer', 'description' => 'Id of a Heading widget from get_page.'),
                        'title' => array('type' => 'string', 'maxLength' => 255, 'description' => 'Plain text.'),
                        'level' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 6),
                    ),
                    'required' => array('pageId', 'widgetId', 'title'),
                ),
                'annotations' => $edit,
            ),
        );
    }

    protected static function readTools()
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
        if (in_array($name, array('propose_page_fields', 'update_text_widget', 'update_heading_widget'), true) && !Model::canEdit($token)) {
            throw ToolException::denied('This token is read-only. An administrator can allow editing for it under MCP Server in the admin.');
        }

        switch ($name) {
            case 'propose_page_fields':
                return Editor::proposePageFields($token, self::intArgument($arguments, 'id', 1), $arguments);
            case 'update_text_widget':
                return Editor::updateText($token, self::intArgument($arguments, 'pageId', 1), self::intArgument($arguments, 'widgetId', 1), self::stringArgument($arguments, 'html'));
            case 'update_heading_widget':
                $level = isset($arguments['level']) ? self::intArgument($arguments, 'level', 1, 6) : null;
                return Editor::updateHeading($token, self::intArgument($arguments, 'pageId', 1), self::intArgument($arguments, 'widgetId', 1), self::stringArgument($arguments, 'title'), $level);
            case 'list_languages':
                return self::listLanguages();
            case 'list_menus':
                return self::listMenus($arguments);
            case 'list_pages':
                return self::listPages($arguments);
            case 'get_page':
                return self::getPage($arguments, $token);
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

    protected static function getPage($arguments, $token)
    {
        $version = $arguments['version'] ?? 'published';
        if (!in_array($version, array('published', 'draft'), true)) {
            throw new ToolException('version must be "published" or "draft".');
        }
        if ($version === 'draft' && !Model::canEdit($token)) {
            throw ToolException::denied('Drafts are only shown to tokens that may edit.');
        }

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
        $page = Pages::details($row, !empty($arguments['includeHtml']), $version === 'draft');
        if (Model::canEdit($token)) {
            $page['pendingProposals'] = array_map(function ($change) {
                return array('changeId' => (int)$change['id'], 'after' => $change['after'], 'createdAt' => date('c', $change['createdAt']));
            }, Changes::pending($row['id']));
        }
        return $page;
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
