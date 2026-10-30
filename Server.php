<?php

namespace Plugin\Mcp;

/**
 * MCP over JSON-RPC 2.0. Stateless: no sessions, no server-to-client stream.
 */
class Server
{
    const SERVER_NAME = 'impresspages-mcp';
    const SERVER_VERSION = '1.0.0';

    /** Newest first. The client's version is echoed when supported, otherwise the newest is offered. */
    const PROTOCOL_VERSIONS = array('2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05');

    const PARSE_ERROR = -32700;
    const INVALID_REQUEST = -32600;
    const METHOD_NOT_FOUND = -32601;
    const INVALID_PARAMS = -32602;
    const INTERNAL_ERROR = -32603;

    protected $token;

    public function __construct($token)
    {
        $this->token = $token;
    }

    /**
     * @param array $message One decoded JSON-RPC message
     * @return array|null The response, or null for notifications
     */
    public function handle($message)
    {
        $id = is_array($message) && array_key_exists('id', $message) ? $message['id'] : null;
        $isNotification = is_array($message) && !array_key_exists('id', $message);

        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || !isset($message['method']) || !is_string($message['method'])) {
            Model::log($this->token['id'], 'invalid', null, 'error', 'Invalid JSON-RPC message');
            return self::error($id, self::INVALID_REQUEST, 'Invalid Request');
        }

        $method = $message['method'];
        $params = isset($message['params']) && is_array($message['params']) ? $message['params'] : array();

        if ($isNotification) {
            // notifications/initialized, notifications/cancelled, ...: nothing to do.
            return null;
        }

        if (Model::isRateLimited($this->token['id'])) {
            Model::log($this->token['id'], $method, $params['name'] ?? null, 'rate_limited');
            return self::error($id, self::INTERNAL_ERROR, 'Rate limit exceeded: at most ' . Model::rateLimit() . ' requests per minute. Try again shortly.');
        }

        try {
            switch ($method) {
                case 'initialize':
                    $result = $this->initialize($params);
                    break;
                case 'ping':
                    $result = new \stdClass();
                    break;
                case 'tools/list':
                    $result = array('tools' => Tools::definitions());
                    break;
                case 'tools/call':
                    return $this->callTool($id, $params);
                default:
                    Model::log($this->token['id'], $method, null, 'error', 'Method not found');
                    return self::error($id, self::METHOD_NOT_FOUND, 'Method not found: ' . $method);
            }
        } catch (\Throwable $e) {
            ipLog()->error('Mcp.error: {message}', array('message' => $e->getMessage(), 'method' => $method));
            Model::log($this->token['id'], $method, null, 'error', $e->getMessage());
            return self::error($id, self::INTERNAL_ERROR, 'Internal error');
        }

        Model::log($this->token['id'], $method, null, 'ok');
        return self::result($id, $result);
    }

    protected function initialize($params)
    {
        $requested = $params['protocolVersion'] ?? null;
        $version = in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0];

        $siteTitle = trim((string)ipGetOptionLang('Config.websiteTitle', null, ''));

        return array(
            'protocolVersion' => $version,
            'capabilities' => array(
                'tools' => array('listChanged' => false),
            ),
            'serverInfo' => array(
                'name' => self::SERVER_NAME,
                'title' => $siteTitle !== '' ? $siteTitle : ipConfig()->baseUrl(),
                'version' => self::SERVER_VERSION,
            ),
            'instructions' => 'Read-only access to the pages of the website at ' . ipConfig()->baseUrl() . '. '
                . 'Start with list_languages or list_pages, then use get_page for a page\'s SEO fields and text, '
                . 'or search_pages to find pages by words. Content comes from the published version of each page.',
        );
    }

    /**
     * Tool problems (bad arguments, missing pages) are returned as tool results with isError,
     * so the assistant can read them and try again; only unknown tools are protocol errors.
     */
    protected function callTool($id, $params)
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? array();
        if (!is_string($name) || !Tools::exists($name)) {
            Model::log($this->token['id'], 'tools/call', is_string($name) ? $name : null, 'error', 'Unknown tool');
            return self::error($id, self::INVALID_PARAMS, 'Unknown tool: ' . (is_string($name) ? $name : ''));
        }
        if (!is_array($arguments)) {
            $arguments = array();
        }

        $summary = json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            $data = Tools::call($name, $arguments, $this->token);
        } catch (ToolException $e) {
            Model::log($this->token['id'], 'tools/call', $name, 'error', $summary . ' → ' . $e->getMessage());
            return self::result($id, array(
                'content' => array(array('type' => 'text', 'text' => $e->getMessage())),
                'isError' => true,
            ));
        } catch (\Throwable $e) {
            ipLog()->error('Mcp.toolError: {message}', array('message' => $e->getMessage(), 'tool' => $name));
            Model::log($this->token['id'], 'tools/call', $name, 'error', $summary . ' → ' . $e->getMessage());
            return self::result($id, array(
                'content' => array(array('type' => 'text', 'text' => 'The tool failed on the server. The error has been logged.')),
                'isError' => true,
            ));
        }

        Model::log($this->token['id'], 'tools/call', $name, 'ok', $summary);
        return self::result($id, array(
            'content' => array(array(
                'type' => 'text',
                'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            )),
            'structuredContent' => $data,
        ));
    }

    public static function result($id, $result)
    {
        return array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result);
    }

    public static function error($id, $code, $message)
    {
        return array('jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => $code, 'message' => $message));
    }
}
