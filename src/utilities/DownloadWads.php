<?php

namespace bensomething\daemon\utilities;

use bensomething\daemon\Plugin;
use bensomething\daemon\services\Wads;
use bensomething\daemon\web\assets\settings\SettingsAsset;
use Craft;
use craft\base\Utility;

/**
 * Downloading WADs.
 *
 * A utility rather than part of the settings screen, which is where this
 * started. Craft renders plugin settings through Html::disableInputs() when
 * allowAdminChanges is off, and that disables every button and throws the
 * screen's JavaScript away with it, so on the production installs the setting
 * is recommended for, the download buttons were dead. Utilities are not
 * subject to allowAdminChanges, and fetching a large file onto the server is a
 * utility shaped thing to do in any case.
 */
class DownloadWads extends Utility
{
    /**
     * The name in the Utilities sidebar. Prefixed, because that list belongs
     * to the whole install and "WADs" on its own says nothing about which
     * plugin put it there.
     */
    public static function displayName(): string
    {
        return Craft::t('daemon', 'Daemon WADs');
    }

    /**
     * Also the permission handle, as `utility:daemon-wads`.
     */
    public static function id(): string
    {
        return 'daemon-wads';
    }

    /**
     * The same icon the games carry in the Game menu.
     */
    public static function icon(): ?string
    {
        return Wads::ICON;
    }

    /**
     * @throws \yii\base\Exception if the template can't be rendered.
     * @throws \Twig\Error\LoaderError if the template is missing.
     * @throws \Twig\Error\RuntimeError if rendering it fails.
     * @throws \Twig\Error\SyntaxError if it doesn't parse.
     */
    public static function contentHtml(): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(SettingsAsset::class);

        return $view->renderTemplate('daemon/_utility.twig', [
            'wads' => Plugin::getInstance()->getWads(),
        ]);
    }
}
