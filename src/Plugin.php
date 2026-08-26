<?php

namespace bensomething\daemon;

use bensomething\daemon\controllers\PlayController;
use bensomething\daemon\models\Settings;
use bensomething\daemon\services\Engine;
use bensomething\daemon\services\Saves;
use bensomething\daemon\services\Wads;
use bensomething\daemon\web\assets\settings\SettingsAsset;
use Craft;
use craft\base\Model;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * Daemon: a Doom engine running as a control panel section.
 *
 * The PHP here is MIT. The compiled engine under src/web/assets/daemon/dist/engine
 * is a derivative of GPL-2.0 source. See NOTICE.md.
 *
 * @property-read Engine $engine
 * @property-read Saves $saves
 * @property-read Wads $wads
 * @method Settings getSettings()
 */
class Plugin extends \craft\base\Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'engine' => Engine::class,
                'saves' => Saves::class,
                'wads' => Wads::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerPermissions();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->registerCpRoutes();
        }
    }

    public function getEngine(): Engine
    {
        return $this->get('engine');
    }

    public function getSaves(): Saves
    {
        return $this->get('saves');
    }

    public function getWads(): Wads
    {
        return $this->get('wads');
    }

    /**
     * Craft hides the nav item for anyone without `accessplugin-daemon`, but that
     * permission is really "can this user reach the plugin at all". Playing is
     * gated separately so it can be granted on its own.
     */
    public function getCpNavItem(): ?array
    {
        if (!Craft::$app->getUser()->checkPermission(PlayController::PERMISSION_PLAY)) {
            return null;
        }

        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        // A Craft system icon rather than the plugin's own icon.svg: nav icons
        // are masked to currentColor at 16px, which turns anything detailed
        // into a blob.
        $item['icon'] = 'face-smile-horns';

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        Craft::$app->getView()->registerAssetBundle(SettingsAsset::class);

        // Resolved here rather than in the template: Twig has no is_dir(), and
        // a directory that was typed wrong should say so on the screen where
        // it was typed.
        $wadDir = $this->getSettings()->getWadDir();

        return Craft::$app->getView()->renderTemplate('daemon/_settings.twig', [
            'settings' => $this->getSettings(),
            'wads' => $this->getWads(),
            'engine' => $this->getEngine(),
            'wadDir' => $wadDir,
            'wadDirMissing' => $wadDir !== null && !is_dir($wadDir),
        ]);
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['daemon'] = 'daemon/play/index';
            }
        );
    }

    /**
     * Registered in every request context, not just CP requests: permissions
     * are read by console commands and by user-save validation too.
     */
    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('daemon', 'Daemon'),
                    'permissions' => [
                        PlayController::PERMISSION_PLAY => [
                            'label' => Craft::t('daemon', 'Play'),
                        ],
                    ],
                ];
            }
        );
    }
}
