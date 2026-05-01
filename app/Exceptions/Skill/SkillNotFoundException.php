<?php

namespace App\Exceptions\Skill;

use App\Exceptions\SkillException;

/**
 * Requested skill does not exist / is inactive, or its skill.md body is missing.
 */
class SkillNotFoundException extends SkillException
{
}
