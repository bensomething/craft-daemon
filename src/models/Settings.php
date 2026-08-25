<?php

namespace bensomething\daemon\models;

use craft\base\Model;
use craft\helpers\App;

class Settings extends Model
{
    /**
     * @var string|null Path to the WAD to load, as an alias or an env var
     * reference. Null means "whatever `daemon/wad/fetch` put in storage".
     */
    public ?string $wadPath = null;

    /**
     * @var bool Whether pointer lock is offered for mouselook. Off means
     * keyboard only, which some people prefer and some browsers force.
     */
    public bool $pointerLock = true;

    /**
     * The configured WAD path with any $ENV_VAR or @alias resolved. Null when
     * nothing has been configured, which is the signal to fall back to the
     * storage directory.
     */
    public function getWadPath(): ?string
    {
        if ($this->wadPath === null || trim($this->wadPath) === '') {
            return null;
        }

        $path = App::parseEnv($this->wadPath);

        return is_string($path) && $path !== '' ? $path : null;
    }

    protected function defineRules(): array
    {
        return [
            [['wadPath'], 'trim'],
            [['wadPath'], 'string'],
            [['pointerLock'], 'boolean'],
        ];
    }
}
