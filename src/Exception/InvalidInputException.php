<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Exception;

use InvalidArgumentException;

/**
 * Thrown before any request is sent when the sources, an option or a run ID are invalid.
 */
class InvalidInputException extends InvalidArgumentException {}
