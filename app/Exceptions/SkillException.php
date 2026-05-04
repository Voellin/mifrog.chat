<?php

namespace App\Exceptions;

use DomainException;

/**
 * Base class for all Skill-domain exceptions.
 *
 * Extends \DomainException so existing `catch (\DomainException $e)` sites
 * (ToolCallExecutorService, AdminSkillController) keep working unchanged.
 *
 * Introduced 2026-04-21 as part of P1.3 refactor.
 * See SKILL_BOUNDARY.md — the contract "DomainException must be turned into
 * status=error results by ToolCallExecutorService" is preserved because
 * every subclass of this class is-a DomainException.
 */
abstract class SkillException extends DomainException
{
}
