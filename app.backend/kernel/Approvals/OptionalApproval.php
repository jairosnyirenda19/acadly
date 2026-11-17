<?php

declare(strict_types=1);

namespace Acadly\Kernel\Approvals;

/**
 * A kind of request that needs no yes until the school sets steps for it
 * (D-081): publishing results happens at once unless the school asks for a
 * chain. Without saved steps its chain is empty, and the module acts at once.
 */
interface OptionalApproval extends ApprovalTopic {}
