<?php

namespace bensomething\doom;

use bensomething\doom\controllers\PlayController;
use bensomething\doom\models\Settings;
use bensomething\doom\services\Engine;
use bensomething\doom\services\Wads;
use Craft;
use craft\base\Model;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * Doom: an id Software shareware-era first person shooter, running as a
 * control panel section.
 *
 * The PHP here is MIT. The compiled engine under src/web/assets/doom/dist/engine
 * is a derivative of GPL-2.0 source. See NOTICE.md.
 *
 * @property-read Engine $engine
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

    public function getWads(): Wads
    {
        return $this->get('wads');
    }

    /**
     * Craft hides the nav item for anyone without `accessplugin-doom`, but that
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

        $item['label'] = $this->getSettings()->navLabel ?: Craft::t('doom', 'Doom');
        $item['icon'] = '@bensomething/doom/icon.svg';

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('doom/_settings.twig', [
            'settings' => $this->getSettings(),
            'wads' => $this->getWads(),
            'engine' => $this->getEngine(),
        ]);
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['doom'] = 'doom/play/index';
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
                    'heading' => Craft::t('doom', 'Doom'),
                    'permissions' => [
                        PlayController::PERMISSION_PLAY => [
                            'label' => Craft::t('doom', 'Play Doom'),
                        ],
                    ],
                ];
            }
        );
    }
}
