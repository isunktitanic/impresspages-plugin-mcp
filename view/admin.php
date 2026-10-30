<?php
/**
 * @var string $endpoint
 * @var array $tokens
 * @var array $log
 * @var array|null $newToken
 * @var string|null $notice
 * @var array $pending Page field proposals waiting for review
 * @var array $recentChanges
 * @var array $pageTitles
 */
$formatTime = function ($time) {
    return $time ? date('Y-m-d H:i', (int)$time) : '—';
};
$pageLink = function ($pageId) use ($pageTitles) {
    $title = $pageTitles[(int)$pageId] ?? ('#' . (int)$pageId);
    $page = ipContent()->getPage((int)$pageId);
    return $page ? '<a href="' . escAttr($page->getLink()) . '" target="_blank">' . esc($title) . '</a>' : esc($title);
};
$tokenLabels = array_column($tokens, 'label', 'id');
$fieldLabels = array('title' => 'Title', 'metaTitle' => 'Meta title', 'description' => 'Meta description', 'keywords' => 'Keywords');
$statusClass = array('ok' => 'success', 'error' => 'warning', 'denied' => 'danger', 'unauthorized' => 'danger', 'rate_limited' => 'warning');
?>
<div class="ipModuleMcp" style="padding: 0 20px 40px; max-width: 1100px;">
    <div class="page-header">
        <h1>MCP Server</h1>
    </div>

    <p>
        AI assistants that support the Model Context Protocol can read this site's pages through
        <code><?php echo esc($endpoint); ?></code> with one of the API tokens below.
        They can list languages, menus and pages, read a page's SEO fields and text, and search.
        Tokens are read-only unless you allow editing. Even then nothing goes live by itself:
        title and SEO changes wait below for you to apply, and text changes are saved as the page's unpublished draft for you to publish in the editor.
    </p>

    <?php if ($notice) { ?>
        <p class="alert alert-info"><?php echo esc($notice); ?></p>
    <?php } ?>

    <?php if (!$enabled) { ?>
        <p class="alert alert-warning">The endpoint is switched off. Turn on <strong>Enable the MCP endpoint</strong> on the Plugins screen.</p>
    <?php } ?>
    <p class="text-muted">
        Settings (Plugins screen → MCP Server):
        HTTPS <?php echo $httpsOnly ? 'required' : '<strong>not required</strong>'; ?> ·
        <?php echo (int)$rateLimit; ?> requests per minute per token ·
        hidden pages <?php echo $includeHidden ? '<strong>included</strong>' : 'not included'; ?> ·
        log kept <?php echo (int)$logDays; ?> days.
    </p>

    <?php if ($newToken) { ?>
        <div class="alert alert-success">
            <p><strong>Token "<?php echo esc($newToken['label']); ?>" created.</strong> Copy it now: it is stored only as a hash and can't be shown again.</p>
            <p><input type="text" class="form-control" readonly value="<?php echo escAttr($newToken['token']); ?>" onclick="this.select()" style="font-family: monospace;"></p>
            <p style="margin-bottom: 0;">Add it to Claude Code:</p>
            <pre style="white-space: pre-wrap; margin-bottom: 0;">claude mcp add --transport http mysite <?php echo esc($endpoint); ?> --header "Authorization: Bearer <?php echo esc($newToken['token']); ?>"</pre>
        </div>
    <?php } ?>

    <?php if ($pending) { ?>
        <h2>Waiting for review <small><?php echo count($pending); ?></small></h2>
        <?php foreach ($pending as $change) { ?>
            <div class="panel panel-default">
                <div class="panel-heading">
                    <?php echo $pageLink($change['pageId']); ?>
                    <span class="text-muted">· proposed <?php echo $formatTime($change['createdAt']); ?>
                        by <?php echo esc($tokenLabels[$change['tokenId']] ?? 'a deleted token'); ?></span>
                </div>
                <table class="table table-condensed" style="margin: 0;">
                    <thead><tr><th style="width: 140px;">Field</th><th>Now</th><th>Proposed</th></tr></thead>
                    <tbody>
                    <?php foreach ($change['after'] as $field => $value) { ?>
                        <tr>
                            <td><?php echo esc($fieldLabels[$field] ?? $field); ?></td>
                            <td><?php echo esc((string)($change['before'][$field] ?? '')); ?></td>
                            <td><strong><?php echo esc((string)$value); ?></strong></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
                <div class="panel-footer">
                    <form method="post" action="<?php echo escAttr(ipActionUrl(array('aa' => 'Mcp.reviewChange'))); ?>" style="margin: 0;">
                        <input type="hidden" name="securityToken" value="<?php echo escAttr(ipSecurityToken()); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$change['id']; ?>">
                        <button type="submit" name="decision" value="apply" class="btn btn-primary btn-sm">Apply to the live page</button>
                        <button type="submit" name="decision" value="reject" class="btn btn-default btn-sm">Reject</button>
                    </form>
                </div>
            </div>
        <?php } ?>
    <?php } ?>

    <h2>API tokens</h2>
    <form class="form-inline" method="post" action="<?php echo escAttr(ipActionUrl(array('aa' => 'Mcp.createToken'))); ?>" style="margin-bottom: 15px;">
        <input type="hidden" name="securityToken" value="<?php echo escAttr(ipSecurityToken()); ?>">
        <div class="form-group">
            <label for="mcpTokenLabel" class="sr-only">Label</label>
            <input type="text" class="form-control" id="mcpTokenLabel" name="label" maxlength="100" placeholder="Label, e.g. Claude on my laptop" style="min-width: 280px;">
        </div>
        <div class="checkbox" style="margin: 0 10px;">
            <label><input type="checkbox" name="allowEdit" value="1"> Allow editing (drafts and proposals only)</label>
        </div>
        <button type="submit" class="btn btn-primary">Create token</button>
    </form>

    <?php if ($tokens) { ?>
        <table class="table table-condensed">
            <thead>
            <tr><th>Label</th><th>Token</th><th>Access</th><th>Created</th><th>Last used</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($tokens as $token) { ?>
                <tr<?php echo $token['revokedAt'] ? ' class="text-muted"' : ''; ?>>
                    <td><?php echo esc($token['label']); ?></td>
                    <td><code><?php echo esc($token['tokenHint']); ?></code></td>
                    <td>
                        <?php echo $token['allowEdit'] ? '<strong>Can edit</strong> (drafts)' : 'Read only'; ?>
                        <?php if (!$token['revokedAt']) { ?>
                            <form method="post" action="<?php echo escAttr(ipActionUrl(array('aa' => 'Mcp.setTokenEdit'))); ?>" style="display: inline; margin: 0;">
                                <input type="hidden" name="securityToken" value="<?php echo escAttr(ipSecurityToken()); ?>">
                                <input type="hidden" name="id" value="<?php echo (int)$token['id']; ?>">
                                <input type="hidden" name="allowEdit" value="<?php echo $token['allowEdit'] ? 0 : 1; ?>">
                                <button type="submit" class="btn btn-link btn-xs"><?php echo $token['allowEdit'] ? 'Make read-only' : 'Allow editing'; ?></button>
                            </form>
                        <?php } ?>
                    </td>
                    <td><?php echo $formatTime($token['createdAt']); ?></td>
                    <td><?php echo $formatTime($token['lastUsedAt']); ?></td>
                    <td class="text-right">
                        <?php if ($token['revokedAt']) { ?>
                            Revoked <?php echo $formatTime($token['revokedAt']); ?>
                        <?php } else { ?>
                            <form method="post" action="<?php echo escAttr(ipActionUrl(array('aa' => 'Mcp.revokeToken'))); ?>" style="margin: 0;"
                                  onsubmit="return confirm('Revoke this token? Assistants using it lose access straight away.');">
                                <input type="hidden" name="securityToken" value="<?php echo escAttr(ipSecurityToken()); ?>">
                                <input type="hidden" name="id" value="<?php echo (int)$token['id']; ?>">
                                <button type="submit" class="btn btn-default btn-xs">Revoke</button>
                            </form>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } else { ?>
        <p>No tokens yet.</p>
    <?php } ?>

    <?php if ($recentChanges) { ?>
        <h2>Recent edits <small>last <?php echo count($recentChanges); ?></small></h2>
        <p class="text-muted">Text and heading edits are in each page's unpublished draft: open the page, check it and click Publish, or discard the draft there.</p>
        <table class="table table-condensed" style="font-size: 12px;">
            <thead><tr><th>Time</th><th>Token</th><th>Page</th><th>Change</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recentChanges as $change) { ?>
                <tr>
                    <td style="white-space: nowrap;"><?php echo $formatTime($change['createdAt']); ?></td>
                    <td><?php echo $change['label'] !== null ? esc($change['label']) : '—'; ?></td>
                    <td><?php echo $pageLink($change['pageId']); ?></td>
                    <td style="word-break: break-word;">
                        <?php if ($change['type'] === 'widget') { ?>
                            Widget <?php echo (int)$change['widgetId']; ?>:
                            <?php echo esc(mb_strimwidth(\Plugin\Mcp\Pages::htmlToText((string)($change['after']['text'] ?? $change['after']['title'] ?? '')), 0, 160, '…')); ?>
                        <?php } else { ?>
                            <?php echo esc(implode(', ', array_map(function ($field) use ($fieldLabels) { return $fieldLabels[$field] ?? $field; }, array_keys($change['after'])))); ?>
                        <?php } ?>
                    </td>
                    <td><?php echo esc($change['status']); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } ?>

    <h2>Request log <small>last <?php echo count($log); ?></small></h2>
    <?php if ($log) { ?>
        <table class="table table-condensed" style="font-size: 12px;">
            <thead>
            <tr><th>Time</th><th>Token</th><th>Address</th><th>Request</th><th>Status</th><th>Details</th></tr>
            </thead>
            <tbody>
            <?php foreach ($log as $row) { ?>
                <tr>
                    <td style="white-space: nowrap;"><?php echo esc(date('Y-m-d H:i:s', (int)$row['time'])); ?></td>
                    <td><?php echo $row['label'] !== null ? esc($row['label']) : '—'; ?></td>
                    <td><?php echo esc($row['ip']); ?></td>
                    <td><?php echo esc($row['method'] . ($row['tool'] ? ' ' . $row['tool'] : '')); ?></td>
                    <td><span class="label label-<?php echo $statusClass[$row['status']] ?? 'default'; ?>"><?php echo esc($row['status']); ?></span></td>
                    <td style="word-break: break-all;"><?php echo esc((string)$row['message']); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } else { ?>
        <p>No requests yet.</p>
    <?php } ?>
</div>
