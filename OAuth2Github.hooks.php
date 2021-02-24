<?php

use DatabaseUpdater;

class OAuth2GithubHooks
{

    public static function onBeforePageDisplay(OutputPage &$out, Skin &$skin)
    {

        $script = '<link rel="stylesheet" type="text/css" href="/extensions/MediaWiki-OAuth2-Github/modules/OAuth2Github.css">';

        $out->addHeadItem("jsonTree script", $script);

        return true;

    }

    public static function onUserLoginForm(&$tpl)
    {
        global $wgRequest;
        $header = $tpl->get('header');
        $header .= '<a class="mw-ui-button dataporten-button" href="' . Skin::makeSpecialUrlSubpage('OAuth2Github', 'redirect', 'returnto=' . $wgRequest->getVal('returnto')) . '">Login with Github</a>';
        $tpl->set('header', $header);
    }

    public static function onAuthChangeFormFields($requests, $fieldInfo, &$formDescriptor, $action) {
        global $wgRequest;
        $formDescriptor["github"] = [
            'type' => 'info',
            'default' => '<a class="mw-ui-button dataporten-button" href="' . Skin::makeSpecialUrlSubpage('OAuth2Github', 'redirect', 'returnto=' . $wgRequest->getVal('returnto')) . '">Login with Github</a>',
            'raw' => true,
        ];
    }

    public static function onUserLogout(&$user)
    {
        return true;
    }

    public static function getOAuth2VendorClassPath()
    {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR . 'vendors' . DIRECTORY_SEPARATOR . 'oauth_2';
    }

    public static function onLoadExtensionSchemaUpdates(DatabaseUpdater $updater)
    {
        $updater->addExtensionTable('github_states',
            __DIR__ . '/sql/state.sql');
        $updater->addExtensionTable('github_users',
            __DIR__ . '/sql/users.sql');
        $updater->doUpdates();
        return true;
    }
}
