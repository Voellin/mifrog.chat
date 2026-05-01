<?php

namespace App\Exceptions\Skill;

use App\Exceptions\SkillException;

/**
 * Caller-supplied parameters to a skill operation are missing, empty, or of the wrong type.
 */
class SkillInputException extends SkillException
{
}
