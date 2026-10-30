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
     * @param string|null $notice
     */
    protected function page($newToken = null, $notice = null)
    {
        Model::pruneLog();

        return ipView('view/admin.php', array(
            'endpoint' => Model::endpointUrl(),
            'tokens' => Model::getTokens(),
            'log' => Model::getLog(100),
            'pending' => Changes::pending(),
            'recentChanges' => Changes::recent(30),
            'pageTitles' => self::pageTitles(),
            'newToken' => $newToken,
            'notice' => $notice,
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

        $allowEdit = (bool)ipRequest()->getPost('allowEdit');
        list($row, $token) = Model::createToken($label, $allowEdit);
        ipLog()->info('Mcp.tokenCreated: {label}', array('label' => $row['label'], 'tokenId' => $row['id'], 'allowEdit' => $allowEdit, 'adminId' => ipAdminId()));

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

    public function setTokenEdit()
    {
        ipRequest()->mustBePost();
        $id = (int)ipRequest()->getPost('id');
        $allowEdit = (bool)ipRequest()->getPost('allowEdit');
        $row = Model::getToken($id);
        if ($row && !$row['revokedAt']) {
            Model::setTokenEdit($id, $allowEdit);
            ipLog()->info('Mcp.tokenEditing: {label}', array('label' => $row['label'], 'tokenId' => $id, 'allowEdit' => $allowEdit, 'adminId' => ipAdminId()));
        }

        return new \Ip\Response\Redirect(ipActionUrl(array('aa' => 'Mcp.index')));
    }

    /**
     * Applies or rejects a proposed change to a page's title and SEO fields.
     */
    public function reviewChange()
    {
        ipRequest()->mustBePost();
        $change = Changes::get((int)ipRequest()->getPost('id'));
        $notice = null;
        if ($change && $change['type'] === 'page' && $change['status'] === 'pending') {
            if (ipRequest()->getPost('decision') === 'apply') {
                $skipped = Changes::apply($change);
                if ($skipped) {
                    $notice = 'Applied, except ' . implode(', ', $skipped) . ': changed on the page since it was proposed, so left as it is.';
                }
            } else {
                Changes::review($change['id'], 'rejected');
            }
        }

        return $notice ? $this->page(null, $notice) : new \Ip\Response\Redirect(ipActionUrl(array('aa' => 'Mcp.index')));
    }

    protected static function pageTitles()
    {
        $titles = array();
        foreach (ipDb()->selectAll('page', array('id', 'title'), array()) as $row) {
            $titles[(int)$row['id']] = $row['title'];
        }
        return $titles;
    }
}
