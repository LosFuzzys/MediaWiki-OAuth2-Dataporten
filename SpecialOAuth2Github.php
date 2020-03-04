<?php
if (!defined('MEDIAWIKI')) {
    die('This is a MediaWiki extension, and must be run from within MediaWiki.');
}

//require_once('OAuth2-Client/OAuth2Client.php');

class SpecialOAuth2Github extends SpecialPage
{

    private $client;
    private $table = 'github_users';

    public function __construct()
    {
        if (!self::OAuthEnabled()) return;

        parent::__construct('OAuth2Github');
        global $wgOAuth2Github, $wgServer, $wgArticlePath;

        $this->client = new OAuth2([
            "client_id" => $wgOAuth2Github['client']['id'],
            "client_secret" => $wgOAuth2Github['client']['secret'],
            "redirect_uri" => $wgServer . str_replace('$1', 'Special:OAuth2Github/callback', $wgArticlePath),
            "auth" => $wgOAuth2Github['config']['auth_endpoint'],
            "token" => $wgOAuth2Github['config']['token_endpoint'],
            "authorization_type" => $wgOAuth2Github['config']['auth_type'],
            "scope" => "user:email, read:org"]);
    }

    public static function OAuthEnabled()
    {
        global $wgOAuth2Github;
        return isset(
            $wgOAuth2Github['client']['id'],
            $wgOAuth2Github['client']['secret'],
            $wgOAuth2Github['config']['auth_endpoint'],
            $wgOAuth2Github['config']['token_endpoint'],
            //$wgOAuth2Github['config']['info_endpoint'],
            //$wgOAuth2Github['config']['required_org']
            $wgOAuth2Github['config']['auth_type']
        );
    }

    public function execute($parameter)
    {
        $this->setHeaders();
        switch ($parameter) {
            case 'redirect':
                $this->_redirect();
                break;
            case 'callback':
                $this->_callback();
                break;
            default:
                $this->_default();
                break;
        }
    }

    private function _redirect()
    {
        global $wgRequest;

        $state = uniqid('', true);
        $url = $wgRequest->getVal('returnto');

        $dbw = wfGetDB(DB_MASTER);
        $dbw->insert('github_states',
            array('state' => $state,
                'return_to' => $url),
            'Database::insert');
        $dbw->begin();
        $this->client->redirect($state);
    }

    private function _callback()
    {
        global $wgOAuth2Github, $wgOut, $wgRequest;

        $dbr = wfGetDB(DB_SLAVE);
        $row = $dbr->selectRow(
            'github_states',
            '*',
            array('state' => $wgRequest->getVal('state')));

        $row = json_decode(json_encode($row), true);
        if (!$row) {
            throw new MWException('States differ');
        }

        $dbw = wfGetDB(DB_MASTER);
        $dbw->delete('github_states',
            array('state' => $wgRequest->getVal('state')));
        $dbw->begin();

        $access_token = $this->client->get_access_token();
        if (!$access_token) {
            throw new MWException('Something went wrong fetching the access token');
        }

        $userdata = $this->client->get_identity($access_token, $wgOAuth2Github['config']['info_endpoint']);
        $credentials = $this->fix_return($userdata);


        if (isset($wgOAuth2Github['config']['required_org']) && $wgOAuth2Github['config']['required_org'] != NULL) {
            if ($userdata['groups'] && $userdata['groups'] != NULL) {
                // 'groups' already received via user data endpoint
                $orgs = $userdata['groups'];

            } else {
                // https://api.github.com/users/$name/orgs
                //$orgsEndpoint = 'https://api.github.com/users/' .$credentials['id'] . '/orgs';
                $orgsEndpoint = 'https://api.github.com/user/orgs';
                $orgs = $this->client->get_identity($access_token, $orgsEndpoint); // $wgOAuth2Github['config']['group_endpoint']);
            }

            if (!$this->checkGroupmembership($orgs, $wgOAuth2Github['config']['required_org'])) {
                $error = ('You a not part of the ' . $wgOAuth2Github['config']['required_org'] . ' organization on Github!');

                global $wgOut;
                $wgOut->setPageTitle('Auth Error');
                $wgOut->addHTML('<strong>' . $error . '</strong>');

                return false;
            }
        }

        $user = $this->userHandling($credentials);
        $user->setCookies(null, null, true);

        //$this->add_user_to_groups($user, $2);

        if ($row['return_to']) {
            $title = Title::newFromText($row['return_to']);
        } else {
            $title = Title::newMainPage();
        }

        $wgOut->redirect($title->getFullUrl());

        return true;
    }

