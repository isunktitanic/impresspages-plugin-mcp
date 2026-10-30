<?php

namespace Plugin\Mcp;

/**
 * The record of every edit made through MCP, and the review queue for page fields.
 */
class Changes
{
    const PAGE_FIELDS = array('title', 'metaTitle', 'description', 'keywords');

    public static function record($tokenId, $pageId, $type, $before, $after, $status, $widgetId = null, $revisionId = null)
    {
        return ipDb()->insert('mcp_change', array(
            'tokenId' => $tokenId,
            'pageId' => $pageId,
            'type' => $type,
            'widgetId' => $widgetId,
            'revisionId' => $revisionId,
            'before' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'after' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $status,
            'createdAt' => time(),
        ));
    }

    public static function get($id)
    {
        $row = ipDb()->selectRow('mcp_change', '*', array('id' => $id));
        return $row ? self::decode($row) : null;
    }

    /**
     * Page field proposals still waiting for review, oldest first.
     */
    public static function pending($pageId = null)
    {
        $where = array('type' => 'page', 'status' => 'pending');
        if ($pageId !== null) {
            $where['pageId'] = $pageId;
        }
        return array_map(array(__CLASS__, 'decode'), ipDb()->selectAll('mcp_change', '*', $where, 'ORDER BY `id`'));
    }

    public static function recent($limit = 50)
    {
        $rows = ipDb()->fetchAll(
            'SELECT c.*, t.`label` FROM ' . ipTable('mcp_change', 'c') . ' LEFT JOIN ' . ipTable('mcp_token', 't') . ' ON t.`id` = c.`tokenId`'
            . " WHERE c.`status` <> 'pending' ORDER BY c.`id` DESC LIMIT " . (int)$limit
        );
        return array_map(array(__CLASS__, 'decode'), $rows);
    }

    /**
     * An earlier proposal from the same token for the same page is replaced by a new one.
     */
    public static function replacePending($tokenId, $pageId)
    {
        ipDb()->update('mcp_change', array('status' => 'replaced'), array('tokenId' => $tokenId, 'pageId' => $pageId, 'type' => 'page', 'status' => 'pending'));
    }

    /**
     * Writes a proposal to the page. Only fields that still hold the value the assistant saw are changed,
     * so an edit made in the admin since then isn't overwritten.
     *
     * @return array Names of the fields that were skipped because they had changed
     */
    public static function apply($change)
    {
        $page = ipDb()->selectRow('page', '*', array('id' => $change['pageId'], 'isDeleted' => 0));
        if (!$page) {
            self::review($change['id'], 'rejected');
            return array_keys($change['after']);
        }

        $update = array();
        $skipped = array();
        foreach ($change['after'] as $field => $value) {
            if (!in_array($field, self::PAGE_FIELDS, true)) {
                continue;
            }
            if ((string)$page[$field] !== (string)($change['before'][$field] ?? '')) {
                $skipped[] = $field;
                continue;
            }
            $update[$field] = $value;
        }
        if ($update) {
            \Ip\Internal\Pages\Service::updatePage($change['pageId'], $update + array('updatedAt' => date('Y-m-d H:i:s')));
        }
        self::review($change['id'], 'applied');
        ipLog()->info('Mcp.changeApplied: page {pageId}', array('pageId' => $change['pageId'], 'changeId' => $change['id'], 'fields' => array_keys($update), 'adminId' => ipAdminId()));

        return $skipped;
    }

    public static function review($id, $status)
    {
        ipDb()->update('mcp_change', array('status' => $status, 'reviewedBy' => ipAdminId() ?: null, 'reviewedAt' => time()), array('id' => $id));
    }

    protected static function decode($row)
    {
        $row['before'] = json_decode($row['before'], true) ?: array();
        $row['after'] = json_decode($row['after'], true) ?: array();
        return $row;
    }
}
