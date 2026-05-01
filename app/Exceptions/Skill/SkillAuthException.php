<?php

namespace App\Exceptions\Skill;

use App\Exceptions\SkillException;

/**
 * User tried to use a skill they have not been granted access to.
 */
class SkillAuthException extends SkillException
{
}
