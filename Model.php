<?php

namespace Plugin\Mcp;

class Model
{
    const TOKEN_PREFIX = 'ipmcp_';

    /** Failed sign-ins allowed from one address in 10 minutes before it's blocked for a while. */
    const MAX_FAILED_AUTH = 20;

    public static function isEnabled()
    {
        return (bool)ipGetOption('Mcp.enabled', 1);
    }

    public static function includeHidden()
    {
        return (bool)ipGetOption('Mcp.includeHidden', 0);
    }

    public static function rateLimit()
    {
        $limit = (int)ipGetOption('Mcp.rateLimit', 60);
        return $limit > 0 ? $limit : 60;
    }

    public static function logDays()
    {
        $days = (int)ipGetOption('Mcp.logDays', 30);
        return $days > 0 ? $days : 30;
    }

    public static function endpointUrl()
    {
        return ipConfig()->baseUrl() . 'mcp';
    }

    // Tokens

    /**
     * @return array [token row, plain token]. The plain token is never stored and can't be shown again.
     */
    public static function createToken($label)
    {
        $token = self::TOKEN_PREFIX . bin2hex(random_bytes(32));
        $id = ipDb()->insert('mcp_token', array(
            'label' => mb_substr($label, 0, 100),
            'tokenHash' => hash('sha256', $token),
            'tokenHint' => substr($token, 0, strlen(self::TOKEN_PREFIX) + 4) . '…' . substr($token, -4),
            'allowEdit' => 0,
            'createdBy' => ipAdminId() ?: null,
            'createdAt' => time(),
        ));

        return array(self::getToken($id), $token);
    }

    public static function getToken($id)
    {
        return ipDb()->selectRow('mcp_token', '*', array('id' => $id));
    }

    public static function getTokens()
    {
        return ipDb()->selectAll('mcp_token', '*', array(), 'ORDER BY `revokedAt` IS NOT NULL, `id` DESC');
    }

    public static function revokeToken($id)
    {
        ipDb()->update('mcp_token', array('revokedAt' => time()), array('id' => $id, 'revokedAt' => null));
    }

    /**
     * Active token row for the plain token, or null.
     * Tokens are 256-bit random values, so a plain SHA-256 lookup is safe (no salt or slow hash needed).
     */
    public static function findActiveToken($token)
    {
        if (strpos($token, self::TOKEN_PREFIX) !== 0 || strlen($token) > 200) {
            return null;
        }
        $row = ipDb()->selectRow('mcp_token', '*', array('tokenHash' => hash('sha256', $token)));
        if (!$row || $row['revokedAt']) {
            return null;
        }
        return $row;
    }

    public static function touchToken($id)
    {
        ipDb()->update('mcp_token', array('lastUsedAt' => time()), array('id' => $id));
    }

    // Request context

    /**
     * Bearer token from the Authorization header. Some Apache/CGI setups only pass it on as REDIRECT_HTTP_AUTHORIZATION.
     */
    public static function bearerToken()
    {
        $header = '';
        foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $key) {
            if (!empty($_SERVER[$key])) {
                $header = $_SERVER[$key];
                break;
            }
        }
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    $header = $value;
                }
            }
        }
        return preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) ? $matches[1] : null;
    }

    /**
     * Only the direct client address is used: X-Forwarded-For can be set by anyone.
     */
    public static function clientIp()
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    public static function isLoopback()
    {
        return in_array(self::clientIp(), array('127.0.0.1', '::1'), true);
    }

    /**
     * X-Forwarded-Proto is accepted because hosts often end TLS at a proxy. A client could fake it,
     * but that would only expose its own token, which is what this check protects.
     */
    public static function isHttps()
    {
        if (ipRequest()->isHttps() || ($_SERVER['SERVER_PORT'] ?? null) == 443) {
            return true;
        }
        return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function httpsRequired()
    {
        return ipGetOption('Mcp.httpsOnly', 1) && !self::isLoopback();
    }

    /**
     * Browsers send Origin; MCP clients don't. A foreign Origin means a web page is trying to reach the endpoint
     * (DNS rebinding or CSRF), which the MCP spec says to refuse.
     */
    public static function isAllowedOrigin()
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin === null || $origin === '') {
            return true;
        }
        $site = parse_url(ipConfig()->baseUrl());
        $request = parse_url($origin);
        if (empty($site['host']) || empty($request['host'])) {
            return false;
        }
        return strtolower($site['host']) === strtolower($request['host']);
    }

    // Rate limit and log

    public static function isRateLimited($tokenId)
    {
        $count = ipDb()->fetchValue(
            'SELECT COUNT(*) FROM ' . ipTable('mcp_log') . ' WHERE `tokenId` = ? AND `time` > ?',
            array($tokenId, time() - 60)
        );
        return $count >= self::rateLimit();
    }

    public static function isIpBlocked()
    {
        $count = ipDb()->fetchValue(
            'SELECT COUNT(*) FROM ' . ipTable('mcp_log') . " WHERE `ip` = ? AND `status` = 'unauthorized' AND `time` > ?",
            array(self::clientIp(), time() - 600)
        );
        return $count >= self::MAX_FAILED_AUTH;
    }

    /**
     * @param int|null $tokenId
     * @param string $method JSON-RPC method, or the HTTP problem for requests that never got that far
     * @param string|null $tool
     * @param string $status ok, error, denied, unauthorized or rate_limited
     * @param string|null $message
     */
    public static function log($tokenId, $method, $tool, $status, $message = null)
    {
        ipDb()->insert('mcp_log', array(
            'tokenId' => $tokenId,
            'time' => time(),
            'ip' => self::clientIp(),
            'method' => mb_substr((string)$method, 0, 100),
            'tool' => $tool === null ? null : mb_substr((string)$tool, 0, 100),
            'status' => $status,
            'message' => $message === null ? null : mb_substr($message, 0, 255),
        ));

        // Prune now and then rather than on every request.
        if (random_int(1, 50) === 1) {
            self::pruneLog();
        }
    }

    public static function pruneLog()
    {
        ipDb()->execute(
            'DELETE FROM ' . ipTable('mcp_log') . ' WHERE `time` < ?',
            array(time() - self::logDays() * 86400)
        );
    }

    public static function getLog($limit = 100)
    {
        return ipDb()->fetchAll(
            'SELECT l.*, t.`label` FROM ' . ipTable('mcp_log', 'l') . ' LEFT JOIN ' . ipTable('mcp_token', 't') . ' ON t.`id` = l.`tokenId`'
            . ' ORDER BY l.`id` DESC LIMIT ' . (int)$limit
        );
    }
}
