<?php
/**
 * @var string $endpoint
 * @var array $tokens
 * @var array $log
 * @var array|null $newToken
 */
$formatTime = function ($time) {
    return $time ? date('Y-m-d H:i', (int)$time) : '—';
};
$statusClass = array('ok' => 'success', 'error' => 'warning', 'denied' => 'danger', 'unauthorized' => 'danger', 'rate_limited' => 'warning');
?>
<div class="ipModuleMcp" style="padding: 0 20px 40px; max-width: 1100px;">
    <div class="page-header">
        <h1>MCP Server <small>read-only</small></h1>
    </div>

    <p>
        AI assistants that support the Model Context Protocol can read this site's pages through
        <code><?php echo esc($endpoint); ?></code> with one of the API tokens below.
        They can list languages, menus and pages, read a page's SEO fields and text, and search. They can't change anything.
    </p>

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

    <h2>API tokens</h2>
    <form class="form-inline" method="post" action="<?php echo escAttr(ipActionUrl(array('aa' => 'Mcp.createToken'))); ?>" style="margin-bottom: 15px;">
        <input type="hidden" name="securityToken" value="<?php echo escAttr(ipSecurityToken()); ?>">
        <div class="form-group">
            <label for="mcpTokenLabel" class="sr-only">Label</label>
            <input type="text" class="form-control" id="mcpTokenLabel" name="label" maxlength="100" placeholder="Label, e.g. Claude on my laptop" style="min-width: 280px;">
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
                    <td>Read only</td>
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
