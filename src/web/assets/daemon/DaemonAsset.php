<?php

namespace bensomething\daemon\web\assets\daemon;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class DaemonAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $css = [
        'daemon.css',
    ];

    public $js = [
        'daemon-host.js',
    ];

    /**
     * The source path as a static, so a controller can ask the asset manager
     * for the published URL of a file inside dist/ (the engine artefacts)
     * without instantiating the bundle.
     */
    public static function sourcePath(): string
    {
        return __DIR__ . '/dist';
    }
}
