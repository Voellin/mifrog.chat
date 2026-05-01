<?php

namespace App\Exceptions\Skill;

use App\Exceptions\SkillException;

/**
 * Skill metadata is malformed or an http_api skill lacks required config (api_url, scheme, host allow-list).
 */
class SkillConfigException extends SkillException
{
}
