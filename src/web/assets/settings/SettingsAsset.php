<?php

namespace bensomething\daemon\web\assets\settings;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class SettingsAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $js = [
        'settings.js',
    ];
}
