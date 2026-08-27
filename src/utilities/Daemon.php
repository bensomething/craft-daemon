<?php

namespace bensomething\daemon\utilities;

use bensomething\daemon\controllers\PlayController;
use bensomething\daemon\Plugin;
use bensomething\daemon\services\Wads;
use bensomething\daemon\web\assets\settings\SettingsAsset;
use Craft;
use craft\base\Utility;

/**
 * Everything that is done to the plugin's storage rather than in the game:
 * fetching WADs onto the server, and taking savegames off it.
 *
 * A utility rather than part of the settings screen, which is where the
 * downloads started. Craft renders plugin settings through Html::disableInputs()
 * when allowAdminChanges is off, and that disables every button and throws the
 * screen's JavaScript away with it, so on the production installs the setting
 * is recommended for, the download buttons were dead. Utilities are not subject
 * to allowAdminChanges, and moving a large file on or off the server is a
 * utility shaped thing to do in any case.
 */
class Daemon extends Utility
{
    /**
     * The name in the Utilities sidebar. Not prefixed, because the plugin is
     * called Daemon and this is its screen. The sections inside it say what
     * each one is for.
     */
    public static function displayName(): string
    {
        return Craft::t('daemon', 'Daemon');
    }

    /**
     * Also the permission handle, as `utility:daemon`.
     */
    public static function id(): string
    {
        return 'daemon';
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

        $plugin = Plugin::getInstance();
        $user = Craft::$app->getUser();
        $userId = $user->getId();

        // The savegame section is one person's own saves, so it needs somebody
        // who could have made some. Holding this utility's permission is not
        // the same as being able to play, and a section that could only ever be
        // empty is worse than no section.
        $canDownloadSaves = $userId !== null && $user->checkPermission(PlayController::PERMISSION_PLAY);

        return $view->renderTemplate('daemon/_utility.twig', [
            'wads' => $plugin->getWads(),
            'canDownloadSaves' => $canDownloadSaves,
            'games' => $canDownloadSaves ? self::savesByGame((int)$userId) : [],
            'autosave' => $plugin->getSettings()->autosave,
        ]);
    }

    /**
     * One user's stored saves, grouped under the game they belong to, with games
     * holding none left out.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function savesByGame(int $userId): array
    {
        $plugin = Plugin::getInstance();
        $saves = $plugin->getSaves();
        $games = [];

        foreach ($plugin->getWads()->getAvailableWads() as $key => $wad) {
            $stored = $saves->getSaves($userId, $key);

            if ($stored === []) {
                continue;
            }

            $games[] = [
                'wad' => $wad,
                'saves' => array_values($stored),
                'bytes' => array_sum(array_map(fn($save) => $save->size, $stored)),
            ];
        }

        return $games;
    }
}