    private
    function fix_return($response)
    {

        if (isset($response['nickname'])) {
            $name = $response['nickname'];
        } else if (isset($response['name'])) {
            $name = $response['name'];
        } else if (isset($response['user']['name'])) {
            $name = $response['user']['name'];
        } else {
            $name = null;
        }

        if (isset($response['id'])) {
            $id = $response['id'];
        } else if (isset($response['userid'])) {
            $id = $response['userid'];
        } else if (isset($response['user_id'])) {
            $id = $response['user_id'];
        } else if (isset($response['user']['userid'])) {
            $id = $response['user']['userid'];
        } else if (isset($response['user']['user_id'])) {
            $id = $response['user']['user_id'];
        } else if (isset($response['sub'])) {
            $id = $response['sub'];
        }

        if (isset($response['email'])) {
            $email = $response['email'];
        } else if (isset($response['user']['email'])) {
            $email = $response['user']['email'];
        } else {
            $email = null;
        }

        $oauth_identity = array(
            'id' => $response['login'],
            'email' => $email,
            'name' => $name,
        );

        return $oauth_identity;
    }

    private
    function checkGroupmembership($orgs, $requiredOrg)
    {
        foreach ($orgs as $org) {
            // if($org['id'] === 15344454) return true;
            if (($org['login'] && $org['login'] === $requiredOrg) || $org === $requiredOrg) return true;
        }

        return false;
    }

    private
    function userHandling($credentials)
    {
        global $wgOAuth2Github, $wgAuth;

        $name = $credentials['name'];
        $id = $credentials["id"];
        $email = $credentials["email"];
        $externalId = $id;
        $dbr = wfGetDB(DB_SLAVE);
        $row = $dbr->selectRow(
            $this->table,
            '*',
            array('external_id' => $externalId)
        );

        if ($row) {                //Github-user already exists
            return User::newFromId($row->internal_id);
        }
        $user = User::newFromName($id, 'creatable');
        if (false === $user || $user->getId() != 0) {
            throw new MWException('Unable to create user.');
        }
        if (!$user->isLoggedIn()) {
            // [New in MW 1.27]
            // User does not exist,
            // so we need to add them to the DB before changing fields.
            $user->addToDatabase();
        }

        if ($name) {
            $user->setRealName($name);
        }
        if ($wgAuth->allowPasswordChange()) {
            // TODO: This is deprecated since MW 1.27, replace by AuthManager
            $user->setPassword(PasswordFactory::generateRandomPasswordString(128));
        }
        if ($email) {
            $user->setEmail($email);
            $user->setEmailAuthenticationTimestamp(time());
        }
        $user->addToDatabase();
        $dbw = wfGetDB(DB_MASTER);
        $dbw->replace(
            $this->table,
            array('internal_id', 'external_id'),
            array('internal_id' => $user->getId(),
                'external_id' => $externalId),
            __METHOD__);
        return $user;
    }

    private
    function _default()
    {
        global $wgOAuth2Github, $wgOut, $wgUser, $wgExtensionAssetsPath;

        return true;
    }

    private
    function _logout()
    {
        global $wgOAuth2Github, $wgOut, $wgUser;
        if ($wgUser->isLoggedIn()) $wgUser->logout();

    }

    private
    function add_user_to_groups($user, $groups)
    {
        foreach ($groups as $key => $value) {
            $user->addGroup($groups[$key]["id"]);
        }
    }

}
