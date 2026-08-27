<?php

namespace bensomething\daemon;

use bensomething\daemon\controllers\PlayController;
use bensomething\daemon\models\Settings;
use bensomething\daemon\services\Engine;
use bensomething\daemon\services\Saves;
use bensomething\daemon\services\Stats;
use bensomething\daemon\services\Wads;
use bensomething\daemon\utilities\DownloadWads;
use bensomething\daemon\web\assets\settings\SettingsAsset;
use Craft;
use craft\base\Model;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\services\Utilities;
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
 * @property-read Stats $stats
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
                'stats' => Stats::class,
                'wads' => Wads::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerPermissions();

        // Not inside the CP-request branch below. Registering a component type
        // is not CP rendering: console commands and permission checks read the
        // list too, and a utility missing from it is a permission that cannot
        // be granted.
        $this->registerUtilities();
        $this->registerGarbageCollection();

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

    public function getStats(): Stats
    {
        return $this->get('stats');
    }

    public function getWads(): Wads
    {
        return $this->get('wads');
    }

    /**
     * Craft hides the nav item without `accessplugin-daemon`, but that permission
     * means "can reach the plugin at all". Playing is gated separately so it can be
     * granted on its own.
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

    private function registerUtilities(): void
    {
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = DownloadWads::class;
            }
        );
    }

    /**
     * Savegames and level stats are both filed under a user id, and a deleted
     * user's directory would otherwise sit there for good. Craft's own garbage
     * collection is where this belongs: it already runs on a schedule, after
     * the soft deleted users it is clearing out have really gone.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function() {
                $this->getSaves()->deleteOrphaned();
                $this->getStats()->deleteOrphaned();
            }
        );
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
     * Registered in every request context: permissions are read by console commands
     * and by user-save validation too.
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
