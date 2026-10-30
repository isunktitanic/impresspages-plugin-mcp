<?php

namespace Plugin\Mcp;

use Ip\Internal\Revision;

/**
 * Edits made by assistants. Nothing here publishes: page fields become proposals an administrator applies,
 * and widget edits land in the page's unpublished draft revision, which an administrator publishes in the editor.
 */
class Editor
{
    const MAX_HTML = 50000;

    const FIELD_LIMITS = array('title' => 255, 'metaTitle' => 1000, 'description' => 1000, 'keywords' => 1000);

    /**
     * @return array The stored proposal
     * @throws ToolException
     */
    public static function proposePageFields($token, $pageId, $fields)
    {
        $page = Pages::find($pageId);
        if (!$page) {
            throw new ToolException('Page not found. Use list_pages or search_pages to find page ids.');
        }

        $before = array();
        $after = array();
        foreach (self::FIELD_LIMITS as $field => $limit) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            if (!is_string($fields[$field])) {
                throw new ToolException($field . ' must be a string.');
            }
            $value = trim(preg_replace('/\s+/u', ' ', strip_tags($fields[$field])));
            if ($field === 'title' && $value === '') {
                throw new ToolException('title can\'t be empty.');
            }
            if (mb_strlen($value) > $limit) {
                throw new ToolException($field . ' is too long (at most ' . $limit . ' characters).');
            }
            if ($value === (string)$page[$field]) {
                continue;
            }
            $before[$field] = (string)$page[$field];
            $after[$field] = $value;
        }
        if (!$after) {
            throw new ToolException('Nothing to change: pass at least one of title, metaTitle, description or keywords with a new value.');
        }

        Changes::replacePending($token['id'], $page['id']);
        $id = Changes::record($token['id'], $page['id'], 'page', $before, $after, 'pending');

        return array(
            'changeId' => (int)$id,
            'pageId' => (int)$page['id'],
            'status' => 'pending',
            'before' => $before,
            'after' => $after,
            'note' => 'Saved as a proposal. Nothing is live yet: an administrator reviews it under MCP Server in the admin and applies or rejects it.',
        );
    }

    /**
     * @param string $html
     * @throws ToolException
     */
    public static function updateText($token, $pageId, $widgetId, $html)
    {
        if (strlen($html) > self::MAX_HTML) {
            throw new ToolException('html is too long (at most ' . self::MAX_HTML . ' bytes).');
        }
        $clean = Html::sanitize($html);
        if (trim(strip_tags($clean)) === '') {
            throw new ToolException('html has no text left after cleaning. Allowed: paragraphs, headings h2-h6, lists, links, bold, italic, tables.');
        }

        return self::editWidget($token, $pageId, $widgetId, 'Text', function ($data) use ($clean) {
            $data['text'] = $clean;
            return $data;
        });
    }

    /**
     * @throws ToolException
     */
    public static function updateHeading($token, $pageId, $widgetId, $title, $level = null)
    {
        $title = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new ToolException('title must be 1 to 255 characters of plain text.');
        }
        // The Heading widget prints its title as HTML, the way the editor saves it.
        $title = esc($title);

        return self::editWidget($token, $pageId, $widgetId, 'Heading', function ($data) use ($title, $level) {
            $data['title'] = $title;
            if ($level !== null) {
                $data['level'] = (string)$level;
            }
            return $data;
        });
    }

    /**
     * Finds the widget in the page's draft (creating the draft from the published version if there's none),
     * changes its data and records the change.
     */
    protected static function editWidget($token, $pageId, $widgetId, $type, $change)
    {
        $page = Pages::find($pageId);
        if (!$page) {
            throw new ToolException('Page not found. Use list_pages or search_pages to find page ids.');
        }

        $widget = ipDb()->fetchRow(
            'SELECT w.* FROM ' . ipTable('widget', 'w') . ' JOIN ' . ipTable('revision', 'r') . ' ON r.`revisionId` = w.`revisionId`'
            . ' WHERE w.`id` = ? AND r.`pageId` = ? AND w.`isDeleted` = 0',
            array($widgetId, $page['id'])
        );
        if (!$widget) {
            throw new ToolException('Widget ' . (int)$widgetId . ' isn\'t on page ' . (int)$page['id'] . '. Use get_page to see the widget ids.');
        }
        if ($widget['name'] !== $type) {
            throw new ToolException('Widget ' . (int)$widgetId . ' is a ' . $widget['name'] . ' widget, not ' . $type . '.');
        }

        $draft = self::draftWidget($page['id'], $widget);
        $before = json_decode($draft['data'], true);
        $before = is_array($before) ? $before : array();
        $after = $change($before);

        \Ip\Internal\Content\Model::updateWidget($draft['id'], array('data' => $after, 'updatedAt' => time()));
        Changes::record($token['id'], $page['id'], 'widget', $before, $after, 'draft', (int)$draft['id'], (int)$draft['revisionId']);

        return array(
            'pageId' => (int)$page['id'],
            'widgetId' => (int)$draft['id'],
            'draftRevisionId' => (int)$draft['revisionId'],
            'status' => 'draft',
            'text' => Pages::widgetText($type, $after),
            'note' => 'Saved in the page\'s unpublished draft. Visitors still see the published version until an administrator publishes the page in the editor.'
                . ((int)$draft['id'] !== (int)$widgetId ? ' The draft has its own widget ids: use widgetId ' . (int)$draft['id'] . ' for further edits, or get_page with version "draft".' : ''),
        );
    }

    /**
     * The copy of $widget in the page's latest unpublished revision.
     *
     * @throws ToolException When the widget is from an older version or the draft has changed that part of the page
     */
    protected static function draftWidget($pageId, $widget)
    {
        $last = Revision::getLastRevision($pageId);

        if ($widget['revisionId'] == $last['revisionId']) {
            if ($last['isPublished']) {
                return self::copyIntoNewDraft($last['revisionId'], $widget);
            }
            return $widget;
        }

        $published = Revision::getPublishedRevision($pageId);
        if ($widget['revisionId'] == $published['revisionId'] && !$last['isPublished']) {
            // There is already a draft. Use its copy of the widget if that part hasn't been changed there.
            $copy = ipDb()->selectRow('widget', '*', array(
                'revisionId' => $last['revisionId'],
                'name' => $widget['name'],
                'blockName' => $widget['blockName'],
                'position' => $widget['position'],
                'isDeleted' => 0,
            ));
            if ($copy && $copy['data'] === $widget['data']) {
                return $copy;
            }
        }

        throw new ToolException('This page has an unpublished draft that differs here. Call get_page with version "draft" and use the widget ids from there.');
    }

    /**
     * Copies the published revision into a new draft (the core copies the widgets) and returns the copy of $widget.
     */
    protected static function copyIntoNewDraft($revisionId, $widget)
    {
        // The core copies widgets in this order, giving each a new, higher id.
        $originals = ipDb()->selectAll('widget', '*', array('revisionId' => $revisionId, 'isDeleted' => 0), 'ORDER BY `position` ASC');
        $draftId = Revision::duplicateRevision($revisionId);
        $copies = ipDb()->selectAll('widget', '*', array('revisionId' => $draftId), 'ORDER BY `id` ASC');

        foreach ($originals as $i => $original) {
            if ($original['id'] == $widget['id']) {
                $copy = $copies[$i] ?? null;
                if ($copy && $copy['name'] === $original['name'] && $copy['position'] == $original['position']) {
                    return $copy;
                }
            }
        }
        throw new ToolException('Couldn\'t find the widget in the new draft. Call get_page with version "draft" and try again.');
    }
}
