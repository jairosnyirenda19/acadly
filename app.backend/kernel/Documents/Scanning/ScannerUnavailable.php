<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Scanning;

use RuntimeException;

/** The scanner could not be asked. This is never a pass. */
final class ScannerUnavailable extends RuntimeException {}
