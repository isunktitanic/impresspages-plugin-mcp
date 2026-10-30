<?php

namespace Plugin\Mcp;

/**
 * The MCP endpoint (Streamable HTTP transport). Clients POST JSON-RPC messages and get JSON back;
 * there are no sessions and no server-sent event stream, so GET and DELETE are refused.
 */
class PublicController extends \Ip\Controller
{
    public function mcp()
    {
        if (!Model::isEnabled()) {
            return new \Ip\Response\PageNotFound();
        }

        if (!Model::isAllowedOrigin()) {
            Model::log(null, 'http', null, 'denied', 'Foreign Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? ''));
            return self::jsonError(403, 'Requests from web pages on other sites are not allowed.');
        }

        if (ipRequest()->getMethod() !== 'POST') {
            return new \Ip\Response('', array('Allow: POST', 'Cache-Control: no-store'), 405);
        }

        if (Model::httpsRequired() && !Model::isHttps()) {
            Model::log(null, 'http', null, 'denied', 'Plain HTTP refused');
            return self::jsonError(403, 'HTTPS is required. Use the https:// address of this site.');
        }

        $bearer = Model::bearerToken();
        $token = $bearer ? Model::findActiveToken($bearer) : null;
        if (!$token) {
            // Throttles guessing without locking out valid tokens that share the address (an office network, say).
            // Blocked attempts aren't logged, so the block lifts 10 minutes after the last logged failure.
            if (Model::isIpBlocked()) {
                return self::jsonError(429, 'Too many failed sign-ins from this address. Try again in a few minutes.', array('Retry-After: 600'));
            }
            Model::log(null, 'http', null, 'unauthorized', $bearer ? 'Unknown or revoked token' : 'No bearer token');
            return self::jsonError(401, 'A valid API token is required (Authorization: Bearer ...).', array('WWW-Authenticate: Bearer realm="MCP"'));
        }

        $version = $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? null;
        if ($version !== null && !in_array($version, Server::PROTOCOL_VERSIONS, true)) {
            return self::jsonError(400, 'Unsupported MCP-Protocol-Version: ' . $version);
        }

        $body = json_decode((string)file_get_contents('php://input'), true);
        if ($body === null) {
            Model::log($token['id'], 'invalid', null, 'error', 'Parse error');
            return self::json(400, Server::error(null, Server::PARSE_ERROR, 'Parse error'));
        }

        Model::touchToken($token['id']);
        $server = new Server($token);

        // Batches were allowed in protocol version 2025-03-26; still accepted for older clients.
        if (is_array($body) && array_is_list($body) && $body) {
            $responses = array_values(array_filter(array_map(array($server, 'handle'), $body)));
            return $responses ? self::json(200, $responses) : self::accepted();
        }

        $response = $server->handle($body);
        return $response ? self::json(200, $response) : self::accepted();
    }

    protected static function json($status, $data)
    {
        return new \Ip\Response(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            array('Content-Type: application/json; charset=utf-8', 'Cache-Control: no-store'),
            $status
        );
    }

    protected static function jsonError($status, $message, $headers = array())
    {
        $response = self::json($status, Server::error(null, Server::INVALID_REQUEST, $message));
        foreach ($headers as $header) {
            $response->addHeader($header);
        }
        return $response;
    }

    /**
     * Notifications get no JSON-RPC answer, only "202 Accepted".
     */
    protected static function accepted()
    {
        return new \Ip\Response('', array('Cache-Control: no-store'), 202);
    }
}
