<?php

namespace Plugin\Mcp;

class AdminController
{
    public function index()
    {
        return $this->page();
    }

    /**
     * API tokens and the request log.
     *
     * @param array|null $newToken ['label' => ..., 'token' => ...] right after a token is created
     */
    protected function page($newToken = null)
    {
        Model::pruneLog();

        return ipView('view/admin.php', array(
            'endpoint' => Model::endpointUrl(),
            'tokens' => Model::getTokens(),
            'log' => Model::getLog(100),
            'newToken' => $newToken,
            'rateLimit' => Model::rateLimit(),
            'logDays' => Model::logDays(),
            'enabled' => Model::isEnabled(),
            'httpsOnly' => (bool)ipGetOption('Mcp.httpsOnly', 1),
            'includeHidden' => Model::includeHidden(),
        ));
    }

    /**
     * Shows the new token on the page it returns, once. Only its hash is kept.
     */
    public function createToken()
    {
        ipRequest()->mustBePost();
        $label = trim((string)ipRequest()->getPost('label', ''));
        if ($label === '') {
            $label = 'Token ' . date('Y-m-d H:i');
        }

        list($row, $token) = Model::createToken($label);
        ipLog()->info('Mcp.tokenCreated: {label}', array('label' => $row['label'], 'tokenId' => $row['id'], 'adminId' => ipAdminId()));

        return $this->page(array('label' => $row['label'], 'token' => $token));
    }

    public function revokeToken()
    {
        ipRequest()->mustBePost();
        $id = (int)ipRequest()->getPost('id');
        $row = Model::getToken($id);
        if ($row) {
            Model::revokeToken($id);
            ipLog()->info('Mcp.tokenRevoked: {label}', array('label' => $row['label'], 'tokenId' => $id, 'adminId' => ipAdminId()));
        }

        return new \Ip\Response\Redirect(ipActionUrl(array('aa' => 'Mcp.index')));
    }
}
